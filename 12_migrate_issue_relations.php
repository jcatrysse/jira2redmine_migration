<?php
/** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Jira2Redmine\Http\ClientFactory;
use Jira2Redmine\Database\ConnectionFactory;
use Jira2Redmine\Redmine\ExtendedApi;
use Jira2Redmine\Relations\RelationState;
use Jira2Redmine\Cli\Phases;
use Jira2Redmine\Cli\Script;
use Jira2Redmine\Cli\ScriptCommandLines;
use Jira2Redmine\Cli\ConfigValue;
use Jira2Redmine\Time\Clock;

const MIGRATE_RELATIONS_SCRIPT_VERSION = '0.0.1';
const AVAILABLE_PHASES = [
    'transform' => 'Reconcile Jira issue links with Redmine targets and propose relation types.',
    'push' => 'Create the pending Redmine issue relations.',
];
const REDMINE_RELATION_TYPES = [
    'relates',
    'duplicates',
    'duplicated_by',
    'blocks',
    'blocked_by',
    'precedes',
    'follows',
    'copied_to',
    'copied_from',
];

require_once __DIR__ . '/lib/cli.php';

exit(Script::run(
    $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? null),
    ScriptCommandLines::for(basename(__FILE__)),
    'printUsage',
    'printVersion',
    static function (array $cliOptions): void {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/bootstrap.php';
        main($config, $cliOptions);
    }
));

/**
 * @param array<string, mixed> $config
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool} $cliOptions
 * @throws Throwable
 */
function main(array $config, array $cliOptions): void
{
    $phasesToRun = Phases::fromOptions(AVAILABLE_PHASES, $cliOptions);

    printf('[%s] Selected phases: %s%s', Clock::dateTime(), implode(', ', $phasesToRun), PHP_EOL);

    $databaseConfig = ConfigValue::section($config, 'database', 'Missing %s configuration block.');
    $pdo = ConnectionFactory::connect($databaseConfig, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    if (in_array('transform', $phasesToRun, true)) {
        runRelationTransformPhase($pdo, $config);
    } else {
        printf('[%s] Skipping transform phase (disabled via CLI option).%s', Clock::dateTime(), PHP_EOL);
    }

    if (in_array('push', $phasesToRun, true)) {
        $confirmPush = (bool)($cliOptions['confirm_push'] ?? false);
        $isDryRun = (bool)($cliOptions['dry_run'] ?? false);
        runRelationPushPhase($pdo, $config, $confirmPush, $isDryRun);
    } else {
        printf('[%s] Skipping push phase (disabled via CLI option).%s', Clock::dateTime(), PHP_EOL);
    }
}

function printUsage(): void
{
    printf('Jira to Redmine Relation Migration (step 12) — version %s%s', MIGRATE_RELATIONS_SCRIPT_VERSION, PHP_EOL);
    printf("Usage: php 12_migrate_issue_relations.php [options]\n\n");
    printf("Options:\n");
    printf("  --phases=LIST     Comma separated list of phases to run (default: transform,push).\n");
    printf("  --skip=LIST       Comma separated list of phases to skip.\n");
    printf("  --confirm-push    Required to execute the push phase (creates relations in Redmine).\n");
    printf("  --dry-run         Preview push work without calling Redmine.\n");
    printf("  --version         Display version information.\n");
    printf("  --help            Display this help message.\n");
}

function printVersion(): void
{
    printf('%s%s', MIGRATE_RELATIONS_SCRIPT_VERSION, PHP_EOL);
}

/**
 * @throws Throwable
 */
function runRelationTransformPhase(PDO $pdo, array $config): void
{
    $customMappings = normalizeCustomRelationMappings(extractCustomRelationMappings($config));
    $supportedRelationTypes = buildSupportedRelationTypes($customMappings);

    printf('[%s] Synchronising Jira issue links...%s', Clock::dateTime(), PHP_EOL);
    $syncSummary = syncIssueRelationMappings($pdo);
    printf(
        "  Synchronized %d link record(s) from staging.%s",
        $syncSummary['affected'],
        PHP_EOL
    );

    $rows = fetchRelationMappings($pdo);
    if ($rows === []) {
        printf("  No issue links found; nothing to transform.%s", PHP_EOL);
        return;
    }

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_issue_relations
        SET
            redmine_issue_from_id = :redmine_issue_from_id,
            redmine_issue_to_id = :redmine_issue_to_id,
            proposed_relation_type = :proposed_relation_type,
            migration_status = :migration_status,
            notes = :notes,
            automation_hash = :automation_hash,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);
    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare relation update statement.');
    }

    $summary = [
        'ready' => 0,
        'manual' => 0,
        'success' => 0,
        'preserved' => 0,
        'ignored' => 0,
    ];
    $parentRows = $pdo->query('SELECT id, parent_issue_id FROM staging_jira_issues WHERE parent_issue_id IS NOT NULL');
    /** @var array<int|string, int|string> $parentOf */
    $parentOf = $parentRows !== false ? $parentRows->fetchAll(PDO::FETCH_KEY_PAIR) : [];

    foreach ($rows as $row) {
        $currentHash = computeRelationAutomationStateHash(
            $row['redmine_issue_from_id'],
            $row['redmine_issue_to_id'],
            $row['redmine_relation_id'],
            $row['proposed_relation_type'],
            $row['migration_status'],
            $row['notes']
        );
        $storedHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);
        if ($storedHash !== null && $storedHash !== $currentHash) {
            $summary['preserved']++;
            printf(
                "  [preserved] Jira link %s has manual overrides; skipping automated changes.%s",
                (string)$row['jira_link_id'],
                PHP_EOL
            );
            continue;
        }

        $sourceRedmineId = isset($row['source_redmine_issue_id']) && $row['source_redmine_issue_id'] !== null
            ? (int)$row['source_redmine_issue_id']
            : null;
        $targetRedmineId = isset($row['target_redmine_issue_id']) && $row['target_redmine_issue_id'] !== null
            ? (int)$row['target_redmine_issue_id']
            : null;

        $nextStatus = 'READY_FOR_CREATION';
        $notes = null;

        if ($row['redmine_relation_id'] !== null) {
            $nextStatus = 'CREATION_SUCCESS';
            $summary['success']++;
        } elseif (RelationState::linksAncestor((string)$row['jira_source_issue_id'], (string)$row['jira_target_issue_id'], $parentOf)) {
            $nextStatus = 'IGNORED';
            $notes = 'Parent and subtask in Redmine already; Redmine does not relate an issue to its own subtask.';
            $summary['ignored']++;
        } elseif ($sourceRedmineId === null || $targetRedmineId === null) {
            $nextStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $notes = 'Missing Redmine issue mapping for source or target; rerun 10_migrate_issues.php.';
            $summary['manual']++;
        } else {
            $relationMapping = mapJiraRelationToRedmineType(
                $row['jira_link_type_name'] ?? null,
                $row['jira_link_type_outward'] ?? null,
                $row['jira_link_type_inward'] ?? null,
                $customMappings,
                $supportedRelationTypes
            );
            $proposedRelationType = $relationMapping['relation_type'];
            $notes = mergeNotes($notes, $relationMapping['note']);

            $blockedConflictNote = detectBlockedRelationConflict(
                $proposedRelationType,
                $row['source_redmine_is_closed'] ?? null,
                $row['target_redmine_is_closed'] ?? null
            );
            // Jira had the relation and Redmine accepts it (201 on 7.0 for a closed blocked issue): create it
            // and keep the remark, instead of leaving the relation out for a manual review.
            if ($blockedConflictNote !== null) {
                $notes = mergeNotes($notes, $blockedConflictNote);
            }
        }

        if ($row['redmine_relation_id'] !== null) {
            $proposedRelationType = $row['proposed_relation_type'] ?? 'relates';
            $notes = null;
        } elseif ($sourceRedmineId === null || $targetRedmineId === null) {
            $proposedRelationType = 'relates';
        } elseif (!isset($proposedRelationType)) {
            $proposedRelationType = 'relates';
        }

        if ($nextStatus === 'READY_FOR_CREATION') {
            $summary['ready']++;
        }

        $newHash = computeRelationAutomationStateHash(
            $sourceRedmineId,
            $targetRedmineId,
            $row['redmine_relation_id'],
            $proposedRelationType,
            $nextStatus,
            $notes
        );

        $updateStatement->execute([
            'mapping_id' => (int)$row['mapping_id'],
            'redmine_issue_from_id' => $sourceRedmineId,
            'redmine_issue_to_id' => $targetRedmineId,
            'proposed_relation_type' => $proposedRelationType,
            'migration_status' => $nextStatus,
            'notes' => $notes,
            'automation_hash' => $newHash,
        ]);
    }

    printf("  Ready for creation: %d%s", $summary['ready'], PHP_EOL);
    printf("  Completed previously: %d%s", $summary['success'], PHP_EOL);
    printf("  Manual review required: %d%s", $summary['manual'], PHP_EOL);
    printf("  Ignored (parent and subtask): %d%s", $summary['ignored'], PHP_EOL);
    printf("  Preserved manual overrides: %d%s", $summary['preserved'], PHP_EOL);
}

/**
 * @param array<string, mixed> $config
 * @throws Throwable
 */
function runRelationPushPhase(PDO $pdo, array $config, bool $confirmPush, bool $isDryRun): void
{
    $sql = <<<SQL
        SELECT *
        FROM migration_mapping_issue_relations
        WHERE migration_status = 'READY_FOR_CREATION'
        ORDER BY mapping_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch relation push candidates: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to fetch relation push candidates.');
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    if ($rows === []) {
        printf("[%s] No issue relations queued for creation.%s", Clock::dateTime(), PHP_EOL);
        return;
    }

    printf('[%s] %d issue relation(s) queued for creation.%s', Clock::dateTime(), count($rows), PHP_EOL);

    $redmineConfig = ConfigValue::section($config, 'redmine', 'Missing %s configuration block.');
    $useExtendedApi = ExtendedApi::enabled($redmineConfig, false);
    $extendedApiPrefix = ExtendedApi::prefix($redmineConfig);

    if ($isDryRun) {
        foreach ($rows as $row) {
            $endpoint = buildRelationEndpoint(
                $useExtendedApi,
                $extendedApiPrefix,
                (int)$row['redmine_issue_from_id']
            );
            $payload = buildRelationPayload(
                (int)$row['redmine_issue_to_id'],
                (string)$row['proposed_relation_type'],
                $useExtendedApi
            );
            printf(
                "  [dry-run] POST %s payload=%s (Jira link %s → Redmine #%d %s #%d)%s",
                $endpoint,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (string)$row['jira_link_id'],
                (int)$row['redmine_issue_from_id'],
                (string)$row['proposed_relation_type'],
                (int)$row['redmine_issue_to_id'],
                PHP_EOL
            );
        }
        printf("  Dry-run active; Redmine relations will not be created.%s", PHP_EOL);
        return;
    }

    if (!$confirmPush) {
        printf("  Push confirmation missing; rerun with --confirm-push to create Redmine relations.%s", PHP_EOL);
        return;
    }

    $client = ClientFactory::redmine($redmineConfig);

    $successStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_issue_relations
        SET
            redmine_relation_id = :redmine_relation_id,
            migration_status = 'CREATION_SUCCESS',
            notes = NULL,
            automation_hash = :automation_hash,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);
    if ($successStatement === false) {
        throw new RuntimeException('Failed to prepare relation success statement.');
    }

    $failureStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_issue_relations
        SET migration_status = :migration_status, notes = :notes, automation_hash = :automation_hash,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);
    if ($failureStatement === false) {
        throw new RuntimeException('Failed to prepare relation failure statement.');
    }

    foreach ($rows as $row) {
        $mappingId = (int)$row['mapping_id'];
        $fromId = (int)$row['redmine_issue_from_id'];
        $toId = (int)$row['redmine_issue_to_id'];
        $relationType = (string)$row['proposed_relation_type'];
        $payload = buildRelationPayload($toId, $relationType, $useExtendedApi);
        $endpoint = buildRelationEndpoint($useExtendedApi, $extendedApiPrefix, $fromId);
        $options = ['json' => $payload];

        try {
            $response = $client->post($endpoint, $options);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = 'Failed to create Redmine relation';
            if ($response instanceof ResponseInterface) {
                $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                $message .= ': ' . extractErrorBody($response);
            }

            $failureStatement->execute(['mapping_id' => $mappingId] + RelationState::failed($row, $message));
            printf("  [error] %s for Jira link %s.%s", $message, (string)$row['jira_link_id'], PHP_EOL);
            continue;
        } catch (GuzzleException $exception) {
            $message = 'Failed to create Redmine relation: ' . $exception->getMessage();
            $failureStatement->execute(['mapping_id' => $mappingId] + RelationState::failed($row, $message));
            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        }

        $body = decodeJsonResponse($response);
        $redmineRelationId = isset($body['relation']['id']) ? (int)$body['relation']['id'] : null;
        if ($redmineRelationId === null) {
            $message = 'Redmine did not return a relation identifier.';
            $failureStatement->execute(['mapping_id' => $mappingId] + RelationState::failed($row, $message));
            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        }

        $newHash = computeRelationAutomationStateHash(
            $row['redmine_issue_from_id'],
            $row['redmine_issue_to_id'],
            $redmineRelationId,
            $row['proposed_relation_type'],
            'CREATION_SUCCESS',
            null
        );

        $successStatement->execute([
            'mapping_id' => $mappingId,
            'redmine_relation_id' => $redmineRelationId,
            'automation_hash' => $newHash,
        ]);

        printf(
            "  [created] Jira link %s → Redmine relation #%d (%s).%s",
            (string)$row['jira_link_id'],
            $redmineRelationId,
            (string)$row['proposed_relation_type'],
            PHP_EOL
        );
    }
}

/**
 * @return array{affected: int}
 */
function syncIssueRelationMappings(PDO $pdo): array
{
    $insertSql = <<<SQL
        INSERT INTO migration_mapping_issue_relations (
            jira_link_id,
            jira_source_issue_id,
            jira_source_issue_key,
            jira_target_issue_id,
            jira_target_issue_key,
            jira_link_type_id,
            jira_link_type_name,
            jira_link_type_inward,
            jira_link_type_outward
        )
        SELECT
            link.link_id,
            link.source_issue_id,
            link.source_issue_key,
            link.target_issue_id,
            link.target_issue_key,
            link.link_type_id,
            link.link_type_name,
            link.link_type_inward,
            link.link_type_outward
        FROM staging_jira_issue_links link
        ON DUPLICATE KEY UPDATE
            jira_source_issue_id = VALUES(jira_source_issue_id),
            jira_source_issue_key = VALUES(jira_source_issue_key),
            jira_target_issue_id = VALUES(jira_target_issue_id),
            jira_target_issue_key = VALUES(jira_target_issue_key),
            jira_link_type_id = VALUES(jira_link_type_id),
            jira_link_type_name = VALUES(jira_link_type_name),
            jira_link_type_inward = VALUES(jira_link_type_inward),
            jira_link_type_outward = VALUES(jira_link_type_outward)
    SQL;

    try {
        $affected = $pdo->exec($insertSql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to synchronise issue relation mappings: ' . $exception->getMessage(), 0, $exception);
    }

    if ($affected === false) {
        $affected = 0;
    }

    return ['affected' => (int)$affected];
}

/**
 * @return array<int, array<string, mixed>>
 * @throws Throwable
 */
function fetchRelationMappings(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            rel.*, 
            src.redmine_issue_id AS source_redmine_issue_id,
            tgt.redmine_issue_id AS target_redmine_issue_id,
            src_status.is_closed AS source_redmine_is_closed,
            tgt_status.is_closed AS target_redmine_is_closed
        FROM migration_mapping_issue_relations rel
        LEFT JOIN migration_mapping_issues src ON src.jira_issue_id = rel.jira_source_issue_id
        LEFT JOIN migration_mapping_issues tgt ON tgt.jira_issue_id = rel.jira_target_issue_id
        LEFT JOIN staging_redmine_issue_statuses src_status ON src_status.id = src.redmine_status_id
        LEFT JOIN staging_redmine_issue_statuses tgt_status ON tgt_status.id = tgt.redmine_status_id
        ORDER BY rel.mapping_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch relation mappings: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to fetch relation mappings.');
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    return $rows;
}

/**
 * @return array<string, mixed>
 */
function extractCustomRelationMappings(array $config): array
{
    $migrationConfig = $config['migration'] ?? [];
    if (!is_array($migrationConfig)) {
        return [];
    }

    $relationsConfig = $migrationConfig['relations'] ?? [];
    if (!is_array($relationsConfig)) {
        return [];
    }

    $customMappings = $relationsConfig['custom_mappings'] ?? [];
    if (!is_array($customMappings)) {
        return [];
    }

    return $customMappings;
}

/**
 * @param array<string, array{outward: ?string, inward: ?string}> $customMappings
 * @return array<int, string>
 */
function buildSupportedRelationTypes(array $customMappings): array
{
    $types = REDMINE_RELATION_TYPES;

    foreach ($customMappings as $mapping) {
        if (!is_array($mapping)) {
            continue;
        }

        foreach (['outward', 'inward'] as $direction) {
            $value = $mapping[$direction] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $types[] = trim($value);
            }
        }
    }

    return array_values(array_unique($types));
}

/**
 * @return array{relation_type: string, note: ?string}
 */
function mapJiraRelationToRedmineType(
    ?string $name,
    ?string $outward,
    ?string $inward,
    array $customMappings,
    array $supportedRelationTypes
): array
{
    $relationType = null;
    $note = null;

    $customRelationType = resolveCustomRelationType($name, $outward, $inward, $customMappings);
    if ($customRelationType !== null) {
        return [
            'relation_type' => $customRelationType,
            'note' => null,
        ];
    }

    if (is_string($outward) && trim($outward) !== '') {
        $relationType = mapJiraDescriptionToRelationType($outward, true);
    }

    if ($relationType === null && is_string($inward) && trim($inward) !== '') {
        $relationType = mapJiraDescriptionToRelationType($inward, false);
    }

    if ($relationType === null && is_string($name) && trim($name) !== '') {
        $relationType = mapJiraDescriptionToRelationType($name, true)
            ?? mapJiraDescriptionToRelationType($name, false);
    }

    if ($relationType === null || !isSupportedRedmineRelationType($relationType, $supportedRelationTypes)) {
        $fallback = $relationType;
        $relationType = 'relates';
        $note = $fallback === null
            ? 'Jira relation type not recognised; defaulted to relates.'
            : sprintf('Jira relation "%s" not supported by Redmine; defaulted to relates.', $fallback);
    }

    return [
        'relation_type' => $relationType,
        'note' => $note,
    ];
}

/**
 * @return array<string, array{outward: ?string, inward: ?string}>
 */
function normalizeCustomRelationMappings(array $customMappings): array
{
    $normalized = [];

    foreach ($customMappings as $name => $mapping) {
        if (!is_array($mapping)) {
            continue;
        }

        $key = normalizeRelationMappingKey($name);
        if ($key === '') {
            continue;
        }

        $normalized[$key] = [
            'outward' => normalizeRelationTypeValue($mapping['outward'] ?? null),
            'inward' => normalizeRelationTypeValue($mapping['inward'] ?? null),
        ];
    }

    return $normalized;
}

function normalizeRelationMappingKey(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    return strtolower(trim($value));
}

function normalizeRelationTypeValue(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    return $trimmed !== '' ? $trimmed : null;
}

function resolveCustomRelationType(
    ?string $name,
    ?string $outward,
    ?string $inward,
    array $customMappings
): ?string {
    if (!is_string($name) || trim($name) === '') {
        return null;
    }

    $key = normalizeRelationMappingKey($name);
    if (!isset($customMappings[$key])) {
        return null;
    }

    $mapping = $customMappings[$key];
    $outwardValue = $mapping['outward'] ?? null;
    $inwardValue = $mapping['inward'] ?? null;

    if (is_string($outward) && trim($outward) !== '' && $outwardValue !== null) {
        return $outwardValue;
    }

    if (is_string($inward) && trim($inward) !== '' && $inwardValue !== null) {
        return $inwardValue;
    }

    return $outwardValue ?? $inwardValue;
}

function mapJiraDescriptionToRelationType(string $description, bool $isOutward): ?string
{
    $normalized = strtolower(trim($description));
    if ($normalized === '') {
        return null;
    }

    if (str_contains($normalized, 'blocked by')) {
        return $isOutward ? 'blocked_by' : 'blocks';
    }
    if (str_contains($normalized, 'blocks')) {
        return $isOutward ? 'blocks' : 'blocked_by';
    }
    if (str_contains($normalized, 'duplicated by')) {
        return $isOutward ? 'duplicated_by' : 'duplicates';
    }
    if (str_contains($normalized, 'duplicates')) {
        return $isOutward ? 'duplicates' : 'duplicated_by';
    }
    if (str_contains($normalized, 'copied from')) {
        return $isOutward ? 'copied_from' : 'copied_to';
    }
    if (str_contains($normalized, 'copied by')) {
        return $isOutward ? 'copied_from' : 'copied_to';
    }
    if (str_contains($normalized, 'copied to') || str_contains($normalized, 'copies') || str_contains($normalized, 'clones')) {
        return $isOutward ? 'copied_to' : 'copied_from';
    }
    if (str_contains($normalized, 'precedes') || str_contains($normalized, 'preced')) {
        return $isOutward ? 'precedes' : 'follows';
    }
    if (str_contains($normalized, 'follows')) {
        return $isOutward ? 'follows' : 'precedes';
    }
    if (str_contains($normalized, 'depend')) {
        return $isOutward ? 'follows' : 'precedes';
    }
    if (str_contains($normalized, 'relat')) {
        return 'relates';
    }

    return null;
}

function isSupportedRedmineRelationType(string $relationType, array $supportedRelationTypes): bool
{
    return in_array($relationType, $supportedRelationTypes, true);
}

function detectBlockedRelationConflict(string $relationType, ?int $sourceIsClosed, ?int $targetIsClosed): ?string
{
    if (!in_array($relationType, ['blocks', 'blocked_by'], true)) {
        return null;
    }

    if ($relationType === 'blocks') {
        if ($targetIsClosed === 1) {
            return 'Blocked issue is already closed; review before creating a blocks relation.';
        }
        if ($sourceIsClosed === 1 && $targetIsClosed === 0) {
            return 'Blocking issue is closed while the blocked issue is open; review relation before creating.';
        }
    }

    if ($relationType === 'blocked_by') {
        if ($sourceIsClosed === 1) {
            return 'Blocked issue is already closed; review before creating a blocked_by relation.';
        }
        if ($targetIsClosed === 1 && $sourceIsClosed === 0) {
            return 'Blocking issue is closed while the blocked issue is open; review relation before creating.';
        }
    }

    return null;
}

function mergeNotes(?string $current, ?string $addition): ?string
{
    if ($addition === null || $addition === '') {
        return $current;
    }

    if ($current === null || $current === '') {
        return $addition;
    }

    return $current . ' ' . $addition;
}

function decodeJsonResponse(ResponseInterface $response): array
{
    $body = (string)$response->getBody();
    if ($body === '') {
        return [];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Unexpected Redmine response payload: ' . $body);
    }

    return $decoded;
}

function extractErrorBody(ResponseInterface $response): string
{
    $body = (string)$response->getBody();
    if ($body === '') {
        return '[empty response]';
    }

    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            return implode('; ', array_map(static fn($error) => is_string($error) ? $error : json_encode($error), $decoded['errors']));
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }
    }

    return trim($body);
}

function buildRelationPayload(int $issueToId, string $relationType, bool $includeNotify): array
{
    $payload = [
        'relation' => [
            'issue_to_id' => $issueToId,
            'relation_type' => $relationType,
        ],
    ];

    if ($includeNotify) {
        $payload['notify'] = false;
        // the Extended API stores no journal on either issue for it (decision 37)
        $payload['skip_journal'] = true;
    }

    return $payload;
}

function buildRelationEndpoint(bool $useExtendedApi, string $extendedApiPrefix, int $issueId): string
{
    $resource = sprintf('issues/%d/relations.json', $issueId);
    if ($useExtendedApi) {
        return ExtendedApi::path($extendedApiPrefix, $resource);
    }

    return $resource;
}

function normalizeStoredAutomationHash(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim((string)$value);
    return $trimmed !== '' ? $trimmed : null;
}

function computeRelationAutomationStateHash(
    mixed $redmineIssueFromId,
    mixed $redmineIssueToId,
    mixed $redmineRelationId,
    mixed $proposedRelationType,
    mixed $migrationStatus,
    mixed $notes
): string {
    return RelationState::hash($redmineIssueFromId, $redmineIssueToId, $redmineRelationId, $proposedRelationType, $migrationStatus, $notes);
}

<?php
/** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_RELATIONS_SCRIPT_VERSION = '0.0.1';
const AVAILABLE_PHASES = [
    'transform' => 'Reconcile Jira issue links with Redmine targets and propose relation types.',
    'push' => 'Create the pending Redmine issue relations.',
];

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This script is intended to be run from the command line.');
}

$cliArguments = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? null);
if (!is_array($cliArguments)) {
    throw new RuntimeException('Unable to access the command-line arguments.');
}

try {
    [$cliOptions, $positionalArguments] = parseCommandLineOptions($cliArguments);

    if (!empty($cliOptions['help'])) {
        printUsage();
        exit(0);
    }

    if (!empty($cliOptions['version'])) {
        printVersion();
        exit(0);
    }

    if ($positionalArguments !== []) {
        throw new RuntimeException(sprintf('Unexpected positional arguments: %s', implode(', ', $positionalArguments)));
    }

    /** @var array<string, mixed> $config */
    $config = require __DIR__ . '/bootstrap.php';

    main($config, $cliOptions);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('[ERROR] %s%s', $exception->getMessage(), PHP_EOL));
    exit(1);
}

/**
 * @param array<string, mixed> $config
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool} $cliOptions
 * @throws Throwable
 */
function main(array $config, array $cliOptions): void
{
    $phasesToRun = determinePhasesToRun($cliOptions);

    printf('[%s] Selected phases: %s%s', formatCurrentTimestamp(), implode(', ', $phasesToRun), PHP_EOL);

    $databaseConfig = extractArrayConfig($config, 'database');
    $pdo = createDatabaseConnection($databaseConfig);

    if (in_array('transform', $phasesToRun, true)) {
        runRelationTransformPhase($pdo);
    } else {
        printf('[%s] Skipping transform phase (disabled via CLI option).%s', formatCurrentTimestamp(), PHP_EOL);
    }

    if (in_array('push', $phasesToRun, true)) {
        $confirmPush = (bool)($cliOptions['confirm_push'] ?? false);
        $isDryRun = (bool)($cliOptions['dry_run'] ?? false);
        runRelationPushPhase($pdo, $config, $confirmPush, $isDryRun);
    } else {
        printf('[%s] Skipping push phase (disabled via CLI option).%s', formatCurrentTimestamp(), PHP_EOL);
    }
}

function printUsage(): void
{
    printf('Jira to Redmine Relation Migration (step 14) — version %s%s', MIGRATE_RELATIONS_SCRIPT_VERSION, PHP_EOL);
    printf("Usage: php 14_migrate_issue_relations.php [options]\n\n");
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
function runRelationTransformPhase(PDO $pdo): void
{
    printf('[%s] Synchronising Jira issue links...%s', formatCurrentTimestamp(), PHP_EOL);
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
    ];

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

        $proposedRelationType = guessRedmineRelationType(
            $row['jira_link_type_name'] ?? null,
            $row['jira_link_type_outward'] ?? null,
            $row['jira_link_type_inward'] ?? null
        );

        $nextStatus = 'READY_FOR_CREATION';
        $notes = null;

        if ($row['redmine_relation_id'] !== null) {
            $nextStatus = 'CREATION_SUCCESS';
            $summary['success']++;
        } elseif ($sourceRedmineId === null || $targetRedmineId === null) {
            $nextStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $notes = 'Missing Redmine issue mapping for source or target; rerun 11_migrate_issues.php.';
            $summary['manual']++;
        } elseif ($proposedRelationType === null) {
            $nextStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $notes = 'Unable to map Jira link type to a Redmine relation; populate proposed_relation_type manually.';
            $summary['manual']++;
        } else {
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
        printf("[%s] No issue relations queued for creation.%s", formatCurrentTimestamp(), PHP_EOL);
        return;
    }

    printf('[%s] %d issue relation(s) queued for creation.%s', formatCurrentTimestamp(), count($rows), PHP_EOL);

    if ($isDryRun) {
        foreach ($rows as $row) {
            printf(
                "  [dry-run] Jira link %s → Redmine #%d %s #%d%s",
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

    $redmineConfig = extractArrayConfig($config, 'redmine');
    $client = createRedmineClient($redmineConfig);

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
        SET migration_status = 'CREATION_FAILED', notes = :notes, last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);
    if ($failureStatement === false) {
        throw new RuntimeException('Failed to prepare relation failure statement.');
    }

    foreach ($rows as $row) {
        $mappingId = (int)$row['mapping_id'];
        $payload = [
            'relation' => [
                'issue_id' => (int)$row['redmine_issue_from_id'],
                'issue_to_id' => (int)$row['redmine_issue_to_id'],
                'relation_type' => (string)$row['proposed_relation_type'],
            ],
        ];

        try {
            $response = $client->post('/relations.json', ['json' => $payload]);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = 'Failed to create Redmine relation';
            if ($response instanceof ResponseInterface) {
                $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                $message .= ': ' . extractErrorBody($response);
            }

            $failureStatement->execute([
                'mapping_id' => $mappingId,
                'notes' => $message,
            ]);
            printf("  [error] %s for Jira link %s.%s", $message, (string)$row['jira_link_id'], PHP_EOL);
            continue;
        } catch (GuzzleException $exception) {
            $message = 'Failed to create Redmine relation: ' . $exception->getMessage();
            $failureStatement->execute([
                'mapping_id' => $mappingId,
                'notes' => $message,
            ]);
            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        }

        $body = decodeJsonResponse($response);
        $redmineRelationId = isset($body['relation']['id']) ? (int)$body['relation']['id'] : null;
        if ($redmineRelationId === null) {
            $message = 'Redmine did not return a relation identifier.';
            $failureStatement->execute([
                'mapping_id' => $mappingId,
                'notes' => $message,
            ]);
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
            tgt.redmine_issue_id AS target_redmine_issue_id
        FROM migration_mapping_issue_relations rel
        LEFT JOIN migration_mapping_issues src ON src.jira_issue_id = rel.jira_source_issue_id
        LEFT JOIN migration_mapping_issues tgt ON tgt.jira_issue_id = rel.jira_target_issue_id
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

function guessRedmineRelationType(?string $name, ?string $outward, ?string $inward): ?string
{
    $candidates = array_filter([
        is_string($outward) ? $outward : null,
        is_string($name) ? $name : null,
        is_string($inward) ? $inward : null,
    ]);

    foreach ($candidates as $candidate) {
        $normalized = strtolower($candidate);
        if (str_contains($normalized, 'block')) {
            return 'blocks';
        }
        if (str_contains($normalized, 'duplicate')) {
            return 'duplicates';
        }
        if (str_contains($normalized, 'relat')) {
            return 'relates';
        }
        if (str_contains($normalized, 'preced') || str_contains($normalized, 'depend')) {
            return 'precedes';
        }
        if (str_contains($normalized, 'follow')) {
            return 'follows';
        }
        if (str_contains($normalized, 'clone') || str_contains($normalized, 'copy')) {
            return 'copied_to';
        }
    }

    return null;
}

function determinePhasesToRun(array $cliOptions): array
{
    $defaultPhases = array_keys(AVAILABLE_PHASES);

    $phases = isset($cliOptions['phases']) && is_string($cliOptions['phases']) && $cliOptions['phases'] !== ''
        ? array_map('trim', explode(',', (string)$cliOptions['phases']))
        : $defaultPhases;

    $skips = isset($cliOptions['skip']) && is_string($cliOptions['skip']) && $cliOptions['skip'] !== ''
        ? array_map('trim', explode(',', (string)$cliOptions['skip']))
        : [];

    $phases = array_values(array_filter($phases, static function ($phase) use ($skips) {
        if ($phase === '') {
            return false;
        }

        return !in_array($phase, $skips, true);
    }));

    foreach ($phases as $phase) {
        if (!array_key_exists($phase, AVAILABLE_PHASES)) {
            throw new RuntimeException(sprintf('Unknown phase "%s". Supported phases: %s', $phase, implode(', ', array_keys(AVAILABLE_PHASES))));
        }
    }

    return $phases;
}

/**
 * @return array{0: array<string, mixed>, 1: array<int, string>}
 */
function parseCommandLineOptions(array $argv): array
{
    $options = [
        'help' => false,
        'version' => false,
        'phases' => null,
        'skip' => null,
        'confirm_push' => false,
        'dry_run' => false,
    ];

    $positionals = [];

    array_shift($argv);
    foreach ($argv as $argument) {
        if ($argument === '--help') {
            $options['help'] = true;
        } elseif ($argument === '--version') {
            $options['version'] = true;
        } elseif (str_starts_with($argument, '--phases=')) {
            $options['phases'] = substr($argument, 9);
        } elseif (str_starts_with($argument, '--skip=')) {
            $options['skip'] = substr($argument, 7);
        } elseif ($argument === '--confirm-push') {
            $options['confirm_push'] = true;
        } elseif ($argument === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($argument === '') {
            continue;
        } elseif ($argument[0] === '-') {
            throw new RuntimeException(sprintf('Unknown option: %s', $argument));
        } else {
            $positionals[] = $argument;
        }
    }

    return [$options, $positionals];
}

function extractArrayConfig(array $config, string $key): array
{
    if (!isset($config[$key]) || !is_array($config[$key])) {
        throw new RuntimeException(sprintf('Missing %s configuration block.', $key));
    }

    return $config[$key];
}

function createDatabaseConnection(array $databaseConfig): PDO
{
    $host = (string)($databaseConfig['host'] ?? 'localhost');
    $port = (int)($databaseConfig['port'] ?? 3306);
    $dbname = (string)($databaseConfig['dbname'] ?? 'migration');
    $user = (string)($databaseConfig['user'] ?? 'root');
    $password = (string)($databaseConfig['password'] ?? '');

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbname);

    try {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to connect to the database: ' . $exception->getMessage(), 0, $exception);
    }

    return $pdo;
}

function createRedmineClient(array $redmineConfig): Client
{
    $baseUri = (string)($redmineConfig['base_uri'] ?? '');
    if ($baseUri === '') {
        throw new RuntimeException('Redmine base_uri is required.');
    }

    $apiKey = (string)($redmineConfig['api_key'] ?? '');
    if ($apiKey === '') {
        throw new RuntimeException('Redmine API key is required.');
    }

    return new Client([
        'base_uri' => $baseUri,
        'headers' => [
            'X-Redmine-API-Key' => $apiKey,
            'Accept' => 'application/json',
        ],
    ]);
}

function formatCurrentTimestamp(string $format = 'Y-m-d H:i:s'): string
{
    return (new DateTimeImmutable('now'))->format($format);
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
    $payload = [
        'redmine_issue_from_id' => $redmineIssueFromId,
        'redmine_issue_to_id' => $redmineIssueToId,
        'redmine_relation_id' => $redmineRelationId,
        'proposed_relation_type' => $proposedRelationType,
        'migration_status' => $migrationStatus,
        'notes' => $notes,
    ];

    try {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode relation automation payload: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', $encoded);
}

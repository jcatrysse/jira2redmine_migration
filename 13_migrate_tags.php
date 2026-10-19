<?php
/** @noinspection DuplicatedCode */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Jira2Redmine\Http\ClientFactory;
use Jira2Redmine\Database\ConnectionFactory;
use Jira2Redmine\Issues\IssueLabels;
use Jira2Redmine\Redmine\ExtendedApi;
use Jira2Redmine\Redmine\IssueTags;
use Jira2Redmine\Cli\Phases;
use Jira2Redmine\Cli\Script;
use Jira2Redmine\Cli\ScriptCommandLines;
use Jira2Redmine\Cli\ConfigValue;

const MIGRATE_TAGS_SCRIPT_VERSION = '0.0.1';
const AVAILABLE_PHASES = [
    'transform' => 'Build per-issue tag payloads from staged Jira labels.',
    'push' => 'Apply tags to Redmine issues via redmine_tags plugin.',
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

    printf(
        "[%s] Selected phases: %s%s",
        formatCurrentTimestamp(),
        implode(', ', $phasesToRun),
        PHP_EOL
    );

    $databaseConfig = ConfigValue::section($config, 'database');
    $pdo = createDatabaseConnection($databaseConfig);

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Building issue tag payloads...%s", formatCurrentTimestamp(), PHP_EOL);
        $summary = transformIssueTags($pdo);
        printf(
            "[%s] Transform complete. Ready: %d, Ignored: %d, Pending: %d.%s",
            formatCurrentTimestamp(),
            $summary['ready'],
            $summary['ignored'],
            $summary['pending'],
            PHP_EOL
        );
    } else {
        printf("[%s] Skipping transform phase (disabled via CLI option).%s", formatCurrentTimestamp(), PHP_EOL);
    }

    if (in_array('push', $phasesToRun, true)) {
        $confirmPush = (bool)($cliOptions['confirm_push'] ?? false);
        $isDryRun = (bool)($cliOptions['dry_run'] ?? false);
        $redmineConfig = ConfigValue::section($config, 'redmine');
        $redmineClient = createRedmineClient($redmineConfig);
        // Jan (2026-10-15): the issue update of 7.0 goes through the Extended API with notify=false and skip_journal
        $extendedApiPrefix = ExtendedApi::enabled($redmineConfig, false) ? ExtendedApi::prefix($redmineConfig) : null;
        runTagPushPhase($pdo, $redmineClient, $confirmPush, $isDryRun, $extendedApiPrefix);
    } else {
        printf("[%s] Skipping push phase (disabled via CLI option).%s", formatCurrentTimestamp(), PHP_EOL);
    }
}

function printUsage(): void
{
    printf("Jira to Redmine Tag Migration (step 13) — version %s%s", MIGRATE_TAGS_SCRIPT_VERSION, PHP_EOL);
    printf("Usage: php 13_migrate_tags.php [options]\n\n");
    printf("Options:\n");
    printf("  --phases=LIST        Comma separated list of phases to run (default: transform,push).\n");
    printf("  --skip=LIST          Comma separated list of phases to skip.\n");
    printf("  --confirm-push       Required to execute the push phase.\n");
    printf("  --dry-run            Preview the push phase without modifying Redmine.\n");
    printf("  --version            Display version information.\n");
    printf("  --help               Display this help message.\n");
}

function printVersion(): void
{
    printf("%s%s", MIGRATE_TAGS_SCRIPT_VERSION, PHP_EOL);
}

/**
 * @return array{ready: int, ignored: int, pending: int}
 */
function transformIssueTags(PDO $pdo): array
{
    $issueRows = $pdo->query('SELECT id, issue_key, labels FROM staging_jira_issues ORDER BY id');
    if ($issueRows === false) {
        throw new RuntimeException('Failed to read staged Jira issues for tags.');
    }

    $issueMappings = $pdo->query('SELECT jira_issue_id, redmine_issue_id FROM migration_mapping_issues');
    if ($issueMappings === false) {
        throw new RuntimeException('Failed to load issue mappings.');
    }

    $mappingIndex = [];
    while ($row = $issueMappings->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($row['jira_issue_id'])) {
            continue;
        }
        $mappingIndex[(string)$row['jira_issue_id']] = $row['redmine_issue_id'] !== null ? (int)$row['redmine_issue_id'] : null;
    }
    $issueMappings->closeCursor();

    $selectMappings = $pdo->query('SELECT mapping_id, jira_issue_id, proposed_tags, automation_hash FROM migration_mapping_issue_tags');
    if ($selectMappings === false) {
        throw new RuntimeException('Failed to read tag mappings.');
    }

    $existing = [];
    while ($row = $selectMappings->fetch(PDO::FETCH_ASSOC)) {
        $issueId = isset($row['jira_issue_id']) ? (string)$row['jira_issue_id'] : '';
        if ($issueId === '') {
            continue;
        }
        $existing[$issueId] = [
            'mapping_id' => (int)$row['mapping_id'],
            'proposed_tags' => $row['proposed_tags'],
            'automation_hash' => $row['automation_hash'],
        ];
    }
    $selectMappings->closeCursor();

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO migration_mapping_issue_tags (
            jira_issue_id,
            jira_issue_key,
            redmine_issue_id,
            proposed_tags,
            migration_status,
            automation_hash
        ) VALUES (
            :jira_issue_id,
            :jira_issue_key,
            :redmine_issue_id,
            :proposed_tags,
            :migration_status,
            :automation_hash
        )
    SQL);

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_issue_tags
        SET
            jira_issue_key = :jira_issue_key,
            redmine_issue_id = :redmine_issue_id,
            proposed_tags = :proposed_tags,
            migration_status = :migration_status,
            automation_hash = :automation_hash,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);

    if ($insertStatement === false || $updateStatement === false) {
        throw new RuntimeException('Failed to prepare tag mapping statements.');
    }

    $ready = 0;
    $ignored = 0;
    $pending = 0;

    while ($row = $issueRows->fetch(PDO::FETCH_ASSOC)) {
        $jiraIssueId = isset($row['id']) ? (string)$row['id'] : '';
        $jiraIssueKey = isset($row['issue_key']) ? (string)$row['issue_key'] : $jiraIssueId;
        if ($jiraIssueId === '') {
            continue;
        }

        $labels = IssueLabels::tags(decodeJsonColumn($row['labels'] ?? null));
        $redmineIssueId = $mappingIndex[$jiraIssueId] ?? null;

        $proposedTags = $labels !== [] ? encodeJsonColumn($labels) : null;
        $migrationStatus = 'PENDING_ANALYSIS';
        if ($labels === []) {
            $migrationStatus = 'IGNORED';
        } elseif ($redmineIssueId !== null) {
            $migrationStatus = 'READY_FOR_PUSH';
        } else {
            $migrationStatus = 'PENDING_ANALYSIS';
        }

        if ($migrationStatus === 'READY_FOR_PUSH') {
            $ready++;
        } elseif ($migrationStatus === 'IGNORED') {
            $ignored++;
        } else {
            $pending++;
        }

        $automationHash = hash('sha256', implode('|', [
            (string)$redmineIssueId,
            $proposedTags ?? '',
        ]));

        if (!isset($existing[$jiraIssueId])) {
            $insertStatement->execute([
                'jira_issue_id' => $jiraIssueId,
                'jira_issue_key' => $jiraIssueKey,
                'redmine_issue_id' => $redmineIssueId,
                'proposed_tags' => $proposedTags,
                'migration_status' => $migrationStatus,
                'automation_hash' => $automationHash,
            ]);
            continue;
        }

        $current = $existing[$jiraIssueId];
        $storedHash = normalizeStoredAutomationHash($current['automation_hash'] ?? null);

        if ($storedHash !== null && hash_equals($storedHash, $automationHash)) {
            continue;
        }

        $updateStatement->execute([
            'mapping_id' => $current['mapping_id'],
            'jira_issue_key' => $jiraIssueKey,
            'redmine_issue_id' => $redmineIssueId,
            'proposed_tags' => $proposedTags,
            'migration_status' => $migrationStatus,
            'automation_hash' => $automationHash,
        ]);
    }

    $issueRows->closeCursor();

    return [
        'ready' => $ready,
        'ignored' => $ignored,
        'pending' => $pending,
    ];
}

function runTagPushPhase(PDO $pdo, Client $client, bool $confirmPush, bool $isDryRun, ?string $extendedApiPrefix = null): void
{
    $statement = $pdo->query(<<<SQL
        SELECT mapping_id, jira_issue_key, redmine_issue_id, proposed_tags
        FROM migration_mapping_issue_tags
        WHERE migration_status = 'READY_FOR_PUSH'
        ORDER BY mapping_id
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to fetch tag push candidates.');
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    if ($rows === []) {
        printf("[%s] No issue tags queued for push.%s", formatCurrentTimestamp(), PHP_EOL);
        return;
    }

    printf("[%s] %d issue(s) queued for tag push.%s", formatCurrentTimestamp(), count($rows), PHP_EOL);

    if ($isDryRun) {
        foreach ($rows as $row) {
            $tags = decodeJsonColumn($row['proposed_tags'] ?? null) ?: [];
            printf("  - Issue #%d (%s): %s%s", (int)$row['redmine_issue_id'], $row['jira_issue_key'], implode(', ', $tags), PHP_EOL);
        }
        printf("  Dry-run active; no tags will be pushed.%s", PHP_EOL);
        return;
    }

    if (!$confirmPush) {
        printf("  Push confirmation missing; rerun with --confirm-push to apply tags.%s", PHP_EOL);
        return;
    }

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_issue_tags
        SET
            migration_status = :migration_status,
            notes = :notes,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare tag update statement.');
    }

    $tagEndpoint = null;
    foreach ($rows as $row) {
        $mappingId = (int)$row['mapping_id'];
        $redmineIssueId = $row['redmine_issue_id'] !== null ? (int)$row['redmine_issue_id'] : null;
        $tags = decodeJsonColumn($row['proposed_tags'] ?? null);
        $tags = is_array($tags) ? $tags : [];

        if ($redmineIssueId === null || $tags === []) {
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'migration_status' => 'IGNORED',
                'notes' => 'No tags to apply or missing Redmine issue id.',
            ]);
            continue;
        }

        try {
            $tagEndpoint = IssueTags::push($client, $redmineIssueId, array_values($tags), $tagEndpoint, $extendedApiPrefix);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = sprintf('Failed to apply tags to Redmine issue #%d', $redmineIssueId);
            if ($response instanceof ResponseInterface) {
                $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                $message .= ': ' . extractErrorBody($response);
            } else {
                $message .= ': ' . $exception->getMessage();
            }
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'migration_status' => 'FAILED',
                'notes' => $message,
            ]);
            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        } catch (GuzzleException $exception) {
            $message = sprintf('Failed to apply tags to Redmine issue #%d: %s', $redmineIssueId, $exception->getMessage());
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'migration_status' => 'FAILED',
                'notes' => $message,
            ]);
            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        }

        $updateStatement->execute([
            'mapping_id' => $mappingId,
            'migration_status' => 'SUCCESS',
            'notes' => null,
        ]);
        printf("  [tagged] Redmine issue #%d updated.%s", $redmineIssueId, PHP_EOL);
    }
}

/**
 * @param array<string, mixed> $config
 * @return PDO
 */
function createDatabaseConnection(array $config): PDO
{
    return ConnectionFactory::connect($config, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

/**
 * @param array<string, mixed> $config
 * @return Client
 */
function createRedmineClient(array $config): Client
{
    return ClientFactory::redmine($config);
}

function extractErrorBody(ResponseInterface $response): string
{
    $body = trim((string)$response->getBody());
    if ($body === '') {
        return '[empty body]';
    }

    if (strlen($body) > 512) {
        return substr($body, 0, 512) . '…';
    }

    return $body;
}

/**
 * @throws DateMalformedStringException
 */
function formatCurrentTimestamp(string $format = 'Y-m-d H:i:s'): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format($format);
}

function decodeJsonColumn(mixed $value): mixed
{
    if ($value === null) {
        return null;
    }

    if (is_array($value)) {
        return $value;
    }

    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    try {
        return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
}

function encodeJsonColumn(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    try {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode JSON payload: ' . $exception->getMessage(), 0, $exception);
    }
}

function normalizeStoredAutomationHash(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    return $trimmed !== '' ? $trimmed : null;
}

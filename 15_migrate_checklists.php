<?php
/** @noinspection DuplicatedCode */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_CHECKLISTS_SCRIPT_VERSION = '0.0.1';
const AVAILABLE_PHASES = [
    'transform' => 'Parse Jira checklist custom fields and stage Redmine payloads.',
    'push' => 'Replace Redmine checklists via redmine_checklists plugin.',
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
        throw new RuntimeException(sprintf(
            'Unexpected positional arguments: %s',
            implode(', ', $positionalArguments)
        ));
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

    printf(
        "[%s] Selected phases: %s%s",
        formatCurrentTimestamp(),
        implode(', ', $phasesToRun),
        PHP_EOL
    );

    $databaseConfig = extractArrayConfig($config, 'database');
    $pdo = createDatabaseConnection($databaseConfig);

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Parsing Jira checklists...%s", formatCurrentTimestamp(), PHP_EOL);
        $summary = transformChecklists($pdo, $config);
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
        $redmineClient = createRedmineClient(extractArrayConfig($config, 'redmine'));
        runChecklistPushPhase($pdo, $redmineClient, $confirmPush, $isDryRun);
    } else {
        printf("[%s] Skipping push phase (disabled via CLI option).%s", formatCurrentTimestamp(), PHP_EOL);
    }
}

function printUsage(): void
{
    printf("Jira to Redmine Checklist Migration (step 15) — version %s%s", MIGRATE_CHECKLISTS_SCRIPT_VERSION, PHP_EOL);
    printf("Usage: php 15_migrate_checklists.php [options]\n\n");
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
    printf("%s%s", MIGRATE_CHECKLISTS_SCRIPT_VERSION, PHP_EOL);
}

/**
 * @return array{ready: int, ignored: int, pending: int}
 */
function transformChecklists(PDO $pdo, array $config): array
{
    $checklistConfig = $config['migration']['checklists'] ?? [];
    $primaryRenderedField = isset($checklistConfig['primary_rendered_field'])
        ? (string)$checklistConfig['primary_rendered_field']
        : 'customfield_10083';
    $fallbackField = isset($checklistConfig['fallback_field'])
        ? (string)$checklistConfig['fallback_field']
        : 'customfield_10160';

    $issueStatement = $pdo->query('SELECT id, issue_key, raw_payload FROM staging_jira_issues ORDER BY id');
    if ($issueStatement === false) {
        throw new RuntimeException('Failed to read staged Jira issues for checklists.');
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

    $existingStatement = $pdo->query('SELECT mapping_id, jira_issue_id, proposed_payload, automation_hash FROM migration_mapping_checklists');
    if ($existingStatement === false) {
        throw new RuntimeException('Failed to read checklist mappings.');
    }

    $existing = [];
    while ($row = $existingStatement->fetch(PDO::FETCH_ASSOC)) {
        $issueId = isset($row['jira_issue_id']) ? (string)$row['jira_issue_id'] : '';
        if ($issueId === '') {
            continue;
        }
        $existing[$issueId] = [
            'mapping_id' => (int)$row['mapping_id'],
            'proposed_payload' => $row['proposed_payload'],
            'automation_hash' => $row['automation_hash'],
        ];
    }
    $existingStatement->closeCursor();

    $insertMapping = $pdo->prepare(<<<SQL
        INSERT INTO migration_mapping_checklists (
            jira_issue_id,
            jira_issue_key,
            redmine_issue_id,
            proposed_payload,
            migration_status,
            automation_hash
        ) VALUES (
            :jira_issue_id,
            :jira_issue_key,
            :redmine_issue_id,
            :proposed_payload,
            :migration_status,
            :automation_hash
        )
    SQL);

    $updateMapping = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_checklists
        SET
            jira_issue_key = :jira_issue_key,
            redmine_issue_id = :redmine_issue_id,
            proposed_payload = :proposed_payload,
            migration_status = :migration_status,
            automation_hash = :automation_hash,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);

    $deleteChecklist = $pdo->prepare('DELETE FROM staging_jira_checklists WHERE issue_id = :issue_id');
    $insertChecklist = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_checklists (
            issue_id,
            ordinal,
            item_status,
            item_text,
            raw_payload
        ) VALUES (
            :issue_id,
            :ordinal,
            :item_status,
            :item_text,
            :raw_payload
        )
    SQL);

    if ($insertMapping === false || $updateMapping === false || $deleteChecklist === false || $insertChecklist === false) {
        throw new RuntimeException('Failed to prepare checklist statements.');
    }

    $ready = 0;
    $ignored = 0;
    $pending = 0;

    while ($row = $issueStatement->fetch(PDO::FETCH_ASSOC)) {
        $jiraIssueId = isset($row['id']) ? (string)$row['id'] : '';
        $jiraIssueKey = isset($row['issue_key']) ? (string)$row['issue_key'] : $jiraIssueId;
        $rawPayload = isset($row['raw_payload']) ? (string)$row['raw_payload'] : null;

        if ($jiraIssueId === '') {
            continue;
        }

        $issueData = $rawPayload !== null ? decodeJsonColumn($rawPayload) : null;
        $issueData = is_array($issueData) ? $issueData : [];

        $items = extractChecklistItems($issueData, $primaryRenderedField, $fallbackField);
        $deleteChecklist->execute(['issue_id' => $jiraIssueId]);
        foreach ($items as $index => $item) {
            $insertChecklist->execute([
                'issue_id' => $jiraIssueId,
                'ordinal' => $index + 1,
                'item_status' => $item['status'],
                'item_text' => $item['text'],
                'raw_payload' => isset($item['raw']) ? encodeJsonColumn($item['raw']) : null,
            ]);
        }

        $redmineIssueId = $mappingIndex[$jiraIssueId] ?? null;
        $proposedPayload = $items !== [] ? encodeJsonColumn($items) : null;
        $migrationStatus = 'PENDING_ANALYSIS';
        if ($items === []) {
            $migrationStatus = 'IGNORED';
        } elseif ($redmineIssueId !== null) {
            $migrationStatus = 'READY_FOR_PUSH';
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
            $proposedPayload ?? '',
        ]));

        if (!isset($existing[$jiraIssueId])) {
            $insertMapping->execute([
                'jira_issue_id' => $jiraIssueId,
                'jira_issue_key' => $jiraIssueKey,
                'redmine_issue_id' => $redmineIssueId,
                'proposed_payload' => $proposedPayload,
                'migration_status' => $migrationStatus,
                'automation_hash' => $automationHash,
            ]);
            continue;
        }

        $current = $existing[$jiraIssueId];
        $storedProposed = $current['proposed_payload'];
        $storedHash = normalizeStoredAutomationHash($current['automation_hash'] ?? null);
        $currentHash = is_string($storedProposed) ? hash('sha256', $storedProposed) : null;
        if ($storedHash !== null && $currentHash !== null && !hash_equals($storedHash, $currentHash)) {
            continue;
        }

        $updateMapping->execute([
            'mapping_id' => $current['mapping_id'],
            'jira_issue_key' => $jiraIssueKey,
            'redmine_issue_id' => $redmineIssueId,
            'proposed_payload' => $proposedPayload,
            'migration_status' => $migrationStatus,
            'automation_hash' => $automationHash,
        ]);
    }

    $issueStatement->closeCursor();

    return [
        'ready' => $ready,
        'ignored' => $ignored,
        'pending' => $pending,
    ];
}

function runChecklistPushPhase(PDO $pdo, Client $client, bool $confirmPush, bool $isDryRun): void
{
    $statement = $pdo->query(<<<SQL
        SELECT mapping_id, jira_issue_key, redmine_issue_id, proposed_payload
        FROM migration_mapping_checklists
        WHERE migration_status = 'READY_FOR_PUSH'
        ORDER BY mapping_id
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to fetch checklist push candidates.');
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    if ($rows === []) {
        printf("[%s] No checklists queued for push.%s", formatCurrentTimestamp(), PHP_EOL);
        return;
    }

    printf("[%s] %d issue(s) queued for checklist push.%s", formatCurrentTimestamp(), count($rows), PHP_EOL);

    if ($isDryRun) {
        foreach ($rows as $row) {
            $items = decodeJsonColumn($row['proposed_payload'] ?? null) ?: [];
            printf("  - Issue #%d (%s): %d checklist items%s", (int)$row['redmine_issue_id'], $row['jira_issue_key'], count($items), PHP_EOL);
        }
        printf("  Dry-run active; no checklists will be pushed.%s", PHP_EOL);
        return;
    }

    if (!$confirmPush) {
        printf("  Push confirmation missing; rerun with --confirm-push to apply checklists.%s", PHP_EOL);
        return;
    }

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_checklists
        SET
            migration_status = :migration_status,
            notes = :notes,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare checklist update statement.');
    }

    foreach ($rows as $row) {
        $mappingId = (int)$row['mapping_id'];
        $redmineIssueId = $row['redmine_issue_id'] !== null ? (int)$row['redmine_issue_id'] : null;
        $items = decodeJsonColumn($row['proposed_payload'] ?? null);
        $items = is_array($items) ? $items : [];

        if ($redmineIssueId === null) {
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'migration_status' => 'FAILED',
                'notes' => 'Missing Redmine issue id.',
            ]);
            continue;
        }

        try {
            $existing = fetchExistingChecklists($client, $redmineIssueId);
            foreach ($existing as $checklistId) {
                $client->delete(sprintf('/checklists/%d.json', $checklistId));
            }
        } catch (Throwable $exception) {
            $message = sprintf('Failed to clear existing checklists for Redmine issue #%d: %s', $redmineIssueId, $exception->getMessage());
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'migration_status' => 'FAILED',
                'notes' => $message,
            ]);
            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        }

        try {
            foreach ($items as $item) {
                $payload = [
                    'checklist' => [
                        'subject' => (string)($item['text'] ?? ''),
                        'is_done' => ($item['status'] ?? '') === 'done',
                    ],
                ];
                $client->post(sprintf('/issues/%d/checklists.json', $redmineIssueId), ['json' => $payload]);
            }
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = sprintf('Failed to create checklist for Redmine issue #%d', $redmineIssueId);
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
            $message = sprintf('Failed to create checklist for Redmine issue #%d: %s', $redmineIssueId, $exception->getMessage());
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
        printf("  [checklist] Redmine issue #%d replaced.%s", $redmineIssueId, PHP_EOL);
    }
}

/**
 * @return array<int>
 */
function fetchExistingChecklists(Client $client, int $redmineIssueId): array
{
    $response = $client->get(sprintf('/issues/%d/checklists.json', $redmineIssueId));
    $payload = decodeJsonResponse($response);

    $items = isset($payload['checklists']) && is_array($payload['checklists']) ? $payload['checklists'] : [];
    $ids = [];
    foreach ($items as $item) {
        if (is_array($item) && isset($item['id'])) {
            $ids[] = (int)$item['id'];
        }
    }

    return $ids;
}

/**
 * @param array<string, mixed> $issue
 * @return array<int, array{status: string, text: string, raw?: mixed}>
 */
function extractChecklistItems(array $issue, string $primaryRenderedField, string $fallbackField): array
{
    $items = [];

    $rendered = $issue['renderedFields'][$primaryRenderedField] ?? null;
    if (is_string($rendered) && trim($rendered) !== '') {
        $text = strip_tags($rendered);
        $lines = preg_split('/\R+/', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/\[(open|done)]\s*(.+)$/i', $line, $matches)) {
                $items[] = [
                    'status' => strtolower($matches[1]),
                    'text' => trim($matches[2]),
                ];
            }
        }
    }

    if ($items !== []) {
        return $items;
    }

    $fallback = $issue['fields'][$fallbackField] ?? null;
    if (!is_array($fallback) || !isset($fallback['content']) || !is_array($fallback['content'])) {
        return $items;
    }

    foreach ($fallback['content'] as $block) {
        if (($block['type'] ?? '') !== 'bulletList') {
            continue;
        }
        foreach ($block['content'] ?? [] as $listItem) {
            $paragraphs = $listItem['content'] ?? [];
            foreach ($paragraphs as $paragraph) {
                if (($paragraph['type'] ?? '') !== 'paragraph') {
                    continue;
                }
                $buf = [];
                foreach ($paragraph['content'] ?? [] as $node) {
                    if (($node['type'] ?? '') === 'text') {
                        $buf[] = $node['text'];
                    } elseif (($node['type'] ?? '') === 'hardBreak') {
                        $buf[] = "\n";
                    }
                }
                $joined = trim(implode('', $buf));
                if (preg_match('/text:\s*(.+?)\s*checked:\s*(true|false)\s*status:\s*(\w+)/is', $joined, $matches)) {
                    $text = trim($matches[1]);
                    $status = strtolower($matches[3]);
                    if ($status !== 'open' && $status !== 'done') {
                        $status = $matches[2] === 'true' ? 'done' : 'open';
                    }
                    $items[] = [
                        'status' => $status,
                        'text' => $text,
                        'raw' => $joined,
                    ];
                }
            }
        }
    }

    return $items;
}

/**
 * @param array<string, mixed> $config
 * @return PDO
 */
function createDatabaseConnection(array $config): PDO
{
    $dsn = isset($config['dsn']) ? (string)$config['dsn'] : '';
    $username = isset($config['username']) ? (string)$config['username'] : '';
    $password = isset($config['password']) ? (string)$config['password'] : '';
    $options = isset($config['options']) && is_array($config['options']) ? $config['options'] : [];

    $pdo = new PDO($dsn, $username, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

/**
 * @param array<string, mixed> $config
 * @return Client
 */
function createRedmineClient(array $config): Client
{
    $baseUrl = isset($config['base_url']) ? rtrim((string)$config['base_url'], '/') : '';
    $apiKey = isset($config['api_key']) ? (string)$config['api_key'] : '';

    if ($baseUrl === '' || $apiKey === '') {
        throw new RuntimeException('Incomplete Redmine configuration.');
    }

    return new Client([
        'base_uri' => $baseUrl,
        'headers' => [
            'Accept' => 'application/json',
            'X-Redmine-API-Key' => $apiKey,
        ],
    ]);
}

/**
 * @param ResponseInterface $response
 * @return array<string, mixed>
 */
function decodeJsonResponse(ResponseInterface $response): array
{
    $body = (string)$response->getBody();
    if ($body === '') {
        return [];
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to decode JSON response: ' . $exception->getMessage(), 0, $exception);
    }

    return is_array($decoded) ? $decoded : [];
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
 * @param array<string, mixed> $config
 * @param string $key
 * @return array<string, mixed>
 */
function extractArrayConfig(array $config, string $key): array
{
    if (!isset($config[$key]) || !is_array($config[$key])) {
        throw new RuntimeException(sprintf('Missing configuration section: %s', $key));
    }

    return $config[$key];
}

/**
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool} $cliOptions
 * @return list<string>
 */
function determinePhasesToRun(array $cliOptions): array
{
    $defaultPhases = array_keys(AVAILABLE_PHASES);

    $phases = $defaultPhases;
    if (isset($cliOptions['phases']) && is_string($cliOptions['phases']) && $cliOptions['phases'] !== '') {
        $phases = array_map('trim', explode(',', $cliOptions['phases']));
    }

    if (isset($cliOptions['skip']) && is_string($cliOptions['skip']) && $cliOptions['skip'] !== '') {
        $skip = array_map('trim', explode(',', $cliOptions['skip']));
        $phases = array_values(array_diff($phases, $skip));
    }

    $phases = array_values(array_intersect($phases, $defaultPhases));
    if ($phases === []) {
        $phases = $defaultPhases;
    }

    return $phases;
}

/**
 * @param array<int, string> $argv
 * @return array{0: array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool}, 1: list<string>}
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

    $arguments = [];

    array_shift($argv);
    foreach ($argv as $argument) {
        if (!is_string($argument)) {
            continue;
        }

        if ($argument === '--help') {
            $options['help'] = true;
            continue;
        }

        if ($argument === '--version') {
            $options['version'] = true;
            continue;
        }

        if ($argument === '--confirm-push') {
            $options['confirm_push'] = true;
            continue;
        }

        if ($argument === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }

        if (str_starts_with($argument, '--phases=')) {
            $options['phases'] = substr($argument, 9);
            continue;
        }

        if (str_starts_with($argument, '--skip=')) {
            $options['skip'] = substr($argument, 7);
            continue;
        }

        $arguments[] = $argument;
    }

    return [$options, $arguments];
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

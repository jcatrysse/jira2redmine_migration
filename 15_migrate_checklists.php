<?php

/**
 * @noinspection DuplicatedCode
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Jira2Redmine\Http\ClientFactory;
use Jira2Redmine\Database\ConnectionFactory;
use Jira2Redmine\Cli\Phases;
use Jira2Redmine\Redmine\ChecklistItem;
use Jira2Redmine\Cli\Script;
use Jira2Redmine\Cli\ScriptCommandLines;
use Jira2Redmine\Cli\ConfigValue;
use Jira2Redmine\Time\Clock;

const MIGRATE_CHECKLISTS_SCRIPT_VERSION = '0.0.1';
const AVAILABLE_PHASES = [
    'transform' => 'Parse Jira checklist custom fields and stage Redmine payloads.',
    'push' => 'Replace Redmine checklists via redmine_checklists plugin.',
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
        /** @var array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool} $cliOptions */
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
        Clock::dateTime(),
        implode(', ', $phasesToRun),
        PHP_EOL
    );

    $databaseConfig = ConfigValue::section($config, 'database');
    $pdo = ConnectionFactory::connect($databaseConfig, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Parsing Jira checklists...%s", Clock::dateTime(), PHP_EOL);
        $summary = transformChecklists($pdo, $config);
        printf(
            "[%s] Transform complete. Ready: %d, Ignored: %d, Pending: %d.%s",
            Clock::dateTime(),
            $summary['ready'],
            $summary['ignored'],
            $summary['pending'],
            PHP_EOL
        );
    } else {
        printf("[%s] Skipping transform phase (disabled via CLI option).%s", Clock::dateTime(), PHP_EOL);
    }

    if (in_array('push', $phasesToRun, true)) {
        $confirmPush = $cliOptions['confirm_push'];
        $isDryRun = $cliOptions['dry_run'];
        $redmineClient = ClientFactory::redmine(ConfigValue::section($config, 'redmine'));
        runChecklistPushPhase($pdo, $redmineClient, $confirmPush, $isDryRun);
    } else {
        printf("[%s] Skipping push phase (disabled via CLI option).%s", Clock::dateTime(), PHP_EOL);
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
    printf("  -V, --version        Display version information.\n");
    printf("  -h, --help           Display this help message.\n");
}

function printVersion(): void
{
    printf("%s%s", MIGRATE_CHECKLISTS_SCRIPT_VERSION, PHP_EOL);
}

/**
 * @param array<string, mixed> $config
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

    $existingStatement = $pdo->query(
        'SELECT mapping_id, jira_issue_id, proposed_payload, automation_hash, migration_status FROM migration_mapping_checklists'
    );
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
            'migration_status' => $row['migration_status'],
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
            notes,
            automation_hash
        ) VALUES (
            :jira_issue_id,
            :jira_issue_key,
            :redmine_issue_id,
            :proposed_payload,
            :migration_status,
            :notes,
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
            notes = :notes,
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
        $notes = null;
        if ($items === []) {
            $migrationStatus = 'IGNORED';
            $notes = 'No checklist items found in Jira payload.';
        } elseif ($redmineIssueId !== null) {
            $migrationStatus = 'READY_FOR_PUSH';
        } else {
            $notes = 'Missing Redmine issue mapping; rerun 10_migrate_issues.php.';
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
                'notes' => $notes,
                'automation_hash' => $automationHash,
            ]);
            continue;
        }

        $current = $existing[$jiraIssueId];
        $storedHash = normalizeStoredAutomationHash($current['automation_hash'] ?? null);

        // an unchanged checklist keeps its row, but a push that failed is tried again (B83: the row stayed FAILED)
        if ($storedHash !== null && hash_equals($storedHash, $automationHash) && $current['migration_status'] !== 'FAILED') {
            continue;
        }

        $updateMapping->execute([
            'mapping_id' => $current['mapping_id'],
            'jira_issue_key' => $jiraIssueKey,
            'redmine_issue_id' => $redmineIssueId,
            'proposed_payload' => $proposedPayload,
            'migration_status' => $migrationStatus,
            'notes' => $notes,
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
        printf("[%s] No checklists queued for push.%s", Clock::dateTime(), PHP_EOL);
        return;
    }

    printf("[%s] %d issue(s) queued for checklist push.%s", Clock::dateTime(), count($rows), PHP_EOL);

    if ($isDryRun) {
        foreach ($rows as $row) {
            $items = decodeJsonColumn($row['proposed_payload'] ?? null) ?: [];
            printf(
                "  - Issue #%d (%s): %d checklist items%s",
                (int)$row['redmine_issue_id'],
                $row['jira_issue_key'],
                count($items),
                PHP_EOL
            );
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

        $shortened = 0;
        try {
            foreach ($items as $item) {
                // an item longer than the plugin allows goes in cut to its maximum (B80)
                $subject = (string)($item['text'] ?? '');
                if (ChecklistItem::post($client, $redmineIssueId, $subject, ($item['status'] ?? '') === 'done')) {
                    $shortened++;
                }
            }
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = sprintf('Failed to create checklist for Redmine issue #%d', $redmineIssueId);
            $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
            $message .= ': ' . extractErrorBody($response);
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

        $notes = $shortened > 0 ? sprintf('%d item(s) cut to the length Redmine allows (B80).', $shortened) : null;
        $updateStatement->execute([
            'mapping_id' => $mappingId,
            'migration_status' => 'SUCCESS',
            'notes' => $notes,
        ]);
        printf("  [checklist] Redmine issue #%d replaced.%s%s", $redmineIssueId, $notes !== null ? ' [shortened] ' . $notes : '', PHP_EOL);
    }
}

/**
 * @return array<int>
 * @throws GuzzleException
 * @throws GuzzleException
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

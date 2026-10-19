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
use Jira2Redmine\Redmine\Watchers;
use Jira2Redmine\Cli\Phases;
use Jira2Redmine\Cli\Script;
use Jira2Redmine\Cli\ScriptCommandLines;
use Jira2Redmine\Cli\ConfigValue;
use Jira2Redmine\Time\Clock;

const MIGRATE_WATCHERS_SCRIPT_VERSION = '0.0.1';
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira watchers into staging tables.',
    'transform' => 'Prepare watcher mappings and payloads.',
    'push' => 'Apply watchers to Redmine issues.',
];

const JIRA_RATE_LIMIT_MAX_RETRIES = 5;
const JIRA_RATE_LIMIT_BASE_DELAY_MS = 1000;

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

    if (in_array('jira', $phasesToRun, true)) {
        printf("[%s] Fetching Jira watchers...%s", Clock::dateTime(), PHP_EOL);
        $jiraClient = ClientFactory::jira(ConfigValue::section($config, 'jira'));
        $summary = fetchJiraWatchers($jiraClient, $pdo);
        printf(
            "[%s] Jira watcher extraction complete. Watchers processed: %d (updated %d, warnings %d).%s",
            Clock::dateTime(),
            $summary['watchers_processed'],
            $summary['watchers_updated'],
            $summary['warnings'],
            PHP_EOL
        );
    } else {
        printf("[%s] Skipping Jira extraction phase (disabled via CLI option).%s", Clock::dateTime(), PHP_EOL);
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Reconciling watcher mappings...%s", Clock::dateTime(), PHP_EOL);
        $summary = transformWatcherMappings($pdo);
        printf(
            "[%s] Transform complete. Ready: %d, Pending: %d.%s",
            Clock::dateTime(),
            $summary['ready'],
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
        runWatcherPushPhase($pdo, $redmineClient, $confirmPush, $isDryRun);
    } else {
        printf("[%s] Skipping push phase (disabled via CLI option).%s", Clock::dateTime(), PHP_EOL);
    }
}

function printUsage(): void
{
    printf("Jira to Redmine Watcher Migration (step 14) — version %s%s", MIGRATE_WATCHERS_SCRIPT_VERSION, PHP_EOL);
    printf("Usage: php 14_migrate_watchers.php [options]\n\n");
    printf("Options:\n");
    printf("  --phases=LIST        Comma separated list of phases to run (default: jira,transform,push).\n");
    printf("  --skip=LIST          Comma separated list of phases to skip.\n");
    printf("  --confirm-push       Required to execute the push phase.\n");
    printf("  --dry-run            Preview the push phase without modifying Redmine.\n");
    printf("  --version            Display version information.\n");
    printf("  --help               Display this help message.\n");
}

function printVersion(): void
{
    printf("%s%s", MIGRATE_WATCHERS_SCRIPT_VERSION, PHP_EOL);
}

/**
 * @return array{watchers_processed: int, watchers_updated: int, warnings: int}
 * @throws Throwable
 */
function fetchJiraWatchers(Client $client, PDO $pdo): array
{
    $issueStatement = $pdo->query('SELECT id, issue_key FROM staging_jira_issues ORDER BY id');
    if ($issueStatement === false) {
        throw new RuntimeException('Failed to enumerate staged Jira issues.');
    }

    $issues = $issueStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $issueStatement->closeCursor();

    $watcherInsert = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_watchers (
            issue_id,
            account_id,
            raw_payload
        ) VALUES (
            :issue_id,
            :account_id,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($watcherInsert === false) {
        throw new RuntimeException('Failed to prepare Jira watcher insert.');
    }

    $warningInsert = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_watcher_fetch_failures (
            issue_id,
            issue_key,
            status_code,
            message,
            response_body
        ) VALUES (
            :issue_id,
            :issue_key,
            :status_code,
            :message,
            :response_body
        )
        ON DUPLICATE KEY UPDATE
            status_code = VALUES(status_code),
            message = VALUES(message),
            response_body = VALUES(response_body),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($warningInsert === false) {
        throw new RuntimeException('Failed to prepare Jira watcher warning insert.');
    }

    $processed = 0;
    $updated = 0;
    $warnings = 0;

    foreach ($issues as $issue) {
        $issueId = (string)$issue['id'];
        $issueKey = isset($issue['issue_key']) ? (string)$issue['issue_key'] : $issueId;

        try {
            $response = jiraGetWithRetry(
                $client,
                sprintf('/rest/api/3/issue/%s/watchers', $issueId),
                [],
                $issueKey,
                'watchers'
            );
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $status = $response->getStatusCode();
            if ($status !== 403 && $status !== 404) {
                throw $exception;
            }
            $message = sprintf('Jira watchers not accessible for issue %s (HTTP %d).', $issueKey, $status);
            $body = extractErrorBody($response);
            $warningInsert->execute([
                'issue_id' => $issueId,
                'issue_key' => $issueKey,
                'status_code' => $status,
                'message' => $message,
                'response_body' => $body,
            ]);
            printf("  [warn] %s%s", $message, PHP_EOL);
            $warnings++;
            continue;
        }

        $payload = decodeJsonResponse($response);
        $watchers = isset($payload['watchers']) && is_array($payload['watchers']) ? $payload['watchers'] : [];

        foreach ($watchers as $watcher) {
            if (!is_array($watcher)) {
                continue;
            }
            $accountId = isset($watcher['accountId']) ? (string)$watcher['accountId'] : null;
            if ($accountId === null || $accountId === '') {
                continue;
            }

            $watcherInsert->execute([
                'issue_id' => $issueId,
                'account_id' => $accountId,
                'raw_payload' => encodeJson($watcher),
            ]);

            if ($watcherInsert->rowCount() === 1) {
                $processed++;
            } else {
                $updated++;
            }
        }
    }

    return [
        'watchers_processed' => $processed,
        'watchers_updated' => $updated,
        'warnings' => $warnings,
    ];
}

/**
 * @return array{ready: int, pending: int}
 */
function transformWatcherMappings(PDO $pdo): array
{
    $insertSql = <<<SQL
        INSERT INTO migration_mapping_watchers (
            jira_issue_id,
            jira_issue_key,
            jira_account_id
        )
        SELECT
            w.issue_id,
            i.issue_key,
            w.account_id
        FROM staging_jira_watchers w
        JOIN staging_jira_issues i ON i.id = w.issue_id
        LEFT JOIN migration_mapping_watchers map
            ON map.jira_issue_id = w.issue_id AND map.jira_account_id = w.account_id
        WHERE map.jira_issue_id IS NULL
    SQL;

    $pdo->exec($insertSql);

    $updateSql = <<<SQL
        UPDATE migration_mapping_watchers map
        LEFT JOIN migration_mapping_issues issue_map ON issue_map.jira_issue_id = map.jira_issue_id
        LEFT JOIN migration_mapping_users user_map ON user_map.jira_account_id = map.jira_account_id
        SET
            map.redmine_issue_id = issue_map.redmine_issue_id,
            map.redmine_user_id = user_map.redmine_user_id,
            map.migration_status = CASE
                WHEN issue_map.redmine_issue_id IS NULL THEN 'PENDING_ANALYSIS'
                WHEN user_map.redmine_user_id IS NULL THEN 'PENDING_ANALYSIS'
                ELSE 'READY_FOR_PUSH'
            END,
            map.notes = CASE
                WHEN issue_map.redmine_issue_id IS NULL THEN 'Missing Redmine issue mapping; rerun 10_migrate_issues.php.'
                WHEN user_map.redmine_user_id IS NULL THEN 'Missing Redmine user mapping; rerun 02_migrate_users.php.'
                END,
            map.last_updated_at = CURRENT_TIMESTAMP
        WHERE 1
    SQL;

    $pdo->exec($updateSql);

    $statusCounts = summariseWatcherStatuses($pdo);

    return [
        'ready' => $statusCounts['READY_FOR_PUSH'] ?? 0,
        'pending' => $statusCounts['PENDING_ANALYSIS'] ?? 0,
    ];
}

/**
 * @return array<string, int>
 */
function summariseWatcherStatuses(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT migration_status, COUNT(*) AS total
        FROM migration_mapping_watchers
        GROUP BY migration_status
        ORDER BY migration_status
    SQL;

    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to summarise watcher statuses.');
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    $result = [];
    foreach ($rows as $row) {
        $status = isset($row['migration_status']) ? (string)$row['migration_status'] : '';
        if ($status === '') {
            continue;
        }
        $result[$status] = isset($row['total']) ? (int)$row['total'] : 0;
    }

    return $result;
}

function runWatcherPushPhase(PDO $pdo, Client $client, bool $confirmPush, bool $isDryRun): void
{
    $statement = $pdo->query(<<<SQL
        SELECT mapping_id, jira_issue_key, redmine_issue_id, redmine_user_id
        FROM migration_mapping_watchers
        WHERE migration_status = 'READY_FOR_PUSH'
        ORDER BY mapping_id
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to fetch watcher push candidates.');
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    if ($rows === []) {
        printf("[%s] No watchers queued for push.%s", Clock::dateTime(), PHP_EOL);
        return;
    }

    printf("[%s] %d watcher(s) queued for push.%s", Clock::dateTime(), count($rows), PHP_EOL);

    if ($isDryRun) {
        foreach ($rows as $row) {
            printf(
                "  - Issue #%d (%s) watcher user #%d%s",
                (int)$row['redmine_issue_id'],
                $row['jira_issue_key'],
                (int)$row['redmine_user_id'],
                PHP_EOL
            );
        }
        printf("  Dry-run active; no watchers will be pushed.%s", PHP_EOL);
        return;
    }

    if (!$confirmPush) {
        printf("  Push confirmation missing; rerun with --confirm-push to apply watchers.%s", PHP_EOL);
        return;
    }

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_watchers
        SET
            migration_status = :migration_status,
            notes = :notes,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare watcher update statement.');
    }

    $addedPerIssue = [];
    foreach ($rows as $row) {
        $mappingId = (int)$row['mapping_id'];
        $redmineIssueId = $row['redmine_issue_id'] !== null ? (int)$row['redmine_issue_id'] : null;
        $redmineUserId = $row['redmine_user_id'] !== null ? (int)$row['redmine_user_id'] : null;

        if ($redmineIssueId === null || $redmineUserId === null) {
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'migration_status' => 'FAILED',
                'notes' => 'Missing Redmine identifiers.',
            ]);
            continue;
        }

        $payload = ['user_id' => $redmineUserId];

        try {
            $client->post(sprintf('/issues/%d/watchers.json', $redmineIssueId), ['json' => $payload]);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = sprintf('Failed to add watcher to Redmine issue #%d', $redmineIssueId);
            $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
            $body = extractErrorBody($response);
            $message .= ': ' . $body;

            if (str_contains($body, 'is already watching')) {
                $updateStatement->execute([
                    'mapping_id' => $mappingId,
                    'migration_status' => 'SUCCESS',
                    'notes' => 'Watcher already present.',
                ]);
                continue;
            }

            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'migration_status' => 'FAILED',
                'notes' => $message,
            ]);
            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        } catch (GuzzleException $exception) {
            $message = sprintf('Failed to add watcher to Redmine issue #%d: %s', $redmineIssueId, $exception->getMessage());
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'migration_status' => 'FAILED',
                'notes' => $message,
            ]);
            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        }

        // Redmine answers 204 even when it does not store the watcher: read back per issue below (B20).
        $addedPerIssue[$redmineIssueId][$mappingId] = $redmineUserId;
    }

    foreach ($addedPerIssue as $redmineIssueId => $added) {
        try {
            $response = $client->get(sprintf('/issues/%d.json', $redmineIssueId), ['query' => ['include' => 'watchers']]);
            $issuePayload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $stored = Watchers::storedUserIds(is_array($issuePayload) ? $issuePayload : []);
        } catch (GuzzleException | JsonException $exception) {
            foreach (array_keys($added) as $mappingId) {
                $updateStatement->execute([
                    'mapping_id' => $mappingId,
                    'migration_status' => 'FAILED',
                    'notes' => sprintf('Watcher sent but not verified on Redmine issue #%d: %s', $redmineIssueId, $exception->getMessage()),
                ]);
            }
            printf(
                "  [error] Could not read back the watchers of Redmine issue #%d: %s%s",
                $redmineIssueId,
                $exception->getMessage(),
                PHP_EOL
            );
            continue;
        }

        $notStored = Watchers::notStored(array_values($added), $stored);
        // a dropped watcher who is not a member of the project has no access in Jira either (B50)
        $members = $notStored === [] ? [] : projectMemberUserIds($client, (int)($issuePayload['issue']['project']['id'] ?? 0));
        foreach ($added as $mappingId => $redmineUserId) {
            $missing = in_array($redmineUserId, $notStored, true);
            $note = null;
            if ($missing) {
                $note = $members !== null && !in_array($redmineUserId, $members, true)
                    ? Watchers::noAccessNote($redmineUserId)
                    : sprintf(
                        'Redmine answered 204 but did not store user #%d as watcher (missing permission such as view_watched_issues).',
                        $redmineUserId
                    );
            }
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'migration_status' => $missing ? 'FAILED' : 'SUCCESS',
                'notes' => $note,
            ]);
        }
        if ($notStored !== []) {
            printf("  [error] Redmine issue #%d: watcher(s) not stored: user #%s%s", $redmineIssueId, implode(', #', $notStored), PHP_EOL);
        } else {
            printf("  [watcher] Redmine issue #%d updated.%s", $redmineIssueId, PHP_EOL);
        }
    }
}

/**
 * User ids of a Redmine project's members (directly or through a group), cached per project; null when
 * they cannot be read.
 *
 * @return list<int>|null
 */
function projectMemberUserIds(Client $client, int $projectId): ?array
{
    static $cache = [];
    if ($projectId <= 0) {
        return null;
    }
    if (!array_key_exists($projectId, $cache)) {
        try {
            $ids = [];
            $offset = 0;
            do {
                $page = json_decode((string)$client->get(
                    sprintf('/projects/%d/memberships.json', $projectId),
                    ['query' => ['limit' => 100, 'offset' => $offset]]
                )->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $page = is_array($page) ? $page : [];
                $ids = array_merge($ids, Watchers::memberUserIds($page));
                $count = is_array($page['memberships'] ?? null) ? count($page['memberships']) : 0;
                $offset += $count;
            } while ($count > 0 && $offset < (int)($page['total_count'] ?? 0));
            $cache[$projectId] = $ids;
        } catch (GuzzleException | JsonException) {
            $cache[$projectId] = null;
        }
    }

    return $cache[$projectId];
}

/**
 * @param array<string, mixed> $options
 * @throws BadResponseException
 * @throws GuzzleException|\Random\RandomException
 */
function jiraGetWithRetry(
    Client $client,
    string $path,
    array $options,
    string $issueKey,
    string $context
): ResponseInterface {
    $attempt = 0;

    do {
        try {
            return $client->get($path, $options);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $status = $response->getStatusCode();

            if ($status !== 429) {
                throw $exception;
            }

            $attempt++;
            if ($attempt > JIRA_RATE_LIMIT_MAX_RETRIES) {
                throw $exception;
            }

            $retryAfter = null;
            $headers = $response->getHeader('Retry-After');
            if ($headers !== []) {
                $headerValue = trim((string)($headers[0] ?? ''));
                if ($headerValue !== '' && ctype_digit($headerValue)) {
                    $retryAfter = (int)$headerValue;
                }
            }

            $delayMs = calculateRateLimitDelayMs($attempt, $retryAfter);
            printf(
                "  [warn] Jira rate limit (429) for issue %s (%s). Retrying in %.1fs (attempt %d/%d).%s",
                $issueKey,
                $context,
                $delayMs / 1000,
                $attempt,
                JIRA_RATE_LIMIT_MAX_RETRIES,
                PHP_EOL
            );
            usleep($delayMs * 1000);
        }
    } while (true);
}

/**
 * @throws \Random\RandomException
 */
function calculateRateLimitDelayMs(int $attempt, ?int $retryAfterSeconds): int
{
    if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
        return $retryAfterSeconds * 1000;
    }

    $base = JIRA_RATE_LIMIT_BASE_DELAY_MS;
    $delay = (int)($base * (2 ** max(0, $attempt - 1)));
    $jitter = random_int(0, (int)($base / 2));

    return $delay + $jitter;
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

function encodeJson(mixed $value): ?string
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

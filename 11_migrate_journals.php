<?php
/** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Karvaka\AdfToGfm\Converter as AdfConverter;
use Jira2Redmine\Http\ClientFactory;
use Jira2Redmine\Database\ConnectionFactory;
use Jira2Redmine\Redmine\ExtendedApi;
use Jira2Redmine\Attachments\AttachmentNames;
use Jira2Redmine\Journals\AttachmentWindow;
use Jira2Redmine\Journals\JournalStatus;
use Jira2Redmine\Journals\PushOrder;
use Jira2Redmine\Text\Relink;
use Jira2Redmine\Cli\Phases;
use Jira2Redmine\Journals\ExtractionFailure;
use Jira2Redmine\History\HistoryChain;
use Jira2Redmine\History\HistoryFile;
use Jira2Redmine\History\HistoryLookups;
use Jira2Redmine\History\HistoryPush;
use Jira2Redmine\History\HistoryRequest;
use Jira2Redmine\History\IssueHistory;
use Jira2Redmine\Users\DefaultAuthor;
use Jira2Redmine\Cli\Script;
use Jira2Redmine\Cli\ScriptCommandLines;
use Jira2Redmine\Cli\ConfigValue;
use Jira2Redmine\Time\Clock;
const MIGRATE_JOURNALS_SCRIPT_VERSION = '0.0.1';
const JIRA_RATE_LIMIT_MAX_RETRIES = 5;
const JIRA_RATE_LIMIT_BASE_DELAY_MS = 1000;
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira comments and changelog entries into staging tables.',
    'transform' => 'Populate and classify journal mappings based on issue availability.',
    'push' => 'Create Redmine journals for migrated Jira comments and changelog events.',
    'history' => 'Write the Jira history of status, assignee and priority as Redmine journals (decision 41).',
];

require_once __DIR__ . '/lib/description_conversion.php';

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
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool} $cliOptions
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
        printf("[%s] Fetching Jira comments and changelog entries...%s", Clock::dateTime(), PHP_EOL);
        $jiraClient = ClientFactory::jira(ConfigValue::section($config, 'jira'));
        $jiraSummary = fetchJiraJournals($jiraClient, $pdo, $config);
        refreshIssueKeyAliases($jiraClient, $pdo);
        printf(
            "[%s] Jira journal extraction complete. Comments: %d (updated %d), Changelog entries: %d (updated %d), not read: %d.%s",
            Clock::dateTime(),
            $jiraSummary['comments_processed'],
            $jiraSummary['comments_updated'],
            $jiraSummary['changelog_processed'],
            $jiraSummary['changelog_updated'],
            $jiraSummary['failed'],
            PHP_EOL
        );
    } else {
        printf("[%s] Skipping Jira extraction phase (disabled via CLI option).%s", Clock::dateTime(), PHP_EOL);
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Reconciling journal mappings...%s", Clock::dateTime(), PHP_EOL);
        $summary = transformJournalMappings($pdo);
        printf(
            "[%s] Transform phase complete. Ready: %d, Pending: %d, Failed: %d.%s",
            Clock::dateTime(),
            $summary['ready'],
            $summary['pending'],
            $summary['failed'],
            PHP_EOL
        );
        try {
            $noteSummary = populateProposedJournalNotes($pdo, AttachmentWindow::seconds($config));
            printf(
                "  [transform] Proposed journal notes updated: %d (skipped manual overrides: %d).%s",
                $noteSummary['updated'],
                $noteSummary['skipped_manual'],
                PHP_EOL
            );
        } catch (Throwable $e) {
            printf("  [warn] populateProposedJournalNotes failed: %s%s", $e->getMessage(), PHP_EOL);
        }
        updateMigrationJournalProposedNotesWithRedmineIds($pdo);
        printf("  [transform] Canonicalised proposed journal notes with #redmine_id.%s", PHP_EOL);
        if ($summary['status_breakdown'] !== []) {
            printf("  Current journal status breakdown:%s", PHP_EOL);
            foreach ($summary['status_breakdown'] as $status => $count) {
                printf("  - %-24s %d%s", $status, $count, PHP_EOL);
            }
        }
    } else {
        printf("[%s] Skipping transform phase (disabled via CLI option).%s", Clock::dateTime(), PHP_EOL);
    }

    // push and history go together, per issue in Jira's time order (decision 41); history alone is appended
    $withJournals = in_array('push', $phasesToRun, true);
    $withHistory = in_array('history', $phasesToRun, true);
    if ($withJournals || $withHistory) {
        $confirmPush = (bool)($cliOptions['confirm_push'] ?? false);
        $isDryRun = (bool)($cliOptions['dry_run'] ?? false);
        $redmineConfig = ConfigValue::section($config, 'redmine');
        $useExtendedApi = ExtendedApi::enabled($redmineConfig, (bool)($cliOptions['use_extended_api'] ?? false));
        $extendedApiPrefix = ExtendedApi::prefix($redmineConfig);
        runJournalPushPhase($pdo, $config, $confirmPush, $isDryRun, $useExtendedApi, $extendedApiPrefix, $withJournals, $withHistory);
    } else {
        printf("[%s] Skipping push and history phases (disabled via CLI option).%s", Clock::dateTime(), PHP_EOL);
    }
}

function printUsage(): void
{
    printf("Jira to Redmine Journal Migration (step 12) — version %s%s", MIGRATE_JOURNALS_SCRIPT_VERSION, PHP_EOL);
    printf("Usage: php 11_migrate_journals.php [options]\n\n");
    printf("Options:\n");
    printf("  --phases=LIST        Comma separated list of phases to run (default: jira,transform,push,history).\n");
    printf("  --skip=LIST          Comma separated list of phases to skip.\n");
    printf("  --confirm-push       Required to execute the push phase.\n");
    printf("  --dry-run            Preview the push phase without modifying Redmine.\n");
    printf("  --use-extended-api   Push journals through the redmine_extended_api plugin when enabled.\n");
    printf("  --version            Display version information.\n");
    printf("  --help               Display this help message.\n");
}

function printVersion(): void
{
    printf("%s%s", MIGRATE_JOURNALS_SCRIPT_VERSION, PHP_EOL);
}

/**
 * @param Client $client
 * @param PDO $pdo
 * @param array<string, mixed> $config
 * @return array{comments_processed: int, comments_updated: int, changelog_processed: int, changelog_updated: int, failed: int}
 * @throws Throwable
 */
function fetchJiraJournals(Client $client, PDO $pdo, array $config): array
{
    $issueStatement = $pdo->query('SELECT id, issue_key FROM staging_jira_issues ORDER BY id');
    if ($issueStatement === false) {
        throw new RuntimeException('Failed to enumerate staged Jira issues.');
    }

    $issues = $issueStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $issueStatement->closeCursor();

    $commentInsert = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_comments (
            id,
            issue_id,
            author_account_id,
            body_adf,
            body_html,
            created_at,
            updated_at,
            raw_payload
        ) VALUES (
            :id,
            :issue_id,
            :author_account_id,
            :body_adf,
            :body_html,
            :created_at,
            :updated_at,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            author_account_id = VALUES(author_account_id),
            body_adf = VALUES(body_adf),
            body_html = VALUES(body_html),
            created_at = VALUES(created_at),
            updated_at = VALUES(updated_at),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($commentInsert === false) {
        throw new RuntimeException('Failed to prepare Jira comment insert.');
    }

    $changelogInsert = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_changelogs (
            id,
            issue_id,
            author_account_id,
            created_at,
            items_json,
            raw_payload
        ) VALUES (
            :id,
            :issue_id,
            :author_account_id,
            :created_at,
            :items_json,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            author_account_id = VALUES(author_account_id),
            created_at = VALUES(created_at),
            items_json = VALUES(items_json),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($changelogInsert === false) {
        throw new RuntimeException('Failed to prepare Jira changelog insert.');
    }

    $stateStatement = $pdo->query(<<<SQL
        SELECT
            issue_id,
            comments_extracted_at,
            comments_migration_status,
            comments_notes,
            changelog_extracted_at,
            changelog_migration_status,
            changelog_notes
        FROM staging_jira_journal_extract_state
    SQL);
    if ($stateStatement === false) {
        throw new RuntimeException('Failed to load Jira journal extraction state.');
    }

    $stateRows = $stateStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stateStatement->closeCursor();

    $stateByIssue = [];
    foreach ($stateRows as $row) {
        $issueId = isset($row['issue_id']) ? (string)$row['issue_id'] : '';
        if ($issueId === '') {
            continue;
        }
        $stateByIssue[$issueId] = [
            'comments_extracted_at' => $row['comments_extracted_at'] ?? null,
            'comments_migration_status' => isset($row['comments_migration_status']) ? (string)$row['comments_migration_status'] : 'PENDING',
            'comments_notes' => isset($row['comments_notes']) ? (string)$row['comments_notes'] : null,
            'changelog_extracted_at' => $row['changelog_extracted_at'] ?? null,
            'changelog_migration_status' => isset($row['changelog_migration_status']) ? (string)$row['changelog_migration_status'] : 'PENDING',
            'changelog_notes' => isset($row['changelog_notes']) ? (string)$row['changelog_notes'] : null,
        ];
    }

    $stateUpsertComments = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_journal_extract_state (
            issue_id,
            issue_key,
            comments_extracted_at,
            comments_migration_status,
            comments_notes
        ) VALUES (
            :issue_id,
            :issue_key,
            :comments_extracted_at,
            :comments_migration_status,
            :comments_notes
        )
        ON DUPLICATE KEY UPDATE
            issue_key = VALUES(issue_key),
            comments_extracted_at = VALUES(comments_extracted_at),
            comments_migration_status = VALUES(comments_migration_status),
            comments_notes = VALUES(comments_notes),
            last_updated_at = CURRENT_TIMESTAMP
    SQL);

    if ($stateUpsertComments === false) {
        throw new RuntimeException('Failed to prepare journal extraction state update (comments).');
    }

    $stateUpsertChangelog = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_journal_extract_state (
            issue_id,
            issue_key,
            changelog_extracted_at,
            changelog_migration_status,
            changelog_notes
        ) VALUES (
            :issue_id,
            :issue_key,
            :changelog_extracted_at,
            :changelog_migration_status,
            :changelog_notes
        )
        ON DUPLICATE KEY UPDATE
            issue_key = VALUES(issue_key),
            changelog_extracted_at = VALUES(changelog_extracted_at),
            changelog_migration_status = VALUES(changelog_migration_status),
            changelog_notes = VALUES(changelog_notes),
            last_updated_at = CURRENT_TIMESTAMP
    SQL);

    if ($stateUpsertChangelog === false) {
        throw new RuntimeException('Failed to prepare journal extraction state update (changelog).');
    }

    $commentsProcessed = 0;
    $commentsUpdated = 0;
    $changelogProcessed = 0;
    $changelogUpdated = 0;
    $failed = 0;

    foreach ($issues as $issue) {
        $issueId = (string)$issue['id'];
        $issueKey = isset($issue['issue_key']) ? (string)$issue['issue_key'] : $issueId;

        $state = $stateByIssue[$issueId] ?? [
            'comments_extracted_at' => null,
            'comments_migration_status' => 'PENDING',
            'comments_notes' => null,
            'changelog_extracted_at' => null,
            'changelog_migration_status' => 'PENDING',
            'changelog_notes' => null,
        ];
        $commentsStatus = $state['comments_migration_status'];
        $changelogStatus = $state['changelog_migration_status'];
        $commentsDone = shouldSkipJournalExtraction($commentsStatus);
        $changelogDone = shouldSkipJournalExtraction($changelogStatus);

        if (!$commentsDone) {
            try {
                [$newComments, $updatedComments] = fetchJiraCommentsForIssue($client, $commentInsert, $issueId, $issueKey);
                $commentsProcessed += $newComments;
                $commentsUpdated += $updatedComments;

                $stateUpsertComments->execute([
                    'issue_id' => $issueId,
                    'issue_key' => $issueKey,
                    'comments_extracted_at' => Clock::dateTime(),
                    'comments_migration_status' => 'SUCCESS',
                    'comments_notes' => null,
                ]);
            } catch (RuntimeException $exception) {
                $warningStatus = classifyJournalExtractionFailure($exception);
                $message = $exception->getMessage();
                printf("%s%s", ExtractionFailure::line($issueKey, 'comments', $warningStatus, $message), PHP_EOL);
                $failed += $warningStatus === 'FAILED' ? 1 : 0;
                $stateUpsertComments->execute([
                    'issue_id' => $issueId,
                    'issue_key' => $issueKey,
                    'comments_extracted_at' => null,
                    'comments_migration_status' => $warningStatus,
                    'comments_notes' => $message,
                ]);
            }
        }

        if (!$changelogDone) {
            try {
                [$newChangelog, $updatedChangelog] = fetchJiraChangelogForIssue($client, $changelogInsert, $issueId, $issueKey);
                $changelogProcessed += $newChangelog;
                $changelogUpdated += $updatedChangelog;

                $stateUpsertChangelog->execute([
                    'issue_id' => $issueId,
                    'issue_key' => $issueKey,
                    'changelog_extracted_at' => Clock::dateTime(),
                    'changelog_migration_status' => 'SUCCESS',
                    'changelog_notes' => null,
                ]);
            } catch (RuntimeException $exception) {
                $warningStatus = classifyJournalExtractionFailure($exception);
                $message = $exception->getMessage();
                printf("%s%s", ExtractionFailure::line($issueKey, 'history', $warningStatus, $message), PHP_EOL);
                $failed += $warningStatus === 'FAILED' ? 1 : 0;
                $stateUpsertChangelog->execute([
                    'issue_id' => $issueId,
                    'issue_key' => $issueKey,
                    'changelog_extracted_at' => null,
                    'changelog_migration_status' => $warningStatus,
                    'changelog_notes' => $message,
                ]);
            }
        }
    }

    return [
        'comments_processed' => $commentsProcessed,
        'comments_updated' => $commentsUpdated,
        'changelog_processed' => $changelogProcessed,
        'changelog_updated' => $changelogUpdated,
        'failed' => $failed,
    ];
}

function shouldSkipJournalExtraction(string $status): bool
{
    return in_array($status, ['SUCCESS', 'WARNING', 'SKIPPED', 'IGNORED'], true);
}

function classifyJournalExtractionFailure(RuntimeException $exception): string
{
    $statusCode = $exception->getCode();
    if (in_array($statusCode, [401, 403, 404], true)) {
        return 'WARNING';
    }

    return 'FAILED';
}

/**
 * @param Client $client
 * @param PDOStatement $statement
 * @param string $issueId
 * @param string $issueKey
 * @return array{0: int, 1: int}
 * @throws \Random\RandomException
 * @throws \Random\RandomException
 */
function fetchJiraCommentsForIssue(Client $client, PDOStatement $statement, string $issueId, string $issueKey): array
{
    $new = 0;
    $updated = 0;

    $startAt = 0;
    $maxResults = 100;

    do {
        try {
            $response = jiraGetWithRetry(
                $client,
                sprintf('/rest/api/3/issue/%s/comment', $issueId),
                [
                    'query' => [
                        'startAt' => $startAt,
                        'maxResults' => $maxResults,
                        'expand' => 'renderedBody',
                    ],
                ],
                $issueKey,
                'comments'
            );
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = sprintf('Failed to fetch comments for Jira issue %s', $issueKey);
            $statusCode = 0;
            if ($response instanceof ResponseInterface) {
                $statusCode = $response->getStatusCode();
                $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                $message .= ': ' . extractErrorBody($response);
            } else {
                $message .= ': ' . $exception->getMessage();
            }
            throw new RuntimeException($message, $statusCode, $exception);
        } catch (GuzzleException $exception) {
            throw new RuntimeException(sprintf('Failed to fetch comments for Jira issue %s: %s', $issueKey, $exception->getMessage()), 0, $exception);
        }

        $payload = decodeJsonResponse($response);
        $comments = isset($payload['comments']) && is_array($payload['comments']) ? $payload['comments'] : [];
        $total = isset($payload['total']) ? (int)$payload['total'] : count($comments);

        foreach ($comments as $comment) {
            if (!is_array($comment) || !isset($comment['id'])) {
                continue;
            }

            $commentId = (string)$comment['id'];
            $authorId = isset($comment['author']['accountId']) ? (string)$comment['author']['accountId'] : null;
            $createdAt = isset($comment['created']) ? normalizeDateTimeString($comment['created']) : null;
            $updatedAt = isset($comment['updated']) ? normalizeDateTimeString($comment['updated']) : $createdAt;
            $bodyAdf = isset($comment['body']) ? encodeJson($comment['body']) : null;
            $bodyHtml = isset($comment['renderedBody']) && is_string($comment['renderedBody'])
                ? trim($comment['renderedBody'])
                : null;
            $rawPayload = encodeJson($comment);

            $statement->execute([
                'id' => $commentId,
                'issue_id' => $issueId,
                'author_account_id' => $authorId,
                'body_adf' => $bodyAdf,
                'body_html' => $bodyHtml,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
                'raw_payload' => $rawPayload,
            ]);

            if ($statement->rowCount() === 1) {
                $new++;
            } else {
                $updated++;
            }
        }

        $startAt += $maxResults;
    } while ($startAt < $total);

    return [$new, $updated];
}

/**
 * @param Client $client
 * @param PDOStatement $statement
 * @param string $issueId
 * @param string $issueKey
 * @return array{0: int, 1: int}
 * @throws \Random\RandomException
 * @throws \Random\RandomException
 */
function fetchJiraChangelogForIssue(Client $client, PDOStatement $statement, string $issueId, string $issueKey): array
{
    $new = 0;
    $updated = 0;

    $startAt = 0;
    $maxResults = 100;
    $total = 0;

    do {
        try {
            $response = jiraGetWithRetry(
                $client,
                sprintf('/rest/api/3/issue/%s/changelog', $issueId),
                [
                    'query' => [
                        'startAt' => $startAt,
                        'maxResults' => $maxResults,
                    ],
                ],
                $issueKey,
                'changelog'
            );
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = sprintf('Failed to fetch changelog for Jira issue %s', $issueKey);
            $statusCode = 0;
            if ($response instanceof ResponseInterface) {
                $statusCode = $response->getStatusCode();
                $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                $message .= ': ' . extractErrorBody($response);
            } else {
                $message .= ': ' . $exception->getMessage();
            }
            throw new RuntimeException($message, $statusCode, $exception);
        } catch (GuzzleException $exception) {
            throw new RuntimeException(sprintf('Failed to fetch changelog for Jira issue %s: %s', $issueKey, $exception->getMessage()), 0, $exception);
        }

        $payload = decodeJsonResponse($response);
        $histories = isset($payload['values']) && is_array($payload['values']) ? $payload['values'] : [];
        $total = isset($payload['total']) ? (int)$payload['total'] : max($total, count($histories));

        foreach ($histories as $history) {
            if (!is_array($history) || !isset($history['id'])) {
                continue;
            }

            $historyId = (string)$history['id'];
            $authorId = isset($history['author']['accountId']) ? (string)$history['author']['accountId'] : null;
            $createdAt = isset($history['created']) ? normalizeDateTimeString($history['created']) : null;
            $itemsJson = isset($history['items']) ? encodeJson($history['items']) : null;
            $rawPayload = encodeJson($history);

            $statement->execute([
                'id' => $historyId,
                'issue_id' => $issueId,
                'author_account_id' => $authorId,
                'created_at' => $createdAt,
                'items_json' => $itemsJson,
                'raw_payload' => $rawPayload,
            ]);

            if ($statement->rowCount() === 1) {
                $new++;
            } else {
                $updated++;
            }
        }

        $startAt += $maxResults;
    } while ($startAt < $total);

    return [$new, $updated];
}

/**
 * @return array{ready: int, pending: int, failed: int, status_breakdown: array<string, int>}
 */
function transformJournalMappings(PDO $pdo): array
{
    $insertComments = <<<SQL
        INSERT INTO migration_mapping_journals (jira_entity_id, jira_issue_id, entity_type)
        SELECT c.id, c.issue_id, 'COMMENT'
        FROM staging_jira_comments c
        LEFT JOIN migration_mapping_journals map ON map.jira_entity_id = c.id AND map.entity_type = 'COMMENT'
        WHERE map.jira_entity_id IS NULL
    SQL;

    $insertChangelog = <<<SQL
        INSERT INTO migration_mapping_journals (jira_entity_id, jira_issue_id, entity_type)
        SELECT h.id, h.issue_id, 'CHANGELOG'
        FROM staging_jira_changelogs h
        LEFT JOIN migration_mapping_journals map ON map.jira_entity_id = h.id AND map.entity_type = 'CHANGELOG'
        WHERE map.jira_entity_id IS NULL
    SQL;

    try {
        $pdo->exec($insertComments);
        $pdo->exec($insertChangelog);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to insert journal mapping rows: ' . $exception->getMessage(), 0, $exception);
    }

    try {
        $rows = $pdo->query(<<<SQL
            SELECT map.mapping_id, map.migration_status, map.redmine_journal_id, issue_map.redmine_issue_id
            FROM migration_mapping_journals map
            LEFT JOIN migration_mapping_issues issue_map ON issue_map.jira_issue_id = map.jira_issue_id
        SQL);
        $update = $pdo->prepare(
            'UPDATE migration_mapping_journals SET migration_status = :migration_status, last_updated_at = CURRENT_TIMESTAMP
             WHERE mapping_id = :mapping_id'
        );
        if ($rows === false || $update === false) {
            throw new RuntimeException('Failed to read journal mapping statuses.');
        }
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $next = JournalStatus::afterTransform(
                (string)$row['migration_status'],
                $row['redmine_journal_id'] !== null ? (int)$row['redmine_journal_id'] : null,
                $row['redmine_issue_id'] !== null ? (int)$row['redmine_issue_id'] : null
            );
            if ($next !== $row['migration_status']) {
                $update->execute(['migration_status' => $next, 'mapping_id' => $row['mapping_id']]);
            }
        }
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to update journal mapping statuses: ' . $exception->getMessage(), 0, $exception);
    }

    $statusCounts = summariseJournalStatuses($pdo);

    return [
        'ready' => $statusCounts['READY_FOR_PUSH'] ?? 0,
        'pending' => $statusCounts['PENDING'] ?? 0,
        'failed' => $statusCounts['FAILED'] ?? 0,
        'status_breakdown' => $statusCounts,
    ];
}

/**
 * @return array<string, int>
 */
function summariseJournalStatuses(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT migration_status, COUNT(*) AS total
        FROM migration_mapping_journals
        GROUP BY migration_status
        ORDER BY migration_status
    SQL;

    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to summarise journal statuses.');
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

/**
 * @return array{updated: int, skipped_manual: int}
 * @throws JsonException
 * @throws JsonException
 */
function populateProposedJournalNotes(PDO $pdo, int $commentWindow = 0): array
{
    $userLookup = buildRedmineUserLookup($pdo);

    $attachmentMetadata = buildAttachmentMetadataIndex($pdo);
    $adfConverter = new AdfConverter();

    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_entity_id,
            map.jira_issue_id,
            map.entity_type,
            map.proposed_notes,
            map.notes,
            map.automation_hash,
            c.author_account_id AS comment_author,
            c.body_adf AS comment_body_adf,
            c.body_html AS comment_body_html,
            c.created_at AS comment_created_at,
            c.updated_at AS comment_updated_at,
            h.author_account_id AS history_author,
            h.created_at AS history_created_at,
            h.items_json AS history_items_json
        FROM migration_mapping_journals map
        LEFT JOIN staging_jira_comments c ON c.id = map.jira_entity_id AND map.entity_type = 'COMMENT'
        LEFT JOIN staging_jira_changelogs h ON h.id = map.jira_entity_id AND map.entity_type = 'CHANGELOG'
        JOIN migration_mapping_issues issue_map ON issue_map.jira_issue_id = map.jira_issue_id
        WHERE issue_map.redmine_issue_id IS NOT NULL
        ORDER BY map.mapping_id
    SQL;

    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to load journal mappings for proposed note transform.');
    }

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_journals
        SET
            proposed_notes = :proposed_notes,
            proposed_author_id = :proposed_author_id,
            proposed_created_on = :proposed_created_on,
            proposed_updated_on = :proposed_updated_on,
            automation_hash = :automation_hash,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare journal proposed note update statement.');
    }

    $updated = 0;
    $skippedManual = 0;

    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        $mappingId = (int)$row['mapping_id'];
        $jiraIssueId = (string)$row['jira_issue_id'];
        $entityType = (string)$row['entity_type'];

        $storedProposed = $row['proposed_notes'] ?? null;
        $storedNotes = $row['notes'] ?? null;
        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);
        $currentHash = is_string($storedProposed) ? hash('sha256', $storedProposed) : null;
        $warningNote = null;

        if ($storedAutomationHash !== null && $currentHash !== null && !hash_equals($storedAutomationHash, $currentHash)) {
            $skippedManual++;
            continue;
        }

        if ($entityType === 'COMMENT') {
            $createdAt = isset($row['comment_created_at']) ? (string)$row['comment_created_at'] : null;
            $updatedAt = isset($row['comment_updated_at']) ? (string)$row['comment_updated_at'] : null;
            $authorId = isset($row['comment_author']) ? (string)$row['comment_author'] : null;
            $bodyAdf = isset($row['comment_body_adf']) ? (string)$row['comment_body_adf'] : null;
            $bodyHtml = isset($row['comment_body_html']) ? (string)$row['comment_body_html'] : null;

            $proposedAuthorId = resolveRedmineUserId($userLookup, $authorId, null);
            $proposedCreatedOn = normalizeDateTimeString($createdAt);
            $proposedUpdatedOn = normalizeDateTimeString($updatedAt ?? $createdAt);

            $issueAttachments = $attachmentMetadata[$jiraIssueId] ?? [];
            $bodyText = convertJournalBodyToMarkdown($bodyHtml, $bodyAdf, $issueAttachments, $adfConverter);
            $bodyText = replaceJiraUserLinksWithRedmineIds($bodyText, $userLookup);

            $note = $bodyText;

            $journalAttachments = fetchJournalAttachmentsForNote($pdo, $jiraIssueId, $createdAt, $commentWindow);
            $attachmentBlock = buildJournalAttachmentBlock($journalAttachments);
            if ($attachmentBlock !== null) {
                $note = trim($note) === ''
                    ? $attachmentBlock
                    : rtrim($note) . PHP_EOL . PHP_EOL . $attachmentBlock;
            }
        } else {
            $createdAt = isset($row['history_created_at']) ? (string)$row['history_created_at'] : null;
            $itemsJson = isset($row['history_items_json']) ? (string)$row['history_items_json'] : null;

            $authorId = isset($row['history_author']) ? (string)$row['history_author'] : null;
            $proposedAuthorId = resolveRedmineUserId($userLookup, $authorId, null);
            $proposedCreatedOn = normalizeDateTimeString($createdAt);
            $proposedUpdatedOn = $proposedCreatedOn;

            $items = [];
            if ($itemsJson !== null) {
                try {
                    $items = json_decode($itemsJson, true, 512, JSON_THROW_ON_ERROR) ?: [];
                } catch (JsonException) {
                    $items = [];
                }
            }

            $mentionsAttachment = false;
            $attachmentItemIds = [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_array($item) || !isset($item['field'])) {
                        continue;
                    }
                    $field = strtolower((string)$item['field']);
                    if (str_contains($field, 'attachment')) {
                        $mentionsAttachment = true;
                        $attachmentId = $item['to'] ?? null;
                        if ($attachmentId !== null && is_scalar($attachmentId) && preg_match('/^\d+$/', (string)$attachmentId)) {
                            $attachmentItemIds[] = (string)$attachmentId;
                        }
                    }
                }
            }
            $attachmentItemIds = array_values(array_unique($attachmentItemIds));

            // B59: a changelog entry lists only the attachments its items name, never those uploaded around it
            $journalAttachments = [];
            $attachmentBlock = buildJournalAttachmentBlock($journalAttachments);

            if ($attachmentBlock !== null) {
                $note = $attachmentBlock;
            } elseif ($mentionsAttachment) {
                $attachmentBlock = buildAttachmentBlockForChangelogItems($pdo, $jiraIssueId, $attachmentItemIds);
                if ($attachmentBlock !== null) {
                    $note = $attachmentBlock;
                } else {
                $note = '';
                $warningNote = sprintf(
                    'WARN: Jira changelog %s%s mentions attachments but none were mapped.',
                    $row['jira_entity_id'],
                    $createdAt !== null ? ' at ' . $createdAt : ''
                );
                }
            } else {
                $note = '';
            }
        }

        if ($note !== '') {
            $note = replaceJiraUserLinksWithRedmineIds($note, $userLookup);
            // Last step: attachment tokens become their Redmine file names (B14).
            $note = AttachmentNames::rewrite($note, attachmentOriginalNamesByIssue($pdo)[(string)$jiraIssueId] ?? []);
        }

        $note = trim($note);
        $newHash = hash('sha256', $note);

        $warningPrefix = 'WARN: Jira changelog ';
        if ($warningNote !== null) {
            if ($storedNotes === null || trim((string)$storedNotes) === '' || str_starts_with((string)$storedNotes, $warningPrefix)) {
                $notesUpdate = $pdo->prepare('UPDATE migration_mapping_journals SET notes = :notes, last_updated_at = CURRENT_TIMESTAMP WHERE mapping_id = :mapping_id');
                if ($notesUpdate === false) {
                    throw new RuntimeException('Failed to prepare journal warning note update statement.');
                }
                $notesUpdate->execute([
                    'notes' => $warningNote,
                    'mapping_id' => $mappingId,
                ]);
                $notesUpdate->closeCursor();
            }
        } elseif ($storedNotes !== null && str_starts_with((string)$storedNotes, $warningPrefix)) {
            $notesUpdate = $pdo->prepare('UPDATE migration_mapping_journals SET notes = NULL, last_updated_at = CURRENT_TIMESTAMP WHERE mapping_id = :mapping_id');
            if ($notesUpdate === false) {
                throw new RuntimeException('Failed to prepare journal warning clear statement.');
            }
            $notesUpdate->execute(['mapping_id' => $mappingId]);
            $notesUpdate->closeCursor();
        }

        if ($storedProposed === $note && $storedAutomationHash === $newHash) {
            continue;
        }

        $updateStatement->execute([
            'proposed_notes' => $note,
            'proposed_author_id' => $proposedAuthorId,
            'proposed_created_on' => $proposedCreatedOn,
            'proposed_updated_on' => $proposedUpdatedOn,
            'automation_hash' => $newHash,
            'mapping_id' => $mappingId,
        ]);
        $updated++;
    }

    $statement->closeCursor();

    return ['updated' => $updated, 'skipped_manual' => $skippedManual];
}

/**
 * Comments, changelog entries with attachments and the Jira history (decision 41), per issue in Jira's time order.
 *
 * @param array<string, mixed> $config
 * @throws Throwable
 */
function runJournalPushPhase(
    PDO $pdo,
    array $config,
    bool $confirmPush,
    bool $isDryRun,
    bool $useExtendedApi,
    string $extendedApiPrefix,
    bool $withJournals = true,
    bool $withHistory = false
): void
{
    $candidateComments = $withJournals ? fetchPushableComments($pdo) : [];
    $candidateChangelogs = $withJournals ? fetchPushableChangelog($pdo) : [];
    $redmineClient = ClientFactory::redmine(ConfigValue::section($config, 'redmine'));
    $history = null;
    if ($withHistory && !$useExtendedApi) {
        printf("  [warn] The Jira history needs the Extended API (--use-extended-api); left out.%s", PHP_EOL);
    } elseif ($withHistory) {
        $history = prepareHistory($pdo, DefaultAuthor::required($config, 'journals'));
    }
    $historyEvents = $history['events'] ?? [];

    if (count($candidateComments) + count($candidateChangelogs) + count($historyEvents) + count($history['files'] ?? []) === 0) {
        printf("[%s] No journals queued for push.%s", Clock::dateTime(), PHP_EOL);
        return;
    }

    printf(
        "[%s] Journals ready for push: %d comment(s), %d changelog entr(y/ies), %d history journal(s).%s",
        Clock::dateTime(),
        count($candidateComments),
        count($candidateChangelogs),
        count($historyEvents),
        PHP_EOL
    );

    // per issue in Jira's time order, so the highest journal id is the latest journal ("Last updated by")
    $timeline = PushOrder::timeline($candidateComments, $candidateChangelogs, $historyEvents);

    if ($isDryRun) {
        $preview = $history !== null ? historyPreviewFile($config) : null;
        $pendingFiles = $history['files'] ?? [];
        foreach (PushOrder::steps($timeline) as [$kind, $item]) {
            if ($kind === 'history') {
                $first = $item[0];
                printf("  - History, %d journal(s) on issue %s → Redmine #%d%s", count($item), $first['jira_issue_key'], $first['redmine_issue_id'], PHP_EOL);
                if ($preview !== null && $history !== null) {
                    $fileIssueId = isset($pendingFiles[(string)$first['jira_issue_id']]) ? (string)$first['jira_issue_id'] : null;
                    unset($pendingFiles[(string)$first['jira_issue_id']]);
                    writeHistoryPreview($preview, $first, historyRequestBody($item, $history, '(upload)', $fileIssueId));
                }
                continue;
            }
            printf(
                "  - %s %s on issue %s → Redmine #%d%s",
                $kind === 'comment' ? 'Comment' : 'Changelog',
                $item['jira_entity_id'],
                $item['jira_issue_key'],
                $item['redmine_issue_id'],
                PHP_EOL
            );
        }
        if ($preview !== null && $history !== null) {
            foreach ($pendingFiles as $jiraIssueId => $issueFile) {
                writeHistoryPreview($preview, $issueFile, historyRequestBody([], $history, '(upload)', (string)$jiraIssueId));
            }
            fclose($preview);
            printf("  History requests written to %s.%s", historyPreviewPath($config), PHP_EOL);
        }
        printf("  Dry-run active; no journal updates will be sent to Redmine.%s", PHP_EOL);
        return;
    }

    if (!$confirmPush) {
        printf("  Push confirmation missing; rerun with --confirm-push to create journals in Redmine.%s", PHP_EOL);
        return;
    }

    $userLookup = buildRedmineUserLookup($pdo);
    // decision 42: an author without a Redmine account gets the configured user (Anonymous by default), never the API
    // user
    $defaultAuthorId = $useExtendedApi ? DefaultAuthor::required($config, 'journals') : extractDefaultJournalAuthorId($config);
    $attachmentMetadata = buildAttachmentMetadataIndex($pdo);
    $adfConverter = new AdfConverter();

    foreach (PushOrder::steps($timeline) as [$kind, $item]) {
        if ($kind === 'history') {
            if ($history !== null) {
                pushHistorySeries($redmineClient, $pdo, $extendedApiPrefix, $item, $history);
            }
            continue;
        }
        if ($kind === 'comment') {
            processCommentPush(
                $redmineClient,
                $pdo,
                $item,
                $attachmentMetadata,
                $userLookup,
                $defaultAuthorId,
                $useExtendedApi,
                $extendedApiPrefix,
                $adfConverter,
                AttachmentWindow::seconds($config)
            );
            continue;
        }
        processChangelogPush(
            $redmineClient,
            $pdo,
            $item,
            $userLookup,
            $defaultAuthorId,
            $useExtendedApi,
            $extendedApiPrefix
        );
    }
    if ($history !== null) {
        // an issue whose history lost every change it had still gets its file
        foreach ($history['files'] as $jiraIssueId => $issueFile) {
            pushHistorySeries($redmineClient, $pdo, $extendedApiPrefix, [], $history, (string)$jiraIssueId);
        }
        printf("[%s] History pushed: %d journal(s), %d file(s), %d failed request(s).%s", Clock::dateTime(), $history['pushed']['journals'], $history['pushed']['files'], $history['pushed']['failed'], PHP_EOL);
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchPushableComments(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_entity_id,
            map.jira_issue_id,
            issue_map.jira_issue_key,
            issue_map.redmine_issue_id,
            map.proposed_notes,
            map.proposed_author_id,
            map.proposed_created_on,
            map.proposed_updated_on,
            c.author_account_id,
            c.body_adf,
            c.body_html,
            c.created_at,
            c.updated_at,
            c.raw_payload,
            ji.updated_at AS issue_updated_at
        FROM migration_mapping_journals map
        JOIN staging_jira_comments c ON c.id = map.jira_entity_id AND map.entity_type = 'COMMENT'
        JOIN migration_mapping_issues issue_map ON issue_map.jira_issue_id = map.jira_issue_id
        LEFT JOIN staging_jira_issues ji ON ji.id = map.jira_issue_id
        WHERE map.migration_status = 'READY_FOR_PUSH'
          AND issue_map.redmine_issue_id IS NOT NULL
        ORDER BY c.created_at, map.mapping_id
    SQL;

    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to fetch pushable comments.');
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    return $rows;
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchPushableChangelog(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_entity_id,
            map.jira_issue_id,
            issue_map.jira_issue_key,
            issue_map.redmine_issue_id,
            map.proposed_notes,
            map.proposed_author_id,
            map.proposed_created_on,
            map.proposed_updated_on,
            h.author_account_id,
            h.created_at,
            h.items_json,
            ji.updated_at AS issue_updated_at
        FROM migration_mapping_journals map
        JOIN staging_jira_changelogs h ON h.id = map.jira_entity_id AND map.entity_type = 'CHANGELOG'
        JOIN migration_mapping_issues issue_map ON issue_map.jira_issue_id = map.jira_issue_id
        LEFT JOIN staging_jira_issues ji ON ji.id = map.jira_issue_id
        WHERE map.migration_status = 'READY_FOR_PUSH'
          AND issue_map.redmine_issue_id IS NOT NULL
        ORDER BY h.created_at, map.mapping_id
    SQL;

    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to fetch pushable changelog entries.');
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    return $rows;
}

/**
 * Decision 41 (Jan, 2026-10-19): Jira's status, assignee and priority changes as Redmine journals, written in 11's
 * push between the comments (src/History). A value Redmine does not have is skipped with the chain kept connected,
 * and only such an issue gets jira-history.json. Every migrated issue's chain is built in full; what is pushed
 * already (migration_mapping_journals, CHANGELOG: history_journal_id, history_attachment_id) is left out, so a rerun
 * sends only what is missing.
 *
 * @return array{events: list<array<string, mixed>>, files: array<string, array<string, mixed>>, histories: array<string, IssueHistory>, lookups: HistoryLookups, pushed: array{journals: int, files: int, failed: int}, disabled: bool}
 * @throws Throwable
 */
function prepareHistory(PDO $pdo, int $defaultAuthorId): array
{
    $lookups = buildHistoryLookups($pdo, $defaultAuthorId);
    $issues = fetchHistoryIssues($pdo);
    $done = fetchPushedHistory($pdo);

    $events = [];
    $files = [];
    $histories = [];
    $totals = ['issues' => 0, 'journals' => 0, 'details' => 0, 'skipped' => 0, 'dropped' => 0, 'files' => 0, 'mismatches' => 0, 'open_parents' => 0];
    foreach (fetchHistoryEntries($pdo, $issues) as $jiraIssueId => $entries) {
        $jiraIssueId = (string)$jiraIssueId;
        $issue = $issues[$jiraIssueId];
        $history = HistoryChain::build($entries, $lookups);
        $journals = array_values(array_filter(
            $history->journals,
            static fn (array $journal): bool => !isset($done['journals'][$journal['history_id']])
        ));
        $needsFile = $history->needsFile() && !isset($done['files'][$jiraIssueId]);
        if ($journals === [] && !$needsFile) {
            continue;
        }
        $histories[$jiraIssueId] = $history;
        $check = $history->statusCheck($issue['status_id'], $issue['jira_status_id']);
        if ($check !== null) {
            $openParent = $check === 'open parent';
            $totals[$openParent ? 'open_parents' : 'mismatches']++;
            printf(
                "  [%s] %s: the history ends in status %s, the issue has status %s%s.%s",
                $openParent ? 'note' : 'warn',
                $issue['key'],
                var_export($history->last['status'], true),
                var_export($issue['status_id'], true),
                $openParent ? ' (an open parent: 10 changed it, the history ends as in Jira)' : '',
                PHP_EOL
            );
        }
        $row = ['jira_issue_id' => $jiraIssueId, 'jira_issue_key' => $issue['key'], 'redmine_issue_id' => $issue['redmine_issue_id']];
        foreach ($journals as $journal) {
            $events[] = $row + [
                'created_at' => substr(str_replace('T', ' ', $journal['created_on']), 0, 19),
                'mapping_id' => (int)$journal['history_id'],
                'journal' => $journal,
            ];
        }
        if ($needsFile) {
            $files[$jiraIssueId] = $row;
            $totals['files']++;
        }
        $totals['issues']++;
        $totals['journals'] += count($journals);
        $totals['details'] += array_sum(array_map(static fn (array $journal): int => count($journal['details']), $journals));
        $totals['skipped'] += $history->skipped;
        $totals['dropped'] += $history->dropped;
    }

    printf(
        "[%s] History: %d issue(s), %d journal(s), %d detail(s); %d item(s) skipped and connected, %d dropped as no change; %d file(s); status mismatches: %d (open parents: %d).%s",
        Clock::dateTime(),
        $totals['issues'],
        $totals['journals'],
        $totals['details'],
        $totals['skipped'],
        $totals['dropped'],
        $totals['files'],
        $totals['mismatches'],
        $totals['open_parents'],
        PHP_EOL
    );

    return [
        'events' => $events,
        'files' => $files,
        'histories' => $histories,
        'lookups' => $lookups,
        'pushed' => ['journals' => 0, 'files' => 0, 'failed' => 0],
        'disabled' => false,
    ];
}

/**
 * One request to the history endpoint: a series of one issue's history journals, with the issue's file on its first
 * request. An answer without one id per journal, or a refusal, leaves the series unmarked for a rerun; 404 means the
 * Extended API has no history endpoint, and the history is left out for the rest of the run.
 *
 * @param list<array<string, mixed>> $series
 * @param array{events: list<array<string, mixed>>, files: array<string, array<string, mixed>>, histories: array<string, IssueHistory>, lookups: HistoryLookups, pushed: array{journals: int, files: int, failed: int}, disabled: bool} $history
 */
function pushHistorySeries(Client $client, PDO $pdo, string $prefix, array $series, array &$history, ?string $fileIssueId = null): void
{
    if ($history['disabled']) {
        return;
    }
    $issue = $series[0] ?? ($fileIssueId !== null ? ($history['files'][$fileIssueId] ?? null) : null);
    if ($issue === null) {
        return;
    }
    $jiraIssueId = (string)$issue['jira_issue_id'];
    $token = null;
    $stamp = null;
    try {
        if (isset($history['files'][$jiraIssueId])) {
            $issueHistory = $history['histories'][$jiraIssueId];
            $stamp = HistoryFile::stamp($issueHistory, $history['lookups']);
            $content = HistoryFile::content((string)$issue['jira_issue_key'], $jiraIssueId, $issueHistory);
            $token = HistoryPush::upload($client, $prefix, $content, $stamp);
        }
        $body = historyRequestBody($series, $history, $token, $jiraIssueId);
        $answer = HistoryPush::send($client, $prefix, (int)$issue['redmine_issue_id'], $body);
    } catch (BadResponseException $exception) {
        $history['pushed']['failed']++;
        if ($exception->getResponse()->getStatusCode() === 404) {
            $history['disabled'] = true;
            printf("  [error] The Extended API has no history endpoint (POST .../issues/:id/history.json answered 404); the history is left out.%s", PHP_EOL);
            return;
        }
        printf("  [error] History of %s: %s%s", $issue['jira_issue_key'], ExtendedApi::summarizeError($exception), PHP_EOL);
        return;
    } catch (Throwable $exception) {
        $history['pushed']['failed']++;
        printf("  [error] History of %s: %s%s", $issue['jira_issue_key'], $exception->getMessage(), PHP_EOL);
        return;
    }

    foreach ($series as $index => $event) {
        storeHistoryResult($pdo, (string)$event['journal']['history_id'], $jiraIssueId, 'history_journal_id', $answer['journal_ids'][$index]);
    }
    $history['pushed']['journals'] += count($series);
    if ($token !== null && $stamp !== null) {
        if ($answer['attachment_id'] === null) {
            printf("  [error] History of %s: the answer names no attachment for jira-history.json.%s", $issue['jira_issue_key'], PHP_EOL);
        } else {
            storeHistoryResult($pdo, $stamp['history_id'], $jiraIssueId, 'history_attachment_id', $answer['attachment_id']);
            $history['pushed']['files']++;
        }
        unset($history['files'][$jiraIssueId]);
    }
}

/**
 * The body of one history request; the file goes along when it was uploaded (a token) for that issue.
 *
 * @param list<array<string, mixed>> $series
 * @param array{histories: array<string, IssueHistory>, lookups: HistoryLookups} $history
 * @return array<string, mixed>
 */
function historyRequestBody(array $series, array $history, ?string $token, ?string $fileIssueId): array
{
    $file = null;
    if ($token !== null && $fileIssueId !== null && isset($history['histories'][$fileIssueId])) {
        $stamp = HistoryFile::stamp($history['histories'][$fileIssueId], $history['lookups']);
        $file = ['token' => $token, 'author_id' => $stamp['author_id'], 'created_on' => $stamp['created_on']];
    }

    return HistoryRequest::body(array_map(static fn (array $event): array => $event['journal'], $series), $file);
}

/**
 * The rows of migration_mapping_journals keep what the history step pushed, on the Jira history entry it came from.
 */
function storeHistoryResult(PDO $pdo, string $historyId, string $jiraIssueId, string $column, int $redmineId): void
{
    if (!in_array($column, ['history_journal_id', 'history_attachment_id'], true)) {
        throw new InvalidArgumentException($column);
    }
    $statement = $pdo->prepare(
        "INSERT INTO migration_mapping_journals (jira_entity_id, jira_issue_id, entity_type, $column) VALUES (:id, :issue, 'CHANGELOG', :value)"
        . " ON DUPLICATE KEY UPDATE $column = VALUES($column)"
    );
    $statement->execute(['id' => $historyId, 'issue' => $jiraIssueId, 'value' => $redmineId]);
}

/**
 * @param resource $preview
 * @param array<string, mixed> $issue
 * @param array<string, mixed> $body
 */
function writeHistoryPreview($preview, array $issue, array $body): void
{
    fwrite($preview, json_encode([
        'jira_key' => $issue['jira_issue_key'],
        'redmine_issue_id' => $issue['redmine_issue_id'],
        'path' => HistoryRequest::path((int)$issue['redmine_issue_id']),
        'request' => $body,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * Statuses (05), priorities (06) and people (02) with a Redmine value, by the rule 10 uses; authors without an
 * account get the configured default author (decision 42: Anonymous unless Jan sets another).
 */
function buildHistoryLookups(PDO $pdo, int $defaultAuthorId): HistoryLookups
{
    $mapped = "migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')";
    $statuses = [];
    foreach (historyRows($pdo, "SELECT jira_status_id, redmine_status_id FROM migration_mapping_statuses WHERE $mapped AND redmine_status_id IS NOT NULL") as $row) {
        $statuses[(string)$row['jira_status_id']] = (int)$row['redmine_status_id'];
    }
    $priorities = [];
    foreach (historyRows($pdo, "SELECT jira_priority_id, redmine_priority_id FROM migration_mapping_priorities WHERE $mapped AND redmine_priority_id IS NOT NULL") as $row) {
        $priorities[(string)$row['jira_priority_id']] = (int)$row['redmine_priority_id'];
    }
    $users = [];
    $userSql = 'SELECT m.jira_account_id, m.redmine_user_id, COALESCE(r.login, m.proposed_redmine_login) AS login'
        . ' FROM migration_mapping_users m LEFT JOIN staging_redmine_users r ON r.id = m.redmine_user_id'
        . " WHERE m.$mapped AND m.redmine_user_id IS NOT NULL";
    foreach (historyRows($pdo, $userSql) as $row) {
        $users[(string)$row['jira_account_id']] = ['id' => (int)$row['redmine_user_id'], 'login' => $row['login'] !== null ? (string)$row['login'] : null];
    }

    return new HistoryLookups($statuses, $priorities, $users, $defaultAuthorId);
}

/**
 * @return list<array<string, mixed>>
 */
function historyRows(PDO $pdo, string $sql): array
{
    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to read the history lookups.');
    }

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * The migrated issues, with the status 10 gave them and the one their Jira status maps onto.
 *
 * @return array<string, array{key: string, redmine_issue_id: int, status_id: ?int, jira_status_id: ?int}>
 */
function fetchHistoryIssues(PDO $pdo): array
{
    $rows = historyRows(
        $pdo,
        'SELECT jira_issue_id, jira_issue_key, redmine_issue_id, redmine_status_id, proposed_status_id'
        . ' FROM migration_mapping_issues WHERE redmine_issue_id IS NOT NULL'
    );
    $issues = [];
    foreach ($rows as $row) {
        $issues[(string)$row['jira_issue_id']] = [
            'key' => (string)$row['jira_issue_key'],
            'redmine_issue_id' => (int)$row['redmine_issue_id'],
            // the status 10 gave the issue, and the one Jira's current status maps onto
            'status_id' => $row['proposed_status_id'] !== null ? (int)$row['proposed_status_id'] : null,
            'jira_status_id' => $row['redmine_status_id'] !== null ? (int)$row['redmine_status_id'] : null,
        ];
    }

    return $issues;
}

/**
 * What the history step pushed before: the Jira history entries with their journal, the issues with their file.
 *
 * @return array{journals: array<string, true>, files: array<string, true>}
 */
function fetchPushedHistory(PDO $pdo): array
{
    $done = ['journals' => [], 'files' => []];
    $sql = "SELECT jira_entity_id, jira_issue_id, history_journal_id, history_attachment_id FROM migration_mapping_journals"
        . " WHERE entity_type = 'CHANGELOG' AND (history_journal_id IS NOT NULL OR history_attachment_id IS NOT NULL)";
    foreach (historyRows($pdo, $sql) as $row) {
        if ($row['history_journal_id'] !== null) {
            $done['journals'][(string)$row['jira_entity_id']] = true;
        }
        if ($row['history_attachment_id'] !== null) {
            $done['files'][(string)$row['jira_issue_id']] = true;
        }
    }

    return $done;
}

/**
 * The Jira history entries of those issues with their status, assignee and priority items only.
 *
 * @param array<string, mixed> $issues
 * @return array<string, list<array{id: string, created: string, author: ?string, items: list<array<string, mixed>>}>>
 * @throws JsonException
 */
function fetchHistoryEntries(PDO $pdo, array $issues): array
{
    $statement = $pdo->query(
        'SELECT id, issue_id, author_account_id, created_at, items_json FROM staging_jira_changelogs'
        . ' ORDER BY issue_id, created_at, CAST(id AS UNSIGNED)'
    );
    if ($statement === false) {
        throw new RuntimeException('Failed to read the Jira history entries.');
    }
    $entries = [];
    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        $jiraIssueId = (string)$row['issue_id'];
        if (!isset($issues[$jiraIssueId]) || $row['items_json'] === null) {
            continue;
        }
        $items = json_decode((string)$row['items_json'], true, 512, JSON_THROW_ON_ERROR);
        $items = array_values(array_filter(
            is_array($items) ? $items : [],
            static fn (mixed $item): bool => is_array($item) && isset(HistoryChain::FIELDS[$item['field'] ?? ''])
        ));
        if ($items === []) {
            continue;
        }
        $entries[$jiraIssueId][] = [
            'id' => (string)$row['id'],
            'created' => (string)$row['created_at'],
            'author' => $row['author_account_id'] !== null ? (string)$row['author_account_id'] : null,
            'items' => $items,
        ];
    }
    $statement->closeCursor();

    return $entries;
}

/**
 * @param array<string, mixed> $config
 */
function historyPreviewPath(array $config): string
{
    $tmp = $config['paths']['tmp'] ?? null;
    if (!is_string($tmp) || trim($tmp) === '') {
        throw new RuntimeException('paths.tmp is not configured: the history preview goes to the attachment directory.');
    }
    if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
        throw new RuntimeException(sprintf('Cannot create %s.', $tmp));
    }

    return rtrim($tmp, '/') . '/history-preview.jsonl';
}

/**
 * @param array<string, mixed> $config
 * @return resource
 */
function historyPreviewFile(array $config)
{
    $preview = fopen(historyPreviewPath($config), 'wb');
    if ($preview === false) {
        throw new RuntimeException(sprintf('Cannot write the history preview %s.', historyPreviewPath($config)));
    }

    return $preview;
}

/**
 * @param array<string, mixed> $comment
 * @param array<string, array<string, string>> $attachmentMetadata
 * @param array<string, array{redmine_user_id: int}> $userLookup
 * @throws JsonException
 */
function processCommentPush(
    Client $client,
    PDO $pdo,
    array $comment,
    array $attachmentMetadata,
    array $userLookup,
    ?int $defaultAuthorId,
    bool $useExtendedApi,
    string $extendedApiPrefix,
    AdfConverter $adfConverter,
    int $commentWindow = 0
): void
{
    $mappingId = (int)$comment['mapping_id'];
    $jiraIssueId = (string)$comment['jira_issue_id'];
    $jiraIssueKey = (string)$comment['jira_issue_key'];
    $jiraCommentId = (string)$comment['jira_entity_id'];
    $redmineIssueId = (int)$comment['redmine_issue_id'];

    $authorId = isset($comment['author_account_id']) ? (string)$comment['author_account_id'] : null;
    $createdAt = isset($comment['created_at']) ? (string)$comment['created_at'] : null;
    $updatedAt = isset($comment['updated_at']) ? (string)$comment['updated_at'] : null;
    $bodyAdf = isset($comment['body_adf']) ? (string)$comment['body_adf'] : null;
    $bodyHtml = isset($comment['body_html']) ? (string)$comment['body_html'] : null;
    $proposedNotes = isset($comment['proposed_notes']) ? (string)$comment['proposed_notes'] : null;
    $proposedAuthorId = isset($comment['proposed_author_id']) ? (int)$comment['proposed_author_id'] : null;
    $proposedCreatedOn = isset($comment['proposed_created_on']) ? (string)$comment['proposed_created_on'] : null;
    $proposedUpdatedOn = isset($comment['proposed_updated_on']) ? (string)$comment['proposed_updated_on'] : null;

    if ($proposedNotes !== null && trim($proposedNotes) !== '') {
        $note = $proposedNotes;
    } else {
        $issueAttachments = $attachmentMetadata[$jiraIssueId] ?? [];
        $bodyText = convertJournalBodyToMarkdown($bodyHtml, $bodyAdf, $issueAttachments, $adfConverter);
        // safety: replace any remaining profile links (ADF -> Markdown cases) to user#id
        $bodyText = replaceJiraUserLinksWithRedmineIds($bodyText, $userLookup);

        // safety: replace any remaining browse/selectedIssue links to #redmine_id using mapping
        // build jira->redmine map once per push
        static $jiraToRedmineMap = null;
        if ($jiraToRedmineMap === null) {
            $jiraToRedmineMap = loadIssueLinkMap($pdo);
        }
        if (!empty($jiraToRedmineMap)) {
            $bodyText = inlineReplaceJiraIssueKeysWithHashes($bodyText, $jiraToRedmineMap);
        }

        // build base note
        $note = $bodyText;

        // fetch only attachments that are relevant and already uploaded / have sharepoint links
        $journalAttachments = fetchJournalAttachmentsForNote($pdo, $jiraIssueId, $createdAt, $commentWindow);

        // append journal attachment references (read-only, no DB modifications here)
        $attachmentBlock = buildJournalAttachmentBlock($journalAttachments);
        if ($attachmentBlock !== null) {
            $note = trim($note) === ''
                ? $attachmentBlock
                : rtrim($note) . PHP_EOL . PHP_EOL . $attachmentBlock;
        }
    }

    // add token only when we don't use the extended API (fallback matching)
    if (!$useExtendedApi) {
        $token = sprintf('<!-- MIGRATE:%d -->', $mappingId);
        $note = rtrim($note) . PHP_EOL . PHP_EOL . $token;
    } else {
        $token = null;
    }

    // only include journal overrides (user/created_on/updated_on) when using the extended API
    $journalOverrides = $useExtendedApi
        ? buildJournalOverridesWithProposed(
            $authorId,
            $createdAt,
            $updatedAt,
            $userLookup,
            $defaultAuthorId,
            $proposedAuthorId,
            $proposedCreatedOn,
            $proposedUpdatedOn,
            true
        )
        : [];

    // the issue keeps Jira's updated time, as 10 set it: a journal does not move it (decision 41)
    $issueUpdatedOn = $useExtendedApi ? buildIssueUpdatedOnOverride($comment['issue_updated_at'] ?? $updatedAt ?? $createdAt) : null;

    if ($useExtendedApi && $journalOverrides !== []) {
        $issuePayload = ['notes' => $note, 'journal' => $journalOverrides];
        if ($issueUpdatedOn !== null) {
            $issuePayload['updated_on'] = $issueUpdatedOn;
        }
    } else {
        $issuePayload = ['notes' => $note];
        if ($issueUpdatedOn !== null) {
            $issuePayload['updated_on'] = $issueUpdatedOn;
        }
    }

    $payload = ['issue' => array_filter($issuePayload, static fn($v) => $v !== null && $v !== [])];

    $endpoint = $useExtendedApi
        ? ExtendedApi::path($extendedApiPrefix, sprintf('issues/%d.json', $redmineIssueId))
        : sprintf('issues/%d.json', $redmineIssueId);

    $options = ['json' => $payload];
    if ($useExtendedApi) {
        $options['query'] = ['notify' => 'false', 'send_notification' => 0];
    }

    // execute the request and capture the response
    try {
        $response = $client->request($useExtendedApi ? 'patch' : 'put', $endpoint, $options);
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $message = sprintf('Failed to create journal for Jira comment %s on issue %s', $jiraCommentId, $jiraIssueKey);
        if ($response instanceof ResponseInterface) {
            $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
            $message .= ': ' . extractErrorBody($response);
        } else {
            $message .= ': ' . $exception->getMessage();
        }
        markJournalPushFailure($pdo, $mappingId, $message);
        printf("  [error] %s%s", $message, PHP_EOL);
        return;
    } catch (GuzzleException $exception) {
        $message = sprintf('Failed to create journal for Jira comment %s on issue %s: %s', $jiraCommentId, $jiraIssueKey, $exception->getMessage());
        markJournalPushFailure($pdo, $mappingId, $message);
        printf("  [error] %s%s", $message, PHP_EOL);
        return;
    }

    // If we're using the extended API, prefer the returned journal id
    if ($useExtendedApi) {
        $respBody = decodeJsonResponse($response);

        // Best-effort locations: top-level 'journal' or nested under 'extended_api'
        $journalId = null;
        if (isset($respBody['journal']['id'])) {
            $journalId = (int)$respBody['journal']['id'];
        } elseif (isset($respBody['extended_api']['journal']['id'])) {
            $journalId = (int)$respBody['extended_api']['journal']['id'];
        }

        if ($journalId !== null) {
            // success, mark and continue
            markJournalPushSuccess($pdo, $mappingId, $journalId, $note);
            printf("  [journal] (extended api) Jira %s %s → Redmine issue #%d, journal #%d%s",
                'comment',
                $jiraCommentId,
                $redmineIssueId,
                $journalId,
                PHP_EOL
            );
            return;
        }

        // Extended API did not return a journal id — treat as failure so it gets investigated
        $msg = sprintf('Extended API did not return journal id for Jira %s on Redmine issue %s', 'comment ' . $jiraCommentId, $jiraIssueKey);
        markJournalPushFailure($pdo, $mappingId, $msg);
        printf("  [error] %s%s", $msg, PHP_EOL);
        return;
    }

    // If we reach here, not using extended API — do the token/time-based search
    $journalId = fetchLatestJournalId($client, $redmineIssueId, $token, $createdAt);

    if ($journalId === null) {
        $msg = sprintf('Unable to confirm Redmine journal for Jira %s on issue %s', 'comment ' . $jiraCommentId, $jiraIssueKey);
        markJournalPushFailure($pdo, $mappingId, $msg);
        printf("  [error] %s%s", $msg, PHP_EOL);
        return;
    }

    markJournalPushSuccess($pdo, $mappingId, $journalId, $note);
}

/**
 * @param array<string, mixed> $history
 * @param array<string, array{redmine_user_id: int}> $userLookup
 */
function processChangelogPush(
    Client $client,
    PDO $pdo,
    array $history,
    array $userLookup,
    ?int $defaultAuthorId,
    bool $useExtendedApi,
    string $extendedApiPrefix
): void
{
    $mappingId = (int)$history['mapping_id'];
    $jiraIssueId = (string)$history['jira_issue_id'];
    $jiraIssueKey = (string)$history['jira_issue_key'];
    $historyId = (string)$history['jira_entity_id'];
    $redmineIssueId = (int)$history['redmine_issue_id'];

    $createdAt = isset($history['created_at']) ? (string)$history['created_at'] : null;
    $itemsJson = isset($history['items_json']) ? (string)$history['items_json'] : null;
    $proposedNotes = isset($history['proposed_notes']) ? (string)$history['proposed_notes'] : null;
    $proposedAuthorId = isset($history['proposed_author_id']) ? (int)$history['proposed_author_id'] : null;
    $proposedCreatedOn = isset($history['proposed_created_on']) ? (string)$history['proposed_created_on'] : null;
    $proposedUpdatedOn = isset($history['proposed_updated_on']) ? (string)$history['proposed_updated_on'] : null;

    // If a user has explicitly provided proposed_notes, use them.
    if ($proposedNotes !== null && trim($proposedNotes) !== '') {
        $baseNote = $proposedNotes;
    } else {
        // detect attachments referenced for this journal
        // B59: only the attachments its items name (below), never those uploaded around it
        $journalAttachments = [];

        // detect whether items mention attachments (best-effort)
        $items = [];
        if ($itemsJson !== null) {
            try {
                $items = json_decode($itemsJson, true, 512, JSON_THROW_ON_ERROR) ?: [];
            } catch (JsonException) {
                $items = [];
            }
        }
    $mentionsAttachment = false;
    $attachmentItemIds = [];
    if (is_array($items)) {
        foreach ($items as $it) {
            if (!is_array($it) || !isset($it['field'])) continue;
            $f = strtolower((string)$it['field']);
            if (str_contains($f, 'attachment')) {
                $mentionsAttachment = true;
                $attachmentId = $it['to'] ?? null;
                if ($attachmentId !== null && is_scalar($attachmentId) && preg_match('/^\d+$/', (string)$attachmentId)) {
                    $attachmentItemIds[] = (string)$attachmentId;
                }
            }
        }
    }
    $attachmentItemIds = array_values(array_unique($attachmentItemIds));

        // If neither attachments mapped nor a mention of attachments, skip (we don't want to pollute Redmine).
        if (empty($journalAttachments) && !$mentionsAttachment) {
            $msg = 'Skipped changelog push: no proposed note and no attachments to show.';
            markJournalPushSkipped($pdo, $mappingId, $msg);
            printf("  [skip] %s (jira history %s on %s)%s", $msg, $historyId, $jiraIssueKey, PHP_EOL);
            return;
        }

        $attachmentBlock = buildJournalAttachmentBlock($journalAttachments);
        if ($attachmentBlock === null && $mentionsAttachment) {
            $attachmentBlock = buildAttachmentBlockForChangelogItems($pdo, $jiraIssueId, $attachmentItemIds);
        }

        if ($attachmentBlock === null && $mentionsAttachment) {
            $msg = sprintf('WARN: Jira changelog %s mentions attachments but none were mapped.', $historyId);
            markJournalPushSkipped($pdo, $mappingId, $msg);
            printf("  [warn] %s (jira history %s on %s)%s", $msg, $historyId, $jiraIssueKey, PHP_EOL);
            return;
        }

        $baseNote = $attachmentBlock ?? '';
    }

    // Add invisible migration token (if not using extended API)
    if (!$useExtendedApi) {
        $token = sprintf('<!-- MIGRATE:%d -->', $mappingId);
        $baseNote = rtrim($baseNote) . PHP_EOL . PHP_EOL . $token;
    } else {
        $token = null;
    }

    // Build journal overrides same as for comments
    $journalOverrides = $useExtendedApi
        ? buildJournalOverridesWithProposed(
            $history['author_account_id'] ?? null,
            $createdAt,
            $createdAt,
            $userLookup,
            $defaultAuthorId,
            $proposedAuthorId,
            $proposedCreatedOn,
            $proposedUpdatedOn,
            true
        )
        : [];

    // the issue keeps Jira's updated time, as 10 set it: a journal does not move it (decision 41)
    $issueUpdatedOn = $useExtendedApi ? buildIssueUpdatedOnOverride($history['issue_updated_at'] ?? $createdAt) : null;

    if ($useExtendedApi && $journalOverrides !== []) {
        $issuePayload = ['notes' => $baseNote, 'journal' => $journalOverrides];
        if ($issueUpdatedOn !== null) {
            $issuePayload['updated_on'] = $issueUpdatedOn;
        }
    } else {
        $issuePayload = ['notes' => $baseNote];
        if ($issueUpdatedOn !== null) {
            $issuePayload['updated_on'] = $issueUpdatedOn;
        }
    }

    $payload = ['issue' => array_filter($issuePayload, static fn($v) => $v !== null && $v !== [])];

    $endpoint = $useExtendedApi
        ? ExtendedApi::path($extendedApiPrefix, sprintf('issues/%d.json', $redmineIssueId))
        : sprintf('issues/%d.json', $redmineIssueId);

    $options = ['json' => $payload];
    if ($useExtendedApi) {
        $options['query'] = ['notify' => 'false', 'send_notification' => 0];
    }

    // execute and handle response similar to comments...
    try {
        $response = $client->request($useExtendedApi ? 'patch' : 'put', $endpoint, $options);
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $message = sprintf('Failed to create journal for Jira %s on issue %s', 'history ' . $historyId, $jiraIssueKey);
        if ($response instanceof ResponseInterface) {
            $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
            $message .= ': ' . extractErrorBody($response);
        } else {
            $message .= ': ' . $exception->getMessage();
        }
        markJournalPushFailure($pdo, $mappingId, $message);
        printf("  [error] %s%s", $message, PHP_EOL);
        return;
    } catch (GuzzleException $exception) {
        $message = sprintf('Failed to create journal for Jira %s on issue %s: %s', 'history ' . $historyId, $jiraIssueKey, $exception->getMessage());
        markJournalPushFailure($pdo, $mappingId, $message);
        printf("  [error] %s%s", $message, PHP_EOL);
        return;
    }

    // handle response (extended api vs token/time like in comment flow)
    if ($useExtendedApi) {
        $respBody = decodeJsonResponse($response);
        $journalId = null;
        if (isset($respBody['journal']['id'])) {
            $journalId = (int)$respBody['journal']['id'];
        } elseif (isset($respBody['extended_api']['journal']['id'])) {
            $journalId = (int)$respBody['extended_api']['journal']['id'];
        }

        if ($journalId !== null) {
            markJournalPushSuccess($pdo, $mappingId, $journalId);
            printf("  [journal] (extended api) Jira history %s → Redmine issue #%d, journal #%d%s",
                $historyId,
                $redmineIssueId,
                $journalId,
                PHP_EOL
            );
            return;
        }

        $msg = sprintf('Extended API did not return journal id for Jira history %s on Redmine issue %s', $historyId, $jiraIssueKey);
        markJournalPushFailure($pdo, $mappingId, $msg);
        printf("  [error] %s%s", $msg, PHP_EOL);
        return;
    }

    $journalId = fetchLatestJournalId($client, $redmineIssueId, $token, $createdAt);

    if ($journalId === null) {
        $msg = sprintf('Unable to confirm Redmine journal for Jira history %s on issue %s', $historyId, $jiraIssueKey);
        markJournalPushFailure($pdo, $mappingId, $msg);
        printf("  [error] %s%s", $msg, PHP_EOL);
        return;
    }

    markJournalPushSuccess($pdo, $mappingId, $journalId);
}

/**
 * Find the journal id by migration token, then by time-based fallback, else latest.
 *
 * @param Client $client
 * @param int $redmineIssueId
 * @param string|null $token The migration token text, e.g. "<!-- MIGRATE:1234 -->"
 * @param string|null $expectedCreatedAt Jira created timestamp (used for time-based fallback)
 * @param int $toleranceSeconds tolerance window for time-based match
 * @return int|null
 */
function fetchLatestJournalId(Client $client, int $redmineIssueId, ?string $token, ?string $expectedCreatedAt = null, int $toleranceSeconds = 30): ?int
{
    try {
        $response = $client->get(sprintf('/issues/%d.json', $redmineIssueId), ['query' => ['include' => 'journals']]);
    } catch (Throwable) {
        return null;
    }

    $payload = decodeJsonResponse($response);
    $journals = isset($payload['issue']['journals']) && is_array($payload['issue']['journals']) ? $payload['issue']['journals'] : [];

    $latestId = null;
    $timeCandidates = [];

    foreach ($journals as $journal) {
        if (!is_array($journal) || !isset($journal['id'])) continue;

        $jid = (int)$journal['id'];
        $latestId = $latestId === null || $jid > $latestId ? $jid : $latestId;

        $note = isset($journal['notes']) ? (string)$journal['notes'] : '';

        // 1) token search (exact substring) - most reliable
        if ($token !== null && $token !== '' && mb_strpos($note, $token) !== false) {
            return $jid;
        }

        // 2) time-based collection
        if ($expectedCreatedAt !== null && isset($journal['created_on'])) {
            $journalTs = strtotime($journal['created_on']);
            $expectedTs = strtotime($expectedCreatedAt);
            if ($journalTs !== false && $expectedTs !== false && abs($journalTs - $expectedTs) <= $toleranceSeconds) {
                $timeCandidates[] = $jid;
            }
        }
    }

    // if exactly one time candidate, return it
    if (count($timeCandidates) === 1) {
        return $timeCandidates[0];
    }

    // fallback: return latest journal id as best-effort
    return $latestId;
}

/**
 * @param string|null $pushedNotes the comment as sent: its hash lets the relink step (B52) see later edits
 */
function markJournalPushSuccess(PDO $pdo, int $mappingId, ?int $journalId, ?string $pushedNotes = null): void
{
    $sql = <<<SQL
        UPDATE migration_mapping_journals
        SET
            redmine_journal_id = :redmine_journal_id,
            migration_status = 'SUCCESS',
            notes = NULL,
            pushed_text_hash = COALESCE(:pushed_text_hash, pushed_text_hash),
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL;

    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare journal success update.');
    }

    $statement->execute([
        'redmine_journal_id' => $journalId,
        'pushed_text_hash' => $pushedNotes !== null ? Relink::hash($pushedNotes) : null,
        'mapping_id' => $mappingId,
    ]);
    $statement->closeCursor();
}

function markJournalPushFailure(PDO $pdo, int $mappingId, string $message): void
{
    $sql = <<<SQL
        UPDATE migration_mapping_journals
        SET
            migration_status = 'FAILED',
            notes = :notes,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL;

    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare journal failure update.');
    }

    $statement->execute([
        'notes' => $message,
        'mapping_id' => $mappingId,
    ]);
    $statement->closeCursor();
}

function updateAttachmentMappingAfterPush(PDO $pdo, int $mappingId, ?int $redmineAttachmentId, string $status, ?string $notes, ?int $redmineIssueId = null): void
{
    $sql = <<<SQL
        UPDATE migration_mapping_attachments
        SET
            redmine_attachment_id = :redmine_attachment_id,
            redmine_issue_id = COALESCE(:redmine_issue_id, redmine_issue_id),
            migration_status = :migration_status,
            notes = :notes,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL;

    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare attachment mapping update statement.');
    }

    $statement->execute([
        'redmine_attachment_id' => $redmineAttachmentId,
        'redmine_issue_id' => $redmineIssueId,
        'migration_status' => $status,
        'notes' => $notes,
        'mapping_id' => $mappingId,
    ]);
    $statement->closeCursor();
}

/**
 * @return array<string, array<string, string>>
 */
function buildAttachmentMetadataIndex(PDO $pdo): array
{
    $sql = 'SELECT id, issue_id, filename FROM staging_jira_attachments';
    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build attachment metadata index: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to build attachment metadata index.');
    }

    $index = [];
    while (true) {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            break;
        }

        $issueId = isset($row['issue_id']) ? (string)$row['issue_id'] : '';
        $attachmentId = isset($row['id']) ? (string)$row['id'] : '';
        if ($issueId === '' || $attachmentId === '') {
            continue;
        }

        $filename = isset($row['filename']) ? (string)$row['filename'] : '';
        if (!isset($index[$issueId])) {
            $index[$issueId] = [];
        }
        $index[$issueId][$attachmentId] = buildRedmineAttachmentFilename($attachmentId, $filename);
    }

    $statement->closeCursor();

    return $index;
}

function buildRedmineAttachmentFilename(string $jiraAttachmentId, string $originalFilename): string
{
    $name = trim($originalFilename);
    if ($name === '') {
        $name = 'attachment';
    }

    $name = sanitizeAttachmentFileName($name);

    // Prefix with Jira attachment id (uniqueness)
    $filename = $jiraAttachmentId . '__' . $name;

    // Hard cap length (safe for Redmine + SharePoint)
    $maxLength = 180;
    if (strlen($filename) > $maxLength) {
        $ext = '';
        if (preg_match('/(\.[A-Za-z0-9]{1,10})$/', $filename, $m)) {
            $ext = $m[1];
        }
        $base = substr($filename, 0, $maxLength - strlen($ext));
        $filename = $base . $ext;
    }

    return $filename;
}

function sanitizeAttachmentFileName(string $filename): string
{
    $sanitized = preg_replace('/[^A-Za-z0-9._-]/u', '_', $filename);
    if ($sanitized === null) {
        return 'attachment';
    }

    $sanitized = trim($sanitized, '_');
    if ($sanitized === '') {
        $sanitized = 'attachment';
    }

    return $sanitized;
}

/**
 * @throws JsonException
 */
function convertJournalBodyToMarkdown(?string $bodyHtml, ?string $bodyAdf, array $issueAttachments, AdfConverter $adfConverter): string
{
    $bodyText = null;

    if ($bodyHtml !== null && !str_contains($bodyHtml, "<!-- ADF macro (type = 'table') -->")) {
        $bodyText = convertJiraHtmlToMarkdown($bodyHtml, $issueAttachments, $bodyAdf);
    }

    if ($bodyText === null && $bodyAdf !== null) {
        $bodyText = convertDescriptionToMarkdown($bodyAdf, $adfConverter);
    }

    if ($bodyText === null && $bodyAdf !== null) {
        $bodyText = convertJiraAdfToPlaintext($bodyAdf);
    }

    if ($bodyText !== null) {
        // Link markup Jira showed as text becomes a link (B42); user avatars out, as in descriptions.
        $bodyText = removeJiraAvatarImages(restoreLiteralMarkupLinks($bodyText));
    }

    if ($bodyText !== null && $issueAttachments !== []) {
        $bodyText = normalizeJournalAttachmentLinks($bodyText, $issueAttachments);
    }

    return $bodyText ?? '';
}

/**
 * Normalize attachment links in journal text (Markdown) using issue attachments.
 *
 * @param array<string, string> $issueAttachments [jira_attachment_id => unique_name]
 */
function normalizeJournalAttachmentLinks(string $text, array $issueAttachments): string
{
    if (trim($text) === '' || $issueAttachments === []) {
        return $text;
    }

    $attachmentsMap = [];
    foreach ($issueAttachments as $aid => $unique) {
        $attachmentsMap[(string)$aid] = [
            'unique' => (string)$unique,
            'sharepoint' => null,
        ];
    }

    // Markdown-style replacements: ![alt](URL) and [label](URL)
    $text = preg_replace_callback(
        '/(!?\[[^]]*])\(\s*([^)]+?)\s*\)/m',
        function ($m) use ($attachmentsMap) {
            $label = $m[1];
            $raw   = trim($m[2]);
            if (preg_match('/^(.*?)\s+"[^"]*"$/', $raw, $parts)) {
                $url = trim($parts[1]);
            } else {
                $url = $raw;
            }
            $isImageLabel = str_starts_with($label, '!');

            if (str_starts_with($url, 'attachment:')) {
                $unique = substr($url, strlen('attachment:'));
                $mapped = mapAttachmentUrlToTarget($unique, $attachmentsMap);
                if (preg_match('#^https?://#i', $mapped)) {
                    return $label . '(' . $mapped . ')';
                }
                if (!$isImageLabel && preg_match('/^\d+__.+$/', $mapped)) {
                    return $label . '(attachment:' . $mapped . ')';
                }
                return $label . '(' . $mapped . ')';
            }

            $new = mapAttachmentUrlToTarget($url, $attachmentsMap);
            if (preg_match('#^https?://#i', $new)) {
                return $label . '(' . $new . ')';
            }
            if (!$isImageLabel && preg_match('/^\d+__.+$/', $new)) {
                $new = 'attachment:' . $new;
            }

            return $label . '(' . $new . ')';
        },
        $text
    );

    // plain "attachment:123__filename" tokens -> resolve to SharePoint if available
    $text = preg_replace_callback(
        '/\battachment:(\d+__[^)\s,;]+)/i',
        function ($m) use ($attachmentsMap) {
            $unique = $m[1];
            $mapped = mapAttachmentUrlToTarget($unique, $attachmentsMap);
            if (preg_match('#^https?://#i', $mapped)) {
                return $mapped;
            }
            return $m[0];
        },
        $text
    );

    // bare unique filenames such as "10388__foo"
    $text = preg_replace_callback(
        '#(?<![/:])\b(\d+__[^)\s,;]+)#',
        function ($m) use ($attachmentsMap) {
            $unique = $m[1];
            $mapped = mapAttachmentUrlToTarget($unique, $attachmentsMap);
            if (preg_match('#^https?://#i', $mapped)) {
                return $mapped;
            }
            return $m[0];
        },
        $text
    );

    // leftover absolute REST API attachment URLs
    $text = preg_replace_callback(
        '#https?://[^\s)\]}]+/rest/api/\d+/attachment/(?:content|thumbnail)/(\d+)[^\s)\]}]*#i',
        function ($m) use ($attachmentsMap) {
            $id = $m[1] ?? null;
            if ($id === null || !isset($attachmentsMap[$id])) return $m[0];
            $meta = $attachmentsMap[$id];
            return !empty($meta['sharepoint']) ? $meta['sharepoint'] : $meta['unique'];
        },
        $text
    );

    // relative REST API attachment URLs
    $text = preg_replace_callback(
        '#/rest/api/\d+/attachment/(?:content|thumbnail)/(\d+)[^\s)\]}]*#i',
        function ($m) use ($attachmentsMap) {
            $id = $m[1] ?? null;
            if ($id === null || !isset($attachmentsMap[$id])) return $m[0];
            $meta = $attachmentsMap[$id];
            return !empty($meta['sharepoint']) ? $meta['sharepoint'] : $meta['unique'];
        },
        $text
    );

    // patterns like "/attachment/1234" or "/secure/attachment/1234"
    $text = preg_replace_callback(
        '#(?:/secure/attachment/|/attachment/|/attachment/content/)(\d+)#i',
        function ($m) use ($attachmentsMap) {
            $id = $m[1] ?? null;
            if ($id === null || !isset($attachmentsMap[$id])) return $m[0];
            $meta = $attachmentsMap[$id];
            return !empty($meta['sharepoint']) ? $meta['sharepoint'] : $meta['unique'];
        },
        $text
    );

    return normalizeRedmineReferenceSpacing($text);
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

function normalizeDateTimeString(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($trimmed);
    } catch (Throwable) {
        return null;
    }

    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function normalizeStoredAutomationHash(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    return $trimmed !== '' ? $trimmed : null;
}

/**
 * @return array<string, array{redmine_user_id: int}>
 */
function buildRedmineUserLookup(PDO $pdo): array
{
    // The login turns mentions into @login (decision 4).
    $statement = $pdo->query(
        'SELECT m.jira_account_id, m.redmine_user_id, m.migration_status, COALESCE(r.login, m.proposed_redmine_login) AS redmine_login'
        . ' FROM migration_mapping_users m LEFT JOIN staging_redmine_users r ON r.id = m.redmine_user_id'
    );
    if ($statement === false) {
        throw new RuntimeException('Failed to load user mappings.');
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    $lookup = [];
    foreach ($rows as $row) {
        $status = isset($row['migration_status']) ? (string)$row['migration_status'] : '';
        if (!in_array($status, ['MATCH_FOUND', 'CREATION_SUCCESS'], true)) {
            continue;
        }

        if (!isset($row['jira_account_id'], $row['redmine_user_id']) || $row['redmine_user_id'] === null) {
            continue;
        }

        $lookup[(string)$row['jira_account_id']] = ['redmine_user_id' => (int)$row['redmine_user_id']];
    }

    return $lookup;
}

/**
 * @param array<string, array{redmine_user_id: int}> $lookup
 */
function resolveRedmineUserId(array $lookup, ?string $jiraAccountId, ?int $defaultAuthorId): ?int
{
    if ($jiraAccountId !== null && isset($lookup[$jiraAccountId])) {
        return $lookup[$jiraAccountId]['redmine_user_id'];
    }

    return $defaultAuthorId;
}

/**
 * @param array<string, array{redmine_user_id: int}> $userLookup
 * @return array<string, int|string>
 */
function buildJournalOverrides(
    ?string $authorAccountId,
    ?string $createdAt,
    ?string $updatedAt,
    array $userLookup,
    ?int $defaultAuthorId,
    bool $includeTimestamps = false
): array {
    $overrides = [];

    $redmineUserId = resolveRedmineUserId($userLookup, $authorAccountId, $defaultAuthorId);
    // only include user/updated_by when we explicitly want to include timestamps (i.e. using extended API)
    if ($includeTimestamps && $redmineUserId !== null) {
        $overrides['user_id'] = $redmineUserId;
        $overrides['updated_by_id'] = $redmineUserId;
    }

    if ($includeTimestamps) {
        $created = normalizeJiraTimestamp($createdAt);
        if ($created !== null) {
            $overrides['created_on'] = $created;
        }

        $updated = normalizeJiraTimestamp($updatedAt ?? $createdAt);
        if ($updated !== null) {
            $overrides['updated_on'] = $updated;
        }
    }

    return $overrides;
}

/**
 * @param array<string, array{redmine_user_id: int}> $userLookup
 * @return array<string, int|string>
 */
function buildJournalOverridesWithProposed(
    ?string $authorAccountId,
    ?string $createdAt,
    ?string $updatedAt,
    array $userLookup,
    ?int $defaultAuthorId,
    ?int $proposedAuthorId,
    ?string $proposedCreatedOn,
    ?string $proposedUpdatedOn,
    bool $includeTimestamps = false
): array {
    if (!$includeTimestamps) {
        return [];
    }

    $overrides = [];

    $redmineUserId = $proposedAuthorId ?? resolveRedmineUserId($userLookup, $authorAccountId, $defaultAuthorId);
    if ($redmineUserId !== null) {
        $overrides['user_id'] = $redmineUserId;
        $overrides['updated_by_id'] = $redmineUserId;
    }

    $created = normalizeJiraTimestamp($proposedCreatedOn ?? $createdAt);
    if ($created !== null) {
        $overrides['created_on'] = $created;
    }

    $updated = normalizeJiraTimestamp($proposedUpdatedOn ?? $updatedAt ?? $createdAt);
    if ($updated !== null) {
        $overrides['updated_on'] = $updated;
    }

    return $overrides;
}

function buildIssueUpdatedOnOverride(?string $updatedAt): ?string
{
    return normalizeJiraTimestamp($updatedAt);
}

function extractDefaultJournalAuthorId(array $config): ?int
{
    $migrationConfig = $config['migration'] ?? [];
    $journalConfig = isset($migrationConfig['journals']) && is_array($migrationConfig['journals'])
        ? $migrationConfig['journals']
        : [];

    if (!isset($journalConfig['default_redmine_author_id'])) {
        return null;
    }

    $value = $journalConfig['default_redmine_author_id'];
    if (!is_numeric($value)) {
        return null;
    }

    $intValue = (int)$value;

    return $intValue > 0 ? $intValue : null;
}

function normalizeJiraTimestamp(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    if (is_string($value) && trim($value) === '') {
        return null;
    }

    try {
        $dateTime = new DateTimeImmutable((string)$value);
    } catch (Throwable) {
        return null;
    }

    return $dateTime->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
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
 * @throws BadResponseException
 * @throws GuzzleException
 * @throws \Random\RandomException
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
            $status = $response instanceof ResponseInterface ? $response->getStatusCode() : null;

            if ($status !== 429) {
                throw $exception;
            }

            $attempt++;
            if ($attempt > JIRA_RATE_LIMIT_MAX_RETRIES) {
                throw $exception;
            }

            $retryAfter = null;
            if ($response instanceof ResponseInterface) {
                $headers = $response->getHeader('Retry-After');
                if ($headers !== []) {
                    $headerValue = trim((string)($headers[0] ?? ''));
                    if ($headerValue !== '' && ctype_digit($headerValue)) {
                        $retryAfter = (int)$headerValue;
                    }
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
 * Read-only fetch of attachments that should be referenced for a given journal note.
 * Only returns attachments that are already uploaded (migration_status = 'SUCCESS') or have a sharepoint_url.
 *
 * @return array<int,array{mapping_id:int,jira_attachment_id:string,filename:string,sharepoint_url:string|null,redmine_attachment_id:int|null,created_at:string|null}>
 */
function fetchJournalAttachmentsForNote(PDO $pdo, string $jiraIssueId, ?string $journalCreatedAt, int $windowSeconds): array
{
    $sql = <<<SQL
SELECT
    map.mapping_id,
    map.jira_attachment_id,
    map.redmine_attachment_id,
    map.sharepoint_url,
    att.filename,
    att.created_at
FROM migration_mapping_attachments map
JOIN staging_jira_attachments att ON att.id = map.jira_attachment_id
WHERE map.jira_issue_id = :issue_id
  AND (
      map.migration_status = 'SUCCESS'
      OR (map.sharepoint_url IS NOT NULL AND map.sharepoint_url <> '')
  )
ORDER BY att.created_at, map.mapping_id
SQL;

    $stmt = $pdo->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Failed to prepare journal attachments query.');
    }
    $stmt->execute(['issue_id' => $jiraIssueId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stmt->closeCursor();

    $rows = AttachmentWindow::select($rows, $journalCreatedAt, $windowSeconds);

    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'mapping_id' => isset($r['mapping_id']) ? (int)$r['mapping_id'] : 0,
            'jira_attachment_id' => isset($r['jira_attachment_id']) ? (string)$r['jira_attachment_id'] : '',
            'filename' => isset($r['filename']) ? (string)$r['filename'] : '',
            'sharepoint_url' => isset($r['sharepoint_url']) && trim((string)$r['sharepoint_url']) !== '' ? trim((string)$r['sharepoint_url']) : null,
            'redmine_attachment_id' => isset($r['redmine_attachment_id']) && $r['redmine_attachment_id'] !== null ? (int)$r['redmine_attachment_id'] : null,
            'created_at' => isset($r['created_at']) ? (string)$r['created_at'] : null,
        ];
    }

    return $result;
}

/**
 * Build the journal attachment reference block (read-only). Exact format:
 *
 * > SharePoint attachment: [naam.pdf](https://...)
 * > attachment:1234__naam.pdf
 *
 * @param array<int,array{mapping_id:int,jira_attachment_id:string,filename:string,sharepoint_url:string|null,redmine_attachment_id:int|null,created_at:string|null}> $attachments
 * @return string|null
 */
function buildJournalAttachmentBlock(array $attachments): ?string
{
    if ($attachments === []) return null;

    $lines = [];

    foreach ($attachments as $att) {
        $filename = $att['filename'] ?? ($att['jira_attachment_id'] ?? '');
        $sp = $att['sharepoint_url'] ?? null;

        if ($sp !== null && trim($sp) !== '') {
            $lines[] = '> SharePoint attachment: ' . sprintf('[%s](%s)', $filename, $sp);
            continue;
        }

        // create the upload-unique token (same format as used during issue uploads)
        if (function_exists('buildUploadUniqueName')) {
            $unique = buildUploadUniqueName((string)$att['jira_attachment_id'], $filename);
        } else {
            // fallback sanitization (should not be needed if your lib exports buildUploadUniqueName)
            $san = preg_replace('/[^A-Za-z0-9._-]/u', '_', $filename);
            $san = trim($san, '_');
            if ($san === '') $san = 'attachment';
            $unique = $att['jira_attachment_id'] . '__' . $san;
        }

        $lines[] = '> attachment:' . $unique;
    }

    return implode(PHP_EOL, $lines);
}

/**
 * Build an attachment block for a changelog entry based on attachment ids from items.
 *
 * @param array<int,string> $attachmentIds
 */
function buildAttachmentBlockForChangelogItems(PDO $pdo, string $jiraIssueId, array $attachmentIds): ?string
{
    if ($attachmentIds === []) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($attachmentIds), '?'));
    $sql = <<<SQL
SELECT map.mapping_id, map.jira_attachment_id, map.sharepoint_url, map.redmine_attachment_id, att.filename, att.created_at
FROM migration_mapping_attachments map
JOIN staging_jira_attachments att ON att.id = map.jira_attachment_id
WHERE map.jira_issue_id = ?
  AND map.jira_attachment_id IN ($placeholders)
  AND (
      map.migration_status = 'SUCCESS'
      OR (map.sharepoint_url IS NOT NULL AND map.sharepoint_url <> '')
  )
ORDER BY att.created_at, map.mapping_id
SQL;

    $stmt = $pdo->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Failed to prepare changelog attachment lookup statement.');
    }

    $stmt->execute(array_merge([$jiraIssueId], $attachmentIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stmt->closeCursor();

    if ($rows === []) {
        return null;
    }

    $attachments = [];
    foreach ($rows as $r) {
        $attachments[] = [
            'mapping_id' => isset($r['mapping_id']) ? (int)$r['mapping_id'] : 0,
            'jira_attachment_id' => isset($r['jira_attachment_id']) ? (string)$r['jira_attachment_id'] : '',
            'filename' => isset($r['filename']) ? (string)$r['filename'] : '',
            'sharepoint_url' => isset($r['sharepoint_url']) && trim((string)$r['sharepoint_url']) !== '' ? trim((string)$r['sharepoint_url']) : null,
            'redmine_attachment_id' => isset($r['redmine_attachment_id']) && $r['redmine_attachment_id'] !== null ? (int)$r['redmine_attachment_id'] : null,
            'created_at' => isset($r['created_at']) ? (string)$r['created_at'] : null,
        ];
    }

    return buildJournalAttachmentBlock($attachments);
}

function updateMigrationJournalProposedNotesWithRedmineIds(PDO $pdo): void
{
    // build jira->redmine map
    $jiraToRedmine = loadIssueLinkMap($pdo);
    if ($jiraToRedmine === []) {
        return;
    }

    // select proposed_notes for journals whose issue is already mapped (we can canonicalize then)
    $selSql = <<<SQL
SELECT map.mapping_id, map.proposed_notes
FROM migration_mapping_journals map
JOIN migration_mapping_issues im ON im.jira_issue_id = map.jira_issue_id
WHERE im.redmine_issue_id IS NOT NULL
  AND map.proposed_notes IS NOT NULL
  AND map.proposed_notes <> ''
SQL;
    $sel = $pdo->query($selSql);
    if ($sel === false) {
        throw new RuntimeException('Failed to select migration_mapping_journals for canonicalization.');
    }

    $upd = $pdo->prepare("UPDATE migration_mapping_journals SET proposed_notes = :notes, automation_hash = :ahash, last_updated_at = CURRENT_TIMESTAMP WHERE mapping_id = :mid");
    if ($upd === false) {
        throw new RuntimeException('Failed to prepare update for migration_mapping_journals.');
    }

    while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$row['mapping_id'];
        $notes = (string)$row['proposed_notes'];

        // canonicalise jira issue links → #redmine_id
        $canonical = inlineReplaceJiraIssueKeysWithHashes($notes, $jiraToRedmine);

        if ($canonical !== $notes) {
            $newHash = hash('sha256', $canonical);
            $upd->execute([':notes' => $canonical, ':ahash' => $newHash, ':mid' => $mid]);
        }
    }

    $sel->closeCursor();
}

function markJournalPushSkipped(PDO $pdo, int $mappingId, string $message = null): void
{
    $sql = <<<SQL
        UPDATE migration_mapping_journals
        SET
            migration_status = 'SKIPPED',
            notes = :notes,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL;

    $stmt = $pdo->prepare($sql);
    if ($stmt === false) throw new RuntimeException('Failed to prepare journal skipped update.');

    $stmt->execute([
        'notes' => $message,
        'mapping_id' => $mappingId,
    ]);
    $stmt->closeCursor();
}

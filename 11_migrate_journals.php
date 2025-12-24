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
const MIGRATE_JOURNALS_SCRIPT_VERSION = '0.0.25';
const JIRA_RATE_LIMIT_MAX_RETRIES = 5;
const JIRA_RATE_LIMIT_BASE_DELAY_MS = 1000;
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira comments and changelog entries into staging tables.',
    'transform' => 'Populate and classify journal mappings based on issue availability.',
    'push' => 'Create Redmine journals for migrated Jira comments and changelog events.',
];

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This script is intended to be run from the command line.');
}

require_once __DIR__ . '/lib/description_conversion.php';

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
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool} $cliOptions
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

    if (in_array('jira', $phasesToRun, true)) {
        printf("[%s] Fetching Jira comments and changelog entries...%s", formatCurrentTimestamp(), PHP_EOL);
        $jiraClient = createJiraClient(extractArrayConfig($config, 'jira'));
        $jiraSummary = fetchJiraJournals($jiraClient, $pdo, $config);
        printf(
            "[%s] Jira journal extraction complete. Comments: %d (updated %d), Changelog entries: %d (updated %d).%s",
            formatCurrentTimestamp(),
            $jiraSummary['comments_processed'],
            $jiraSummary['comments_updated'],
            $jiraSummary['changelog_processed'],
            $jiraSummary['changelog_updated'],
            PHP_EOL
        );
    } else {
        printf("[%s] Skipping Jira extraction phase (disabled via CLI option).%s", formatCurrentTimestamp(), PHP_EOL);
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Reconciling journal mappings...%s", formatCurrentTimestamp(), PHP_EOL);
        $summary = transformJournalMappings($pdo);
        printf(
            "[%s] Transform phase complete. Ready: %d, Pending: %d, Failed: %d.%s",
            formatCurrentTimestamp(),
            $summary['ready'],
            $summary['pending'],
            $summary['failed'],
            PHP_EOL
        );
        try {
            transformDescriptionsForUsersAndCanonicalizeIssues($pdo);
            printf("  [transform] Issue descriptions: avatars removed and user links canonicalized.%s", PHP_EOL);
        } catch (Throwable $e) {
            printf("  [warn] transformDescriptionsForUsersAndCanonicalizeIssues failed: %s%s", $e->getMessage(), PHP_EOL);
        }
        try {
            $noteSummary = populateProposedJournalNotes($pdo);
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
        printf("[%s] Skipping transform phase (disabled via CLI option).%s", formatCurrentTimestamp(), PHP_EOL);
    }

    if (in_array('push', $phasesToRun, true)) {
        $confirmPush = (bool)($cliOptions['confirm_push'] ?? false);
        $isDryRun = (bool)($cliOptions['dry_run'] ?? false);
        $redmineConfig = extractArrayConfig($config, 'redmine');
        $useExtendedApi = shouldUseExtendedApi($redmineConfig, (bool)($cliOptions['use_extended_api'] ?? false));
        $extendedApiPrefix = resolveExtendedApiPrefix($redmineConfig);
        runJournalPushPhase($pdo, $config, $confirmPush, $isDryRun, $useExtendedApi, $extendedApiPrefix);
    } else {
        printf("[%s] Skipping push phase (disabled via CLI option).%s", formatCurrentTimestamp(), PHP_EOL);
    }
}

function printUsage(): void
{
    printf("Jira to Redmine Journal Migration (step 12) — version %s%s", MIGRATE_JOURNALS_SCRIPT_VERSION, PHP_EOL);
    printf("Usage: php 11_migrate_journals.php [options]\n\n");
    printf("Options:\n");
    printf("  --phases=LIST        Comma separated list of phases to run (default: jira,transform,push).\n");
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
 * @return array{comments_processed: int, comments_updated: int, changelog_processed: int, changelog_updated: int}
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
                    'comments_extracted_at' => formatCurrentTimestamp(),
                    'comments_migration_status' => 'SUCCESS',
                    'comments_notes' => null,
                ]);
            } catch (RuntimeException $exception) {
                $warningStatus = classifyJournalExtractionFailure($exception);
                $message = $exception->getMessage();
                printf("  [warn] %s%s", $message, PHP_EOL);
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
                    'changelog_extracted_at' => formatCurrentTimestamp(),
                    'changelog_migration_status' => 'SUCCESS',
                    'changelog_notes' => null,
                ]);
            } catch (RuntimeException $exception) {
                $warningStatus = classifyJournalExtractionFailure($exception);
                $message = $exception->getMessage();
                printf("  [warn] %s%s", $message, PHP_EOL);
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

    $updateSql = <<<SQL
        UPDATE migration_mapping_journals map
        LEFT JOIN migration_mapping_issues issue_map ON issue_map.jira_issue_id = map.jira_issue_id
        SET
            map.migration_status = CASE
                WHEN issue_map.redmine_issue_id IS NULL THEN 'PENDING'
                WHEN map.migration_status = 'FAILED' THEN 'FAILED'
                ELSE 'READY_FOR_PUSH'
            END,
            map.last_updated_at = CURRENT_TIMESTAMP
    SQL;

    try {
        $pdo->exec($updateSql);
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
function populateProposedJournalNotes(PDO $pdo): array
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

            $journalAttachments = fetchJournalAttachmentsForNote($pdo, $jiraIssueId, $createdAt);
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

            $journalAttachments = fetchJournalAttachmentsForNote($pdo, $jiraIssueId, $createdAt);
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
 * @throws Throwable
 */
function runJournalPushPhase(
    PDO $pdo,
    array $config,
    bool $confirmPush,
    bool $isDryRun,
    bool $useExtendedApi,
    string $extendedApiPrefix
): void
{
    $candidateComments = fetchPushableComments($pdo);
    $candidateChangelogs = fetchPushableChangelog($pdo);

    $totalCandidates = count($candidateComments) + count($candidateChangelogs);

    if ($totalCandidates === 0) {
        printf("[%s] No journals queued for push.%s", formatCurrentTimestamp(), PHP_EOL);
        return;
    }

    printf("[%s] Journals ready for push: %d comment(s), %d changelog entr(y/ies).%s", formatCurrentTimestamp(), count($candidateComments), count($candidateChangelogs), PHP_EOL);

    if ($isDryRun) {
        foreach ($candidateComments as $comment) {
            printf(
                "  - Comment %s on issue %s → Redmine #%d%s",
                $comment['jira_entity_id'],
                $comment['jira_issue_key'],
                $comment['redmine_issue_id'],
                PHP_EOL
            );
        }
        foreach ($candidateChangelogs as $history) {
            printf(
                "  - Changelog %s on issue %s → Redmine #%d%s",
                $history['jira_entity_id'],
                $history['jira_issue_key'],
                $history['redmine_issue_id'],
                PHP_EOL
            );
        }
        printf("  Dry-run active; no journal updates will be sent to Redmine.%s", PHP_EOL);
        return;
    }

    if (!$confirmPush) {
        printf("  Push confirmation missing; rerun with --confirm-push to create journals in Redmine.%s", PHP_EOL);
        return;
    }

    $redmineClient = createRedmineClient(extractArrayConfig($config, 'redmine'));
    $userLookup = buildRedmineUserLookup($pdo);
    $defaultAuthorId = extractDefaultJournalAuthorId($config);
    $attachmentMetadata = buildAttachmentMetadataIndex($pdo);
    $adfConverter = new AdfConverter();

    foreach ($candidateComments as $comment) {
        processCommentPush(
            $redmineClient,
            $pdo,
            $comment,
            $attachmentMetadata,
            $userLookup,
            $defaultAuthorId,
            $useExtendedApi,
            $extendedApiPrefix,
            $adfConverter
        );
    }

    foreach ($candidateChangelogs as $history) {
        processChangelogPush(
            $redmineClient,
            $pdo,
            $history,
            $userLookup,
            $defaultAuthorId,
            $useExtendedApi,
            $extendedApiPrefix
        );
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
            c.raw_payload
        FROM migration_mapping_journals map
        JOIN staging_jira_comments c ON c.id = map.jira_entity_id AND map.entity_type = 'COMMENT'
        JOIN migration_mapping_issues issue_map ON issue_map.jira_issue_id = map.jira_issue_id
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
            h.items_json
        FROM migration_mapping_journals map
        JOIN staging_jira_changelogs h ON h.id = map.jira_entity_id AND map.entity_type = 'CHANGELOG'
        JOIN migration_mapping_issues issue_map ON issue_map.jira_issue_id = map.jira_issue_id
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
    AdfConverter $adfConverter
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
            $jiraToRedmineMap = [];
            $stmt = $pdo->query("SELECT jira_issue_key, redmine_issue_id FROM migration_mapping_issues WHERE redmine_issue_id IS NOT NULL");
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $jiraToRedmineMap[strtoupper($r['jira_issue_key'])] = (int)$r['redmine_issue_id'];
            }
            $stmt->closeCursor();
        }
        if (!empty($jiraToRedmineMap)) {
            $bodyText = inlineReplaceJiraIssueKeysWithHashes($bodyText, $jiraToRedmineMap);
        }

        // build base note
        $note = $bodyText;

        // fetch only attachments that are relevant and already uploaded / have sharepoint links
        $journalAttachments = fetchJournalAttachmentsForNote($pdo, $jiraIssueId, $createdAt);

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

    $issueUpdatedOn = $useExtendedApi ? buildIssueUpdatedOnOverride($updatedAt ?? $createdAt) : null;

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
        ? buildExtendedApiPath($extendedApiPrefix, sprintf('issues/%d.json', $redmineIssueId))
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
            markJournalPushSuccess($pdo, $mappingId, $journalId);
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

    markJournalPushSuccess($pdo, $mappingId, $journalId);
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
        $journalAttachments = fetchJournalAttachmentsForNote($pdo, $jiraIssueId, $createdAt);

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

    $issueUpdatedOn = $useExtendedApi ? buildIssueUpdatedOnOverride($createdAt) : null;

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
        ? buildExtendedApiPath($extendedApiPrefix, sprintf('issues/%d.json', $redmineIssueId))
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

function markJournalPushSuccess(PDO $pdo, int $mappingId, ?int $journalId): void
{
    $sql = <<<SQL
        UPDATE migration_mapping_journals
        SET
            redmine_journal_id = :redmine_journal_id,
            migration_status = 'SUCCESS',
            notes = NULL,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL;

    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare journal success update.');
    }

    $statement->execute([
        'redmine_journal_id' => $journalId,
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
        $bodyText = convertJiraHtmlToMarkdown($bodyHtml, $issueAttachments);
    }

    if ($bodyText === null && $bodyAdf !== null) {
        $bodyText = convertDescriptionToMarkdown($bodyAdf, $adfConverter);
    }

    if ($bodyText === null && $bodyAdf !== null) {
        $bodyText = convertJiraAdfToPlaintext($bodyAdf);
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
    $statement = $pdo->query('SELECT jira_account_id, redmine_user_id, migration_status FROM migration_mapping_users');
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
function createJiraClient(array $config): Client
{
    $baseUrl = isset($config['base_url']) ? rtrim((string)$config['base_url'], '/') : '';
    $username = isset($config['username']) ? (string)$config['username'] : '';
    $apiToken = isset($config['api_token']) ? (string)$config['api_token'] : '';

    if ($baseUrl === '' || $username === '' || $apiToken === '') {
        throw new RuntimeException('Incomplete Jira configuration.');
    }

    return new Client([
        'base_uri' => $baseUrl,
        'headers' => [
            'Accept' => 'application/json',
        ],
        'auth' => [$username, $apiToken],
    ]);
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

function shouldUseExtendedApi(array $redmineConfig, bool $force): bool
{
    if ($force) {
        return true;
    }

    $extendedConfig = isset($redmineConfig['extended_api']) && is_array($redmineConfig['extended_api'])
        ? $redmineConfig['extended_api']
        : [];

    return (bool)($extendedConfig['enabled'] ?? false);
}

function resolveExtendedApiPrefix(array $redmineConfig): string
{
    $extendedConfig = isset($redmineConfig['extended_api']) && is_array($redmineConfig['extended_api'])
        ? $redmineConfig['extended_api']
        : [];

    $prefix = isset($extendedConfig['prefix']) ? trim((string)$extendedConfig['prefix']) : '/extended_api';

    if ($prefix === '') {
        $prefix = '/extended_api';
    }

    return trim($prefix, '/');
}

function buildExtendedApiPath(string $prefix, string $resource): string
{
    $normalizedPrefix = trim($prefix, '/');
    $normalizedResource = ltrim($resource, '/');

    if ($normalizedPrefix === '') {
        return $normalizedResource;
    }

    return $normalizedPrefix . '/' . $normalizedResource;
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
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool} $cliOptions
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
 * @return array{0: array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool}, 1: list<string>}
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
        'use_extended_api' => false,
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

        if ($argument === '--use-extended-api') {
            $options['use_extended_api'] = true;
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
function formatCurrentTimestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

/**
 * Read-only fetch of attachments that should be referenced for a given journal note.
 * Only returns attachments that are already uploaded (migration_status = 'SUCCESS') or have a sharepoint_url.
 *
 * @return array<int,array{mapping_id:int,jira_attachment_id:string,filename:string,sharepoint_url:string|null,redmine_attachment_id:int|null,created_at:string|null}>
 */
function fetchJournalAttachmentsForNote(PDO $pdo, string $jiraIssueId, ?string $journalCreatedAt): array
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

    if ($journalCreatedAt !== null) {
        $journalTimestamp = strtotime($journalCreatedAt);
        if ($journalTimestamp !== false) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($journalTimestamp) {
                $createdAt = isset($row['created_at']) ? (string)$row['created_at'] : null;
                if ($createdAt === null) return true;
                $createdTimestamp = strtotime($createdAt);
                if ($createdTimestamp === false) return true;
                return abs($createdTimestamp - $journalTimestamp) <= 300; // 5 minute tolerance
            }));
        }
    }

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
    $jiraToRedmine = [];
    $stmt = $pdo->query("SELECT jira_issue_key, redmine_issue_id FROM migration_mapping_issues WHERE redmine_issue_id IS NOT NULL");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $jiraToRedmine[strtoupper($r['jira_issue_key'])] = (int)$r['redmine_issue_id'];
    }
    $stmt->closeCursor();
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

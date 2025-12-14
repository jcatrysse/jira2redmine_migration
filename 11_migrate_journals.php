<?php
/** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use League\HTMLToMarkdown\HtmlConverter;

const MIGRATE_JOURNALS_SCRIPT_VERSION = '0.0.15';
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira comments and changelog entries into staging tables.',
    'transform' => 'Populate and classify journal mappings based on issue availability.',
    'push' => 'Create Redmine journals for migrated Jira comments and changelog events.',
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
        runJournalPushPhase($pdo, $config, $confirmPush, $isDryRun);
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

    $commentsProcessed = 0;
    $commentsUpdated = 0;
    $changelogProcessed = 0;
    $changelogUpdated = 0;

    foreach ($issues as $issue) {
        $issueId = (string)$issue['id'];
        $issueKey = isset($issue['issue_key']) ? (string)$issue['issue_key'] : $issueId;

        [$newComments, $updatedComments] = fetchJiraCommentsForIssue($client, $commentInsert, $issueId, $issueKey);
        $commentsProcessed += $newComments;
        $commentsUpdated += $updatedComments;

        [$newChangelog, $updatedChangelog] = fetchJiraChangelogForIssue($client, $changelogInsert, $issueId, $issueKey);
        $changelogProcessed += $newChangelog;
        $changelogUpdated += $updatedChangelog;
    }

    return [
        'comments_processed' => $commentsProcessed,
        'comments_updated' => $commentsUpdated,
        'changelog_processed' => $changelogProcessed,
        'changelog_updated' => $changelogUpdated,
    ];
}

/**
 * @param Client $client
 * @param PDOStatement $statement
 * @param string $issueId
 * @param string $issueKey
 * @return array{0: int, 1: int}
 */
function fetchJiraCommentsForIssue(Client $client, PDOStatement $statement, string $issueId, string $issueKey): array
{
    $new = 0;
    $updated = 0;

    $startAt = 0;
    $maxResults = 100;

    do {
        try {
            $response = $client->get(sprintf('/rest/api/3/issue/%s/comment', $issueId), [
                'query' => [
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                    'expand' => 'renderedBody',
                ],
            ]);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = sprintf('Failed to fetch comments for Jira issue %s', $issueKey);
            if ($response instanceof ResponseInterface) {
                $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                $message .= ': ' . extractErrorBody($response);
            } else {
                $message .= ': ' . $exception->getMessage();
            }
            throw new RuntimeException($message, 0, $exception);
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
            $response = $client->get(sprintf('/rest/api/3/issue/%s/changelog', $issueId), [
                'query' => [
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                ],
            ]);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = sprintf('Failed to fetch changelog for Jira issue %s', $issueKey);
            if ($response instanceof ResponseInterface) {
                $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                $message .= ': ' . extractErrorBody($response);
            } else {
                $message .= ': ' . $exception->getMessage();
            }
            throw new RuntimeException($message, 0, $exception);
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
 * @throws Throwable
 */
function runJournalPushPhase(PDO $pdo, array $config, bool $confirmPush, bool $isDryRun): void
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
    $attachmentMetadata = buildAttachmentMetadataIndex($pdo);

    foreach ($candidateComments as $comment) {
        processCommentPush($redmineClient, $pdo, $comment, $config, $attachmentMetadata);
    }

    foreach ($candidateChangelogs as $history) {
        processChangelogPush($redmineClient, $pdo, $history);
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
 */
function processCommentPush(Client $client, PDO $pdo, array $comment, array $config, array $attachmentMetadata): void
{
    $mappingId = (int)$comment['mapping_id'];
    $jiraIssueId = (string)$comment['jira_issue_id'];
    $jiraIssueKey = (string)$comment['jira_issue_key'];
    $jiraCommentId = (string)$comment['jira_entity_id'];
    $redmineIssueId = (int)$comment['redmine_issue_id'];

    $authorId = isset($comment['author_account_id']) ? (string)$comment['author_account_id'] : null;
    $createdAt = isset($comment['created_at']) ? (string)$comment['created_at'] : null;
    $bodyAdf = isset($comment['body_adf']) ? (string)$comment['body_adf'] : null;
    $bodyHtml = isset($comment['body_html']) ? (string)$comment['body_html'] : null;

    $noteParts = [];
    if ($authorId !== null) {
        $noteParts[] = sprintf('Comment by %s', $authorId);
    } else {
        $noteParts[] = 'Comment';
    }
    if ($createdAt !== null) {
        $noteParts[] = sprintf('created at %s', $createdAt);
    }
    $noteParts[] = '';

    $issueAttachments = $attachmentMetadata[$jiraIssueId] ?? [];
    $bodyText = $bodyHtml !== null ? convertJiraHtmlToMarkdown($bodyHtml, $issueAttachments) : null;
    if ($bodyText === null && $bodyAdf !== null) {
        $bodyText = convertJiraAdfToPlaintext($bodyAdf);
    }
    $bodyText = $bodyText ?? '';

    $attachments = fetchPreparedJournalAttachments($pdo, $jiraIssueId, $createdAt);
    foreach ($attachments as $att) {
        if ($att['redmine_upload_token'] !== '' && ($att['sharepoint_url'] ?? '') !== null) {
            printf(
                "  [warn] Attachment %s has both Redmine token and SharePoint URL, using SharePoint link.%s",
                $att['jira_attachment_id'],
                PHP_EOL
            );
        }
    }

    $redmineUploads = array_values(array_filter(
        $attachments,
        static fn($attachment) => $attachment['redmine_upload_token'] !== ''
            && ($attachment['sharepoint_url'] ?? '') === ''
    ));
    $sharePointLinks = array_values(array_filter(
        $attachments,
        static fn($attachment) => ($attachment['sharepoint_url'] ?? '') !== ''
    ));

    $uploadPayload = buildAttachmentUploadPayload($redmineUploads);

    $note = implode(' ', array_filter($noteParts, static fn($part) => $part !== '')) . PHP_EOL . $bodyText;
    $note = appendSharePointLinksToNotes($note, $sharePointLinks);
    $payload = [
        'issue' => array_filter([
            'notes' => $note,
            'uploads' => $uploadPayload,
        ], static fn($value) => $value !== null && $value !== []),
    ];

    try {
        $client->put(sprintf('/issues/%d.json', $redmineIssueId), ['json' => $payload]);
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
        markAttachmentAssociationFailure($pdo, $attachments, $message);
        printf("  [error] %s%s", $message, PHP_EOL);
        return;
    } catch (GuzzleException $exception) {
        $message = sprintf('Failed to create journal for Jira comment %s on issue %s: %s', $jiraCommentId, $jiraIssueKey, $exception->getMessage());
        markJournalPushFailure($pdo, $mappingId, $message);
        markAttachmentAssociationFailure($pdo, $attachments, $message);
        printf("  [error] %s%s", $message, PHP_EOL);
        return;
    }

    $journalId = fetchLatestJournalId($client, $redmineIssueId, $note);

    markJournalPushSuccess($pdo, $mappingId, $journalId);

    if ($redmineUploads !== []) {
        finalizeAttachmentAssociations($client, $pdo, $jiraIssueId, $redmineIssueId, $redmineUploads);
    }

    if ($sharePointLinks !== []) {
        markSharePointJournalAttachmentsAsLinked($pdo, $sharePointLinks, $redmineIssueId);
    }

    printf(
        "  [journal] Jira comment %s on %s → Redmine issue #%d%s",
        $jiraCommentId,
        $jiraIssueKey,
        $redmineIssueId,
        PHP_EOL
    );
}

/**
 * @param array<string, mixed> $history
 */
function processChangelogPush(Client $client, PDO $pdo, array $history): void
{
    $mappingId = (int)$history['mapping_id'];
    $jiraIssueId = (string)$history['jira_issue_id'];
    $jiraIssueKey = (string)$history['jira_issue_key'];
    $historyId = (string)$history['jira_entity_id'];
    $redmineIssueId = (int)$history['redmine_issue_id'];

    $createdAt = isset($history['created_at']) ? (string)$history['created_at'] : null;
    $itemsJson = isset($history['items_json']) ? (string)$history['items_json'] : null;

    $noteLines = [];
    $noteLines[] = sprintf('Jira history %s%s', $historyId, $createdAt !== null ? ' at ' . $createdAt : '');

    if ($itemsJson !== null) {
        try {
            $items = json_decode($itemsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $items = [];
        }

        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['field'])) {
                    continue;
                }
                $field = (string)$item['field'];
                $from = isset($item['fromString']) ? (string)$item['fromString'] : '';
                $to = isset($item['toString']) ? (string)$item['toString'] : '';
                $noteLines[] = sprintf('• %s: %s → %s', $field, $from !== '' ? $from : '[empty]', $to !== '' ? $to : '[empty]');
            }
        }
    }

    $payload = [
        'issue' => [
            'notes' => implode(PHP_EOL, $noteLines),
        ],
    ];

    try {
        $client->put(sprintf('/issues/%d.json', $redmineIssueId), ['json' => $payload]);
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $message = sprintf('Failed to create journal for Jira changelog %s on issue %s', $historyId, $jiraIssueKey);
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
        $message = sprintf('Failed to create journal for Jira changelog %s on issue %s: %s', $historyId, $jiraIssueKey, $exception->getMessage());
        markJournalPushFailure($pdo, $mappingId, $message);
        printf("  [error] %s%s", $message, PHP_EOL);
        return;
    }

    $journalId = fetchLatestJournalId($client, $redmineIssueId, $payload['issue']['notes']);
    markJournalPushSuccess($pdo, $mappingId, $journalId);

    printf(
        "  [journal] Jira changelog %s on %s → Redmine issue #%d%s",
        $historyId,
        $jiraIssueKey,
        $redmineIssueId,
        PHP_EOL
    );
}

function fetchLatestJournalId(Client $client, int $redmineIssueId, string $expectedNote): ?int
{
    try {
        $response = $client->get(sprintf('/issues/%d.json', $redmineIssueId), ['query' => ['include' => 'journals']]);
    } catch (Throwable) {
        return null;
    }

    $payload = decodeJsonResponse($response);
    $journals = isset($payload['issue']['journals']) && is_array($payload['issue']['journals']) ? $payload['issue']['journals'] : [];

    $expected = trim($expectedNote);
    $candidateId = null;

    foreach ($journals as $journal) {
        if (!is_array($journal) || !isset($journal['id'])) {
            continue;
        }
        $note = isset($journal['notes']) ? trim((string)$journal['notes']) : '';
        if ($expected !== '' && $note !== '' && $note === $expected) {
            return (int)$journal['id'];
        }
        if ($candidateId === null || (int)$journal['id'] > $candidateId) {
            $candidateId = (int)$journal['id'];
        }
    }

    return $candidateId;
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

/**
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string, sharepoint_url: ?string, created_at: ?string}> $attachments
 */
function markAttachmentAssociationFailure(PDO $pdo, array $attachments, string $message): void
{
    foreach ($attachments as $attachment) {
        updateAttachmentMappingAfterPush($pdo, (int)$attachment['mapping_id'], null, 'PENDING_ASSOCIATION', $message);
    }
}

/**
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string, sharepoint_url: ?string, created_at: ?string}> $attachments
 * @return array<int, array<string, mixed>>
 */
function buildAttachmentUploadPayload(array $attachments): array
{
    $uploads = [];
    foreach ($attachments as $attachment) {
        if ($attachment['redmine_upload_token'] === '') {
            continue;
        }

        $filename = buildRedmineAttachmentFilename($attachment['jira_attachment_id'], $attachment['filename']);
        $description = $attachment['filename'] !== ''
            ? $attachment['filename']
            : sprintf('Jira attachment %s', $attachment['jira_attachment_id']);

        $uploads[] = array_filter([
            'token' => $attachment['redmine_upload_token'],
            'filename' => $filename,
            'description' => $description,
            'content_type' => $attachment['mime_type'] ?? null,
        ], static fn($value) => $value !== null);
    }

    return $uploads;
}

/**
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string, sharepoint_url: ?string, created_at: ?string}> $sharePointLinks
 */
function appendSharePointLinksToNotes(string $note, array $sharePointLinks): string
{
    if ($sharePointLinks === []) {
        return $note;
    }

    $lines = [
        '---',
        'SharePoint attachments:',
    ];

    foreach ($sharePointLinks as $attachment) {
        $url = (string)($attachment['sharepoint_url'] ?? '');
        if ($url === '') {
            continue;
        }

        $label = $attachment['filename'] !== '' ? $attachment['filename'] : $attachment['jira_attachment_id'];
        $lines[] = sprintf('- %s: %s', $label, $url);
    }

    $block = implode(PHP_EOL, $lines);

    if (trim($note) === '') {
        return $block;
    }

    return rtrim($note) . PHP_EOL . PHP_EOL . $block;
}

/**
 * @return array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string, sharepoint_url: ?string, created_at: ?string}>
 */
function fetchPreparedJournalAttachments(PDO $pdo, string $jiraIssueId, ?string $journalCreatedAt): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_attachment_id,
            map.redmine_upload_token,
            map.sharepoint_url,
            att.filename,
            att.mime_type,
            att.size_bytes,
            att.created_at
        FROM migration_mapping_attachments map
        JOIN staging_jira_attachments att ON att.id = map.jira_attachment_id
        WHERE map.jira_issue_id = :issue_id
          AND map.association_hint = 'JOURNAL'
          AND map.migration_status = 'PENDING_ASSOCIATION'
        ORDER BY att.created_at, map.mapping_id
    SQL;

    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare journal attachment query.');
    }

    $statement->execute(['issue_id' => $jiraIssueId]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    if ($journalCreatedAt !== null) {
        $journalTimestamp = strtotime($journalCreatedAt);
        if ($journalTimestamp !== false) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($journalTimestamp) {
                $createdAt = isset($row['created_at']) ? (string)$row['created_at'] : null;
                if ($createdAt === null) {
                    return true;
                }

                $createdTimestamp = strtotime($createdAt);
                if ($createdTimestamp === false) {
                    return true;
                }

                return abs($createdTimestamp - $journalTimestamp) <= 300; // 5 minute tolerance
            }));
        }
    }

    $result = [];
    foreach ($rows as $row) {
        $token = isset($row['redmine_upload_token']) ? (string)$row['redmine_upload_token'] : '';
        $sharePointUrl = isset($row['sharepoint_url']) ? trim((string)$row['sharepoint_url']) : '';

        if ($token === '' && $sharePointUrl === '') {
            continue;
        }

        $result[] = [
            'mapping_id' => isset($row['mapping_id']) ? (int)$row['mapping_id'] : 0,
            'jira_attachment_id' => isset($row['jira_attachment_id']) ? (string)$row['jira_attachment_id'] : '',
            'filename' => isset($row['filename']) ? (string)$row['filename'] : '',
            'mime_type' => isset($row['mime_type']) && $row['mime_type'] !== '' ? (string)$row['mime_type'] : null,
            'size_bytes' => isset($row['size_bytes']) ? (int)$row['size_bytes'] : null,
            'redmine_upload_token' => $token,
            'sharepoint_url' => $sharePointUrl !== '' ? $sharePointUrl : null,
            'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : null,
        ];
    }

    return $result;
}

/**
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string, sharepoint_url: ?string, created_at: ?string}> $attachments
 */
function finalizeAttachmentAssociations(Client $client, PDO $pdo, string $jiraIssueId, int $redmineIssueId, array $attachments): void
{
    if ($attachments === []) {
        return;
    }

    try {
        $response = $client->get(sprintf('/issues/%d.json', $redmineIssueId), ['query' => ['include' => 'attachments']]);
    } catch (Throwable $exception) {
        markAttachmentAssociationFailure($pdo, $attachments, 'Unable to confirm attachment association: ' . $exception->getMessage());
        return;
    }

    $body = decodeJsonResponse($response);
    $redmineAttachments = isset($body['issue']['attachments']) && is_array($body['issue']['attachments'])
        ? $body['issue']['attachments']
        : [];

    $matches = matchAttachmentsByMetadata($attachments, $redmineAttachments);

    foreach ($attachments as $attachment) {
        $mappingId = (int)$attachment['mapping_id'];
        $match = $matches[$attachment['jira_attachment_id']] ?? null;

        if ($match === null) {
            updateAttachmentMappingAfterPush($pdo, $mappingId, null, 'PENDING_ASSOCIATION', 'Unable to locate uploaded attachment on Redmine issue.');
            continue;
        }

        updateAttachmentMappingAfterPush($pdo, $mappingId, $match['id'], 'SUCCESS', null, $redmineIssueId);
    }
}

/**
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string, sharepoint_url: ?string, created_at: ?string}> $attachments
 */
function markSharePointJournalAttachmentsAsLinked(PDO $pdo, array $attachments, int $redmineIssueId): void
{
    foreach ($attachments as $attachment) {
        $note = isset($attachment['sharepoint_url']) && $attachment['sharepoint_url'] !== null
            ? sprintf('Attachment stored on SharePoint: %s', $attachment['sharepoint_url'])
            : 'Attachment stored on SharePoint.';

        updateAttachmentMappingAfterPush($pdo, (int)$attachment['mapping_id'], null, 'SUCCESS', $note, $redmineIssueId);
    }
}

/**
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string, sharepoint_url: ?string, created_at: ?string}> $attachments
 * @param array<int, mixed> $redmineAttachments
 * @return array<string, array{id: int}>
 */
function matchAttachmentsByMetadata(array $attachments, array $redmineAttachments): array
{
    $lookup = [];
    foreach ($redmineAttachments as $redmineAttachment) {
        if (!is_array($redmineAttachment)) {
            continue;
        }

        $id = isset($redmineAttachment['id']) ? (int)$redmineAttachment['id'] : null;
        $filename = isset($redmineAttachment['filename']) ? (string)$redmineAttachment['filename'] : '';
        $filesize = isset($redmineAttachment['filesize']) ? (int)$redmineAttachment['filesize'] : null;

        if ($id === null) {
            continue;
        }

        $lookup[] = [
            'id' => $id,
            'filename' => $filename,
            'filesize' => $filesize,
        ];
    }

    $matches = [];
    foreach ($attachments as $attachment) {
        $targetFilename = buildRedmineAttachmentFilename($attachment['jira_attachment_id'], $attachment['filename']);
        $targetSize = $attachment['size_bytes'];

        $matchId = null;
        foreach ($lookup as $candidate) {
            if ($targetFilename !== '' && $candidate['filename'] !== '' && $candidate['filename'] !== $targetFilename) {
                continue;
            }
            if ($targetSize !== null && $candidate['filesize'] !== null && $candidate['filesize'] !== $targetSize) {
                continue;
            }
            $matchId = $candidate['id'];
            break;
        }

        if ($matchId !== null) {
            $matches[$attachment['jira_attachment_id']] = ['id' => $matchId];
        }
    }

    return $matches;
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

    // Normalize whitespace
    $name = preg_replace('/\s+/', ' ', $name);

    // Replace filesystem and URL-hostile characters + control chars
    $name = preg_replace('/[\/:*?"<>|\x00-\x1F]/', '_', $name);

    // Prevent leading dots (hidden files)
    $name = ltrim($name, '.');

    // Prefix with Jira attachment id (uniqueness)
    $filename = $jiraAttachmentId . '-' . $name;

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

function convertJiraHtmlToMarkdown(?string $html, array $attachments): ?string
{
    if ($html === null) {
        return null;
    }

    $trimmed = trim($html);
    if ($trimmed === '') {
        return null;
    }

    $rewrittenHtml = rewriteJiraAttachmentLinks($trimmed, $attachments);

    static $converter = null;
    if ($converter === null) {
        $converter = new HtmlConverter([
            'strip_tags' => false,
            'hard_break' => true,
        ]);
    }

    try {
        $markdown = trim($converter->convert($rewrittenHtml));
    } catch (Throwable) {
        $markdown = trim(strip_tags($rewrittenHtml));
    }

    return $markdown !== '' ? $markdown : null;
}

function rewriteJiraAttachmentLinks(string $html, array $attachments): string
{
    if ($attachments === []) {
        return $html;
    }

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $options = 0;
    if (defined('LIBXML_HTML_NOIMPLIED')) {
        $options |= LIBXML_HTML_NOIMPLIED;
    }
    if (defined('LIBXML_HTML_NODEFDTD')) {
        $options |= LIBXML_HTML_NODEFDTD;
    }

    $htmlPayload = '<?xml encoding="utf-8"?>' . $html;
    $loaded = $document->loadHTML($htmlPayload, $options);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return $html;
    }

    foreach ($document->getElementsByTagName('a') as $link) {
        if (!$link instanceof DOMElement) {
            continue;
        }

        $href = $link->getAttribute('href');
        $attachmentId = null;
        if ($href !== '' && preg_match('#attachment/(\d+)#', $href, $matches)) {
            $attachmentId = $matches[1];
        } elseif ($link->hasAttribute('data-linked-resource-id')) {
            $attachmentId = (string)$link->getAttribute('data-linked-resource-id');
        }

        if ($attachmentId === null || !isset($attachments[$attachmentId])) {
            continue;
        }

        $filename = $attachments[$attachmentId] !== ''
            ? $attachments[$attachmentId]
            : sprintf('attachment-%s', $attachmentId);

        while ($link->firstChild !== null) {
            $link->removeChild($link->firstChild);
        }
        $link->appendChild($document->createTextNode($filename));
        $link->setAttribute('href', sprintf('attachment:%s', $filename));
    }

    $converted = $document->saveHTML();

    return $converted !== false ? $converted : $html;
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

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function convertJiraAdfToPlaintext(string $adfJson): string
{
    try {
        $decoded = json_decode($adfJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return '[Unable to decode Jira content: ' . $exception->getMessage() . ']';
    }

    if (!is_array($decoded)) {
        return '[Unsupported Jira content format]';
    }

    return trim(renderAdfNode($decoded));
}

function renderAdfNode(mixed $node): string
{
    if (!is_array($node)) {
        return '';
    }

    $type = isset($node['type']) ? (string)$node['type'] : '';
    $text = '';

    switch ($type) {
        case 'doc':
        case 'paragraph':
        case 'bulletList':
        case 'orderedList':
        case 'listItem':
        case 'heading':
            if (isset($node['content']) && is_array($node['content'])) {
                foreach ($node['content'] as $child) {
                    $text .= renderAdfNode($child);
                }
            }
            if (in_array($type, ['paragraph', 'heading', 'listItem'], true)) {
                $text .= PHP_EOL;
            }
            break;
        case 'text':
            $textContent = isset($node['text']) ? (string)$node['text'] : '';
            $text .= $textContent;
            break;
        case 'hardBreak':
            $text .= PHP_EOL;
            break;
        default:
            if (isset($node['content']) && is_array($node['content'])) {
                foreach ($node['content'] as $child) {
                    $text .= renderAdfNode($child);
                }
            }
            break;
    }

    return $text;
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

function formatCurrentTimestamp(): string
{
    return date('Y-m-d H:i:s');
}

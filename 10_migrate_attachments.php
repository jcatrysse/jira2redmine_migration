<?php
/** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_ATTACHMENTS_SCRIPT_VERSION = '0.0.17';
const AVAILABLE_PHASES = [
    'jira' => 'Synchronise Jira attachment metadata with the migration mapping table.',
    'pull' => 'Download Jira attachment binaries into the working directory.',
    'transform' => 'Normalise attachment preparation states and summarise outstanding work.',
    'push' => 'Upload prepared attachments to Redmine to obtain association tokens.',
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
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_pull: bool, confirm_push: bool, dry_run: bool, download_limit: ?int, upload_limit: ?int} $cliOptions
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
        printf("[%s] Refreshing staged attachment metadata from staged issues...%s", formatCurrentTimestamp(), PHP_EOL);
        $harvestSummary = stageAttachmentsFromStagedIssues($pdo);
        printf(
            "[%s] Attachment staging complete. Issues scanned: %d, Attachments indexed: %d.%s",
            formatCurrentTimestamp(),
            $harvestSummary['issues_scanned'],
            $harvestSummary['attachments_indexed'],
            PHP_EOL
        );

        printf("[%s] Synchronising attachment mappings...%s", formatCurrentTimestamp(), PHP_EOL);
        $summary = syncAttachmentMappings($pdo);
        printf(
            "[%s] Attachment mapping sync complete. New rows: %d, Updated rows: %d.%s",
            formatCurrentTimestamp(),
            $summary['new_mappings'],
            $summary['relinked'],
            PHP_EOL
        );
    } else {
        printf("[%s] Skipping Jira sync phase (disabled via CLI option).%s", formatCurrentTimestamp(), PHP_EOL);
    }

    if (in_array('pull', $phasesToRun, true)) {
        $confirmPull = (bool)($cliOptions['confirm_pull'] ?? false);
        $isDryRun = (bool)($cliOptions['dry_run'] ?? false);
        $downloadLimit = $cliOptions['download_limit'] ?? null;
        runAttachmentPullPhase($pdo, $config, $confirmPull, $isDryRun, $downloadLimit);
    } else {
        printf("[%s] Skipping pull phase (disabled via CLI option).%s", formatCurrentTimestamp(), PHP_EOL);
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Normalising attachment preparation state...%s", formatCurrentTimestamp(), PHP_EOL);
        $transformSummary = runAttachmentTransformPhase($pdo);
        printf(
            "[%s] Transform complete. Requeued failures: %d.%s",
            formatCurrentTimestamp(),
            $transformSummary['requeued_failures'],
            PHP_EOL
        );
        if ($transformSummary['status_breakdown'] !== []) {
            printf("  Current attachment status breakdown:%s", PHP_EOL);
            foreach ($transformSummary['status_breakdown'] as $status => $count) {
                printf("  - %-24s %d%s", $status, $count, PHP_EOL);
            }
        }
    } else {
        printf("[%s] Skipping transform phase (disabled via CLI option).%s", formatCurrentTimestamp(), PHP_EOL);
    }

    if (in_array('push', $phasesToRun, true)) {
        $confirmPush = (bool)($cliOptions['confirm_push'] ?? false);
        $isDryRun = (bool)($cliOptions['dry_run'] ?? false);
        $uploadLimit = $cliOptions['upload_limit'] ?? null;
        runAttachmentUploadPhase($pdo, $config, $confirmPush, $isDryRun, $uploadLimit);
    } else {
        printf("[%s] Skipping push phase (disabled via CLI option).%s", formatCurrentTimestamp(), PHP_EOL);
    }
}

function printUsage(): void
{
    printf("Jira to Redmine Attachment Migration (step 10) — version %s%s", MIGRATE_ATTACHMENTS_SCRIPT_VERSION, PHP_EOL);
    printf("Usage: php 10_migrate_attachments.php [options]\n\n");
    printf("Options:\n");
    printf("  --phases=LIST        Comma separated list of phases to run (default: jira,pull,transform,push).\n");
    printf("  --skip=LIST          Comma separated list of phases to skip.\n");
    printf("  --confirm-pull       Required to execute the pull phase (downloads from Jira).\n");
    printf("  --confirm-push       Required to execute the push phase (uploads to Redmine).\n");
    printf("  --download-limit=N   Limit the number of attachments processed during pull.\n");
    printf("  --upload-limit=N     Limit the number of attachments processed during push.\n");
    printf("  --dry-run            Preview pull/push activity without calling Jira or Redmine.\n");
    printf("  --version            Display version information.\n");
    printf("  --help               Display this help message.\n");
}

function printVersion(): void
{
    printf("%s%s", MIGRATE_ATTACHMENTS_SCRIPT_VERSION, PHP_EOL);
}

/**
 * @param array<string, mixed> $config
 * @throws Throwable
 */
function runAttachmentPullPhase(PDO $pdo, array $config, bool $confirmPull, bool $isDryRun, ?int $downloadLimit): void
{
    $statusBreakdown = summariseAttachmentStatuses($pdo);
    printf("[%s] Attachment queue status:%s", formatCurrentTimestamp(), PHP_EOL);
    foreach ($statusBreakdown as $status => $count) {
        printf("  - %-24s %d%s", $status, $count, PHP_EOL);
    }

    if ($isDryRun) {
        printf("  Dry-run active; Jira attachment downloads are skipped.%s", PHP_EOL);
        return;
    }

    if (!$confirmPull) {
        printf("  Pull confirmation missing; rerun with --confirm-pull to download attachments.%s", PHP_EOL);
        return;
    }

    $jiraClient = createJiraClient(extractArrayConfig($config, 'jira'));
    $downloadSummary = downloadPendingJiraAttachments($jiraClient, $pdo, $config, $downloadLimit);
    printf(
        "[%s] Jira attachment download summary — queued: %d, downloaded: %d, failed: %d.%s",
        formatCurrentTimestamp(),
        $downloadSummary['queued'],
        $downloadSummary['downloaded'],
        $downloadSummary['failed'],
        PHP_EOL
    );
}

/**
 * @param array<string, mixed> $config
 * @throws Throwable
 */
function runAttachmentUploadPhase(PDO $pdo, array $config, bool $confirmPush, bool $isDryRun, ?int $uploadLimit): void
{
    $statusBreakdown = summariseAttachmentStatuses($pdo);
    printf("[%s] Attachment queue status:%s", formatCurrentTimestamp(), PHP_EOL);
    foreach ($statusBreakdown as $status => $count) {
        printf("  - %-24s %d%s", $status, $count, PHP_EOL);
    }

    if ($isDryRun) {
        printf("  Dry-run active; Redmine uploads are skipped.%s", PHP_EOL);
        return;
    }

    if (!$confirmPush) {
        printf("  Push confirmation missing; rerun with --confirm-push to upload attachments.%s", PHP_EOL);
        return;
    }

    $redmineClient = createRedmineClient(extractArrayConfig($config, 'redmine'));
    $uploadSummary = uploadPendingAttachmentsToRedmine($redmineClient, $pdo, $uploadLimit);
    printf(
        "[%s] Redmine upload summary — queued: %d, uploaded: %d, failed: %d.%s",
        formatCurrentTimestamp(),
        $uploadSummary['queued'],
        $uploadSummary['uploaded'],
        $uploadSummary['failed'],
        PHP_EOL
    );
}

/**
 * @return array{requeued_failures: int, status_breakdown: array<string, int>}
 */
function runAttachmentTransformPhase(PDO $pdo): array
{
    $requeued = $pdo->exec(<<<SQL
        UPDATE migration_mapping_attachments
        SET
            migration_status = 'PENDING_DOWNLOAD',
            local_filepath = NULL,
            redmine_upload_token = NULL,
            notes = NULL,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE migration_status = 'FAILED'
    SQL);

    if ($requeued === false) {
        $requeued = 0;
    }

    return [
        'requeued_failures' => (int)$requeued,
        'status_breakdown' => summariseAttachmentStatuses($pdo),
    ];
}

/**
 * @return array<string, int>
 */
function summariseAttachmentStatuses(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT migration_status, COUNT(*) AS total
        FROM migration_mapping_attachments
        GROUP BY migration_status
        ORDER BY migration_status
    SQL;

    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to summarise attachment statuses.');
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
 * @return array{issues_scanned: int, attachments_indexed: int}
 */
function stageAttachmentsFromStagedIssues(PDO $pdo): array
{
    $selectSql = 'SELECT id, issue_key, raw_payload, created_at FROM staging_jira_issues ORDER BY id LIMIT :limit OFFSET :offset';
    $selectStatement = $pdo->prepare($selectSql);
    if ($selectStatement === false) {
        throw new RuntimeException('Failed to prepare issue scan statement for attachment staging.');
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_attachments (
            id,
            issue_id,
            filename,
            author_account_id,
            created_at,
            size_bytes,
            mime_type,
            content_url,
            raw_payload,
            extracted_at
        ) VALUES (
            :id,
            :issue_id,
            :filename,
            :author_account_id,
            :created_at,
            :size_bytes,
            :mime_type,
            :content_url,
            :raw_payload,
            :extracted_at
        )
        ON DUPLICATE KEY UPDATE
            issue_id = VALUES(issue_id),
            filename = VALUES(filename),
            author_account_id = VALUES(author_account_id),
            created_at = VALUES(created_at),
            size_bytes = VALUES(size_bytes),
            mime_type = VALUES(mime_type),
            content_url = VALUES(content_url),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare attachment staging statement.');
    }

    $batchSize = 250;
    $offset = 0;
    $issuesScanned = 0;
    $attachmentsIndexed = 0;
    $now = formatCurrentTimestamp();

    while (true) {
        $selectStatement->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $selectStatement->bindValue(':offset', $offset, PDO::PARAM_INT);

        $executed = $selectStatement->execute();
        if ($executed === false) {
            throw new RuntimeException('Failed to scan staged issues for attachments.');
        }

        $rows = $selectStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $selectStatement->closeCursor();

        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $issuesScanned++;
            $issueId = isset($row['id']) ? (string)$row['id'] : '';
            $issuePayloadJson = isset($row['raw_payload']) ? (string)$row['raw_payload'] : '';
            $issueCreatedAt = isset($row['created_at']) ? (string)$row['created_at'] : null;

            if ($issuePayloadJson === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $issuePayload */
                $issuePayload = json_decode($issuePayloadJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $fields = isset($issuePayload['fields']) && is_array($issuePayload['fields']) ? $issuePayload['fields'] : [];
            $attachments = isset($fields['attachment']) && is_array($fields['attachment']) ? $fields['attachment'] : [];

            foreach ($attachments as $attachment) {
                if (!is_array($attachment) || !isset($attachment['id'])) {
                    continue;
                }

                $attachmentId = (string)$attachment['id'];
                $attachmentFilename = isset($attachment['filename']) ? (string)$attachment['filename'] : ('attachment-' . $attachmentId);
                $attachmentAuthor = isset($attachment['author']['accountId']) ? (string)$attachment['author']['accountId'] : null;
                $attachmentCreated = isset($attachment['created']) ? normalizeDateTimeString($attachment['created']) : null;
                $attachmentSize = isset($attachment['size']) ? normalizeInteger($attachment['size'], 0) : null;
                $attachmentMime = isset($attachment['mimeType']) ? (string)$attachment['mimeType'] : null;
                $attachmentUrl = isset($attachment['content']) ? (string)$attachment['content'] : null;

                if ($attachmentCreated === null) {
                    $attachmentCreated = normalizeDateTimeString($issueCreatedAt);
                }

                try {
                    $attachmentPayload = json_encode($attachment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } catch (JsonException) {
                    continue;
                }

                $insertStatement->execute([
                    'id' => $attachmentId,
                    'issue_id' => $issueId,
                    'filename' => $attachmentFilename,
                    'author_account_id' => $attachmentAuthor,
                    'created_at' => $attachmentCreated,
                    'size_bytes' => $attachmentSize,
                    'mime_type' => $attachmentMime,
                    'content_url' => $attachmentUrl,
                    'raw_payload' => $attachmentPayload,
                    'extracted_at' => $now,
                ]);

                $attachmentsIndexed++;
            }
        }

        if (count($rows) < $batchSize) {
            break;
        }

        $offset += $batchSize;
    }

    return [
        'issues_scanned' => $issuesScanned,
        'attachments_indexed' => $attachmentsIndexed,
    ];
}

/**
 * @return array{new_mappings: int, relinked: int}
 */
function syncAttachmentMappings(PDO $pdo): array
{
    $insertSql = <<<SQL
        INSERT INTO migration_mapping_attachments (jira_attachment_id, jira_issue_id, jira_filesize)
        SELECT att.id, att.issue_id, att.size_bytes
        FROM staging_jira_attachments att
        LEFT JOIN migration_mapping_attachments map ON map.jira_attachment_id = att.id
        WHERE map.jira_attachment_id IS NULL
    SQL;

    try {
        $inserted = $pdo->exec($insertSql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to synchronise migration_mapping_attachments: ' . $exception->getMessage(), 0, $exception);
    }

    if ($inserted === false) {
        $inserted = 0;
    }

    $updateSql = <<<SQL
        UPDATE migration_mapping_attachments map
        JOIN staging_jira_attachments att ON att.id = map.jira_attachment_id
        LEFT JOIN staging_jira_issues issue ON issue.id = att.issue_id
        SET
            map.jira_issue_id = att.issue_id,
            map.jira_filesize = att.size_bytes,
            map.association_hint = CASE
                WHEN issue.created_at IS NULL OR att.created_at IS NULL THEN map.association_hint
                WHEN att.created_at <= DATE_ADD(issue.created_at, INTERVAL 60 SECOND) THEN 'ISSUE'
                ELSE 'JOURNAL'
            END
    SQL;

    try {
        $relinked = $pdo->exec($updateSql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to refresh attachment mappings: ' . $exception->getMessage(), 0, $exception);
    }

    if ($relinked === false) {
        $relinked = 0;
    }

    return [
        'new_mappings' => (int)$inserted,
        'relinked' => (int)$relinked,
    ];
}

/**
 * @return array{queued: int, downloaded: int, failed: int}
 */
function downloadPendingJiraAttachments(Client $client, PDO $pdo, array $config, ?int $limit = null): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_attachment_id,
            att.filename,
            att.content_url,
            att.created_at,
            att.mime_type
        FROM migration_mapping_attachments map
        JOIN staging_jira_attachments att ON att.id = map.jira_attachment_id
        WHERE map.migration_status IN ('PENDING_DOWNLOAD', 'FAILED')
          AND map.download_enabled = 1
        ORDER BY map.mapping_id
    SQL;

    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT :limit';
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare Jira attachment download query.');
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $executed = $statement->execute();
        if ($executed === false) {
            throw new RuntimeException('Failed to execute Jira attachment download query.');
        }
    } else {
        try {
            $statement = $pdo->query($sql);
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to fetch pending Jira attachments: ' . $exception->getMessage(), 0, $exception);
        }
        if ($statement === false) {
            throw new RuntimeException('Failed to fetch pending Jira attachments.');
        }
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    $queued = count($rows);
    if ($queued === 0) {
        return [
            'queued' => 0,
            'downloaded' => 0,
            'failed' => 0,
        ];
    }

    $attachmentDirectory = resolveAttachmentWorkingDirectory($config);
    $downloadConcurrency = max(1, (int)($config['attachments']['download_concurrency'] ?? 1));

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_attachments
        SET
            migration_status = :migration_status,
            local_filepath = :local_filepath,
            notes = :notes,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare attachment update statement.');
    }

    $downloaded = 0;
    $failed = 0;

    if ($downloadConcurrency <= 1) {
        $processed = 0;
        foreach ($rows as $row) {
            $mappingId = (int)$row['mapping_id'];
            $attachmentId = (string)$row['jira_attachment_id'];
            $filename = isset($row['filename']) ? (string)$row['filename'] : '';
            $contentUrl = isset($row['content_url']) ? (string)$row['content_url'] : '';

            if ($contentUrl === '') {
                $updateStatement->execute([
                    'migration_status' => 'FAILED',
                    'local_filepath' => null,
                    'notes' => 'Missing Jira attachment content URL.',
                    'mapping_id' => $mappingId,
                ]);
                $failed++;
                $processed++;
                printAttachmentProgress($processed, $queued);
                continue;
            }

            $sanitizedFilename = sanitizeAttachmentFileName($filename !== '' ? $filename : ('attachment-' . $attachmentId));
            $targetPath = $attachmentDirectory . DIRECTORY_SEPARATOR . $attachmentId . '__' . $sanitizedFilename;

            $handle = null;

            try {
                $response = $client->get($contentUrl, [
                    'headers' => ['Accept' => '*/*'],
                    'stream' => true,
                ]);

                $stream = $response->getBody();
                $handle = fopen($targetPath, 'wb');
                if ($handle === false) {
                    throw new RuntimeException(sprintf('Unable to open attachment path for writing: %s', $targetPath));
                }

                while (!$stream->eof()) {
                    $chunk = $stream->read(8192);
                    if ($chunk === '') {
                        continue;
                    }

                    if (fwrite($handle, $chunk) === false) {
                        throw new RuntimeException(sprintf('Failed to write attachment data to %s', $targetPath));
                    }
                }

                fclose($handle);
                $handle = null;

                $resolvedPath = realpath($targetPath) ?: $targetPath;

                $updateStatement->execute([
                    'migration_status' => 'PENDING_UPLOAD',
                    'local_filepath' => $resolvedPath,
                    'notes' => null,
                    'mapping_id' => $mappingId,
                ]);

                $downloaded++;
            } catch (BadResponseException $exception) {
                $response = $exception->getResponse();
                $message = 'Failed to download attachment from Jira';
                if ($response instanceof ResponseInterface) {
                    $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                    $message .= ': ' . extractErrorBody($response);
                } else {
                    $message .= ': ' . $exception->getMessage();
                }

                if (is_resource($handle)) {
                    fclose($handle);
                }
                if (is_file($targetPath)) {
                    @unlink($targetPath);
                }

                $updateStatement->execute([
                    'migration_status' => 'FAILED',
                    'local_filepath' => null,
                    'notes' => $message,
                    'mapping_id' => $mappingId,
                ]);

                $failed++;
            } catch (GuzzleException | RuntimeException $exception) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                if (is_file($targetPath)) {
                    @unlink($targetPath);
                }

                $updateStatement->execute([
                    'migration_status' => 'FAILED',
                    'local_filepath' => null,
                    'notes' => 'Failed to download attachment: ' . $exception->getMessage(),
                    'mapping_id' => $mappingId,
                ]);

                $failed++;
            }

            $processed++;
            printAttachmentProgress($processed, $queued);
        }
    } else {
        $targetPaths = [];
        foreach ($rows as $index => $row) {
            $attachmentId = (string)$row['jira_attachment_id'];
            $filename = isset($row['filename']) ? (string)$row['filename'] : '';
            $sanitizedFilename = sanitizeAttachmentFileName($filename !== '' ? $filename : ('attachment-' . $attachmentId));
            $targetPaths[$index] = $attachmentDirectory . DIRECTORY_SEPARATOR . $attachmentId . '__' . $sanitizedFilename;
        }

        $processed = 0;
        $pool = new Pool($client, (function () use ($rows, $targetPaths) {
            foreach ($rows as $index => $row) {
                $contentUrl = isset($row['content_url']) ? (string)$row['content_url'] : '';
                $targetPath = $targetPaths[$index];

                yield function () use ($contentUrl, $targetPath) {
                    if ($contentUrl === '') {
                        throw new RuntimeException('Missing Jira attachment content URL.');
                    }

                    return $this->getAsync($contentUrl, [
                        'headers' => ['Accept' => '*/*'],
                        'sink' => $targetPath,
                    ]);
                };
            }
        })->call($client), [
            'concurrency' => $downloadConcurrency,
            'fulfilled' => function ($response, int $index) use (
                &$downloaded,
                &$processed,
                $rows,
                $targetPaths,
                $updateStatement,
                $queued
            ) {
                $row = $rows[$index];
                $mappingId = (int)$row['mapping_id'];
                $targetPath = $targetPaths[$index];

                $resolvedPath = realpath($targetPath) ?: $targetPath;
                $updateStatement->execute([
                    'migration_status' => 'PENDING_UPLOAD',
                    'local_filepath' => $resolvedPath,
                    'notes' => null,
                    'mapping_id' => $mappingId,
                ]);

                $downloaded++;
                $processed++;
                printAttachmentProgress($processed, $queued);
            },
            'rejected' => function ($reason, int $index) use (
                &$failed,
                &$processed,
                $rows,
                $targetPaths,
                $updateStatement,
                $queued
            ) {
                $row = $rows[$index];
                $mappingId = (int)$row['mapping_id'];
                $targetPath = $targetPaths[$index];

                $message = 'Failed to download attachment';
                if ($reason instanceof BadResponseException) {
                    $response = $reason->getResponse();
                    if ($response instanceof ResponseInterface) {
                        $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                        $message .= ': ' . extractErrorBody($response);
                    } else {
                        $message .= ': ' . $reason->getMessage();
                    }
                } elseif ($reason instanceof Throwable) {
                    $message .= ': ' . $reason->getMessage();
                }

                if (is_file($targetPath)) {
                    @unlink($targetPath);
                }

                $updateStatement->execute([
                    'migration_status' => 'FAILED',
                    'local_filepath' => null,
                    'notes' => $message,
                    'mapping_id' => $mappingId,
                ]);

                $failed++;
                $processed++;
                printAttachmentProgress($processed, $queued);
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();
    }

    return [
        'queued' => $queued,
        'downloaded' => $downloaded,
        'failed' => $failed,
    ];
}

/**
 * @return array{queued: int, uploaded: int, failed: int}
 */
function uploadPendingAttachmentsToRedmine(Client $client, PDO $pdo, ?int $limit = null): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.local_filepath,
            att.filename,
            att.mime_type
        FROM migration_mapping_attachments map
        JOIN staging_jira_attachments att ON att.id = map.jira_attachment_id
        WHERE map.migration_status = 'PENDING_UPLOAD'
          AND map.upload_enabled = 1
        ORDER BY map.mapping_id
    SQL;

    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT :limit';
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare Redmine upload query.');
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $executed = $statement->execute();
        if ($executed === false) {
            throw new RuntimeException('Failed to execute Redmine upload query.');
        }
    } else {
        try {
            $statement = $pdo->query($sql);
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to fetch attachments queued for Redmine upload: ' . $exception->getMessage(), 0, $exception);
        }

        if ($statement === false) {
            throw new RuntimeException('Failed to fetch attachments queued for Redmine upload.');
        }
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    $queued = count($rows);
    if ($queued === 0) {
        return [
            'queued' => 0,
            'uploaded' => 0,
            'failed' => 0,
        ];
    }

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_attachments
        SET
            migration_status = :migration_status,
            redmine_upload_token = :redmine_upload_token,
            notes = :notes,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare Redmine attachment update statement.');
    }

    $uploaded = 0;
    $failed = 0;

    foreach ($rows as $row) {
        $mappingId = (int)$row['mapping_id'];
        $localPath = isset($row['local_filepath']) ? (string)$row['local_filepath'] : '';
        $filename = isset($row['filename']) ? (string)$row['filename'] : '';
        $mimeType = isset($row['mime_type']) ? (string)$row['mime_type'] : '';

        if ($localPath === '' || !is_file($localPath)) {
            $updateStatement->execute([
                'migration_status' => 'FAILED',
                'redmine_upload_token' => null,
                'notes' => sprintf('Local attachment missing: %s', $localPath !== '' ? $localPath : '[unknown path]'),
                'mapping_id' => $mappingId,
            ]);
            $failed++;
            continue;
        }

        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            $updateStatement->execute([
                'migration_status' => 'FAILED',
                'redmine_upload_token' => null,
                'notes' => sprintf('Unable to open attachment for upload: %s', $localPath),
                'mapping_id' => $mappingId,
            ]);
            $failed++;
            continue;
        }

        $stat = fstat($handle);
        if ($stat === false || !isset($stat['size'])) {
            fclose($handle);
            $updateStatement->execute([
                'migration_status' => 'FAILED',
                'redmine_upload_token' => null,
                'notes' => sprintf('Unable to determine attachment size: %s', $localPath),
                'mapping_id' => $mappingId,
            ]);
            $failed++;
            continue;
        }

        try {
            $response = $client->post('/uploads.json', [
                'headers' => [
                    'Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
                    'X-File-Name' => basename($filename !== '' ? $filename : $localPath),
                ],
                'body' => stream_get_contents($handle) ?: '',
            ]);

            fclose($handle);
        } catch (BadResponseException $exception) {
            fclose($handle);
            $response = $exception->getResponse();
            $message = 'Failed to upload attachment to Redmine';
            if ($response instanceof ResponseInterface) {
                $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                $message .= ': ' . extractErrorBody($response);
            } else {
                $message .= ': ' . $exception->getMessage();
            }

            $updateStatement->execute([
                'migration_status' => 'FAILED',
                'redmine_upload_token' => null,
                'notes' => $message,
                'mapping_id' => $mappingId,
            ]);

            $failed++;
            continue;
        } catch (GuzzleException $exception) {
            fclose($handle);
            $updateStatement->execute([
                'migration_status' => 'FAILED',
                'redmine_upload_token' => null,
                'notes' => 'Failed to upload attachment to Redmine: ' . $exception->getMessage(),
                'mapping_id' => $mappingId,
            ]);
            $failed++;
            continue;
        }

        $body = decodeJsonResponse($response);
        $token = isset($body['upload']['token']) ? (string)$body['upload']['token'] : '';

        if ($token === '') {
            $updateStatement->execute([
                'migration_status' => 'FAILED',
                'redmine_upload_token' => null,
                'notes' => 'Redmine did not return an upload token.',
                'mapping_id' => $mappingId,
            ]);

            $failed++;
            continue;
        }

        $updateStatement->execute([
            'migration_status' => 'PENDING_ASSOCIATION',
            'redmine_upload_token' => $token,
            'notes' => null,
            'mapping_id' => $mappingId,
        ]);

        $uploaded++;
    }

    return [
        'queued' => $queued,
        'uploaded' => $uploaded,
        'failed' => $failed,
    ];
}

function resolveAttachmentWorkingDirectory(array $config): string
{
    $paths = $config['paths'] ?? [];
    $tmpBase = resolveBasePath(isset($paths['tmp']) ? (string)$paths['tmp'] : (dirname(__DIR__) . '/tmp'));
    $directory = rtrim($tmpBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR . 'jira';

    if (!is_dir($directory)) {
        $created = @mkdir($directory, 0775, true);
        if ($created === false && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create attachment working directory: %s', $directory));
        }
    }

    return $directory;
}

function resolveBasePath(string $path): string
{
    if ($path === '') {
        throw new RuntimeException('Empty path provided for temporary directory.');
    }

    if (isAbsolutePath($path)) {
        return $path;
    }

    $projectRoot = dirname(__DIR__);
    return $projectRoot . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
}

function isAbsolutePath(string $path): bool
{
    return str_starts_with($path, DIRECTORY_SEPARATOR)
        || preg_match('/^[A-Za-z]:\\\\|^[A-Za-z]:\//', $path) === 1;
}

function printAttachmentProgress(int $processed, int $total): void
{
    printf("  Progress: %d/%d attachments processed%s", $processed, $total, PHP_EOL);
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
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_pull: bool, confirm_push: bool, dry_run: bool, download_limit: ?int, upload_limit: ?int} $cliOptions
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
 * @return array{0: array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_pull: bool, confirm_push: bool, dry_run: bool, download_limit: ?int, upload_limit: ?int}, 1: list<string>}
 */
function parseCommandLineOptions(array $argv): array
{
    $options = [
        'help' => false,
        'version' => false,
        'phases' => null,
        'skip' => null,
        'confirm_pull' => false,
        'confirm_push' => false,
        'dry_run' => false,
        'download_limit' => null,
        'upload_limit' => null,
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

        if ($argument === '--confirm-pull') {
            $options['confirm_pull'] = true;
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

        if (str_starts_with($argument, '--download-limit=')) {
            $value = substr($argument, 17);
            $options['download_limit'] = parsePositiveIntOption($value, '--download-limit');
            continue;
        }

        if (str_starts_with($argument, '--upload-limit=')) {
            $value = substr($argument, 15);
            $options['upload_limit'] = parsePositiveIntOption($value, '--upload-limit');
            continue;
        }

        $arguments[] = $argument;
    }

    return [$options, $arguments];
}

function parsePositiveIntOption(string $value, string $optionName): int
{
    $trimmed = trim($value);
    if ($trimmed === '' || !preg_match('/^\d+$/', $trimmed)) {
        throw new RuntimeException(sprintf('Invalid %s value: %s', $optionName, $value));
    }

    $intValue = (int)$trimmed;
    if ($intValue <= 0) {
        throw new RuntimeException(sprintf('%s expects a positive integer.', $optionName));
    }

    return $intValue;
}

function normalizeDateTimeString(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    try {
        $dateTime = new DateTimeImmutable($trimmed);
    } catch (Exception) {
        return null;
    }

    return $dateTime->format('Y-m-d H:i:s');
}

function normalizeInteger(mixed $value, int $min = PHP_INT_MIN, ?int $max = null): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_int($value)) {
        $intValue = $value;
    } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
        $intValue = (int)trim($value);
    } else {
        return null;
    }

    if ($intValue < $min) {
        return $min;
    }

    if ($max !== null && $intValue > $max) {
        return $max;
    }

    return $intValue;
}

function formatCurrentTimestamp(): string
{
    return date('Y-m-d H:i:s');
}

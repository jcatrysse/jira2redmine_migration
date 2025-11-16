<?php /** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_ISSUES_SCRIPT_VERSION = '0.0.22';
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira issues into staging_jira_issues (and related staging tables).',
    'transform' => 'Reconcile Jira issues with Redmine dependencies to populate migration mappings.',
    'push' => 'Create missing Redmine issues based on the mapping proposals.',
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
        $jiraConfig = extractArrayConfig($config, 'jira');
        $jiraClient = createJiraClient($jiraConfig);

        printf("[%s] Starting Jira issue extraction...%s", formatCurrentTimestamp(), PHP_EOL);

        $jiraSummary = fetchAndStoreJiraIssues($jiraClient, $pdo, $config);

        $attachmentSyncSummary = syncAttachmentMappings($pdo);

        printf(
            "[%s] Completed Jira extraction. Issues: %d (updated %d), Attachments: %d metadata, Labels captured: %d.%s",
            formatCurrentTimestamp(),
            $jiraSummary['issues_processed'],
            $jiraSummary['issues_updated'],
            $jiraSummary['attachments_processed'],
            $jiraSummary['labels_processed'],
            PHP_EOL
        );

        if ($attachmentSyncSummary['new_mappings'] > 0) {
            printf(
                "[%s] Added %d new attachment mapping row(s).%s",
                formatCurrentTimestamp(),
                $attachmentSyncSummary['new_mappings'],
                PHP_EOL
            );
        }
    } else {
        printf(
            "[%s] Skipping Jira issue extraction (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Starting issue reconciliation & transform phase...%s", formatCurrentTimestamp(), PHP_EOL);

        $transformSummary = runIssueTransformationPhase($pdo, $config);

        printf(
            "[%s] Completed transform phase. Matched: %d, Ready: %d, Manual: %d, Overrides kept: %d, Skipped: %d, Unchanged: %d.%s",
            formatCurrentTimestamp(),
            $transformSummary['matched'],
            $transformSummary['ready_for_creation'],
            $transformSummary['manual_review'],
            $transformSummary['manual_overrides'],
            $transformSummary['skipped'],
            $transformSummary['unchanged'],
            PHP_EOL
        );

        if ($transformSummary['status_counts'] !== []) {
            printf("  Current issue mapping breakdown:%s", PHP_EOL);
            foreach ($transformSummary['status_counts'] as $status => $count) {
                printf("  - %-32s %d%s", $status, $count, PHP_EOL);
            }
        }
    } else {
        printf(
            "[%s] Skipping transform phase (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('push', $phasesToRun, true)) {
        $confirmPush = (bool)($cliOptions['confirm_push'] ?? false);
        $isDryRun = (bool)($cliOptions['dry_run'] ?? false);
        $redmineConfig = extractArrayConfig($config, 'redmine');

        if (!empty($cliOptions['use_extended_api'])) {
            printf(
                "[%s] Extended API option supplied but ignored (issue creation uses the core Redmine REST API).%s",
                formatCurrentTimestamp(),
                PHP_EOL
            );
        }

        runIssuePushPhase($pdo, $confirmPush, $isDryRun, $redmineConfig);
    } else {
        printf(
            "[%s] Skipping push phase (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }
}
/**
 * @param array<string, mixed> $config
 * @return array{issues_processed: int, issues_updated: int, attachments_processed: int, labels_processed: int}
 * @throws Throwable
 */
function fetchAndStoreJiraIssues(Client $client, PDO $pdo, array $config): array
{
    $issueConfig = $config['migration']['issues'] ?? [];
    $baseJqlFilter = isset($issueConfig['jql']) ? trim((string)$issueConfig['jql']) : '';
    if ($baseJqlFilter !== '') {
        $baseJqlFilter = (string)preg_replace('/ORDER\s+BY.+$/i', '', $baseJqlFilter);
        $baseJqlFilter = trim($baseJqlFilter);
    }

    $batchSize = isset($issueConfig['batch_size']) ? (int)$issueConfig['batch_size'] : 100;
    if ($batchSize < 1) {
        $batchSize = 1;
    }
    if ($batchSize > 100) {
        $batchSize = 100;
    }

    $queryBase = [
        'maxResults' => $batchSize,
        'fields' => ['*all'],
    ];

    $totalIssuesProcessed = 0;
    $totalIssuesUpdated = 0;
    $totalAttachmentsProcessed = 0;
    $totalLabelsProcessed = 0;
    $now = formatCurrentTimestamp('Y-m-d H:i:s');

    $insertIssueStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_issues (
            id,
            issue_key,
            summary,
            description_adf,
            project_id,
            issuetype_id,
            status_id,
            status_category_key,
            priority_id,
            reporter_account_id,
            assignee_account_id,
            parent_issue_id,
            due_date,
            time_original_estimate,
            time_remaining_estimate,
            time_spent,
            labels,
            fix_version_ids,
            component_ids,
            created_at,
            updated_at,
            raw_payload,
            extracted_at
        ) VALUES (
            :id,
            :issue_key,
            :summary,
            :description_adf,
            :project_id,
            :issuetype_id,
            :status_id,
            :status_category_key,
            :priority_id,
            :reporter_account_id,
            :assignee_account_id,
            :parent_issue_id,
            :due_date,
            :time_original_estimate,
            :time_remaining_estimate,
            :time_spent,
            :labels,
            :fix_version_ids,
            :component_ids,
            :created_at,
            :updated_at,
            :raw_payload,
            :extracted_at
        )
        ON DUPLICATE KEY UPDATE
            issue_key = VALUES(issue_key),
            summary = VALUES(summary),
            description_adf = VALUES(description_adf),
            project_id = VALUES(project_id),
            issuetype_id = VALUES(issuetype_id),
            status_id = VALUES(status_id),
            status_category_key = VALUES(status_category_key),
            priority_id = VALUES(priority_id),
            reporter_account_id = VALUES(reporter_account_id),
            assignee_account_id = VALUES(assignee_account_id),
            parent_issue_id = VALUES(parent_issue_id),
            due_date = VALUES(due_date),
            time_original_estimate = VALUES(time_original_estimate),
            time_remaining_estimate = VALUES(time_remaining_estimate),
            time_spent = VALUES(time_spent),
            labels = VALUES(labels),
            fix_version_ids = VALUES(fix_version_ids),
            component_ids = VALUES(component_ids),
            created_at = VALUES(created_at),
            updated_at = VALUES(updated_at),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertIssueStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_issues.');
    }

    $insertLabelStatement = $pdo->prepare('INSERT INTO staging_jira_labels (label_name) VALUES (:label_name) ON DUPLICATE KEY UPDATE label_name = VALUES(label_name)');
    if ($insertLabelStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_labels.');
    }

    $insertAttachmentStatement = $pdo->prepare(<<<SQL
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

    if ($insertAttachmentStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_attachments.');
    }

    $projectSql = <<<SQL
        SELECT p.project_key, p.id AS jira_project_id
        FROM migration_mapping_projects AS map
        JOIN staging_jira_projects AS p ON p.id = map.jira_project_id
        WHERE map.issues_extracted_at IS NULL
        ORDER BY p.project_key
    SQL;

    $projectQuery = $pdo->query($projectSql);
    if ($projectQuery === false) {
        throw new RuntimeException('Failed to fetch Jira project list for issue extraction.');
    }

    $projectsToMigrate = $projectQuery->fetchAll(PDO::FETCH_ASSOC);
    $projectQuery->closeCursor();

    if ($projectsToMigrate === []) {
        printf("  All projects already have issues staged (issues_extracted_at is set).%s", PHP_EOL);
    }

    $updateProjectStatusStmt = $pdo->prepare('UPDATE migration_mapping_projects SET issues_extracted_at = :now WHERE jira_project_id = :id');
    if ($updateProjectStatusStmt === false) {
        throw new RuntimeException('Failed to prepare project status update statement.');
    }

    foreach ($projectsToMigrate as $project) {
        $projectKey = isset($project['project_key']) ? (string)$project['project_key'] : '';
        $jiraProjectId = isset($project['jira_project_id']) ? (string)$project['jira_project_id'] : '';

        if ($projectKey === '') {
            printf("  [WARN] Skipping Jira project %s because no key was found.%s", $jiraProjectId !== '' ? $jiraProjectId : '[unknown]', PHP_EOL);
            continue;
        }

        printf("  [%s] Processing project %s%s", formatCurrentTimestamp(), $projectKey, PHP_EOL);

        $lastSeenIssueId = null;
        $projectFailed = false;
        $projectIssueCounter = 0;

        while (true) {
            $query = $queryBase;

            $conditions = [];
            $escapedProjectKey = addcslashes($projectKey, "\"\\");
            $conditions[] = sprintf('project = "%s"', $escapedProjectKey);

            if ($baseJqlFilter !== '') {
                $conditions[] = sprintf('(%s)', $baseJqlFilter);
            }

            if ($lastSeenIssueId !== null) {
                $conditions[] = sprintf('id > %d', $lastSeenIssueId);
            }

            $query['jql'] = sprintf('%s ORDER BY id ASC', implode(' AND ', $conditions));

            try {
                $response = $client->post('/rest/api/3/search/jql', ['json' => $query]);
            } catch (BadResponseException $exception) {
                $response = $exception->getResponse();
                $message = sprintf('Failed to fetch issues from Jira for project %s', $projectKey);
                if ($response instanceof ResponseInterface) {
                    $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                    $message .= ': ' . extractErrorBody($response);
                }
                fwrite(STDERR, sprintf("[ERROR] %s%s", $message, PHP_EOL));
                $projectFailed = true;
                break;
            } catch (GuzzleException $exception) {
                $message = sprintf('Failed to fetch issues from Jira for project %s: %s', $projectKey, $exception->getMessage());
                fwrite(STDERR, sprintf("[ERROR] %s%s", $message, PHP_EOL));
                $projectFailed = true;
                break;
            }

            $payload = decodeJsonResponse($response);
            $issues = isset($payload['issues']) && is_array($payload['issues']) ? $payload['issues'] : [];
            $fetchedCount = count($issues);

            if ($fetchedCount === 0) {
                break;
            }

            foreach ($issues as $issue) {
                if (!is_array($issue)) {
                    continue;
                }

                $issueId = isset($issue['id']) ? (string)$issue['id'] : '';
                $issueKey = isset($issue['key']) ? (string)$issue['key'] : '';
                if ($issueId === '' || $issueKey === '') {
                    continue;
                }

                $issueNumericId = (int)$issueId;
                if ($issueNumericId > 0) {
                    $lastSeenIssueId = max($lastSeenIssueId ?? 0, $issueNumericId);
                }

                $fields = isset($issue['fields']) && is_array($issue['fields']) ? $issue['fields'] : [];
                $summary = isset($fields['summary']) ? truncateString((string)$fields['summary'], 255) : '';
                if ($summary === '') {
                    $summary = sprintf('[No summary] %s', $issueKey);
                }

                $issueProjectId = isset($fields['project']['id']) ? (string)$fields['project']['id'] : '';
                if ($issueProjectId === '' && $jiraProjectId !== '') {
                    $issueProjectId = $jiraProjectId;
                }

                $issueTypeId = isset($fields['issuetype']['id']) ? (string)$fields['issuetype']['id'] : '';
                $statusId = isset($fields['status']['id']) ? (string)$fields['status']['id'] : '';
                $statusCategoryKey = isset($fields['status']['statusCategory']['key']) ? (string)$fields['status']['statusCategory']['key'] : null;
                $priorityId = isset($fields['priority']['id']) ? (string)$fields['priority']['id'] : null;
                $reporterAccountId = isset($fields['reporter']['accountId']) ? (string)$fields['reporter']['accountId'] : null;
                $assigneeAccountId = isset($fields['assignee']['accountId']) ? (string)$fields['assignee']['accountId'] : null;
                $parentIssueId = isset($fields['parent']['id']) ? (string)$fields['parent']['id'] : null;
                $dueDate = isset($fields['duedate']) ? normalizeDateString($fields['duedate']) : null;

                $timeOriginalEstimate = isset($fields['timeoriginalestimate']) ? normalizeInteger($fields['timeoriginalestimate'], 0) : null;
                $timeRemainingEstimate = isset($fields['timeestimate']) ? normalizeInteger($fields['timeestimate'], 0) : null;
                $timeSpent = isset($fields['timespent']) ? normalizeInteger($fields['timespent'], 0) : null;

                $labels = [];
                if (isset($fields['labels']) && is_array($fields['labels'])) {
                    foreach ($fields['labels'] as $label) {
                        if (!is_string($label)) {
                            continue;
                        }

                        $normalizedLabel = trim($label);
                        if ($normalizedLabel === '') {
                            continue;
                        }

                        $labels[] = $normalizedLabel;
                        $insertLabelStatement->execute(['label_name' => $normalizedLabel]);
                        $totalLabelsProcessed++;
                    }
                }

                $fixVersionIds = [];
                if (isset($fields['fixVersions']) && is_array($fields['fixVersions'])) {
                    foreach ($fields['fixVersions'] as $fixVersion) {
                        if (is_array($fixVersion) && isset($fixVersion['id'])) {
                            $fixVersionIds[] = (string)$fixVersion['id'];
                        }
                    }
                }

                $componentIds = [];
                if (isset($fields['components']) && is_array($fields['components'])) {
                    foreach ($fields['components'] as $component) {
                        if (is_array($component) && isset($component['id'])) {
                            $componentIds[] = (string)$component['id'];
                        }
                    }
                }

                $createdAt = isset($fields['created']) ? normalizeDateTimeString($fields['created']) : null;
                $updatedAt = isset($fields['updated']) ? normalizeDateTimeString($fields['updated']) : null;
                if ($createdAt === null) {
                    $createdAt = formatCurrentTimestamp('Y-m-d H:i:s');
                }
                if ($updatedAt === null) {
                    $updatedAt = $createdAt;
                }

                $description = $fields['description'] ?? null;
                $descriptionJson = null;
                if ($description !== null) {
                    try {
                        $descriptionJson = json_encode($description, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } catch (JsonException $exception) {
                        throw new RuntimeException('Failed to encode Jira issue description: ' . $exception->getMessage(), 0, $exception);
                    }
                }

                try {
                    $rawPayload = json_encode($issue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Failed to encode Jira issue payload: ' . $exception->getMessage(), 0, $exception);
                }

                $insertIssueStatement->execute([
                    'id' => $issueId,
                    'issue_key' => $issueKey,
                    'summary' => $summary,
                    'description_adf' => $descriptionJson,
                    'project_id' => $issueProjectId,
                    'issuetype_id' => $issueTypeId,
                    'status_id' => $statusId,
                    'status_category_key' => $statusCategoryKey,
                    'priority_id' => $priorityId,
                    'reporter_account_id' => $reporterAccountId,
                    'assignee_account_id' => $assigneeAccountId,
                    'parent_issue_id' => $parentIssueId,
                    'due_date' => $dueDate,
                    'time_original_estimate' => $timeOriginalEstimate,
                    'time_remaining_estimate' => $timeRemainingEstimate,
                    'time_spent' => $timeSpent,
                    'labels' => encodeJsonIfNotNull($labels),
                    'fix_version_ids' => encodeJsonIfNotNull($fixVersionIds),
                    'component_ids' => encodeJsonIfNotNull($componentIds),
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                    'raw_payload' => $rawPayload,
                    'extracted_at' => $now,
                ]);

                $rowCount = $insertIssueStatement->rowCount();
                if ($rowCount === 1) {
                    $totalIssuesProcessed++;
                    $projectIssueCounter++;
                } else {
                    $totalIssuesUpdated++;
                }

                if (isset($fields['attachment']) && is_array($fields['attachment'])) {
                    foreach ($fields['attachment'] as $attachment) {
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
                            $attachmentCreated = $createdAt;
                        }

                        try {
                            $attachmentPayload = json_encode($attachment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        } catch (JsonException $exception) {
                            throw new RuntimeException('Failed to encode Jira attachment payload: ' . $exception->getMessage(), 0, $exception);
                        }

                        $insertAttachmentStatement->execute([
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

                        $totalAttachmentsProcessed++;
                    }
                }
            }

            printf(
                "    ... %d issues processed in this batch (project total: %d, overall total: %d)%s",
                $fetchedCount,
                $projectIssueCounter,
                $totalIssuesProcessed,
                PHP_EOL
            );

            if ($fetchedCount < $batchSize) {
                break;
            }
        }

        if (!$projectFailed) {
            $updateProjectStatusStmt->execute([
                'now' => $now,
                'id' => $jiraProjectId,
            ]);
            printf("  [%s] Finished project %s.%s", formatCurrentTimestamp(), $projectKey, PHP_EOL);
        } else {
            printf("  [%s] Project %s skipped due to errors.%s", formatCurrentTimestamp(), $projectKey, PHP_EOL);
        }
    }

    return [
        'issues_processed' => $totalIssuesProcessed,
        'issues_updated' => $totalIssuesUpdated,
        'attachments_processed' => $totalAttachmentsProcessed,
        'labels_processed' => $totalLabelsProcessed,
    ];
}

function runIssueTransformationPhase(PDO $pdo, array $config): array
{
    syncIssueMappings($pdo);
    syncAttachmentMappings($pdo);

    $projectLookup = buildProjectLookup($pdo);
    $trackerLookup = buildTrackerLookup($pdo);
    $statusLookup = buildStatusLookup($pdo);
    $priorityLookup = buildPriorityLookup($pdo);
    $userLookup = buildUserLookup($pdo);

    $issueConfig = $config['migration']['issues'] ?? [];
    $defaultProjectId = isset($issueConfig['default_redmine_project_id']) ? normalizeInteger($issueConfig['default_redmine_project_id'], 1) : null;
    $defaultTrackerId = isset($issueConfig['default_redmine_tracker_id']) ? normalizeInteger($issueConfig['default_redmine_tracker_id'], 1) : null;
    $defaultStatusId = isset($issueConfig['default_redmine_status_id']) ? normalizeInteger($issueConfig['default_redmine_status_id'], 1) : null;
    $defaultPriorityId = isset($issueConfig['default_redmine_priority_id']) ? normalizeInteger($issueConfig['default_redmine_priority_id'], 1) : null;
    $defaultAuthorId = isset($issueConfig['default_redmine_author_id']) ? normalizeInteger($issueConfig['default_redmine_author_id'], 1) : null;
    $defaultAssigneeId = isset($issueConfig['default_redmine_assignee_id']) ? normalizeInteger($issueConfig['default_redmine_assignee_id'], 1) : null;
    $defaultIsPrivate = array_key_exists('default_is_private', $issueConfig) ? normalizeBooleanFlag($issueConfig['default_is_private']) : null;

    $mappings = fetchIssueMappingsForTransform($pdo);

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_issues
        SET
            redmine_project_id = :redmine_project_id,
            redmine_tracker_id = :redmine_tracker_id,
            redmine_status_id = :redmine_status_id,
            redmine_priority_id = :redmine_priority_id,
            redmine_author_id = :redmine_author_id,
            redmine_assigned_to_id = :redmine_assigned_to_id,
            redmine_parent_issue_id = :redmine_parent_issue_id,
            proposed_project_id = :proposed_project_id,
            proposed_tracker_id = :proposed_tracker_id,
            proposed_status_id = :proposed_status_id,
            proposed_priority_id = :proposed_priority_id,
            proposed_author_id = :proposed_author_id,
            proposed_assigned_to_id = :proposed_assigned_to_id,
            proposed_parent_issue_id = :proposed_parent_issue_id,
            proposed_subject = :proposed_subject,
            proposed_description = :proposed_description,
            proposed_start_date = :proposed_start_date,
            proposed_due_date = :proposed_due_date,
            proposed_done_ratio = :proposed_done_ratio,
            proposed_estimated_hours = :proposed_estimated_hours,
            proposed_is_private = :proposed_is_private,
            proposed_custom_field_payload = :proposed_custom_field_payload,
            migration_status = :migration_status,
            notes = :notes,
            automation_hash = :automation_hash,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_issues.');
    }

    $summary = [
        'matched' => 0,
        'ready_for_creation' => 0,
        'manual_review' => 0,
        'manual_overrides' => 0,
        'skipped' => 0,
        'unchanged' => 0,
        'status_counts' => [],
    ];

    foreach ($mappings as $row) {
        $currentStatus = (string)$row['migration_status'];
        $allowedStatuses = ['PENDING_ANALYSIS', 'READY_FOR_CREATION', 'MATCH_FOUND', 'CREATION_FAILED'];
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            $summary['skipped']++;
            continue;
        }

        $jiraIssueKey = (string)$row['jira_issue_key'];
        $jiraProjectId = (string)$row['jira_project_id'];
        $jiraIssueTypeId = (string)$row['jira_issue_type_id'];
        $jiraStatusId = (string)$row['jira_status_id'];
        $jiraPriorityId = $row['jira_priority_id'] !== null ? (string)$row['jira_priority_id'] : null;
        $jiraReporterId = $row['jira_reporter_account_id'] !== null ? (string)$row['jira_reporter_account_id'] : null;
        $jiraAssigneeId = $row['jira_assignee_account_id'] !== null ? (string)$row['jira_assignee_account_id'] : null;
        $jiraParentId = $row['jira_parent_issue_id'] !== null ? (string)$row['jira_parent_issue_id'] : null;

        $currentAutomationHash = computeIssueAutomationStateHash(
            $row['redmine_issue_id'],
            $row['redmine_project_id'],
            $row['redmine_tracker_id'],
            $row['redmine_status_id'],
            $row['redmine_priority_id'],
            $row['redmine_author_id'],
            $row['redmine_assigned_to_id'],
            $row['redmine_parent_issue_id'],
            $row['proposed_project_id'],
            $row['proposed_tracker_id'],
            $row['proposed_status_id'],
            $row['proposed_priority_id'],
            $row['proposed_author_id'],
            $row['proposed_assigned_to_id'],
            $row['proposed_parent_issue_id'],
            $row['proposed_subject'],
            $row['proposed_description'],
            $row['proposed_start_date'],
            $row['proposed_due_date'],
            $row['proposed_done_ratio'],
            $row['proposed_estimated_hours'],
            $row['proposed_is_private'],
            $row['proposed_custom_field_payload'],
            $row['migration_status'],
            $row['notes']
        );

        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);
        if ($storedAutomationHash !== null && $storedAutomationHash !== $currentAutomationHash) {
            $summary['manual_overrides']++;
            printf(
                "  [preserved] Jira issue %s has manual overrides; skipping automated changes.%s",
                $jiraIssueKey,
                PHP_EOL
            );
            continue;
        }

        $issueSummary = isset($row['jira_summary']) ? (string)$row['jira_summary'] : $jiraIssueKey;
        $issueDescriptionAdf = $row['jira_description_adf'] ?? null;
        $issueCreatedAt = isset($row['jira_created_at']) ? (string)$row['jira_created_at'] : null;
        $issueDueDate = isset($row['jira_due_date']) ? (string)$row['jira_due_date'] : null;
        $issueStatusCategory = isset($row['jira_status_category_key']) ? (string)$row['jira_status_category_key'] : null;
        $issueTimeOriginalEstimate = isset($row['jira_time_original_estimate']) ? normalizeInteger($row['jira_time_original_estimate'], 0) : null;
        $issueRawPayload = isset($row['jira_raw_payload']) ? (string)$row['jira_raw_payload'] : null;

        $redmineProjectId = resolveRedmineProjectId($projectLookup, $jiraProjectId);
        $redmineTrackerId = resolveRedmineTrackerId($trackerLookup, $jiraIssueTypeId);
        $redmineStatusId = resolveRedmineStatusId($statusLookup, $jiraStatusId);
        $redminePriorityId = $jiraPriorityId !== null ? resolveRedminePriorityId($priorityLookup, $jiraPriorityId) : null;
        $redmineAuthorId = $jiraReporterId !== null ? resolveRedmineUserId($userLookup, $jiraReporterId) : null;
        $redmineAssigneeId = $jiraAssigneeId !== null ? resolveRedmineUserId($userLookup, $jiraAssigneeId) : null;
        $redmineParentIssueId = $jiraParentId !== null ? resolveRedmineParentIssueId($pdo, $jiraParentId) : null;

        $proposedProjectId = $redmineProjectId ?? $defaultProjectId;
        $proposedTrackerId = $redmineTrackerId ?? $defaultTrackerId;
        $proposedStatusId = $redmineStatusId ?? $defaultStatusId;
        $proposedPriorityId = $redminePriorityId ?? $defaultPriorityId;
        $proposedAuthorId = $redmineAuthorId ?? $defaultAuthorId;
        $proposedAssigneeId = $redmineAssigneeId ?? $defaultAssigneeId;
        $proposedParentIssueId = $redmineParentIssueId;

        $proposedSubject = truncateString($issueSummary, 255);
        $proposedDescription = $issueDescriptionAdf !== null ? convertJiraAdfToPlaintext($issueDescriptionAdf) : null;
        $proposedStartDate = $issueCreatedAt !== null ? substr($issueCreatedAt, 0, 10) : null;
        $proposedDueDate = $issueDueDate !== null ? $issueDueDate : null;
        $proposedDoneRatio = ($issueStatusCategory !== null && strtolower($issueStatusCategory) === 'done') ? 100 : null;
        $proposedEstimatedHours = $issueTimeOriginalEstimate !== null ? round($issueTimeOriginalEstimate / 3600, 2) : null;
        $proposedIsPrivate = $defaultIsPrivate;
        $proposedCustomFieldPayload = null;

        if ($issueRawPayload !== null) {
            $decodedIssue = json_decode($issueRawPayload, true);
            if (is_array($decodedIssue)) {
                $security = $decodedIssue['fields']['security'] ?? null;
                if ($security !== null) {
                    $proposedIsPrivate = true;
                }
            }
        }

        $notes = [];
        $nextStatus = $currentStatus;

        if ($row['redmine_issue_id'] !== null) {
            $nextStatus = 'MATCH_FOUND';
        } else {
            if ($proposedProjectId === null) {
                $notes[] = 'Project not mapped to a Redmine identifier.';
            }
            if ($proposedTrackerId === null) {
                $notes[] = 'Tracker not mapped to a Redmine identifier.';
            }
            if ($proposedStatusId === null) {
                $notes[] = 'Status not mapped to a Redmine identifier.';
            }
            if ($jiraPriorityId !== null && $proposedPriorityId === null) {
                $notes[] = 'Priority not mapped to a Redmine identifier.';
            }
            if ($jiraReporterId !== null && $proposedAuthorId === null) {
                $notes[] = 'Reporter not mapped to a Redmine user (and no default configured).';
            }
            if ($jiraAssigneeId !== null && $proposedAssigneeId === null) {
                $notes[] = 'Assignee not mapped to a Redmine user (and no default configured).';
            }
            if ($jiraParentId !== null && $proposedParentIssueId === null) {
                $notes[] = 'Parent issue not yet created in Redmine.';
            }

            if ($notes === []) {
                $nextStatus = 'READY_FOR_CREATION';
            } else {
                $nextStatus = 'MANUAL_INTERVENTION_REQUIRED';
            }
        }

        $notesMessage = $notes !== [] ? implode(' ', $notes) : null;

        $newAutomationHash = computeIssueAutomationStateHash(
            $row['redmine_issue_id'],
            $redmineProjectId,
            $redmineTrackerId,
            $redmineStatusId,
            $redminePriorityId,
            $redmineAuthorId,
            $redmineAssigneeId,
            $redmineParentIssueId,
            $proposedProjectId,
            $proposedTrackerId,
            $proposedStatusId,
            $proposedPriorityId,
            $proposedAuthorId,
            $proposedAssigneeId,
            $proposedParentIssueId,
            $proposedSubject,
            $proposedDescription,
            $proposedStartDate,
            $proposedDueDate,
            $proposedDoneRatio,
            $proposedEstimatedHours,
            $proposedIsPrivate,
            $proposedCustomFieldPayload,
            $nextStatus,
            $notesMessage
        );

        $updateStatement->execute([
            'mapping_id' => (int)$row['mapping_id'],
            'redmine_project_id' => $redmineProjectId,
            'redmine_tracker_id' => $redmineTrackerId,
            'redmine_status_id' => $redmineStatusId,
            'redmine_priority_id' => $redminePriorityId,
            'redmine_author_id' => $redmineAuthorId,
            'redmine_assigned_to_id' => $redmineAssigneeId,
            'redmine_parent_issue_id' => $redmineParentIssueId,
            'proposed_project_id' => $proposedProjectId,
            'proposed_tracker_id' => $proposedTrackerId,
            'proposed_status_id' => $proposedStatusId,
            'proposed_priority_id' => $proposedPriorityId,
            'proposed_author_id' => $proposedAuthorId,
            'proposed_assigned_to_id' => $proposedAssigneeId,
            'proposed_parent_issue_id' => $proposedParentIssueId,
            'proposed_subject' => $proposedSubject,
            'proposed_description' => $proposedDescription,
            'proposed_start_date' => $proposedStartDate,
            'proposed_due_date' => $proposedDueDate,
            'proposed_done_ratio' => $proposedDoneRatio,
            'proposed_estimated_hours' => $proposedEstimatedHours,
            'proposed_is_private' => $proposedIsPrivate,
            'proposed_custom_field_payload' => $proposedCustomFieldPayload,
            'migration_status' => $nextStatus,
            'notes' => $notesMessage,
            'automation_hash' => $newAutomationHash,
        ]);

        if ($currentStatus === $nextStatus && $storedAutomationHash === $newAutomationHash) {
            $summary['unchanged']++;
        } elseif ($nextStatus === 'MATCH_FOUND') {
            $summary['matched']++;
        } elseif ($nextStatus === 'READY_FOR_CREATION') {
            $summary['ready_for_creation']++;
        } elseif ($nextStatus === 'MANUAL_INTERVENTION_REQUIRED') {
            $summary['manual_review']++;
        }

        $summary['status_counts'][$nextStatus] = ($summary['status_counts'][$nextStatus] ?? 0) + 1;
    }

    ksort($summary['status_counts']);

    return $summary;
}
/**
 * @throws Throwable
 */
function runIssuePushPhase(PDO $pdo, bool $confirmPush, bool $isDryRun, array $redmineConfig): void
{
    syncAttachmentMappings($pdo);

    $candidateStatement = $pdo->prepare(<<<SQL
        SELECT *
        FROM migration_mapping_issues
        WHERE migration_status = 'READY_FOR_CREATION'
        ORDER BY mapping_id
    SQL);

    if ($candidateStatement === false) {
        throw new RuntimeException('Failed to prepare issue selection statement for push phase.');
    }

    $candidateStatement->execute();
    $candidates = $candidateStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $candidateStatement->closeCursor();

    if ($candidates === []) {
        printf("  No issues queued for creation.%s", PHP_EOL);
        $danglingAttachments = countAttachmentsAwaitingAssociation($pdo);
        if ($danglingAttachments > 0) {
            printf("  %d attachment(s) still waiting for upload tokens in migration_mapping_attachments.%s", $danglingAttachments, PHP_EOL);
        }
        return;
    }

    printf("  %d issue(s) queued for creation.%s", count($candidates), PHP_EOL);

    $attachmentsByIssue = [];
    foreach ($candidates as $candidate) {
        $jiraIssueId = (string)$candidate['jira_issue_id'];
        $attachmentsByIssue[$jiraIssueId] = fetchPreparedAttachmentUploads($pdo, $jiraIssueId);

        $subject = isset($candidate['proposed_subject']) ? (string)$candidate['proposed_subject'] : '[missing subject]';
        $projectId = $candidate['proposed_project_id'] !== null ? (int)$candidate['proposed_project_id'] : null;
        $trackerId = $candidate['proposed_tracker_id'] !== null ? (int)$candidate['proposed_tracker_id'] : null;
        $statusId = $candidate['proposed_status_id'] !== null ? (int)$candidate['proposed_status_id'] : null;

        if ($projectId === null || $trackerId === null || $statusId === null) {
            printf(
                "  [skip] Jira issue %s is missing mandatory proposed attributes (project/tracker/status).%s",
                $candidate['jira_issue_key'],
                PHP_EOL
            );
            continue;
        }

        $attachmentSummary = summarizeAttachmentStatusesForIssue($pdo, $jiraIssueId);
        $readyCount = $attachmentSummary['ready'];
        $blockedCount = $attachmentSummary['blocked'];

        printf(
            "  - %s → project %d / tracker %d / status %d%s",
            $subject,
            $projectId,
            $trackerId,
            $statusId,
            PHP_EOL
        );

        if ($readyCount > 0) {
            printf("      • %d attachment(s) prepared for association.%s", $readyCount, PHP_EOL);
        }
        if ($blockedCount > 0) {
            printf("      • %d attachment(s) still require download/upload via 10_migrate_attachments.php.%s", $blockedCount, PHP_EOL);
        }
    }

    if ($isDryRun) {
        printf("  Dry-run active; no API calls will be made (issues remain queued).%s", PHP_EOL);
        return;
    }

    if (!$confirmPush) {
        printf("  Push confirmation missing; rerun with --confirm-push to create the issues.%s", PHP_EOL);
        return;
    }

    $client = createRedmineClient($redmineConfig);

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_issues
        SET
            redmine_issue_id = :redmine_issue_id,
            migration_status = :migration_status,
            notes = :notes,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare mapping update statement during push.');
    }

    foreach ($candidates as $candidate) {
        $mappingId = (int)$candidate['mapping_id'];
        $jiraIssueKey = (string)$candidate['jira_issue_key'];
        $jiraIssueId = (string)$candidate['jira_issue_id'];
        $projectId = $candidate['proposed_project_id'] !== null ? (int)$candidate['proposed_project_id'] : null;
        $trackerId = $candidate['proposed_tracker_id'] !== null ? (int)$candidate['proposed_tracker_id'] : null;
        $statusId = $candidate['proposed_status_id'] !== null ? (int)$candidate['proposed_status_id'] : null;

        if ($projectId === null || $trackerId === null || $statusId === null) {
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'redmine_issue_id' => null,
                'migration_status' => 'MANUAL_INTERVENTION_REQUIRED',
                'notes' => 'Missing mandatory attributes during push; verify project/tracker/status assignments.',
            ]);
            printf("  [error] Jira issue %s missing mandatory attributes; flagged for review.%s", $jiraIssueKey, PHP_EOL);
            continue;
        }

        $preparedAttachments = $attachmentsByIssue[$jiraIssueId] ?? [];

        $payload = [
            'issue' => array_filter([
                'project_id' => $projectId,
                'tracker_id' => $trackerId,
                'status_id' => $statusId,
                'priority_id' => $candidate['proposed_priority_id'] !== null ? (int)$candidate['proposed_priority_id'] : null,
                'subject' => isset($candidate['proposed_subject']) ? (string)$candidate['proposed_subject'] : null,
                'description' => $candidate['proposed_description'] !== null ? (string)$candidate['proposed_description'] : null,
                'start_date' => $candidate['proposed_start_date'] !== null ? (string)$candidate['proposed_start_date'] : null,
                'due_date' => $candidate['proposed_due_date'] !== null ? (string)$candidate['proposed_due_date'] : null,
                'assigned_to_id' => $candidate['proposed_assigned_to_id'] !== null ? (int)$candidate['proposed_assigned_to_id'] : null,
                'done_ratio' => $candidate['proposed_done_ratio'] !== null ? (int)$candidate['proposed_done_ratio'] : null,
                'estimated_hours' => $candidate['proposed_estimated_hours'] !== null ? (float)$candidate['proposed_estimated_hours'] : null,
                'parent_issue_id' => $candidate['proposed_parent_issue_id'] !== null ? (int)$candidate['proposed_parent_issue_id'] : null,
                'is_private' => $candidate['proposed_is_private'] !== null ? ((bool)$candidate['proposed_is_private'] ? 1 : 0) : null,
                'custom_fields' => $candidate['proposed_custom_field_payload'] !== null ? json_decode((string)$candidate['proposed_custom_field_payload'], true) : null,
                'uploads' => buildIssueUploadPayload($preparedAttachments),
            ], static fn($value) => $value !== null),
        ];

        if (($payload['issue']['uploads'] ?? []) === []) {
            unset($payload['issue']['uploads']);
        }

        try {
            $response = $client->post('/issues.json', ['json' => $payload]);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = 'Failed to create issue in Redmine';
            if ($response instanceof ResponseInterface) {
                $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                $message .= ': ' . extractErrorBody($response);
            }

            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'redmine_issue_id' => null,
                'migration_status' => 'CREATION_FAILED',
                'notes' => $message,
            ]);

            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        } catch (GuzzleException $exception) {
            $message = 'Failed to create issue in Redmine: ' . $exception->getMessage();
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'redmine_issue_id' => null,
                'migration_status' => 'CREATION_FAILED',
                'notes' => $message,
            ]);

            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        }

        $body = decodeJsonResponse($response);
        $redmineIssueId = isset($body['issue']['id']) ? (int)$body['issue']['id'] : null;

        if ($redmineIssueId === null) {
            $message = 'Redmine did not return an issue identifier.';
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'redmine_issue_id' => null,
                'migration_status' => 'CREATION_FAILED',
                'notes' => $message,
            ]);

            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        }

        $updateStatement->execute([
            'mapping_id' => $mappingId,
            'redmine_issue_id' => $redmineIssueId,
            'migration_status' => 'CREATION_SUCCESS',
            'notes' => null,
        ]);

        printf("  [created] Jira issue %s → Redmine issue #%d%s", $jiraIssueKey, $redmineIssueId, PHP_EOL);

        if ($preparedAttachments !== []) {
            finalizeIssueAttachmentAssociations($client, $pdo, $jiraIssueId, $redmineIssueId, $preparedAttachments);
        }
    }
}

/**
 * @return array{new_mappings: int, relinked: int}
 */
function syncAttachmentMappings(PDO $pdo): array
{
    $insertSql = <<<SQL
        INSERT INTO migration_mapping_attachments (jira_attachment_id, jira_issue_id)
        SELECT att.id, att.issue_id
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

function countAttachmentsAwaitingAssociation(PDO $pdo): int
{
    $sql = "SELECT COUNT(*) FROM migration_mapping_attachments WHERE migration_status IN ('PENDING_DOWNLOAD', 'PENDING_UPLOAD')";

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to count pending attachment preparation tasks: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to count pending attachment preparation tasks.');
    }

    $count = $statement->fetchColumn();
    $statement->closeCursor();

    if ($count === false) {
        return 0;
    }

    return (int)$count;
}

/**
 * @return array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string}>
 */
function fetchPreparedAttachmentUploads(PDO $pdo, string $jiraIssueId): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_attachment_id,
            map.redmine_upload_token,
            att.filename,
            att.mime_type,
            att.size_bytes
        FROM migration_mapping_attachments map
        JOIN staging_jira_attachments att ON att.id = map.jira_attachment_id
        WHERE map.jira_issue_id = :issue_id
          AND map.association_hint = 'ISSUE'
          AND map.migration_status = 'PENDING_ASSOCIATION'
        ORDER BY map.mapping_id
    SQL;

    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare attachment fetch statement.');
    }

    $statement->execute(['issue_id' => $jiraIssueId]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    $result = [];
    foreach ($rows as $row) {
        $token = isset($row['redmine_upload_token']) ? (string)$row['redmine_upload_token'] : '';
        if ($token === '') {
            continue;
        }

        $result[] = [
            'mapping_id' => (int)$row['mapping_id'],
            'jira_attachment_id' => (string)$row['jira_attachment_id'],
            'filename' => isset($row['filename']) ? (string)$row['filename'] : '',
            'mime_type' => isset($row['mime_type']) && $row['mime_type'] !== '' ? (string)$row['mime_type'] : null,
            'size_bytes' => isset($row['size_bytes']) ? (int)$row['size_bytes'] : null,
            'redmine_upload_token' => $token,
        ];
    }

    return $result;
}

/**
 * @return array{ready: int, blocked: int}
 */
function summarizeAttachmentStatusesForIssue(PDO $pdo, string $jiraIssueId): array
{
    $sql = <<<SQL
        SELECT
            SUM(CASE WHEN migration_status = 'PENDING_ASSOCIATION' THEN 1 ELSE 0 END) AS ready_count,
            SUM(CASE WHEN migration_status IN ('PENDING_DOWNLOAD', 'PENDING_UPLOAD') THEN 1 ELSE 0 END) AS blocked_count
        FROM migration_mapping_attachments
        WHERE jira_issue_id = :issue_id
          AND association_hint = 'ISSUE'
    SQL;

    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare attachment summary statement.');
    }

    $statement->execute(['issue_id' => $jiraIssueId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: ['ready_count' => 0, 'blocked_count' => 0];
    $statement->closeCursor();

    return [
        'ready' => isset($row['ready_count']) ? (int)$row['ready_count'] : 0,
        'blocked' => isset($row['blocked_count']) ? (int)$row['blocked_count'] : 0,
    ];
}

/**
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string}> $attachments
 * @return array<int, array<string, mixed>>
 */
function buildIssueUploadPayload(array $attachments): array
{
    $uploads = [];
    foreach ($attachments as $attachment) {
        $uploads[] = array_filter([
            'token' => $attachment['redmine_upload_token'],
            'filename' => $attachment['filename'] !== '' ? $attachment['filename'] : null,
            'content_type' => $attachment['mime_type'] ?? null,
        ], static fn($value) => $value !== null);
    }

    return $uploads;
}

/**
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string}> $attachments
 */
function finalizeIssueAttachmentAssociations(Client $client, PDO $pdo, string $jiraIssueId, int $redmineIssueId, array $attachments): void
{
    if ($attachments === []) {
        return;
    }

    try {
        $response = $client->get(sprintf('/issues/%d.json', $redmineIssueId), ['query' => ['include' => 'attachments']]);
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $message = 'Unable to confirm attachment association';
        if ($response instanceof ResponseInterface) {
            $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
            $message .= ': ' . extractErrorBody($response);
        } else {
            $message .= ': ' . $exception->getMessage();
        }

        markAttachmentAssociationFailure($pdo, $attachments, $message);
        return;
    } catch (GuzzleException $exception) {
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
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string}> $attachments
 */
function markAttachmentAssociationFailure(PDO $pdo, array $attachments, string $message): void
{
    foreach ($attachments as $attachment) {
        updateAttachmentMappingAfterPush($pdo, (int)$attachment['mapping_id'], null, 'PENDING_ASSOCIATION', $message);
    }
}

/**
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string}> $attachments
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

        $filename = isset($redmineAttachment['filename']) ? (string)$redmineAttachment['filename'] : '';
        $filesize = isset($redmineAttachment['filesize']) ? (int)$redmineAttachment['filesize'] : null;
        $id = isset($redmineAttachment['id']) ? (int)$redmineAttachment['id'] : null;

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
        $targetFilename = $attachment['filename'];
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


function syncIssueMappings(PDO $pdo): void
{
    $sql = <<<SQL
        INSERT INTO migration_mapping_issues (
            jira_issue_id,
            jira_issue_key,
            jira_project_id,
            jira_issue_type_id,
            jira_status_id,
            jira_priority_id,
            jira_reporter_account_id,
            jira_assignee_account_id,
            jira_parent_issue_id
        )
        SELECT
            i.id,
            i.issue_key,
            i.project_id,
            i.issuetype_id,
            i.status_id,
            i.priority_id,
            i.reporter_account_id,
            i.assignee_account_id,
            i.parent_issue_id
        FROM staging_jira_issues i
        ON DUPLICATE KEY UPDATE
            jira_issue_key = VALUES(jira_issue_key),
            jira_project_id = VALUES(jira_project_id),
            jira_issue_type_id = VALUES(jira_issue_type_id),
            jira_status_id = VALUES(jira_status_id),
            jira_priority_id = VALUES(jira_priority_id),
            jira_reporter_account_id = VALUES(jira_reporter_account_id),
            jira_assignee_account_id = VALUES(jira_assignee_account_id),
            jira_parent_issue_id = VALUES(jira_parent_issue_id)
    SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to synchronise migration_mapping_issues: ' . $exception->getMessage(), 0, $exception);
    }
}

/**
 * @return array<int, array<string, mixed>>
 * @throws Throwable
 */
function fetchIssueMappingsForTransform(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            map.*,
            issue.summary AS jira_summary,
            issue.description_adf AS jira_description_adf,
            issue.due_date AS jira_due_date,
            issue.status_category_key AS jira_status_category_key,
            issue.time_original_estimate AS jira_time_original_estimate,
            issue.created_at AS jira_created_at,
            issue.raw_payload AS jira_raw_payload
        FROM migration_mapping_issues map
        JOIN staging_jira_issues issue ON issue.id = map.jira_issue_id
        ORDER BY map.mapping_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch issue mappings for transform: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to fetch issue mappings for transform.');
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    return $rows;
}
/**
 * @return array<string, array<string, mixed>>
 */
function buildProjectLookup(PDO $pdo): array
{
    $sql = 'SELECT jira_project_id, redmine_project_id, migration_status FROM migration_mapping_projects';
    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build project lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to build project lookup.');
    }

    $lookup = [];
    while (true) {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            break;
        }

        $jiraProjectId = isset($row['jira_project_id']) ? (string)$row['jira_project_id'] : '';
        if ($jiraProjectId === '') {
            continue;
        }

        $lookup[$jiraProjectId] = $row;
    }

    $statement->closeCursor();

    return $lookup;
}

/**
 * @return array<string, array<string, mixed>>
 */
function buildTrackerLookup(PDO $pdo): array
{
    $sql = 'SELECT jira_issue_type_id, redmine_tracker_id, migration_status FROM migration_mapping_trackers';
    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build tracker lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to build tracker lookup.');
    }

    $lookup = [];
    while (true) {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            break;
        }

        $jiraIssueTypeId = isset($row['jira_issue_type_id']) ? (string)$row['jira_issue_type_id'] : '';
        if ($jiraIssueTypeId === '') {
            continue;
        }

        $lookup[$jiraIssueTypeId] = $row;
    }

    $statement->closeCursor();

    return $lookup;
}

/**
 * @return array<string, array<string, mixed>>
 */
function buildStatusLookup(PDO $pdo): array
{
    $sql = 'SELECT jira_status_id, redmine_status_id, migration_status FROM migration_mapping_statuses';
    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build status lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to build status lookup.');
    }

    $lookup = [];
    while (true) {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            break;
        }

        $jiraStatusId = isset($row['jira_status_id']) ? (string)$row['jira_status_id'] : '';
        if ($jiraStatusId === '') {
            continue;
        }

        $lookup[$jiraStatusId] = $row;
    }

    $statement->closeCursor();

    return $lookup;
}

/**
 * @return array<string, array<string, mixed>>
 */
function buildPriorityLookup(PDO $pdo): array
{
    $sql = 'SELECT jira_priority_id, redmine_priority_id, migration_status FROM migration_mapping_priorities';
    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build priority lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to build priority lookup.');
    }

    $lookup = [];
    while (true) {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            break;
        }

        $jiraPriorityId = isset($row['jira_priority_id']) ? (string)$row['jira_priority_id'] : '';
        if ($jiraPriorityId === '') {
            continue;
        }

        $lookup[$jiraPriorityId] = $row;
    }

    $statement->closeCursor();

    return $lookup;
}

/**
 * @return array<string, array<string, mixed>>
 */
function buildUserLookup(PDO $pdo): array
{
    $sql = 'SELECT jira_account_id, redmine_user_id, migration_status FROM migration_mapping_users';
    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build user lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to build user lookup.');
    }

    $lookup = [];
    while (true) {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            break;
        }

        $jiraAccountId = isset($row['jira_account_id']) ? (string)$row['jira_account_id'] : '';
        if ($jiraAccountId === '') {
            continue;
        }

        $lookup[$jiraAccountId] = $row;
    }

    $statement->closeCursor();

    return $lookup;
}
function resolveRedmineProjectId(array $lookup, string $jiraProjectId): ?int
{
    if (!isset($lookup[$jiraProjectId])) {
        return null;
    }

    $row = $lookup[$jiraProjectId];
    $status = isset($row['migration_status']) ? (string)$row['migration_status'] : '';
    if (!in_array($status, ['MATCH_FOUND', 'CREATION_SUCCESS'], true)) {
        return null;
    }

    return $row['redmine_project_id'] !== null ? (int)$row['redmine_project_id'] : null;
}

function resolveRedmineTrackerId(array $lookup, string $jiraIssueTypeId): ?int
{
    if (!isset($lookup[$jiraIssueTypeId])) {
        return null;
    }

    $row = $lookup[$jiraIssueTypeId];
    $status = isset($row['migration_status']) ? (string)$row['migration_status'] : '';
    if (!in_array($status, ['MATCH_FOUND', 'CREATION_SUCCESS'], true)) {
        return null;
    }

    return $row['redmine_tracker_id'] !== null ? (int)$row['redmine_tracker_id'] : null;
}

function resolveRedmineStatusId(array $lookup, string $jiraStatusId): ?int
{
    if (!isset($lookup[$jiraStatusId])) {
        return null;
    }

    $row = $lookup[$jiraStatusId];
    $status = isset($row['migration_status']) ? (string)$row['migration_status'] : '';
    if (!in_array($status, ['MATCH_FOUND', 'CREATION_SUCCESS'], true)) {
        return null;
    }

    return $row['redmine_status_id'] !== null ? (int)$row['redmine_status_id'] : null;
}

function resolveRedminePriorityId(array $lookup, string $jiraPriorityId): ?int
{
    if (!isset($lookup[$jiraPriorityId])) {
        return null;
    }

    $row = $lookup[$jiraPriorityId];
    $status = isset($row['migration_status']) ? (string)$row['migration_status'] : '';
    if (!in_array($status, ['MATCH_FOUND', 'CREATION_SUCCESS'], true)) {
        return null;
    }

    return $row['redmine_priority_id'] !== null ? (int)$row['redmine_priority_id'] : null;
}

function resolveRedmineUserId(array $lookup, string $jiraAccountId): ?int
{
    if ($jiraAccountId === '' || !isset($lookup[$jiraAccountId])) {
        return null;
    }

    $row = $lookup[$jiraAccountId];
    $status = isset($row['migration_status']) ? (string)$row['migration_status'] : '';
    if (!in_array($status, ['MATCH_FOUND', 'CREATION_SUCCESS'], true)) {
        return null;
    }

    return $row['redmine_user_id'] !== null ? (int)$row['redmine_user_id'] : null;
}

function resolveRedmineParentIssueId(PDO $pdo, string $jiraParentIssueId): ?int
{
    $statement = $pdo->prepare('SELECT redmine_issue_id FROM migration_mapping_issues WHERE jira_issue_id = :jira_issue_id');
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare parent issue lookup statement.');
    }

    $statement->execute(['jira_issue_id' => $jiraParentIssueId]);
    $result = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    $statement->closeCursor();

    if ($result === null) {
        return null;
    }

    return isset($result['redmine_issue_id']) && $result['redmine_issue_id'] !== null ? (int)$result['redmine_issue_id'] : null;
}

function computeIssueAutomationStateHash(
    mixed $redmineIssueId,
    mixed $redmineProjectId,
    mixed $redmineTrackerId,
    mixed $redmineStatusId,
    mixed $redminePriorityId,
    mixed $redmineAuthorId,
    mixed $redmineAssigneeId,
    mixed $redmineParentIssueId,
    mixed $proposedProjectId,
    mixed $proposedTrackerId,
    mixed $proposedStatusId,
    mixed $proposedPriorityId,
    mixed $proposedAuthorId,
    mixed $proposedAssigneeId,
    mixed $proposedParentIssueId,
    mixed $proposedSubject,
    mixed $proposedDescription,
    mixed $proposedStartDate,
    mixed $proposedDueDate,
    mixed $proposedDoneRatio,
    mixed $proposedEstimatedHours,
    mixed $proposedIsPrivate,
    mixed $proposedCustomFieldPayload,
    mixed $migrationStatus,
    mixed $notes
): string {
    $payload = [
        'redmine_issue_id' => $redmineIssueId,
        'redmine_project_id' => $redmineProjectId,
        'redmine_tracker_id' => $redmineTrackerId,
        'redmine_status_id' => $redmineStatusId,
        'redmine_priority_id' => $redminePriorityId,
        'redmine_author_id' => $redmineAuthorId,
        'redmine_assigned_to_id' => $redmineAssigneeId,
        'redmine_parent_issue_id' => $redmineParentIssueId,
        'proposed_project_id' => $proposedProjectId,
        'proposed_tracker_id' => $proposedTrackerId,
        'proposed_status_id' => $proposedStatusId,
        'proposed_priority_id' => $proposedPriorityId,
        'proposed_author_id' => $proposedAuthorId,
        'proposed_assigned_to_id' => $proposedAssigneeId,
        'proposed_parent_issue_id' => $proposedParentIssueId,
        'proposed_subject' => $proposedSubject,
        'proposed_description' => $proposedDescription,
        'proposed_start_date' => $proposedStartDate,
        'proposed_due_date' => $proposedDueDate,
        'proposed_done_ratio' => $proposedDoneRatio,
        'proposed_estimated_hours' => $proposedEstimatedHours,
        'proposed_is_private' => $proposedIsPrivate,
        'proposed_custom_field_payload' => $proposedCustomFieldPayload,
        'migration_status' => $migrationStatus,
        'notes' => $notes,
    ];

    try {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode issue automation hash payload: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', $encoded);
}
function convertJiraAdfToPlaintext(mixed $descriptionAdf): ?string
{
    if ($descriptionAdf === null) {
        return null;
    }

    if (is_string($descriptionAdf)) {
        $trimmed = trim($descriptionAdf);
        return $trimmed !== '' ? $trimmed : null;
    }

    if (!is_array($descriptionAdf)) {
        return null;
    }

    $fragments = [];

    $stack = [$descriptionAdf];
    while ($stack !== []) {
        $current = array_pop($stack);
        if (is_array($current)) {
            if (isset($current['text']) && is_string($current['text'])) {
                $fragments[] = $current['text'];
            }
            if (isset($current['content']) && is_array($current['content'])) {
                foreach (array_reverse($current['content']) as $child) {
                    $stack[] = $child;
                }
                $fragments[] = PHP_EOL;
            }
        }
    }

    $text = trim(preg_replace('/\n{3,}/', PHP_EOL . PHP_EOL, implode('', $fragments)) ?? '');

    return $text !== '' ? $text : null;
}

function encodeJsonIfNotNull(array $value): ?string
{
    if ($value === []) {
        return null;
    }

    try {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode JSON payload: ' . $exception->getMessage(), 0, $exception);
    }
}
function normalizeDateString(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($trimmed);
    } catch (Exception) {
        return null;
    }

    return $date->format('Y-m-d');
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
    } elseif (is_float($value)) {
        if (!is_finite($value) || floor($value) !== $value) {
            return null;
        }
        $intValue = (int)$value;
    } elseif (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '' || !preg_match('/^-?\d+$/', $trimmed)) {
            return null;
        }
        $intValue = (int)$trimmed;
    } else {
        return null;
    }

    if ($intValue < $min) {
        return null;
    }

    if ($max !== null && $intValue > $max) {
        return null;
    }

    return $intValue;
}

function normalizeDecimal(mixed $value, float $min = 0.0): ?float
{
    if ($value === null) {
        return null;
    }

    if (is_float($value) || is_int($value)) {
        $floatValue = (float)$value;
    } elseif (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }
        $floatValue = (float)$trimmed;
    } else {
        return null;
    }

    if ($floatValue < $min) {
        return null;
    }

    return round($floatValue, 2);
}

function normalizeBooleanFlag(mixed $value): ?bool
{
    $normalized = normalizeBooleanDatabaseValue($value);

    if ($normalized === null) {
        return null;
    }

    return $normalized === 1;
}

function normalizeBooleanDatabaseValue(mixed $value): ?int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value)) {
        if ($value === 0 || $value === 1) {
            return $value;
        }
        return null;
    }

    if (is_string($value)) {
        $trimmed = strtolower(trim($value));
        if (in_array($trimmed, ['1', 'true', 'yes'], true)) {
            return 1;
        }
        if (in_array($trimmed, ['0', 'false', 'no'], true)) {
            return 0;
        }
    }

    return null;
}

function normalizeStoredAutomationHash(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    return $trimmed !== '' ? $trimmed : null;
}

function truncateString(string $value, int $maxLength): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if ($maxLength <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($trimmed, 0, $maxLength);
    }

    return substr($trimmed, 0, $maxLength);
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
        return 'No response body';
    }

    if (strlen($body) > 300) {
        return substr($body, 0, 300) . '…';
    }

    return $body;
}
/**
 * @param array<int, string> $argv
 * @return array{0: array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool}, 1: array<int, string>}
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

    $positional = [];
    $argCount = count($argv);

    for ($i = 1; $i < $argCount; $i++) {
        $argument = $argv[$i];
        if (!is_string($argument) || $argument === '') {
            continue;
        }

        if ($argument === '-h' || $argument === '--help') {
            $options['help'] = true;
            continue;
        }

        if ($argument === '-V' || $argument === '--version') {
            $options['version'] = true;
            continue;
        }

        if (str_starts_with($argument, '--phases=')) {
            $options['phases'] = substr($argument, 9) ?: null;
            continue;
        }

        if (str_starts_with($argument, '--skip=')) {
            $options['skip'] = substr($argument, 7) ?: null;
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

        $positional[] = $argument;
    }

    return [$options, $positional];
}

/**
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool} $cliOptions
 * @return array<int, string>
 */
function determinePhasesToRun(array $cliOptions): array
{
    $phases = array_keys(AVAILABLE_PHASES);

    if ($cliOptions['phases'] !== null) {
        $phases = parsePhaseList($cliOptions['phases']);
    }

    if ($cliOptions['skip'] !== null) {
        $skip = parsePhaseList($cliOptions['skip']);
        $phases = array_values(array_diff($phases, $skip));
    }

    if ($phases === []) {
        throw new RuntimeException('No phases selected to run.');
    }

    return $phases;
}

/**
 * @return array<int, string>
 */
function parsePhaseList(string $phaseList): array
{
    $phases = [];
    foreach (explode(',', $phaseList) as $phase) {
        $normalized = strtolower(trim($phase));
        if ($normalized === '') {
            continue;
        }

        if (!array_key_exists($normalized, AVAILABLE_PHASES)) {
            throw new RuntimeException(sprintf('Unknown phase "%s". Valid phases: %s', $normalized, implode(', ', array_keys(AVAILABLE_PHASES))));
        }

        $phases[] = $normalized;
    }

    return array_values(array_unique($phases));
}

function printUsage(): void
{
    $script = basename(__FILE__);
    printf("Usage: php %s [options]%s", $script, PHP_EOL);
    printf("\nOptions:%s", PHP_EOL);
    printf("  -h, --help               Show this help message.%s", PHP_EOL);
    printf("  -V, --version            Display the script version.%s", PHP_EOL);
    printf("      --phases=<list>      Comma-separated list of phases to run (%s).%s", implode(', ', array_keys(AVAILABLE_PHASES)), PHP_EOL);
    printf("      --skip=<list>        Comma-separated list of phases to skip.%s", PHP_EOL);
    printf("      --confirm-push       Required to allow the push phase to create issues in Redmine.%s", PHP_EOL);
    printf("      --dry-run            Preview push payloads without contacting Redmine.%s", PHP_EOL);
    printf("      --use-extended-api   Ignored for this script (standard Redmine API is used).%s", PHP_EOL);
}

function printVersion(): void
{
    printf('%s version %s%s', basename(__FILE__), MIGRATE_ISSUES_SCRIPT_VERSION, PHP_EOL);
}
function extractArrayConfig(array $config, string $key): array
{
    if (!isset($config[$key]) || !is_array($config[$key])) {
        throw new RuntimeException(sprintf('Missing configuration section: %s', $key));
    }

    return $config[$key];
}

function createDatabaseConnection(array $databaseConfig): PDO
{
    $dsn = (string)($databaseConfig['dsn'] ?? '');
    $username = (string)($databaseConfig['username'] ?? '');
    $password = (string)($databaseConfig['password'] ?? '');
    $options = isset($databaseConfig['options']) && is_array($databaseConfig['options']) ? $databaseConfig['options'] : [];

    if ($dsn === '') {
        throw new RuntimeException('Database configuration must include a DSN.');
    }

    $pdo = new PDO($dsn, $username, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function createJiraClient(array $jiraConfig): Client
{
    $baseUrl = trim((string)($jiraConfig['base_url'] ?? ''));
    $username = (string)($jiraConfig['username'] ?? '');
    $apiToken = (string)($jiraConfig['api_token'] ?? '');

    if ($baseUrl === '' || $username === '' || $apiToken === '') {
        throw new RuntimeException('Jira configuration must include base_url, username, and api_token.');
    }

    return new Client([
        'base_uri' => rtrim($baseUrl, '/') . '/',
        'auth' => [$username, $apiToken],
        'headers' => [
            'Accept' => 'application/json',
        ],
        'timeout' => 30,
    ]);
}

function createRedmineClient(array $redmineConfig): Client
{
    $baseUrl = trim((string)($redmineConfig['base_url'] ?? ''));
    $apiKey = (string)($redmineConfig['api_key'] ?? '');

    if ($baseUrl === '' || $apiKey === '') {
        throw new RuntimeException('Redmine configuration must include base_url and api_key.');
    }

    return new Client([
        'base_uri' => rtrim($baseUrl, '/') . '/',
        'headers' => [
            'X-Redmine-API-Key' => $apiKey,
            'Accept' => 'application/json',
        ],
        'timeout' => 30,
    ]);
}

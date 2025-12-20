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
use League\HTMLToMarkdown\Converter\TableConverter;
use Karvaka\AdfToGfm\Converter as AdfConverter;

const MIGRATE_ISSUES_SCRIPT_VERSION = '0.0.34';
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira issues into staging_jira_issues (and related staging tables).',
    'transform' => 'Reconcile Jira issues with Redmine dependencies to populate migration mappings.',
    'push' => 'Create missing Redmine issues based on the mapping proposals.',
];

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This script is intended to be run from the command line.');
}

/**
 * @param array<string, mixed> $candidate
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string, sharepoint_url: ?string}> $attachments
 * @return array{endpoint: string, body: string}|null
 */
function buildIssuePayloadForPreview(
    array $candidate,
    array $attachments,
    bool $useExtendedApi,
    string $extendedApiPrefix,
    ?int $defaultIssueUserId
): ?array {
    $jiraIssueKey = (string)$candidate['jira_issue_key'];

    $description = $candidate['proposed_description'] !== null ? (string)$candidate['proposed_description'] : null;

    $redmineUploads = array_values(array_filter(
        $attachments,
        static fn($attachment) => $attachment['redmine_upload_token'] !== ''
            && ($attachment['sharepoint_url'] ?? '') === ''
    ));
    $sharePointLinks = array_values(array_filter(
        $attachments,
        static fn($attachment) => ($attachment['sharepoint_url'] ?? '') !== ''
    ));

    $descriptionWithSharePoint = appendSharePointLinksToDescription($description, $sharePointLinks);

    try {
        $customFieldPayload = decodeCustomFieldPayload($candidate['proposed_custom_field_payload']);
    } catch (JsonException $exception) {
        printf(
            "      - [error] Invalid custom field payload JSON for Jira issue %s: %s%s",
            $jiraIssueKey,
            $exception->getMessage(),
            PHP_EOL
        );

        return null;
    }

    $issuePayload = array_filter([
        'project_id' => $candidate['proposed_project_id'] !== null ? (int)$candidate['proposed_project_id'] : null,
        'tracker_id' => $candidate['proposed_tracker_id'] !== null ? (int)$candidate['proposed_tracker_id'] : null,
        'status_id' => $candidate['proposed_status_id'] !== null ? (int)$candidate['proposed_status_id'] : null,
        'priority_id' => $candidate['proposed_priority_id'] !== null ? (int)$candidate['proposed_priority_id'] : null,
        'subject' => isset($candidate['proposed_subject']) ? (string)$candidate['proposed_subject'] : null,
        'description' => $descriptionWithSharePoint,
        'start_date' => $candidate['proposed_start_date'] !== null ? (string)$candidate['proposed_start_date'] : null,
        'due_date' => $candidate['proposed_due_date'] !== null ? (string)$candidate['proposed_due_date'] : null,
        'assigned_to_id' => $candidate['proposed_assigned_to_id'] !== null ? (int)$candidate['proposed_assigned_to_id'] : null,
        'done_ratio' => $candidate['proposed_done_ratio'] !== null ? (int)$candidate['proposed_done_ratio'] : null,
        'estimated_hours' => $candidate['proposed_estimated_hours'] !== null ? (float)$candidate['proposed_estimated_hours'] : null,
        'is_private' => $candidate['proposed_is_private'] !== null ? ($candidate['proposed_is_private'] ? 1 : 0) : null,
        'custom_fields' => $customFieldPayload,
        'uploads' => buildIssueUploadPayload($redmineUploads),
    ], static fn($value) => $value !== null);

    if ($useExtendedApi) {
        $issuePayload = array_merge($issuePayload, buildExtendedIssueOverrides($candidate, $defaultIssueUserId));
    }

    $payload = ['issue' => $issuePayload];
    if (($payload['issue']['uploads'] ?? []) === []) {
        unset($payload['issue']['uploads']);
    }

    try {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } catch (JsonException $exception) {
        printf(
            "      - [error] Unable to encode preview payload for Jira issue %s: %s%s",
            $jiraIssueKey,
            $exception->getMessage(),
            PHP_EOL
        );

        return null;
    }

    $endpoint = $useExtendedApi
        ? buildExtendedApiPath($extendedApiPrefix, 'issues.json')
        : 'issues.json';

    return [
        'endpoint' => $endpoint,
        'body' => $encoded,
    ];
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

        printf(
            "[%s] Completed Jira extraction. Issues: %d (updated %d), Labels captured: %d, Object samples: %d, Flattened rows: %d.%s",
            formatCurrentTimestamp(),
            $jiraSummary['issues_processed'],
            $jiraSummary['issues_updated'],
            $jiraSummary['labels_processed'],
            $jiraSummary['object_samples_processed'],
            $jiraSummary['object_kv_rows'],
            PHP_EOL
        );
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
        $useExtendedApi = shouldUseExtendedApi($redmineConfig, (bool)($cliOptions['use_extended_api'] ?? false));
        $extendedApiPrefix = resolveExtendedApiPrefix($redmineConfig);

        $defaultExtendedIssueAuthorId = extractDefaultIssueAuthorId($config);

        runIssuePushPhase(
            $pdo,
            $confirmPush,
            $isDryRun,
            $redmineConfig,
            $useExtendedApi,
            $extendedApiPrefix,
            $defaultExtendedIssueAuthorId
        );
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
 * @return array{issues_processed: int, issues_updated: int, labels_processed: int, object_samples_processed: int, object_kv_rows: int}
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
        'fields' => '*all',
        'expand' => 'renderedFields',
        'fieldsByKeys' => 'false',
    ];

    $totalIssuesProcessed = 0;
    $totalIssuesUpdated = 0;
    $totalLabelsProcessed = 0;
    $totalObjectSamples = 0;
    $totalObjectKvRows = 0;
    $now = formatCurrentTimestamp('Y-m-d H:i:s');

    $objectFieldDefinitions = loadObjectFieldDefinitions($pdo);

    $deleteObjectSamples = null;
    $deleteObjectKv = null;
    $insertObjectSample = null;
    $insertObjectKv = null;

    if ($objectFieldDefinitions !== []) {
        $deleteObjectSamples = $pdo->prepare('DELETE FROM staging_jira_object_samples WHERE issue_key = :issue_key');
        $deleteObjectKv = $pdo->prepare('DELETE FROM staging_jira_object_kv WHERE issue_key = :issue_key');

        $insertObjectSample = $pdo->prepare(<<<SQL
            INSERT INTO staging_jira_object_samples (
                field_id,
                issue_key,
                ordinal,
                is_array,
                raw_json,
                captured_at
            ) VALUES (
                :field_id,
                :issue_key,
                :ordinal,
                :is_array,
                :raw_json,
                :captured_at
            )
            ON DUPLICATE KEY UPDATE
                is_array = VALUES(is_array),
                raw_json = VALUES(raw_json),
                captured_at = VALUES(captured_at)
        SQL);

        if ($insertObjectSample === false || $deleteObjectSamples === false) {
            throw new RuntimeException('Failed to prepare statements for staging_jira_object_samples.');
        }

        $insertObjectKv = $pdo->prepare(<<<SQL
            INSERT INTO staging_jira_object_kv (
                field_id,
                issue_key,
                path,
                ordinal,
                value_type,
                value_text,
                captured_at
            ) VALUES (
                :field_id,
                :issue_key,
                :path,
                :ordinal,
                :value_type,
                :value_text,
                :captured_at
            )
        SQL);

        if ($insertObjectKv === false || $deleteObjectKv === false) {
            throw new RuntimeException('Failed to prepare statements for staging_jira_object_kv.');
        }
    }

    $insertIssueStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_issues (
            id,
            issue_key,
            summary,
            description_adf,
            description_html,
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
            :description_html,
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
            description_html = VALUES(description_html),
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

    $insertIssueLinkStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_issue_links (
            link_id,
            source_issue_id,
            source_issue_key,
            target_issue_id,
            target_issue_key,
            link_type_id,
            link_type_name,
            link_type_inward,
            link_type_outward,
            raw_payload,
            extracted_at
        ) VALUES (
            :link_id,
            :source_issue_id,
            :source_issue_key,
            :target_issue_id,
            :target_issue_key,
            :link_type_id,
            :link_type_name,
            :link_type_inward,
            :link_type_outward,
            :raw_payload,
            :extracted_at
        )
        ON DUPLICATE KEY UPDATE
            source_issue_id = VALUES(source_issue_id),
            source_issue_key = VALUES(source_issue_key),
            target_issue_id = VALUES(target_issue_id),
            target_issue_key = VALUES(target_issue_key),
            link_type_id = VALUES(link_type_id),
            link_type_name = VALUES(link_type_name),
            link_type_inward = VALUES(link_type_inward),
            link_type_outward = VALUES(link_type_outward),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertIssueLinkStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_issue_links.');
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
                $response = $client->get('/rest/api/3/search/jql', ['query' => $query]);
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

                $renderedFields = isset($issue['renderedFields']) && is_array($issue['renderedFields'])
                    ? $issue['renderedFields']
                    : [];
                $descriptionHtml = null;
                if (isset($renderedFields['description']) && is_string($renderedFields['description'])) {
                    $descriptionHtml = trim($renderedFields['description']);
                    if ($descriptionHtml === '') {
                        $descriptionHtml = null;
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
                    'description_html' => $descriptionHtml,
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

                if ($objectFieldDefinitions !== []) {
                    $deleteObjectSamples->execute(['issue_key' => $issueKey]);
                    $deleteObjectKv->execute(['issue_key' => $issueKey]);

                    foreach ($objectFieldDefinitions as $fieldId => $fieldMeta) {
                        if (!array_key_exists($fieldId, $fields)) {
                            continue;
                        }

                        $objectValue = $fields[$fieldId];
                        if ($objectValue === null) {
                            continue;
                        }

                        $isArray = isListArray($objectValue);
                        $capturedAt = $now;

                        if ($isArray) {
                            foreach ($objectValue as $ordinal => $itemValue) {
                                $rawJson = encodeJsonIfNotNull($itemValue);
                                if ($rawJson === null) {
                                    $rawJson = 'null';
                                }

                                $insertObjectSample->execute([
                                    'field_id' => $fieldId,
                                    'issue_key' => $issueKey,
                                    'ordinal' => (int)$ordinal,
                                    'is_array' => 1,
                                    'raw_json' => $rawJson,
                                    'captured_at' => $capturedAt,
                                ]);
                                $totalObjectSamples++;

                                $flatRows = flattenObject($itemValue, '', [], (int)$ordinal);
                                foreach ($flatRows as $flatRow) {
                                    $insertObjectKv->execute([
                                        'field_id' => $fieldId,
                                        'issue_key' => $issueKey,
                                        'path' => $flatRow['path'],
                                        'ordinal' => $flatRow['ordinal'],
                                        'value_type' => $flatRow['valueType'],
                                        'value_text' => $flatRow['value'],
                                        'captured_at' => $capturedAt,
                                    ]);
                                    $totalObjectKvRows++;
                                }
                            }
                        } else {
                            $rawJson = encodeJsonIfNotNull($objectValue);
                            if ($rawJson === null) {
                                $rawJson = 'null';
                            }

                            $insertObjectSample->execute([
                                'field_id' => $fieldId,
                                'issue_key' => $issueKey,
                                'ordinal' => 0,
                                'is_array' => is_array($objectValue) ? 1 : 0,
                                'raw_json' => $rawJson,
                                'captured_at' => $capturedAt,
                            ]);
                            $totalObjectSamples++;

                            $flatRows = flattenObject($objectValue);
                            foreach ($flatRows as $flatRow) {
                                $insertObjectKv->execute([
                                    'field_id' => $fieldId,
                                    'issue_key' => $issueKey,
                                    'path' => $flatRow['path'],
                                    'ordinal' => $flatRow['ordinal'],
                                    'value_type' => $flatRow['valueType'],
                                    'value_text' => $flatRow['value'],
                                    'captured_at' => $capturedAt,
                                ]);
                                $totalObjectKvRows++;
                            }
                        }
                    }
                }

                $rowCount = $insertIssueStatement->rowCount();
                if ($rowCount === 1) {
                    $totalIssuesProcessed++;
                    $projectIssueCounter++;
                } elseif ($rowCount === 2) {
                    $totalIssuesUpdated++;
                } ## else = 0

                if (isset($fields['issuelinks']) && is_array($fields['issuelinks'])) {
                    foreach ($fields['issuelinks'] as $issueLink) {
                        if (!is_array($issueLink) || !isset($issueLink['id'])) {
                            continue;
                        }

                        $linkId = (string)$issueLink['id'];
                        $linkType = isset($issueLink['type']) && is_array($issueLink['type']) ? $issueLink['type'] : [];
                        $linkTypeId = isset($linkType['id']) ? (string)$linkType['id'] : null;
                        $linkTypeName = isset($linkType['name']) ? (string)$linkType['name'] : null;
                        $linkTypeInward = isset($linkType['inward']) ? (string)$linkType['inward'] : null;
                        $linkTypeOutward = isset($linkType['outward']) ? (string)$linkType['outward'] : null;

                        $sourceIssueId = $issueId;
                        $sourceIssueKey = $issueKey;
                        $targetIssueId = null;
                        $targetIssueKey = null;

                        if (isset($issueLink['outwardIssue']) && is_array($issueLink['outwardIssue'])) {
                            $targetIssueId = isset($issueLink['outwardIssue']['id']) ? (string)$issueLink['outwardIssue']['id'] : null;
                            $targetIssueKey = isset($issueLink['outwardIssue']['key']) ? (string)$issueLink['outwardIssue']['key'] : null;
                        } elseif (isset($issueLink['inwardIssue']) && is_array($issueLink['inwardIssue'])) {
                            $sourceIssueId = isset($issueLink['inwardIssue']['id']) ? (string)$issueLink['inwardIssue']['id'] : null;
                            $sourceIssueKey = isset($issueLink['inwardIssue']['key']) ? (string)$issueLink['inwardIssue']['key'] : null;
                            $targetIssueId = $issueId;
                            $targetIssueKey = $issueKey;
                        }

                        if ($sourceIssueId === null || $targetIssueId === null || $sourceIssueKey === null || $targetIssueKey === null) {
                            continue;
                        }

                        try {
                            $linkPayload = json_encode($issueLink, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        } catch (JsonException $exception) {
                            throw new RuntimeException('Failed to encode Jira issue link payload: ' . $exception->getMessage(), 0, $exception);
                        }

                        $insertIssueLinkStatement->execute([
                            'link_id' => $linkId,
                            'source_issue_id' => $sourceIssueId,
                            'source_issue_key' => $sourceIssueKey,
                            'target_issue_id' => $targetIssueId,
                            'target_issue_key' => $targetIssueKey,
                            'link_type_id' => $linkTypeId,
                            'link_type_name' => $linkTypeName,
                            'link_type_inward' => $linkTypeInward,
                            'link_type_outward' => $linkTypeOutward,
                            'raw_payload' => $linkPayload,
                            'extracted_at' => $now,
                        ]);
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

            $serverBatchSize = isset($payload['maxResults']) ? (int)$payload['maxResults'] : $batchSize;
            if ($serverBatchSize <= 0) {
                $serverBatchSize = $batchSize;
            }

            if ($fetchedCount < $serverBatchSize) {
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
        'labels_processed' => $totalLabelsProcessed,
        'object_samples_processed' => $totalObjectSamples,
        'object_kv_rows' => $totalObjectKvRows,
    ];
}

/**
 * @return array<string, array{name: ?string, schema_custom: ?string}>
 */
function loadObjectFieldDefinitions(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT id, name, schema_custom
        FROM staging_jira_fields
        WHERE schema_type = 'object'
    SQL;

    $statement = $pdo->query($sql);
    if ($statement === false) {
        return [];
    }

    $definitions = [];
    /** @var array<int, array{id: string, name?: ?string, schema_custom?: ?string}> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $fieldId = (string)$row['id'];
        if ($fieldId === '') {
            continue;
        }

        $definitions[$fieldId] = [
            'name' => isset($row['name']) ? (string)$row['name'] : null,
            'schema_custom' => isset($row['schema_custom']) ? (string)$row['schema_custom'] : null,
        ];
    }

    return $definitions;
}

/**
 * @param mixed $data
 * @param string $prefix
 * @param array<int, array{path: string, ordinal: int, value: string|null, valueType: string}> $out
 * @param int $ordinal
 * @return array<int, array{path: string, ordinal: int, value: string|null, valueType: string}>
 */
function flattenObject(mixed $data, string $prefix = '', array $out = [], int $ordinal = 0): array
{
    if (is_array($data)) {
        $isAssoc = !isListArray($data);
        if ($isAssoc) {
            foreach ($data as $key => $value) {
                $keyPath = ltrim(($prefix !== '' ? $prefix . '.' : '') . $key, '.');
                $out = flattenObject($value, $keyPath, $out, $ordinal);
            }
        } else {
            foreach ($data as $index => $value) {
                $out = flattenObject($value, $prefix, $out, (int)$index);
            }
        }
    } else {
        $valueType = determineValueType($data);
        $path = $prefix !== '' ? $prefix : 'value';
        $out[] = [
            'path' => $path,
            'ordinal' => $ordinal,
            'value' => stringifyScalar($data),
            'valueType' => $valueType,
        ];
    }

    return $out;
}

/**
 * @param mixed $value
 * @return string
 */
function determineValueType(mixed $value): string
{
    return match (true) {
        is_bool($value) => 'boolean',
        is_int($value), is_float($value) => 'number',
        is_array($value) => 'array',
        $value === null => 'null',
        default => 'string',
    };
}

/**
 * @param mixed $value
 * @return string|null
 */
function stringifyScalar(mixed $value): ?string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    if ($value === null) {
        return null;
    }

    if (is_scalar($value)) {
        return trim((string)$value);
    }

    return null;
}

/**
 * @param mixed $value
 * @return bool
 */
function isListArray(mixed $value): bool
{
    if (!is_array($value)) {
        return false;
    }

    if ($value === []) {
        return true;
    }

    return array_keys($value) === range(0, count($value) - 1);
}

/**
 * @throws Throwable
 */
function runIssueTransformationPhase(PDO $pdo, array $config): array
{
    $adfConverter = new AdfConverter();
    syncIssueMappings($pdo);

    $projectLookup = buildProjectLookup($pdo);
    $trackerLookup = buildTrackerLookup($pdo);
    $statusLookup = buildStatusLookup($pdo);
    $priorityLookup = buildPriorityLookup($pdo);
    $userLookup = buildUserLookup($pdo);
    $customFieldMappingIndex = buildCustomFieldMappingIndex($pdo);
    $cascadingFieldIndex = buildCascadingFieldIndex($pdo);

    $issueConfig = $config['migration']['issues'] ?? [];
    $defaultProjectId = isset($issueConfig['default_redmine_project_id']) ? normalizeInteger($issueConfig['default_redmine_project_id'], 1) : null;
    $defaultTrackerId = isset($issueConfig['default_redmine_tracker_id']) ? normalizeInteger($issueConfig['default_redmine_tracker_id'], 1) : null;
    $defaultStatusId = isset($issueConfig['default_redmine_status_id']) ? normalizeInteger($issueConfig['default_redmine_status_id'], 1) : null;
    $defaultPriorityId = isset($issueConfig['default_redmine_priority_id']) ? normalizeInteger($issueConfig['default_redmine_priority_id'], 1) : null;
    $defaultAuthorId = isset($issueConfig['default_redmine_author_id']) ? normalizeInteger($issueConfig['default_redmine_author_id'], 1) : null;
    $defaultAssigneeId = isset($issueConfig['default_redmine_assignee_id']) ? normalizeInteger($issueConfig['default_redmine_assignee_id'], 1) : null;
    $defaultIsPrivate = array_key_exists('default_is_private', $issueConfig) ? normalizeBooleanFlag($issueConfig['default_is_private']) : null;
    $attachmentMetadata = buildAttachmentMetadataIndex($pdo);

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
            $row['proposed_custom_field_payload']
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
        $issueDescriptionHtml = isset($row['jira_description_html']) ? (string)$row['jira_description_html'] : null;
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
        $redmineParentIssueId = null;

        $proposedProjectId = $redmineProjectId ?? $defaultProjectId;
        $proposedTrackerId = $redmineTrackerId ?? $defaultTrackerId;
        $proposedStatusId = $redmineStatusId ?? $defaultStatusId;
        $proposedPriorityId = $redminePriorityId ?? $defaultPriorityId;
        $proposedAuthorId = $redmineAuthorId ?? $defaultAuthorId;
        $proposedAssigneeId = $redmineAssigneeId ?? $defaultAssigneeId;
        $proposedParentIssueId = $redmineParentIssueId;

        $issueAttachments = $attachmentMetadata[$row['jira_issue_id']] ?? [];
        $proposedSubject = truncateString($issueSummary, 255);
        $proposedDescription = ($issueDescriptionHtml !== null && !str_contains($issueDescriptionHtml, "<!-- ADF macro (type = 'table') -->"))
            ? convertJiraHtmlToMarkdown($issueDescriptionHtml, $issueAttachments)
            : null;

        //if ($proposedDescription === null && $issueDescriptionAdf !== null) {
        //    $proposedDescription = convertJiraAdfToMarkdown($issueDescriptionAdf);
        //}

        if ($proposedDescription === null && $issueDescriptionAdf !== null) {
            $proposedDescription = convertDescriptionToMarkdown($issueDescriptionAdf, $adfConverter);
        }

        if ($proposedDescription === null && $issueDescriptionAdf !== null) {
            // laatste redmiddel
            $proposedDescription = convertJiraAdfToPlaintext($issueDescriptionAdf);
        }

        // patterns om te detecteren of er Jira attachments in de uiteindelijke tekst voorkomen
        $attachmentPatterns = [
            '#/rest/api/\d+/attachment/content/(\d+)#i',
            '#/rest/api/\d+/attachment/thumbnail/(\d+)#i',
            '#/secure/attachment/(\d+)#i',
            '#/attachment/content/(\d+)#i',
            '#/attachment/(\d+)#i',
            '#/attachments/(\d+)#i', // defensive
            // fallback: zeer generiek, alleen gebruiken als geen van de bovenste patterns matcht
            '#(\d+)(?:[^\d]|$)#'
        ];

        if ($proposedDescription !== null && trim((string)$proposedDescription) !== '') {
            $found = false;

            // eerste pass: kijk naar de specifieke patterns (zonder fallback)
            foreach ($attachmentPatterns as $pat) {
                if ($pat === '#(\d+)(?:[^\d]|$)#') continue; // fallback skippen nu
                if (preg_match($pat, (string)$proposedDescription)) {
                    $found = true;
                    break;
                }
            }

            // fallback (numeriek) alleen toepassen indien nog geen match,
            // en alleen wanneer het getal daadwerkelijk een attachment is voor deze issue
            if (!$found) {
                if (preg_match('#(\d+)(?:[^\d]|$)#', (string)$proposedDescription, $m)) {
                    $candidateId = (string)$m[1];
                    $chk = $pdo->prepare('SELECT 1 FROM staging_jira_attachments WHERE id = :id AND issue_id = :issue_id LIMIT 1');
                    if ($chk !== false) {
                        $chk->execute(['id' => $candidateId, 'issue_id' => $row['jira_issue_id']]);
                        if ($chk->fetchColumn() !== false) {
                            $found = true;
                        }
                    }
                }
            }

            if ($found) {
                // call normalize; we pass de huidige beschrijving zodat de functie niet nogmaals uit de DB hoeft te lezen
                // normalizeAttachmentsForMapping retourneert de genormaliseerde tekst of null als geen wijziging
                $normalized = normalizeAttachmentsForMapping($pdo, (int)$row['mapping_id'], (string)$proposedDescription);
                if ($normalized !== null) {
                    $proposedDescription = $normalized;
                    // verwijder heldere cases: link-title die exact de filename is
                    $proposedDescription = preg_replace('/\]\((\d+__[^)\s]+)\s+"([^"]+)"\)/', ']($1)', $proposedDescription);
                }
            }
        }

        $proposedStartDate = $issueCreatedAt !== null ? substr($issueCreatedAt, 0, 10) : null;
        $proposedDueDate = $issueDueDate !== null ? $issueDueDate : null;
        $proposedDoneRatio = ($issueStatusCategory !== null && strtolower($issueStatusCategory) === 'done') ? 100 : null;
        $proposedEstimatedHours = $issueTimeOriginalEstimate !== null ? round($issueTimeOriginalEstimate / 3600, 2) : null;
        $proposedIsPrivate = $defaultIsPrivate;
        $proposedCustomFieldPayload = null;
        $notes = [];

        if ($issueRawPayload !== null) {
            $decodedIssue = json_decode($issueRawPayload, true);
            if (is_array($decodedIssue)) {
                $security = $decodedIssue['fields']['security'] ?? null;
                if ($security !== null) {
                    $proposedIsPrivate = true;
                }
                $customFieldWarnings = [];
                $proposedCustomFields = buildMappedCustomFieldPayload($decodedIssue, $customFieldMappingIndex, $customFieldWarnings);

                $cascadingWarnings = [];
                $cascadingCustomFields = buildCascadingCustomFieldPayload($decodedIssue, $cascadingFieldIndex, $cascadingWarnings);

                $mergedCustomFields = array_values(array_merge($proposedCustomFields, $cascadingCustomFields));
                $proposedCustomFieldPayload = $mergedCustomFields !== []
                    ? encodeJsonColumn($mergedCustomFields)
                    : null;

                if ($customFieldWarnings !== []) {
                    $notes[] = sprintf('Custom field values could not be normalised for fields: %s.', implode(', ', array_unique($customFieldWarnings)));
                }
                if ($cascadingWarnings !== []) {
                    $notes[] = sprintf('Cascading selection could not be mapped for fields: %s.', implode(', ', array_unique($cascadingWarnings)));
                }
            }
        }

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
            $proposedCustomFieldPayload
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
function runIssuePushPhase(
    PDO $pdo,
    bool $confirmPush,
    bool $isDryRun,
    array $redmineConfig,
    bool $useExtendedApi,
    string $extendedApiPrefix,
    ?int $defaultIssueUserId
): void
{
    $candidateStatement = $pdo->prepare(<<<SQL
        SELECT
            map.*,
            issue.created_at AS jira_created_at,
            issue.updated_at AS jira_updated_at,
            issue.raw_payload AS jira_raw_payload
        FROM migration_mapping_issues map
        JOIN staging_jira_issues issue ON issue.id = map.jira_issue_id
        WHERE map.migration_status = 'READY_FOR_CREATION'
        ORDER BY map.mapping_id
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

        if ($blockedCount > 0) {
            printf(
                "      - [blocked] %d attachment(s) still pending download/upload, run 09_migrate_attachments.php first.%s",
                $blockedCount,
                PHP_EOL
            );
            continue;
        }

        printf(
            "  - %s → project %d / tracker %d / status %d%s",
            $subject,
            $projectId,
            $trackerId,
            $statusId,
            PHP_EOL
        );

        if ($readyCount > 0) {
            printf("      - %d attachment(s) prepared for association.%s", $readyCount, PHP_EOL);
        }
        if ($blockedCount > 0) {
            printf("      - %d attachment(s) still require download/upload via 09_migrate_attachments.php.%s", $blockedCount, PHP_EOL);
        }

        if ($isDryRun) {
            $dryRunPayload = buildIssuePayloadForPreview(
                $candidate,
                $attachmentsByIssue[$jiraIssueId],
                $useExtendedApi,
                $extendedApiPrefix,
                $defaultIssueUserId
            );

            if ($dryRunPayload === null) {
                continue;
            }

            printf("      - endpoint: %s%s", $dryRunPayload['endpoint'], PHP_EOL);
            printf("      - payload: %s%s", $dryRunPayload['body'], PHP_EOL);
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

    if ($useExtendedApi) {
        verifyExtendedApiAvailability($client, $extendedApiPrefix, 'issues.json');
    }

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

        $attachmentSummary = summarizeAttachmentStatusesForIssue($pdo, $jiraIssueId);
        if (($attachmentSummary['blocked'] ?? 0) > 0) {
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'redmine_issue_id' => null,
                'migration_status' => 'MANUAL_INTERVENTION_REQUIRED',
                'notes' => sprintf(
                    'Blocked: %d attachment(s) still pending download/upload, run 09_migrate_attachments.php first.',
                    (int)$attachmentSummary['blocked']
                ),
            ]);

            printf(
                "  [blocked] Jira issue %s has %d pending attachment(s), not creating issue.%s",
                $jiraIssueKey,
                (int)$attachmentSummary['blocked'],
                PHP_EOL
            );
            continue;
        }

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

        // Attachments die effectief mogen meegaan: alleen wanneer ze PENDING_ASSOCIATION zijn
        // EN er een Redmine token of SharePoint URL is.
        $preparedAttachments = $attachmentsByIssue[$jiraIssueId] ?? [];

        // Consistency check: als er attachments "klaar om te linken" staan (PENDING_ASSOCIATION),
        // dan moeten we er ook effectief evenveel "usable" hebben (token of sharepoint_url).
        $assocCount = countAssociationCandidates($pdo, $jiraIssueId);
        if ($assocCount > 0 && count($preparedAttachments) !== $assocCount) {
            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'redmine_issue_id' => null,
                'migration_status' => 'MANUAL_INTERVENTION_REQUIRED',
                'notes' => sprintf(
                    'Attachment mapping inconsistent: %d attachment(s) in PENDING_ASSOCIATION, but only %d have token/sharepoint_url.',
                    $assocCount,
                    count($preparedAttachments)
                ),
            ]);

            printf(
                "  [blocked] Jira issue %s attachment mapping inconsistent (PENDING_ASSOCIATION=%d, usable=%d).%s",
                $jiraIssueKey,
                $assocCount,
                count($preparedAttachments),
                PHP_EOL
            );
            continue;
        }

        foreach ($preparedAttachments as $att) {
            if ($att['redmine_upload_token'] !== '' && ($att['sharepoint_url'] ?? '') !== '') {
                printf(
                    "      [warn] Attachment %s has both Redmine token and SharePoint URL, using SharePoint link.%s",
                    $att['jira_attachment_id'],
                    PHP_EOL
                );
            }
        }

        $redmineUploads = array_values(array_filter(
            $preparedAttachments,
            static fn($attachment) => $attachment['redmine_upload_token'] !== ''
                && ($attachment['sharepoint_url'] ?? '') === ''
        ));
        $sharePointLinks = array_values(array_filter(
            $preparedAttachments,
            static fn($attachment) => ($attachment['sharepoint_url'] ?? '') !== ''
        ));

        $description = $candidate['proposed_description'] !== null ? (string)$candidate['proposed_description'] : null;
        $descriptionWithSharePoint = appendSharePointLinksToDescription($description, $sharePointLinks);

        try {
            $customFieldPayload = decodeCustomFieldPayload($candidate['proposed_custom_field_payload']);
        } catch (JsonException $exception) {
            $message = sprintf(
                'Invalid custom field payload JSON for Jira issue %s: %s',
                $jiraIssueKey,
                $exception->getMessage()
            );

            $updateStatement->execute([
                'mapping_id' => $mappingId,
                'redmine_issue_id' => null,
                'migration_status' => 'MANUAL_INTERVENTION_REQUIRED',
                'notes' => $message,
            ]);

            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        }

        $issuePayload = array_filter([
            'project_id' => $projectId,
            'tracker_id' => $trackerId,
            'status_id' => $statusId,
            'priority_id' => $candidate['proposed_priority_id'] !== null ? (int)$candidate['proposed_priority_id'] : null,
            'subject' => isset($candidate['proposed_subject']) ? (string)$candidate['proposed_subject'] : null,
            'description' => $descriptionWithSharePoint,
            'start_date' => $candidate['proposed_start_date'] !== null ? (string)$candidate['proposed_start_date'] : null,
            'due_date' => $candidate['proposed_due_date'] !== null ? (string)$candidate['proposed_due_date'] : null,
            'assigned_to_id' => $candidate['proposed_assigned_to_id'] !== null ? (int)$candidate['proposed_assigned_to_id'] : null,
            'done_ratio' => $candidate['proposed_done_ratio'] !== null ? (int)$candidate['proposed_done_ratio'] : null,
            'estimated_hours' => $candidate['proposed_estimated_hours'] !== null ? (float)$candidate['proposed_estimated_hours'] : null,
            'is_private' => $candidate['proposed_is_private'] !== null ? ($candidate['proposed_is_private'] ? 1 : 0) : null,
            'custom_fields' => $customFieldPayload,
            'uploads' => buildIssueUploadPayload($redmineUploads),
        ], static fn($value) => $value !== null);

        if ($useExtendedApi) {
            $issuePayload = array_merge($issuePayload, buildExtendedIssueOverrides($candidate, $defaultIssueUserId));
        }

        $payload = ['issue' => $issuePayload];

        if (($payload['issue']['uploads'] ?? []) === []) {
            unset($payload['issue']['uploads']);
        }

        $endpoint = $useExtendedApi
            ? buildExtendedApiPath($extendedApiPrefix, 'issues.json')
            : 'issues.json';

        // Punt 5: forceer geen leading slash, zelfs als buildExtendedApiPath dat zou doen
        $endpoint = ltrim($endpoint, '/');

        try {
            $response = $client->post($endpoint, [
                'json' => $payload,
                'query' => ['notify' => 'false'],
            ]);
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

        $finalStatus = 'CREATION_SUCCESS';
        $finalNotes = null;

        printf("  [created] Jira issue %s → Redmine issue #%d%s", $jiraIssueKey, $redmineIssueId, PHP_EOL);

        if ($redmineUploads !== []) {
            markUploadedAttachmentsAsSuccess($pdo, $redmineUploads, $redmineIssueId);
        }

        if ($sharePointLinks !== []) {
            markSharePointAttachmentsAsLinked($pdo, $sharePointLinks, $redmineIssueId);
        }

        $updateStatement->execute([
            'mapping_id' => $mappingId,
            'redmine_issue_id' => $redmineIssueId,
            'migration_status' => $finalStatus,
            'notes' => $finalNotes,
        ]);
    }
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
 * @return array<int, array{
 *   mapping_id: int,
 *   jira_attachment_id: string,
 *   filename: string,
 *   mime_type: ?string,
 *   size_bytes: ?int,
 *   redmine_upload_token: string,
 *   redmine_attachment_id: ?int,
 *   sharepoint_url: ?string
 * }>
 */
function fetchPreparedAttachmentUploads(PDO $pdo, string $jiraIssueId): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_attachment_id,
            map.redmine_upload_token,
            map.redmine_attachment_id,
            map.sharepoint_url,
            att.filename,
            att.mime_type,
            att.size_bytes
        FROM migration_mapping_attachments map
        JOIN staging_jira_attachments att ON att.id = map.jira_attachment_id
        WHERE map.jira_issue_id = :issue_id
          AND map.migration_status = 'PENDING_ASSOCIATION'
          AND (
            (map.redmine_upload_token IS NOT NULL AND map.redmine_upload_token <> '')
            OR (map.sharepoint_url IS NOT NULL AND map.sharepoint_url <> '')
            )
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
        $sharePointUrl = isset($row['sharepoint_url']) ? trim((string)$row['sharepoint_url']) : '';
        if ($token === '' && $sharePointUrl === '') {
            continue;
        }

        $redmineAttachmentId = isset($row['redmine_attachment_id']) && $row['redmine_attachment_id'] !== null
            ? (int)$row['redmine_attachment_id']
            : null;

        $result[] = [
            'mapping_id' => (int)$row['mapping_id'],
            'jira_attachment_id' => (string)$row['jira_attachment_id'],
            'filename' => isset($row['filename']) ? (string)$row['filename'] : '',
            'mime_type' => isset($row['mime_type']) && $row['mime_type'] !== '' ? (string)$row['mime_type'] : null,
            'size_bytes' => isset($row['size_bytes']) ? (int)$row['size_bytes'] : null,
            'redmine_upload_token' => $token,
            'redmine_attachment_id' => $redmineAttachmentId,
            'sharepoint_url' => $sharePointUrl !== '' ? $sharePointUrl : null,
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
            SUM(
                IF(
                    migration_status = 'PENDING_ASSOCIATION'
                    AND (
                        (redmine_upload_token IS NOT NULL AND redmine_upload_token <> '')
                        OR (sharepoint_url IS NOT NULL AND sharepoint_url <> '')
                    ),
                    1, 0
                )
            ) AS ready_count,
            SUM(IF(migration_status IN ('PENDING_DOWNLOAD', 'PENDING_UPLOAD'), 1, 0)) AS blocked_count
        FROM migration_mapping_attachments
        WHERE jira_issue_id = :issue_id
    SQL;

    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare attachment summary statement.');
    }

    $statement->execute(['issue_id' => $jiraIssueId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: ['ready_count' => 0, 'blocked_count' => 0];
    $statement->closeCursor();

    return [
        'ready' => (int)($row['ready_count'] ?? 0),
        'blocked' => (int)($row['blocked_count'] ?? 0),
    ];
}

function countAssociationCandidates(PDO $pdo, string $jiraIssueId): int
{
    $sql = <<<SQL
        SELECT COUNT(*)
        FROM migration_mapping_attachments
        WHERE jira_issue_id = :issue_id
          AND migration_status = 'PENDING_ASSOCIATION'
          AND (
              (redmine_upload_token IS NOT NULL AND redmine_upload_token <> '')
              OR (sharepoint_url IS NOT NULL AND sharepoint_url <> '')
          )
    SQL;

    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare association candidate count statement.');
    }

    $statement->execute(['issue_id' => $jiraIssueId]);
    $count = $statement->fetchColumn();
    $statement->closeCursor();

    return $count === false ? 0 : (int)$count;
}

/**
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string, sharepoint_url: ?string}> $attachments
 * @return array<int, array<string, mixed>>
 */
function buildIssueUploadPayload(array $attachments): array
{
    $uploads = [];
    foreach ($attachments as $attachment) {
        if ($attachment['redmine_upload_token'] === '') {
            continue;
        }

        $filename = buildUploadUniqueName($attachment['jira_attachment_id'], $attachment['filename']);
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
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string, sharepoint_url: ?string}> $sharePointLinks
 */
function appendSharePointLinksToDescription(?string $description, array $sharePointLinks): ?string
{
    if ($sharePointLinks === []) {
        return $description;
    }

    $lines = [
        '',
        '---',
        '**Attachments stored on SharePoint:**',
    ];

    foreach ($sharePointLinks as $attachment) {
        $url = (string)($attachment['sharepoint_url'] ?? '');
        if ($url === '') {
            continue;
        }

        $label = $attachment['filename'] !== ''
            ? $attachment['filename']
            : $attachment['jira_attachment_id'];

        // build unique name for the attachment (same as used by uploads)
        $unique = buildUploadUniqueName((string)$attachment['jira_attachment_id'], $attachment['filename'] ?? '');

        // Skip if description already contains the exact SharePoint URL or the attachment:unique marker
        if (($description !== null && (strpos($description, $url) !== false
                || strpos($description, 'attachment:' . $unique) !== false
                || strpos($description, $unique) !== false))) {
            continue;
        }

        $lines[] = sprintf('- %s: %s', $label, $url);
    }

    $block = implode(PHP_EOL, $lines);

    if ($description === null || trim($description) === '') {
        return ltrim($block);
    }

    return rtrim($description) . PHP_EOL . PHP_EOL . $block;
}

/**
 * @param array<int, array{mapping_id: int, jira_attachment_id: string, filename: string, mime_type: ?string, size_bytes: ?int, redmine_upload_token: string, sharepoint_url: ?string}> $attachments
 */
function markSharePointAttachmentsAsLinked(PDO $pdo, array $attachments, int $redmineIssueId): void
{
    foreach ($attachments as $attachment) {
        $note = isset($attachment['sharepoint_url']) && $attachment['sharepoint_url'] !== null
            ? sprintf('Attachment stored on SharePoint: %s', $attachment['sharepoint_url'])
            : 'Attachment stored on SharePoint.';

        updateAttachmentMappingAfterPush($pdo, (int)$attachment['mapping_id'], null, 'SUCCESS', $note, $redmineIssueId);
    }
}

function updateAttachmentMappingAfterPush(PDO $pdo, int $mappingId, ?int $redmineAttachmentId, string $status, ?string $notes, ?int $redmineIssueId = null): void
{
    $sql = <<<SQL
        UPDATE migration_mapping_attachments
        SET
            redmine_attachment_id = COALESCE(:redmine_attachment_id, redmine_attachment_id),
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
            issue.description_html AS jira_description_html,
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

        $index[$issueId][$attachmentId] = buildUploadUniqueName($attachmentId, $filename);
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

    // Normalise whitespace
    $name = preg_replace('/\s+/', ' ', $name);

    // Replace filesystem and URL-hostile characters
    $name = preg_replace('/[\/:*?"<>|\x00-\x1F]/', '_', $name);

    // Prevent leading dots (hidden files)
    $name = ltrim($name, '.');

    // Prefix with Jira attachment id (guarantees uniqueness)
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


/**
 * @return array<string, array{parent_field_id: int, child_field_id: int, child_lookup: array<string, array{parent_label: string, parent_id: string|null, child_label: string, child_id: string}>, child_label_lookup: array<string, array<int, string>>}>|
 *         array{}
 */
function buildCascadingFieldIndex(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            child.jira_field_id,
            child.jira_field_name,
            child.jira_allowed_values,
            child.redmine_custom_field_id AS child_redmine_custom_field_id,
            child.mapping_parent_custom_field_id,
            parent.mapping_id AS parent_mapping_id,
            parent.redmine_custom_field_id AS parent_redmine_custom_field_id
        FROM migration_mapping_custom_fields child
        LEFT JOIN migration_mapping_custom_fields parent
            ON parent.mapping_id = child.mapping_parent_custom_field_id
        WHERE child.proposed_field_format = 'depending_list'
          AND child.redmine_custom_field_id IS NOT NULL
          AND child.mapping_parent_custom_field_id IS NOT NULL
          AND child.migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS', 'READY_FOR_UPDATE', 'READY_FOR_CREATION')
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build cascading field index: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to build cascading field index.');
    }

    $index = [];
    while (true) {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            break;
        }

        $jiraFieldId = isset($row['jira_field_id']) ? (string)$row['jira_field_id'] : '';
        if ($jiraFieldId === '') {
            continue;
        }

        $allowedValues = decodeJsonColumn($row['jira_allowed_values'] ?? null);
        if (!is_array($allowedValues)) {
            continue;
        }

        $parentRedmineId = isset($row['parent_redmine_custom_field_id']) ? (int)$row['parent_redmine_custom_field_id'] : null;
        $childRedmineId = isset($row['child_redmine_custom_field_id']) ? (int)$row['child_redmine_custom_field_id'] : null;
        if ($parentRedmineId === null && isset($row['parent_mapping_id'])) {
            $resolvedParentId = resolveParentRedmineFieldId($pdo, (int)$row['parent_mapping_id']);
            if ($resolvedParentId !== null) {
                $parentRedmineId = $resolvedParentId;
            }
        }

        if ($parentRedmineId === null || $childRedmineId === null) {
            continue;
        }

        $descriptor = parseCascadingAllowedValues($allowedValues);
        if ($descriptor === null) {
            continue;
        }

        $childLookup = isset($descriptor['child_lookup']) && is_array($descriptor['child_lookup']) ? $descriptor['child_lookup'] : [];
        $childLabelLookup = isset($descriptor['child_label_lookup']) && is_array($descriptor['child_label_lookup']) ? $descriptor['child_label_lookup'] : [];
        if ($childLookup === [] && $childLabelLookup === []) {
            continue;
        }

        $index[$jiraFieldId] = [
            'parent_field_id' => $parentRedmineId,
            'child_field_id' => $childRedmineId,
            'child_lookup' => $childLookup,
            'child_label_lookup' => $childLabelLookup,
        ];
    }

    $statement->closeCursor();

    return $index;
}

/**
 * @return array<string, array{
 *     jira_field_name: string,
 *     redmine_custom_field_id: int,
 *     field_format: string,
 *     is_multiple: bool,
 *     enumeration_lookup: array<string, string>
 * }> | array{}
 */
function buildCustomFieldMappingIndex(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            jira_field_id,
            jira_field_name,
            proposed_field_format,
            proposed_is_multiple,
            redmine_custom_field_id,
            redmine_custom_field_enumerations
        FROM migration_mapping_custom_fields
        WHERE migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')
          AND redmine_custom_field_id IS NOT NULL
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch active custom field mappings: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to fetch active custom field mappings.');
    }

    $index = [];

    while (true) {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            break;
        }

        $jiraFieldId = isset($row['jira_field_id']) ? (string)$row['jira_field_id'] : '';
        $redmineCustomFieldId = isset($row['redmine_custom_field_id']) ? (int)$row['redmine_custom_field_id'] : null;
        if ($jiraFieldId === '' || $redmineCustomFieldId === null) {
            continue;
        }

        $fieldFormat = normalizeCustomFieldFormat($row['proposed_field_format'] ?? null);
        if (in_array($fieldFormat, ['depending_list', 'depending_enumeration'], true)) {
            continue;
        }

        $enumerations = decodeJsonColumn($row['redmine_custom_field_enumerations'] ?? null);
        $index[$jiraFieldId] = [
            'jira_field_name' => isset($row['jira_field_name']) ? (string)$row['jira_field_name'] : $jiraFieldId,
            'redmine_custom_field_id' => $redmineCustomFieldId,
            'field_format' => $fieldFormat,
            'is_multiple' => normalizeBooleanFlag($row['proposed_is_multiple'] ?? null) ?? false,
            'enumeration_lookup' => buildEnumerationLookup(is_array($enumerations) ? $enumerations : []),
        ];
    }

    $statement->closeCursor();

    return $index;
}

/**
 * @param array<string, mixed> $issuePayload
 * @param array<string, array{parent_field_id: int, child_field_id: int, child_lookup: array<string, array{parent_label: string, parent_id: string|null, child_label: string, child_id: string}>, child_label_lookup: array<string, array<int, string>>}> $cascadingIndex
 * @param array<int, string> $warnings
 * @return array<int, array{id: int, value: string}>
 */
function buildCascadingCustomFieldPayload(array $issuePayload, array $cascadingIndex, array &$warnings = []): array
{
    if ($cascadingIndex === []) {
        return [];
    }

    $fields = $issuePayload['fields'] ?? null;
    if (!is_array($fields)) {
        return [];
    }

    $result = [];
    foreach ($cascadingIndex as $jiraFieldId => $meta) {
        $rawValue = $fields[$jiraFieldId] ?? null;
        $selection = extractJiraCascadingSelection($rawValue);
        if ($selection === null) {
            continue;
        }

        $parentLabel = null;
        $childLabel = null;

        if ($selection['child_id'] !== null && isset($meta['child_lookup'][$selection['child_id']])) {
            $lookup = $meta['child_lookup'][$selection['child_id']];
            $parentLabel = $lookup['parent_label'];
            $childLabel = $lookup['child_label'];
        } elseif ($selection['child_label'] !== null && isset($meta['child_label_lookup'][$selection['child_label']])) {
            $potentialParents = $meta['child_label_lookup'][$selection['child_label']];
            if (count($potentialParents) === 1) {
                $parentLabel = current($potentialParents);
                $childLabel = $selection['child_label'];
            }
        }

        if ($parentLabel === null || $childLabel === null) {
            $warnings[] = $jiraFieldId;
            continue;
        }

        $result[] = [
            'id' => $meta['parent_field_id'],
            'value' => $parentLabel,
        ];
        $result[] = [
            'id' => $meta['child_field_id'],
            'value' => $childLabel,
        ];
    }

    return $result;
}

/**
 * @param array<string, mixed> $issuePayload
 * @param array<string, array{jira_field_name: string, redmine_custom_field_id: int, field_format: string, is_multiple: bool, enumeration_lookup: array<string, string>}> $mappingIndex
 * @param array<int, string> $warnings
 * @return array<int, array{id: int, value: string|array<int, string>}>|array{}
 */
function buildMappedCustomFieldPayload(array $issuePayload, array $mappingIndex, array &$warnings = []): array
{
    if ($mappingIndex === []) {
        return [];
    }

    $fields = $issuePayload['fields'] ?? null;
    if (!is_array($fields)) {
        return [];
    }

    $result = [];

    foreach ($mappingIndex as $jiraFieldId => $meta) {
        if (!array_key_exists($jiraFieldId, $fields)) {
            continue;
        }

        $raw = $fields[$jiraFieldId] ?? null;

        // jouw normalisatie
        $normalizedValue = normalizeCustomFieldValue(
            $meta['field_format'],
            $meta['is_multiple'],
            $meta['enumeration_lookup'],
            $raw
        );

        // alleen warnen als raw niet leeg was, maar normalisatie toch null gaf
        if ($normalizedValue === null && normalizeJiraEmptyValue($raw) !== null) {
            error_log("WARN normalize failed for {$jiraFieldId}, raw=" . json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $warnings[] = $jiraFieldId;
        }

        if ($normalizedValue !== null) {
            $result[] = [
                'id' => $meta['redmine_custom_field_id'],
                'value' => $normalizedValue,
            ];
        }
    }

    return $result;
}

/**
 * @param string $fieldFormat
 * @param bool $isMultiple
 * @param array<string, string> $enumerationLookup
 * @param mixed $rawValue
 * @return array<int, string>|string|null
 */
function normalizeCustomFieldValue(
    string $fieldFormat,
    bool $isMultiple,
    array $enumerationLookup,
    mixed $rawValue
): array|string|null
{
    // Special case: Jira label-manager object { labels: [...] }
    $labelsObject = extractJiraLabelsObject($rawValue);
    if ($labelsObject !== null) {
        return $isMultiple ? $labelsObject : ($labelsObject[0] ?? null);
    }

    $values = $isMultiple ? normalizeToList($rawValue) : [$rawValue];
    $normalizedValues = [];

    foreach ($values as $value) {
        $normalized = normalizeCustomFieldSingleValue($fieldFormat, $enumerationLookup, $value);
        if ($normalized === null) {
            continue;
        }
        $normalizedValues[] = $normalized;
    }

    $normalizedValues = array_values(array_unique($normalizedValues));

    if ($normalizedValues === []) {
        return null;
    }

    return $isMultiple ? $normalizedValues : $normalizedValues[0];
}

/**
 * Jira "Label Manager" object: { labels: [...] }
 * @return array<int, string>|null
 */
function extractJiraLabelsObject(mixed $rawValue): ?array
{
    if (!is_array($rawValue)) {
        return null;
    }

    if (!array_key_exists('labels', $rawValue)) {
        return null;
    }

    $labels = $rawValue['labels'];

    if (!is_array($labels)) {
        return null;
    }

    $out = [];
    foreach ($labels as $label) {
        if (!is_string($label)) {
            continue;
        }
        $label = trim($label);
        if ($label === '' || strtolower($label) === 'none') {
            continue;
        }
        $out[] = $label;
    }

    return array_values(array_unique($out));
}

/**
 * @param string $fieldFormat
 * @param array<string, string> $enumerationLookup
 * @param mixed $rawValue
 * @return string|null
 */
function normalizeCustomFieldSingleValue(string $fieldFormat, array $enumerationLookup, mixed $rawValue): ?string
{
    $format = strtolower($fieldFormat);

    switch ($format) {
        case 'bool':
        case 'boolean':
            $flag = normalizeBooleanDatabaseValue($rawValue);
            return $flag === null ? null : ($flag === 1 ? '1' : '0');
        case 'int':
        case 'integer':
            $intValue = normalizeInteger($rawValue, PHP_INT_MIN);
            return $intValue === null ? null : (string)$intValue;
        case 'float':
        case 'decimal':
            $floatValue = normalizeDecimal($rawValue, -INF);
            return $floatValue === null ? null : rtrim(rtrim((string)$floatValue, '0'), '.');
        case 'date':
            $stringValue = extractJiraCustomFieldString($rawValue);
            if ($stringValue === null) {
                return null;
            }

            if (strlen($stringValue) >= 10) {
                return substr($stringValue, 0, 10);
            }

            $timestamp = strtotime($stringValue);
            return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
        case 'list':
        case 'enumeration':
        case 'string':
        case 'text':
        default:
            $stringValue = extractJiraCustomFieldString($rawValue);
            if ($stringValue === null) {
                return null;
            }

            $normalized = strtolower($stringValue);
            if (isset($enumerationLookup[$normalized])) {
                return $enumerationLookup[$normalized];
            }

            return $stringValue;
    }
}

function normalizeToList(mixed $value): array
{
    if ($value === null) {
        return [];
    }

    if (is_array($value) && isListArray($value)) {
        return $value;
    }

    return [$value];
}

/**
 * @param array<int, array<string, mixed>> $enumerations
 * @return array<string, string>
 */
function buildEnumerationLookup(array $enumerations): array
{
    $lookup = [];

    foreach ($enumerations as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $redmineLabel = null;
        if (isset($entry['name'])) {
            $redmineLabel = (string)$entry['name'];
        } elseif (isset($entry['label'])) {
            $redmineLabel = (string)$entry['label'];
        }

        $redmineLabel = $redmineLabel !== null ? trim($redmineLabel) : null;
        if ($redmineLabel === null || $redmineLabel === '') {
            continue;
        }

        $jiraValues = [];
        if (isset($entry['jira_value'])) {
            $jiraValues[] = $entry['jira_value'];
        }
        if (isset($entry['jira_values']) && is_array($entry['jira_values'])) {
            array_push($jiraValues, ...$entry['jira_values']);
        }
        if (isset($entry['jira_option_id'])) {
            $jiraValues[] = $entry['jira_option_id'];
        }

        if ($jiraValues === []) {
            $jiraValues[] = $redmineLabel;
        }

        foreach ($jiraValues as $jiraValue) {
            if (!is_scalar($jiraValue)) {
                continue;
            }

            $normalized = strtolower(trim((string)$jiraValue));
            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = $redmineLabel;
        }
    }

    return $lookup;
}

function normalizeCustomFieldFormat(?string $fieldFormat): string
{
    if ($fieldFormat === null) {
        return 'string';
    }

    $normalized = strtolower(trim($fieldFormat));
    return match ($normalized) {
        'boolean' => 'bool',
        'integer' => 'int',
        'decimal' => 'float',
        default => $normalized !== '' ? $normalized : 'string',
    };
}

function extractJiraCustomFieldString(mixed $value): ?string
{
    if (is_string($value) || is_int($value) || is_float($value)) {
        $trimmed = trim((string)$value);
        if ($trimmed === '' || strtolower($trimmed) === 'none') return null;
        return $trimmed;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_array($value)) {
        // NEW: Jira ADF doc support
        if (($value['type'] ?? null) === 'doc') {
            $content = $value['content'] ?? null;

            // content ontbreekt of is leeg => empty field
            if (!is_array($content) || count($content) === 0) {
                return null;
            }

            $txt = convertJiraAdfToPlaintext($value);
            $txt = $txt !== null ? trim($txt) : null;
            return ($txt === '') ? null : $txt;
        }


        // Jira "labels with colors" (zoals customfield_10067)
        if (array_key_exists('labels', $value) && is_array($value['labels'])) {
            $labels = array_values(array_filter(array_map(
                fn($x) => is_scalar($x) ? trim((string)$x) : '',
                $value['labels']
            ), fn($s) => $s !== '' && strtolower($s) !== 'none'));

            if (count($labels) === 0) return null;

            // Redmine enumeration is single value -> pak de eerste
            // (of implode(', ', $labels) als je toch multi wil bewaren)
            return $labels[0];
        }

        foreach (['value', 'name', 'label', 'id'] as $k) {
            if (isset($value[$k])) {
                $candidate = trim((string)$value[$k]);
                if ($candidate === '' || strtolower($candidate) === 'none') return null;
                return $candidate;
            }
        }
    }

    return null;
}

/**
 * @param PDO $pdo
 * @param int $parentMappingId
 * @return int|null
 */
function resolveParentRedmineFieldId(PDO $pdo, int $parentMappingId): ?int
{
    $sql = <<<SQL
        SELECT redmine_custom_field_id
        FROM migration_mapping_custom_fields
        WHERE mapping_id = :mapping_id
          AND redmine_custom_field_id IS NOT NULL
          AND migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS', 'READY_FOR_UPDATE', 'READY_FOR_CREATION')
    SQL;

    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to resolve parent Redmine custom field identifier.');
    }

    $statement->execute(['mapping_id' => $parentMappingId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    $statement->closeCursor();

    if (!is_array($row)) {
        return null;
    }

    return isset($row['redmine_custom_field_id']) ? (int)$row['redmine_custom_field_id'] : null;
}

/**
 * @param mixed $rawValue
 * @return array{child_id: string|null, child_label: string|null}|null
 */
function extractJiraCascadingSelection(mixed $rawValue): ?array
{
    if (is_array($rawValue)) {
        $child = $rawValue['child'] ?? null;
        if (is_array($child)) {
            $childId = isset($child['id']) ? trim((string)$child['id']) : null;
            $childLabel = isset($child['value']) ? trim((string)$child['value']) : null;
            if ($childId !== null || ($childLabel !== null && $childLabel !== '')) {
                return [
                    'child_id' => $childId !== '' ? $childId : null,
                    'child_label' => $childLabel !== '' ? $childLabel : null,
                ];
            }
        }

        $childId = isset($rawValue['id']) ? trim((string)$rawValue['id']) : null;
        $childLabel = isset($rawValue['value']) ? trim((string)$rawValue['value']) : null;
        if ($childId !== null || ($childLabel !== null && $childLabel !== '')) {
            return [
                'child_id' => $childId !== '' ? $childId : null,
                'child_label' => $childLabel !== '' ? $childLabel : null,
            ];
        }
    } elseif (is_string($rawValue)) {
        $trimmed = trim($rawValue);
        if ($trimmed !== '') {
            return [
                'child_id' => $trimmed,
                'child_label' => null,
            ];
        }
    }

    return null;
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
    mixed $proposedCustomFieldPayload
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
    ];

    try {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode issue automation hash payload: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', $encoded);
}

function decodeCustomFieldPayload(mixed $payload): ?array
{
    if ($payload === null) {
        return null;
    }

    $decoded = json_decode((string)$payload, true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $allowedValues
 * @return array|null
 */
function parseCascadingAllowedValues(array $allowedValues): ?array
{
    if (!isset($allowedValues['mode']) || $allowedValues['mode'] !== 'cascading') {
        return null;
    }

    $parents = [];
    $parentIndex = [];
    if (isset($allowedValues['parents']) && is_array($allowedValues['parents'])) {
        foreach ($allowedValues['parents'] as $parent) {
            if (!is_array($parent)) {
                continue;
            }

            $value = isset($parent['value']) ? trim((string)$parent['value']) : '';
            if ($value === '') {
                continue;
            }

            $parents[$value] = $value;
            if (isset($parent['id']) && (string)$parent['id'] !== '') {
                $parentIndex[$value] = (string)$parent['id'];
            }
        }
    }

    ksort($parents);

    $dependencies = [];
    $childUnion = [];
    $childLookup = [];
    $childLabelLookup = [];

    if (isset($allowedValues['dependencies']) && is_array($allowedValues['dependencies'])) {
        foreach ($allowedValues['dependencies'] as $parentValue => $children) {
            $parentKey = trim((string)$parentValue);
            if ($parentKey === '') {
                continue;
            }

            $parentId = $parentIndex[$parentKey] ?? null;

            $normalizedChildren = [];
            if (is_array($children)) {
                foreach ($children as $child) {
                    if (is_array($child)) {
                        $childValue = isset($child['value']) ? trim((string)$child['value']) : '';
                        $childId = isset($child['id']) ? trim((string)$child['id']) : null;
                    } else {
                        $childValue = trim((string)$child);
                        $childId = null;
                    }

                    if ($childValue === '') {
                        continue;
                    }

                    $normalizedChildren[$childValue] = $childValue;
                    $childUnion[$childValue] = $childValue;

                    if ($childId !== null && $childId !== '') {
                        $childLookup[$childId] = [
                            'parent_label' => $parentKey,
                            'parent_id' => $parentId,
                            'child_label' => $childValue,
                            'child_id' => $childId,
                        ];
                    }

                    if (!isset($childLabelLookup[$childValue])) {
                        $childLabelLookup[$childValue] = [];
                    }
                    $childLabelLookup[$childValue][$parentKey] = $parentKey;
                }
            }

            ksort($normalizedChildren);
            $dependencies[$parentKey] = array_values($normalizedChildren);
        }
    }

    foreach (array_keys($parents) as $parentValue) {
        if (!isset($dependencies[$parentValue])) {
            $dependencies[$parentValue] = [];
        }
    }

    ksort($dependencies);
    ksort($childUnion);

    return [
        'parents' => array_values($parents),
        'dependencies' => $dependencies,
        'child_values' => array_values($childUnion),
        'parent_index' => $parentIndex,
        'child_lookup' => $childLookup,
        'child_label_lookup' => array_map('array_values', $childLabelLookup),
    ];
}

function decodeJsonColumn(mixed $value): mixed
{
    if ($value === null) {
        return null;
    }

    if (is_array($value)) {
        return $value;
    }

    $stringValue = trim((string)$value);
    if ($stringValue === '') {
        return null;
    }

    try {
        return json_decode($stringValue, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return $stringValue;
    }
}

function encodeJsonColumn(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    if (is_string($value)) {
        return $value;
    }

    try {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode JSON column value: ' . $exception->getMessage(), 0, $exception);
    }
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

    // Jira geeft soms enkel: <!-- ADF macro (type = 'table') -->
    // Dat is geen HTML inhoud, dus forceer fallback naar ADF.
    $withoutComments = preg_replace('/<!--.*?-->/s', '', $trimmed);
    $withoutComments = is_string($withoutComments) ? trim($withoutComments) : $trimmed;
    if ($withoutComments === '' && stripos($trimmed, 'ADF macro') !== false) {
        return null;
    }

    $rewrittenHtml = rewriteJiraAttachmentLinks($trimmed, $attachments);

    static $converter = null;
    if ($converter === null) {
        $converter = new HtmlConverter([
            'strip_tags' => true,
            'hard_break' => true,
            'remove_nodes' => 'script style',
        ]);
        $converter->getEnvironment()->addConverter(new TableConverter());
    }

    try {
        $markdown = trim($converter->convert($rewrittenHtml));
    } catch (Throwable) {
        $markdown = trim(strip_tags($rewrittenHtml));
    }

    // extra safety: xml header die toch doorsijpelt
    $markdown = preg_replace('/^\s*<\?xml[^>]*\?>\s*/i', '', $markdown ?? '') ?? $markdown;
    $markdown = str_replace('<?xml encoding="utf-8"?>', '', $markdown);

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

    // process anchors
    $links = iterator_to_array($document->getElementsByTagName('a'));
    foreach ($links as $link) {
        if (!$link instanceof DOMElement) continue;

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

        // Als link geen tekst heeft: vul met filename (maar niet als alt/title!)
        $linkText = trim($link->textContent ?? '');
        if ($linkText === '') {
            while ($link->firstChild !== null) {
                $link->removeChild($link->firstChild);
            }
            $link->appendChild($document->createTextNode($filename));
        }

        // ZET href naar de unieke naam (geen "attachment:" prefix)
        $link->setAttribute('href', $filename);

        // VERWIJDER presentation/preview attributen zodat Markdown-converter geen title overneemt
        foreach (['title', 'file-preview-title', 'file-preview-type', 'file-preview-id', 'data-linked-resource-id'] as $attr) {
            if ($link->hasAttribute($attr)) $link->removeAttribute($attr);
        }
    }

    // process images
    $images = iterator_to_array($document->getElementsByTagName('img'));
    foreach ($images as $image) {
        if (!$image instanceof DOMElement) continue;

        $attachmentId = null;
        $source = $image->getAttribute('src');
        if ($source !== '' && preg_match('#attachment/(\d+)#', $source, $matches)) {
            $attachmentId = $matches[1];
        } elseif ($image->hasAttribute('data-linked-resource-id')) {
            $attachmentId = (string)$image->getAttribute('data-linked-resource-id');
        }

        if ($attachmentId === null || !isset($attachments[$attachmentId])) {
            continue;
        }

        $filename = $attachments[$attachmentId] !== ''
            ? $attachments[$attachmentId]
            : sprintf('attachment-%s', $attachmentId);

        // Zet src naar unieke naam (geen "attachment:" prefix)
        $image->setAttribute('src', $filename);

        // VEEL BELANGRIJKER: verwijder titel/alt/preview-attrs zodat converter géén title toevoegt.
        foreach (['title', 'alt', 'data-attachment-name', 'data-attachment-type', 'data-media-services-id', 'data-media-services-type'] as $attr) {
            if ($image->hasAttribute($attr)) $image->removeAttribute($attr);
        }
        // (optioneel) zet lege alt als je absoluut een alt-attribuut wilt
        // $image->setAttribute('alt', '');
    }

    $converted = $document->saveHTML();

    return $converted !== false ? preg_replace('/^\s*<\?xml[^>]+\?>\s*/i', '', $converted) : $html;
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

    // Altijd in UTC wegschrijven (DATETIME zonder timezone, dus maak het expliciet)
    return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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

/**
 * @param array<string, mixed> $candidate
 * @return array<string, mixed>
 */
function buildExtendedIssueOverrides(array $candidate, ?int $defaultAuthorId = null): array
{
    $overrides = [];

    $authorId = $candidate['proposed_author_id'] ?? $defaultAuthorId;
    if ($authorId !== null) {
        $overrides['author_id'] = (int)$authorId;
    }

    $created = normalizeJiraTimestamp($candidate['jira_created_at'] ?? null, false);
    if ($created !== null) {
        $overrides['created_on'] = $created;
    }

    $updated = normalizeJiraTimestamp($candidate['jira_updated_at'] ?? null, false);
    if ($updated !== null) {
        $overrides['updated_on'] = $updated;
    }

    $closed = extractJiraResolutionDate($candidate['jira_raw_payload'] ?? null);
    if ($closed !== null) {
        $overrides['closed_on'] = $closed;
    }

    return $overrides;
}

function extractDefaultIssueAuthorId(array $config): ?int
{
    $migrationConfig = $config['migration'] ?? [];
    $issueConfig = isset($migrationConfig['issues']) && is_array($migrationConfig['issues'])
        ? $migrationConfig['issues']
        : [];

    if (!isset($issueConfig['default_redmine_author_id'])) {
        return null;
    }

    $value = $issueConfig['default_redmine_author_id'];
    if (!is_numeric($value)) {
        return null;
    }

    $intValue = (int)$value;

    return $intValue > 0 ? $intValue : null;
}

function normalizeJiraTimestamp(mixed $value, bool $allowMicros = false): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_string($value) && trim($value) === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable((string)$value);
    } catch (Exception) {
        return null;
    }

    $dt = $dt->setTimezone(new DateTimeZone('UTC'));

    if ($allowMicros) {
        $s = $dt->format('Y-m-d\TH:i:s.u\Z');     // 2023-11-02T08:15:00.509723Z
        // optioneel: strip .000000 als je bron geen micros had
        $s = preg_replace('/\.0{6}Z$/', 'Z', $s);
        return $s;
    }

    return $dt->format('Y-m-d\TH:i:s\Z');         // 2023-12-01T11:00:00Z
}

function extractJiraResolutionDate(mixed $rawPayload): ?string
{
    $decoded = decodeJsonColumn($rawPayload);
    $fields = is_array($decoded) ? ($decoded['fields'] ?? []) : [];
    if (!is_array($fields)) {
        return null;
    }

    return normalizeJiraTimestamp($fields['resolutiondate'] ?? null, false);
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

/**
 * @throws DateMalformedStringException
 */
function formatCurrentTimestamp(string $format = 'Y-m-d H:i:s'): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format($format);
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

function shouldUseExtendedApi(array $redmineConfig, bool $cliFlag): bool
{
    if ($cliFlag) {
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

function verifyExtendedApiAvailability(Client $client, string $prefix, string $resource): void
{
    $path = buildExtendedApiPath($prefix, $resource);

    try {
        $response = $client->get($path);
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $statusCode = $response ? $response->getStatusCode() : 0;
        $reason = $response ? $response->getReasonPhrase() : 'unknown error';
        throw new RuntimeException(
            sprintf('Extended API availability check failed (%s): HTTP %d %s', $path, $statusCode, $reason),
            0,
            $exception
        );
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Failed to reach the extended API: ' . $exception->getMessage(), 0, $exception);
    }

    $header = $response->getHeaderLine('X-Redmine-Extended-API');
    if (trim($header) === '') {
        throw new RuntimeException('Extended API response missing X-Redmine-Extended-API header. Verify the plugin installation.');
    }
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
    printf("      --use-extended-api   Create issues through the extended API when available (timestamps, authors, no mail).%s", PHP_EOL);
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
/**
 * @param array<int, array{
 *   mapping_id: int,
 *   jira_attachment_id: string,
 *   filename: string,
 *   mime_type: ?string,
 *   size_bytes: ?int,
 *   redmine_upload_token: string,
 *   sharepoint_url: ?string
 * }> $attachments
 */
function markUploadedAttachmentsAsSuccess(PDO $pdo, array $attachments, int $redmineIssueId): void
{
    foreach ($attachments as $attachment) {
        updateAttachmentMappingAfterPush(
            $pdo,
            (int)$attachment['mapping_id'],
            null,          // redmine_attachment_id laten we ongemoeid
            'SUCCESS',
            null,
            $redmineIssueId
        );
    }
}

function normalizeJiraEmptyValue(mixed $v): mixed {
    if ($v === null) return null;

    if (is_string($v)) {
        $t = trim($v);
        if ($t === '' || strtolower($t) === 'none') {
            return null;
        }
        return $t;
    }

    // NEW: Jira ADF doc empty check
    if (is_array($v) && (($v['type'] ?? null) === 'doc')) {
        $content = $v['content'] ?? null;
        if (!is_array($content) || count($content) === 0) {
            return null;
        }
        // optioneel: als content bestaat maar toch geen tekst oplevert
        $txt = convertJiraAdfToPlaintext($v);
        if ($txt === null || trim($txt) === '') {
            return null;
        }
        return $v; // non-empty doc, laat door als "niet leeg"
    }

    if (is_array($v) && isset($v['labels']) && is_array($v['labels'])) {
        $labels = array_filter(array_map(
            fn($x) => is_scalar($x) ? trim((string)$x) : '',
            $v['labels']
        ), fn($s) => $s !== '' && strtolower($s) !== 'none');

        if (count($labels) === 0) return null;
    }

    if ($v === []) {
        return null;
    }

    return $v;
}
function convertJiraAdfToMarkdown(mixed $adf): ?string
{
    if ($adf === null) return null;

    if (is_string($adf)) {
        $t = trim($adf);
        return $t !== '' ? $t : null;
    }

    if (!is_array($adf) || (($adf['type'] ?? null) !== 'doc')) {
        return null;
    }

    $out = renderAdfNodeToMarkdown($adf);
    $out = trim(preg_replace("/\n{3,}/", "\n\n", $out) ?? '');

    return $out !== '' ? $out : null;
}

function convertDescriptionToMarkdown(
    string $adfJson,
    AdfConverter $adfToMd
): string {
    $adfJson = trim($adfJson);

    if ($adfJson !== '') {
        $node = $adfToMd->convert($adfJson);
        $md   = trim((string)$node->toMarkdown());
        if ($md !== '') return $md;
    }

    return '';
}

function renderAdfNodeToMarkdown(array $node): string
{
    $type = $node['type'] ?? null;
    if (!is_string($type)) return '';

    switch ($type) {
        case 'doc':
            return renderAdfChildren($node, '');

        case 'paragraph':
            $txt = trim(renderAdfChildren($node, ''));
            return $txt === '' ? "\n" : $txt . "\n\n";

        case 'text':
            return (string)($node['text'] ?? '');

        case 'hardBreak':
            return "\n";

        case 'heading':
            $level = (int)($node['attrs']['level'] ?? 1);
            if ($level < 1) $level = 1;
            if ($level > 6) $level = 6;
            $txt = trim(renderAdfChildren($node, ''));
            return str_repeat('#', $level) . ' ' . $txt . "\n\n";

        case 'bulletList':
            return renderAdfChildren($node, '- ');

        case 'orderedList':
            // simpel: 1. voor elk item
            return renderAdfChildren($node, '1. ');

        case 'listItem':
            // listItem bevat meestal paragraphs
            $txt = trim(renderAdfChildren($node, ''));
            $txt = preg_replace("/\n{2,}/", "\n", $txt) ?? $txt;
            return $txt;

        case 'blockquote':
            $txt = trim(renderAdfChildren($node, ''));
            $lines = $txt === '' ? [] : preg_split("/\r?\n/", $txt);
            $lines = is_array($lines) ? $lines : [];
            $lines = array_map(fn($l) => '> ' . $l, $lines);
            return implode("\n", $lines) . "\n\n";

        case 'rule':
            return "---\n\n";

        case 'codeBlock':
            $txt = rtrim(renderAdfChildren($node, ''));
            return "```\n" . $txt . "\n```\n\n";

        case 'table':
            return renderAdfTableToMarkdown($node) . "\n\n";

        case 'tableRow':
        case 'tableCell':
        case 'tableHeader':
            // worden door table renderer afgehandeld
            return renderAdfChildren($node, '');

        default:
            // fallback: gewoon children renderen
            return renderAdfChildren($node, '');
    }
}

function renderAdfChildren(array $node, string $listPrefix): string
{
    $content = $node['content'] ?? null;
    if (!is_array($content)) return '';

    $out = '';
    foreach ($content as $child) {
        if (!is_array($child)) continue;

        $childType = $child['type'] ?? null;

        if ($listPrefix !== '' && $childType === 'listItem') {
            $item = trim(renderAdfNodeToMarkdown($child));
            if ($item === '') continue;

            // indent eventuele multiline items
            $lines = preg_split("/\r?\n/", $item);
            $lines = is_array($lines) ? $lines : [$item];
            $first = array_shift($lines);
            $out .= $listPrefix . $first . "\n";
            foreach ($lines as $l) {
                if (trim($l) === '') continue;
                $out .= '  ' . $l . "\n";
            }
            $out .= "\n";
            continue;
        }

        $out .= renderAdfNodeToMarkdown($child);
    }

    return $out;
}

function renderAdfTableToMarkdown(array $tableNode): string
{
    $rows = $tableNode['content'] ?? null;
    if (!is_array($rows) || $rows === []) return '';

    // Eerst grid bouwen met colspans
    $grid = [];
    $maxCols = 0;

    foreach ($rows as $row) {
        if (!is_array($row) || (($row['type'] ?? null) !== 'tableRow')) continue;
        $cells = $row['content'] ?? [];
        if (!is_array($cells)) $cells = [];

        $line = [];
        foreach ($cells as $cell) {
            if (!is_array($cell)) continue;
            $cellType = $cell['type'] ?? null;
            if ($cellType !== 'tableCell' && $cellType !== 'tableHeader') continue;

            $colspan = (int)($cell['attrs']['colspan'] ?? 1);
            if ($colspan < 1) $colspan = 1;

            $txt = trim(renderAdfChildren($cell, ''));
            $txt = preg_replace("/\s+/", ' ', $txt) ?? $txt;
            $txt = str_replace('|', '\|', $txt);

            // In markdown geen echte colspan. We dupen 1 inhoud + rest leeg.
            $line[] = $txt;
            for ($i = 1; $i < $colspan; $i++) {
                $line[] = '';
            }
        }

        $maxCols = max($maxCols, count($line));
        $grid[] = $line;
    }

    if ($grid === []) return '';

    // Normalize: elke rij evenveel cols
    foreach ($grid as $i => $line) {
        while (count($line) < $maxCols) $line[] = '';
        $grid[$i] = $line;
    }

    // Header: als eerste rij leeg is of geen header cellen waren, toch header gebruiken
    $header = $grid[0];
    $hasRealHeaderText = false;
    foreach ($header as $c) {
        if (trim($c) !== '') { $hasRealHeaderText = true; break; }
    }
    if (!$hasRealHeaderText) {
        // maak generieke header
        $header = array_fill(0, $maxCols, '');
    }

    $lines = [];
    $lines[] = '| ' . implode(' | ', $header) . ' |';
    $lines[] = '| ' . implode(' | ', array_fill(0, $maxCols, '---')) . ' |';

    for ($r = 1; $r < count($grid); $r++) {
        $lines[] = '| ' . implode(' | ', $grid[$r]) . ' |';
    }

    return implode("\n", $lines);
}

/**
 * Sanitize filename exactly like 09_migrate_attachments.php
 */
function sanitizeAttachmentFileName(string $filename): string
{
    $sanitized = preg_replace('/[^A-Za-z0-9._-]/u', '_', $filename);
    if ($sanitized === null) {
        return 'attachment';
    }

    $sanitized = trim($sanitized, '_');
    if ($sanitized === '') {
        return 'attachment';
    }

    return $sanitized;
}

/**
 * Build the unique upload filename used during upload: "{id}__{sanitizedFilename}"
 */
function buildUploadUniqueName(string $attachmentId, string $originalFilename): string
{
    $san = sanitizeAttachmentFileName($originalFilename !== '' ? $originalFilename : ('attachment-' . $attachmentId));
    return $attachmentId . '__' . $san;
}

/**
 * Replace a single URL that points to a Jira attachment with either 'attachment:uniqueName' or SharePoint URL.
 * Returns replacement string (not the whole markdown/img tag).
 *
 * @param string $url
 * @param array<string, array{unique: string, sharepoint: ?string}> $attachmentsMap keyed by attachment id
 * @return string replacement URL (or original $url if no match)
 */
function mapAttachmentUrlToTarget(string $url, array $attachmentsMap): string
{
    // try to find a numeric attachment id in a few common patterns
    $patterns = [
        '#/rest/api/\d+/attachment/content/(\d+)#i',
        '#/rest/api/\d+/attachment/thumbnail/(\d+)#i',
        '#/attachment/(\d+)#i',
        '#/attachment/content/(\d+)#i',
        '#attachment/content/(\d+)#i',
        '#/attachments/(\d+)#i',
        '#/secure/attachment/(\d+)#i',
        '#(\d+)(?:[^\d]|$)#' // fallback
    ];

    foreach ($patterns as $pat) {
        if (preg_match($pat, $url, $m)) {
            $id = $m[1] ?? null;
            if ($id === null) continue;
            $id = (string)$id;
            if (!isset($attachmentsMap[$id])) {
                return $url; // not one of our attachments
            }
            $meta = $attachmentsMap[$id];
            // prefer SharePoint absolute URLs
            if (!empty($meta['sharepoint'])) {
                return $meta['sharepoint'];
            }
            // return the unique filename (no "attachment:" prefix)
            return $meta['unique'];
        }
    }

    return $url;
}

/**
 * Normalize attachment links in a text blob (markdown or HTML).
 * - First does a DOM pass for <img> and <a> tags if HTML present.
 * - Then does a markdown-style pass replacing inline links/images.
 *
 * @param string $text
 * @param array<string, array{unique: string, sharepoint: ?string}> $attachmentsMap keyed by attachment id
 * @return string
 */
function normalizeAttachmentLinksInText(string $text, array $attachmentsMap): string
{
    if (trim($text) === '') return $text;

    // 1) If it contains HTML anchors/images we do a DOM pass
    if (stripos($text, '<img') !== false || stripos($text, '<a') !== false || stripos($text, '<div') !== false) {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $options = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) $options |= LIBXML_HTML_NOIMPLIED;
        if (defined('LIBXML_HTML_NODEFDTD')) $options |= LIBXML_HTML_NODEFDTD;
        $payload = '<?xml encoding="utf-8"?>' . $text;
        if (@$doc->loadHTML($payload, $options)) {
            // process <img>
            foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
                if (!$img instanceof DOMElement) continue;
                $src = (string)$img->getAttribute('src');
                if ($src === '') continue;
                $new = mapAttachmentUrlToTarget($src, $attachmentsMap);
                $img->setAttribute('src', $new);
                // verwijder title/alt/preview attributen zodat converter geen title maakt
                foreach (['title', 'alt', 'data-attachment-name', 'data-attachment-type'] as $attr) {
                    if ($img->hasAttribute($attr)) $img->removeAttribute($attr);
                }
            }

            // process <a>
            foreach (iterator_to_array($doc->getElementsByTagName('a')) as $a) {
                if (!$a instanceof DOMElement) continue;
                $href = (string)$a->getAttribute('href');
                if ($href === '') continue;
                $new = mapAttachmentUrlToTarget($href, $attachmentsMap);
                $a->setAttribute('href', $new);
                // verwijder title en preview attrs
                foreach (['title','file-preview-title','file-preview-id','file-preview-type','data-linked-resource-id'] as $attr) {
                    if ($a->hasAttribute($attr)) $a->removeAttribute($attr);
                }
            }

            $converted = $doc->saveHTML();
            if ($converted !== false) {
                // remove bogus xml prolog added earlier
                $converted = preg_replace('/^\s*<\?xml[^>]+\?>\s*/i', '', $converted);
                $text = $converted;
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
    }

    // 2) Markdown-style: replace ![alt](URL) and [label](URL)
    $text = preg_replace_callback('/(!?\[[^]]*])\(\s*([^)]+?)\s*\)/m', function ($m) use ($attachmentsMap) {
        $label = $m[1];
        $url = trim($m[2]);

        // keep absolute SharePoint URLs untouched
        if (preg_match('#^https?://#i', $url) && strpos($url, 'sharepoint.com') !== false) {
            return $m[0];
        }

        $new = mapAttachmentUrlToTarget($url, $attachmentsMap);
        return $label . '(' . $new . ')';
    }, $text);

    // 3) leftover plain URLs pointing to attachments (not inside markdown): replace them
    $text = preg_replace_callback('#https?://[^\s)\]}]+/rest/api/\d+/attachment/(?:content|thumbnail)/(\d+)[^\s)\]}]*#i', function ($m) use ($attachmentsMap) {
        $id = $m[1] ?? null;
        if ($id === null) return $m[0];
        if (!isset($attachmentsMap[$id])) return $m[0];
        $meta = $attachmentsMap[$id];
        return !empty($meta['sharepoint']) ? $meta['sharepoint'] : $meta['unique'];
    }, $text);

    // Replace relative REST API attachment URLs like "/rest/api/3/attachment/content/10001"
    $text = preg_replace_callback(
        '#(?:/rest/api/\d+/attachment/(?:content|thumbnail)/(\d+))[^\s)\]}]*#i',
        function ($m) use ($attachmentsMap) {
            $id = $m[1] ?? null;
            if ($id === null) return $m[0];
            if (!isset($attachmentsMap[$id])) return $m[0];
            $meta = $attachmentsMap[$id];
            return !empty($meta['sharepoint']) ? $meta['sharepoint'] : $meta['unique'];
        },
        $text
    );

    // Also replace patterns like "/attachment/1234" or "/secure/attachment/1234" if they appear plain
    $text = preg_replace_callback('#(?:/secure/attachment/|/attachment/|/attachment/content/)(\d+)#i', function ($m) use ($attachmentsMap) {
        $id = $m[1] ?? null;
        if ($id === null) return $m[0];
        if (!isset($attachmentsMap[$id])) return $m[0];
        $meta = $attachmentsMap[$id];
        return !empty($meta['sharepoint']) ? $meta['sharepoint'] : $meta['unique'];
    }, $text);

    return $text;
}

/**
 * Normalise attachment links in the proposed_description for a migration_mapping_issues row.
 * Deze functie leest attachments uit de DB, normaliseert de tekst en retourneert de nieuwe
 * proposed_description of NULL indien er geen wijziging nodig is of niet van toepassing.
 *
 * Let op: deze functie schrijft **niet** naar de database.
 *
 * @param PDO $pdo
 * @param int $mappingId
 * @param string|null $proposedDescription
 * @return string|null  Genormaliseerde description of null als geen wijziging
 */
function normalizeAttachmentsForMapping(PDO $pdo, int $mappingId, ?string $proposedDescription = null): ?string
{
    // Haal jira_issue_id (altijd nodig) en optioneel de huidige proposed_description
    $selectSql = 'SELECT jira_issue_id' . ($proposedDescription === null ? ', proposed_description' : '') . ' FROM migration_mapping_issues WHERE mapping_id = :mid';
    $stmt = $pdo->prepare($selectSql);
    if ($stmt === false) {
        throw new RuntimeException('Failed to prepare mapping fetch statement.');
    }
    $stmt->execute(['mid' => $mappingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    if (!is_array($row) || empty($row['jira_issue_id'])) {
        return null;
    }

    $jiraIssueId = (string)$row['jira_issue_id'];

    // use passed description if provided, otherwise use DB value
    if ($proposedDescription === null) {
        $proposedDescription = $row['proposed_description'] ?? null;
    }

    if ($proposedDescription === null || trim((string)$proposedDescription) === '') {
        return null;
    }

    // fetch attachments
    $sql = <<<SQL
SELECT map.jira_attachment_id AS aid, map.sharepoint_url AS sp, att.filename AS orig_name, map.redmine_upload_token
FROM migration_mapping_attachments map
JOIN staging_jira_attachments att ON att.id = map.jira_attachment_id
WHERE map.jira_issue_id = :issue_id
ORDER BY map.mapping_id
SQL;
    $stmt = $pdo->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Failed to prepare attachments fetch.');
    }
    $stmt->execute(['issue_id' => $jiraIssueId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stmt->closeCursor();

    if ($rows === []) {
        return null;
    }

    // build attachmentsMap (attachmentId => ['unique' => ..., 'sharepoint' => ...])
    $attachmentsMap = [];
    foreach ($rows as $r) {
        $aid = (string)($r['aid'] ?? '');
        if ($aid === '') continue;
        $orig = isset($r['orig_name']) ? (string)$r['orig_name'] : '';
        $unique = buildUploadUniqueName($aid, $orig);
        $sp = isset($r['sp']) ? trim((string)$r['sp']) : '';
        $attachmentsMap[$aid] = ['unique' => $unique, 'sharepoint' => $sp !== '' ? $sp : null];
    }

    // normalize tekst (DOM-pass + markdown replacements)
    $new = normalizeAttachmentLinksInText((string)$proposedDescription, $attachmentsMap);
    if ($new === $proposedDescription) {
        return null;
    }

    // GEEN DB UPDATE: alleen retour geven
    return $new;
}

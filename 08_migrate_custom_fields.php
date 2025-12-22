<?php
/** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_CUSTOM_FIELDS_SCRIPT_VERSION = '0.0.65';
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira custom fields into staging_jira_fields.',
    'usage' => 'Analyse Jira custom field usage statistics from staging data.',
    'redmine' => 'Refresh the Redmine custom field snapshot from the REST API.',
    'transform' => 'Reconcile Jira custom fields with Redmine custom fields to populate migration mappings.',
    'push' => 'Produce a manual action plan or call the extended API to create missing Redmine custom fields.',
];
const FIELD_USAGE_SCOPE_ISSUE = 'issue';

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

    /** @var array<string, mixed>|null $redmineConfig */
    $redmineConfig = null;
    $redmineUseExtendedApi = null;
    $redmineExtendedApiPrefix = null;

    if (in_array('jira', $phasesToRun, true)) {
        $jiraConfig = extractArrayConfig($config, 'jira');
        $jiraClient = createJiraClient($jiraConfig);

        printf("[%s] Starting Jira custom field extraction...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalJiraProcessed = fetchAndStoreJiraCustomFields($jiraClient, $pdo);

        printf(
            "[%s] Completed Jira extraction. %d custom field records processed.%s",
            formatCurrentTimestamp(),
            $totalJiraProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Jira custom field extraction (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('usage', $phasesToRun, true)) {
        printf("[%s] Starting Jira custom field usage analysis...%s", formatCurrentTimestamp(), PHP_EOL);

        $usageSummary = runCustomFieldUsagePhase($pdo);

        printf(
            "[%s] Completed usage analysis. Analysed: %d, With values: %d, With non-empty values: %d.%s",
            formatCurrentTimestamp(),
            $usageSummary['analysed_fields'],
            $usageSummary['fields_with_values'],
            $usageSummary['fields_with_non_empty_values'],
            PHP_EOL
        );

        if ($usageSummary['total_issues'] === 0) {
            printf("  No Jira issues are present in staging_jira_issues; all usage counts are zero.%s", PHP_EOL);
        } else {
            printf(
                "  Total staged issues: %d (latest analysis at %s).%s",
                $usageSummary['total_issues'],
                $usageSummary['last_counted_at'],
                PHP_EOL
            );
        }

        if ($usageSummary['top_fields'] !== []) {
            printf("  Top custom fields by non-empty issue count:%s", PHP_EOL);
            foreach ($usageSummary['top_fields'] as $topField) {
                printf(
                    "  - %-32s (%s): %d issue(s) ≈ %.2f%%%s",
                    $topField['field_name'],
                    $topField['field_id'],
                    $topField['issues_with_non_empty_value'],
                    $topField['non_empty_percentage'],
                    PHP_EOL
                );
            }
        }
    } else {
        printf(
            "[%s] Skipping Jira custom field usage analysis (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('redmine', $phasesToRun, true)) {
        $redmineConfig ??= extractArrayConfig($config, 'redmine');
        $redmineUseExtendedApi ??= shouldUseExtendedApi($redmineConfig, (bool)($cliOptions['use_extended_api'] ?? false));
        $redmineExtendedApiPrefix ??= $redmineUseExtendedApi ? resolveExtendedApiPrefix($redmineConfig) : null;
        $redmineClient = createRedmineClient($redmineConfig);

        printf("[%s] Starting Redmine custom field snapshot...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalRedmineProcessed = fetchAndStoreRedmineCustomFields(
            $redmineClient,
            $pdo,
            $redmineUseExtendedApi,
            $redmineExtendedApiPrefix
        );

        printf(
            "[%s] Completed Redmine snapshot. %d custom field records processed.%s",
            formatCurrentTimestamp(),
            $totalRedmineProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Redmine custom field snapshot (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Starting custom field reconciliation & transform phase...%s", formatCurrentTimestamp(), PHP_EOL);

        $transformSummary = runCustomFieldTransformationPhase($pdo);

        printf(
            "[%s] Completed transform phase. Matched: %d, Ready: %d, Manual: %d, Overrides kept: %d, Ignored (unused): %d, Skipped: %d, Unchanged: %d.%s",
            formatCurrentTimestamp(),
            $transformSummary['matched'],
            $transformSummary['ready_for_creation'],
            $transformSummary['manual_review'],
            $transformSummary['manual_overrides'],
            $transformSummary['ignored_unused'],
            $transformSummary['skipped'],
            $transformSummary['unchanged'],
            PHP_EOL
        );

        if ($transformSummary['status_counts'] !== []) {
            printf("  Current custom field mapping breakdown:%s", PHP_EOL);
            foreach ($transformSummary['status_counts'] as $status => $count) {
                printf("  - %-32s %d%s", $status, $count, PHP_EOL);
            }
        }

        if (isset($transformSummary['object_field_stats'])) {
            $objectStats = $transformSummary['object_field_stats'];
            printf(
                "  Object field mapping proposals: %d analysed, %d created, %d updated, %d unchanged, %d missing samples.%s",
                $objectStats['evaluated'],
                $objectStats['created'],
                $objectStats['updated'],
                $objectStats['unchanged'],
                $objectStats['missing_samples'],
                PHP_EOL
            );
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
        $redmineConfig ??= extractArrayConfig($config, 'redmine');
        $redmineUseExtendedApi ??= shouldUseExtendedApi($redmineConfig, (bool)($cliOptions['use_extended_api'] ?? false));

        runCustomFieldPushPhase($pdo, $confirmPush, $isDryRun, $redmineConfig, $redmineUseExtendedApi);
    } else {
        printf(
            "[%s] Skipping push phase (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    $planResult = collectCustomFieldUpdatePlan($pdo);
    foreach ($planResult['warnings'] as $warning) {
        printf("  [warning] %s%s", $warning, PHP_EOL);
    }

    renderCustomFieldUpdatePlan($planResult['plan'], false);

    if ($planResult['plan'] !== []) {
        printf(
            "  Apply the above project/tracker associations manually or rerun with --use-extended-api to push them automatically.%s",
            PHP_EOL
        );
    }
}
/**
 * @throws Throwable
 */
function fetchAndStoreJiraCustomFields(Client $client, PDO $pdo): int
{
    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_fields (id, name, is_custom, schema_type, schema_custom, field_category, raw_payload, extracted_at)
        VALUES (:id, :name, :is_custom, :schema_type, :schema_custom, :field_category, :raw_payload, :extracted_at)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            is_custom = VALUES(is_custom),
            schema_type = VALUES(schema_type),
            schema_custom = VALUES(schema_custom),
            field_category = VALUES(field_category),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_fields.');
    }

    try {
        $response = $client->get('/rest/api/3/field');
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $message = 'Failed to fetch custom fields from Jira';
        if ($response !== null) {
            $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
        }

        throw new RuntimeException($message, 0, $exception);
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Failed to fetch custom fields from Jira: ' . $exception->getMessage(), 0, $exception);
    }

    $decoded = decodeJsonResponse($response);
    if (!is_array($decoded)) {
        throw new RuntimeException('Unexpected Jira custom field payload format. Expected an array.');
    }

    $processed = 0;
    $now = formatCurrentTimestamp('Y-m-d H:i:s');
    $customFieldIds = [];

    foreach ($decoded as $field) {
        if (!is_array($field)) {
            continue;
        }

        $fieldId = isset($field['id']) ? trim((string)$field['id']) : '';
        if ($fieldId === '') {
            continue;
        }

        $name = isset($field['name']) ? trim((string)$field['name']) : $fieldId;
        $isCustom = normalizeBooleanDatabaseValue($field['custom'] ?? null) ?? 0;

        $schema = isset($field['schema']) && is_array($field['schema']) ? $field['schema'] : [];
        $schemaType = isset($schema['type']) ? trim((string)$schema['type']) : null;
        $schemaCustom = isset($schema['custom']) ? trim((string)$schema['custom']) : null;
        $fieldCategory = classifyJiraFieldCategory($fieldId, $schemaCustom, $isCustom === 1);

        try {
            $rawPayload = json_encode($field, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Failed to encode Jira custom field payload for %s: %s', $fieldId, $exception->getMessage()),
                0,
                $exception
            );
        }

        $insertStatement->execute([
            'id' => $fieldId,
            'name' => $name,
            'is_custom' => $isCustom,
            'schema_type' => $schemaType,
            'schema_custom' => $schemaCustom,
            'field_category' => $fieldCategory,
            'raw_payload' => $rawPayload,
            'extracted_at' => $now,
        ]);

        $processed++;

        if ($isCustom === 1) {
            $customFieldIds[] = $fieldId;
        }
    }

    printf("  Captured %d Jira field records.%s", $processed, PHP_EOL);

    refreshJiraProjectIssueTypeFields($client, $pdo);

    return $processed;
}

function refreshJiraProjectIssueTypeFields(Client $client, PDO $pdo): void
{
    printf("  Refreshing Jira field availability per project and issue type...%s", PHP_EOL);

    $projects = loadJiraProjectsForMetadata($pdo);
    if ($projects === []) {
        printf("  No Jira projects staged; skipping project/issue-type field refresh.%s", PHP_EOL);
        return;
    }

    try {
        $pdo->exec('TRUNCATE TABLE staging_jira_project_issue_type_fields');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to truncate staging_jira_project_issue_type_fields: ' . $exception->getMessage(), 0, $exception);
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_project_issue_type_fields (
            jira_project_id,
            jira_project_key,
            jira_project_name,
            jira_issue_type_id,
            jira_field_id,
            jira_field_name,
            is_custom,
            is_required,
            has_default_value,
            schema_type,
            schema_custom,
            field_category,
            allowed_values_json,
            raw_field,
            extracted_at
        ) VALUES (
            :jira_project_id,
            :jira_project_key,
            :jira_project_name,
            :jira_issue_type_id,
            :jira_field_id,
            :jira_field_name,
            :is_custom,
            :is_required,
            :has_default_value,
            :schema_type,
            :schema_custom,
            :field_category,
            :allowed_values_json,
            :raw_field,
            :extracted_at
        )
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_project_issue_type_fields.');
    }

    $extractedAt = formatCurrentTimestamp('Y-m-d H:i:s');
    $totalAssignments = 0;

    foreach ($projects as $project) {
        $projectId = $project['id'];
        $projectKey = $project['key'];
        $projectName = trim((string)($project['name'] ?? ''));
        if ($projectName === '') {
            $projectName = null;
        }

        $issueTypeFieldSets = fetchJiraProjectIssueTypeFields($client, $projectKey);
        if ($issueTypeFieldSets === []) {
            printf("  [warn] Jira project %s (%s) returned no issue types for create metadata.%s", $projectKey, $projectId, PHP_EOL);
            continue;
        }

        printf("  Project %s (%s): processing %d issue types.%s", $projectKey, $projectId, count($issueTypeFieldSets), PHP_EOL);

        foreach ($issueTypeFieldSets as $issueTypeData) {
            if (!isset($issueTypeData['issueTypeId'], $issueTypeData['fields']) || !is_array($issueTypeData['fields'])) {
                continue;
            }

            $issueTypeId = trim((string)$issueTypeData['issueTypeId']);
            if ($issueTypeId === '') {
                continue;
            }

            foreach ($issueTypeData['fields'] as $fieldKey => $fieldData) {
                if (!is_array($fieldData)) {
                    continue;
                }

                // Prefer the explicit field id from Jira
                $rawFieldId = $fieldData['fieldId']
                    ?? $fieldData['key']
                    ?? $fieldKey;

                $fieldId = trim((string)$rawFieldId);
                if ($fieldId === '') {
                    continue;
                }

                $fieldName = isset($fieldData['name'])
                    ? trim((string)$fieldData['name'])
                    : $fieldId;

                $schemaType   = $fieldData['schema']['type']   ?? null;
                $schemaCustom = $fieldData['schema']['custom'] ?? null;
                $schemaItems  = $fieldData['schema']['items']  ?? null;

                $isCustom = str_starts_with($fieldId, 'customfield_') ? 1 : 0;

                if ($isCustom === 0) {
                    $fieldCategory = 'system';
                } elseif (is_string($schemaCustom)
                    && str_starts_with($schemaCustom, 'com.atlassian.jira.plugin.system.customfieldtypes:')
                ) {
                    $fieldCategory = 'jira_custom';
                } else {
                    $fieldCategory = 'app_custom';
                }

                $isRequired = normalizeBooleanDatabaseValue($fieldData['required'] ?? null) ?? 0;
                $hasDefault = normalizeBooleanDatabaseValue($fieldData['hasDefaultValue'] ?? null);

                try {
                    $rawField = json_encode($fieldData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } catch (JsonException $exception) {
                    throw new RuntimeException(sprintf('Failed to encode Jira project field payload for %s: %s', $fieldId, $exception->getMessage()), 0, $exception);
                }

                $allowedValuesDescriptor = extractAllowedValuesDescriptorFromField(
                    is_array($fieldData) ? $fieldData : [],
                    is_string($schemaType) ? $schemaType : null,
                    is_string($schemaCustom) ? $schemaCustom : null
                );

                $allowedValuesJson = $allowedValuesDescriptor !== null
                    ? encodeJsonColumn($allowedValuesDescriptor)
                    : null;

                $insertStatement->execute([
                    'jira_project_id' => $projectId,
                    'jira_project_key' => $projectKey,
                    'jira_project_name' => $projectName,
                    'jira_issue_type_id' => $issueTypeId,
                    'jira_field_id' => $fieldId,
                    'jira_field_name' => $fieldName,
                    'is_custom' => $isCustom,
                    'is_required' => $isRequired,
                    'has_default_value' => $hasDefault,
                    'schema_type' => $schemaType,
                    'schema_custom' => $schemaCustom,
                    'field_category' => $fieldCategory,
                    'allowed_values_json' => $allowedValuesJson,
                    'raw_field' => $rawField,
                    'extracted_at' => $extractedAt,
                ]);

                $totalAssignments++;
            }
        }
    }

    $backfilledFromIssues = backfillProjectIssueTypeFieldsFromIssues($pdo, $extractedAt);

    printf(
        "  Captured %d project/issue-type field assignments (%d from staged issues).%s",
        $totalAssignments + $backfilledFromIssues,
        $backfilledFromIssues,
        PHP_EOL
    );
}

/**
 * @return array<int, array{id: string, key: string}>
 */
function loadJiraProjectsForMetadata(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT id, project_key, name
        FROM staging_jira_projects
        ORDER BY project_key
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to load Jira projects for metadata extraction: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $projects = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['id']) || !isset($row['project_key'])) {
            continue;
        }

        $projectId = trim((string)$row['id']);
        $projectKey = trim((string)$row['project_key']);
        $projectName = isset($row['name']) ? trim((string)$row['name']) : '';

        if ($projectId === '') {
            continue;
        }

        $projects[] = [
            'id' => $projectId,
            'key' => $projectKey,
            'name' => $projectName,
        ];
    }

    return $projects;
}

function fetchJiraProjectIssueTypeFields(Client $client, string $projectKeyOrId): array
{
    $issueTypesEndpoint = sprintf(
        '/rest/api/3/issue/createmeta/%s/issuetypes?expand=fields',
        rawurlencode($projectKeyOrId)
    );

    try {
        $issueTypesResponse = $client->get($issueTypesEndpoint);
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $status = $response !== null ? $response->getStatusCode() : null;
        if ($status === 404) {
            printf(
                "  [warn] Jira project %s is not accessible for create metadata (HTTP 404). Skipping.%s",
                $projectKeyOrId,
                PHP_EOL
            );
            return [];
        }

        $message = sprintf('Failed to fetch create metadata issue types for Jira project %s', $projectKeyOrId);
        if ($status !== null) {
            $message .= sprintf(' (HTTP %d)', $status);
        }

        throw new RuntimeException($message, 0, $exception);
    } catch (GuzzleException $exception) {
        throw new RuntimeException(sprintf('Failed to fetch create metadata issue types for Jira project %s: %s', $projectKeyOrId, $exception->getMessage()), 0, $exception);
    }

    $decodedIssueTypes = decodeJsonResponse($issueTypesResponse);
    if (!is_array($decodedIssueTypes)) {
        throw new RuntimeException(sprintf('Unexpected Jira create metadata payload for project %s.', $projectKeyOrId));
    }

    $issueTypes = isset($decodedIssueTypes['issueTypes']) && is_array($decodedIssueTypes['issueTypes'])
        ? $decodedIssueTypes['issueTypes']
        : [];

    if ($issueTypes === []) {
        printf("  [warn] Jira project %s returned no issue types from create metadata.%s", $projectKeyOrId, PHP_EOL);
        return [];
    }

    $issueTypeFieldSets = [];
    foreach ($issueTypes as $issueType) {
        if (!is_array($issueType) || !isset($issueType['id'])) {
            continue;
        }

        $issueTypeId = trim((string)$issueType['id']);
        if ($issueTypeId === '') {
            continue;
        }

        $fieldsEndpoint = sprintf(
            '/rest/api/3/issue/createmeta/%s/issuetypes/%s?expand=fields',
            rawurlencode($projectKeyOrId),
            rawurlencode($issueTypeId)
        );

        try {
            $fieldsResponse = $client->get($fieldsEndpoint);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $status = $response !== null ? $response->getStatusCode() : null;
            if ($status === 404) {
                printf(
                    "  [warn] Jira project %s issue type %s is not accessible for create metadata (HTTP 404). Skipping.%s",
                    $projectKeyOrId,
                    $issueTypeId,
                    PHP_EOL
                );
                continue;
            }

            $message = sprintf(
                'Failed to fetch create metadata fields for Jira project %s issue type %s',
                $projectKeyOrId,
                $issueTypeId
            );
            if ($status !== null) {
                $message .= sprintf(' (HTTP %d)', $status);
            }

            throw new RuntimeException($message, 0, $exception);
        } catch (GuzzleException $exception) {
            throw new RuntimeException(
                sprintf(
                    'Failed to fetch create metadata fields for Jira project %s issue type %s: %s',
                    $projectKeyOrId,
                    $issueTypeId,
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }

        $decodedFields = decodeJsonResponse($fieldsResponse);
        if (!is_array($decodedFields)) {
            printf(
                "  [warn] Unexpected Jira fields payload for project %s issue type %s.%s",
                $projectKeyOrId,
                $issueTypeId,
                PHP_EOL
            );
            continue;
        }

        $fields = isset($decodedFields['fields']) && is_array($decodedFields['fields']) ? $decodedFields['fields'] : [];
        if ($fields === []) {
            printf(
                "  [warn] Jira project %s issue type %s returned no fields in create metadata.%s",
                $projectKeyOrId,
                $issueTypeId,
                PHP_EOL
            );
            continue;
        }

        $issueTypeFieldSets[] = [
            'issueTypeId' => $issueTypeId,
            'fields' => $fields,
        ];
    }

    return $issueTypeFieldSets;
}

function classifyJiraFieldCategory(string $fieldId, ?string $schemaCustom, bool $isCustom): string
{
    if ($isCustom === false) {
        return 'system';
    }

    $schemaCustom = $schemaCustom !== null ? trim($schemaCustom) : '';
    $schemaCustomLower = strtolower($schemaCustom);

    if ($schemaCustomLower === '') {
        return 'jira_custom';
    }

    if (str_starts_with($schemaCustomLower, 'ari:')) {
        return 'app_custom';
    }

    if (str_starts_with($schemaCustomLower, 'com.atlassian.jira.plugin.system.customfieldtypes:')) {
        return 'jira_custom';
    }

    return 'app_custom';
}

function normalizeJiraAllowedValues(mixed $allowedValues): ?array
{
    if (!is_array($allowedValues) || $allowedValues === []) {
        return null;
    }

    $normalized = [];

    foreach ($allowedValues as $allowedValue) {
        if (is_array($allowedValue)) {
            $id = isset($allowedValue['id']) ? trim((string)$allowedValue['id']) : null;
            $id = $id === '' ? null : $id;

            $valueText = null;
            foreach (['value', 'name', 'label', 'title'] as $key) {
                if (isset($allowedValue[$key]) && trim((string)$allowedValue[$key]) !== '') {
                    $valueText = trim((string)$allowedValue[$key]);
                    break;
                }
            }

            if ($id === null && $valueText === null) {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'value' => $valueText,
            ];
            continue;
        }

        if (is_string($allowedValue) || is_numeric($allowedValue)) {
            $normalized[] = [
                'id' => null,
                'value' => trim((string)$allowedValue),
            ];
        }
    }

    return $normalized === [] ? null : $normalized;
}

function normalizeJiraOption(array $option): ?array
{
    $value = isset($option['value']) ? trim((string)$option['value']) : '';
    if ($value === '') {
        return null;
    }

    $normalized = [
        'id' => isset($option['id']) ? (string)$option['id'] : null,
        'value' => $value,
        'disabled' => normalizeBooleanFlag($option['disabled'] ?? null) ?? false,
        'children' => [],
    ];

    // Jira gebruikt in sommige contexten "cascadingOptions",
    // in andere contexten (zoals jouw raw_field) gewoon "children".
    $childContainers = [];

    if (isset($option['cascadingOptions']) && is_array($option['cascadingOptions'])) {
        $childContainers[] = $option['cascadingOptions'];
    }

    if (isset($option['children']) && is_array($option['children'])) {
        $childContainers[] = $option['children'];
    }

    foreach ($childContainers as $childrenList) {
        foreach ($childrenList as $child) {
            if (!is_array($child)) {
                continue;
            }

            $childValue = isset($child['value']) ? trim((string)$child['value']) : '';
            if ($childValue === '') {
                continue;
            }

            $normalized['children'][] = [
                'id' => isset($child['id']) ? (string)$child['id'] : null,
                'value' => $childValue,
                'disabled' => normalizeBooleanFlag($child['disabled'] ?? null) ?? false,
            ];
        }
    }

    if ($normalized['children'] !== []) {
        usort($normalized['children'], static function (array $left, array $right): int {
            return strcmp((string)$left['value'], (string)$right['value']);
        });
    }

    return $normalized;
}

function normalizeOptionsWithChildren(array $options, array $rawOptions): array
{
    $normalized = [];

    $hasFlattenedCascadingChildren = false;
    foreach ($rawOptions as $rawOption) {
        if (!is_array($rawOption)) {
            continue;
        }

        if (isset($rawOption['optionId']) && trim((string)$rawOption['optionId']) !== '') {
            $hasFlattenedCascadingChildren = true;
            break;
        }
    }

    if ($hasFlattenedCascadingChildren) {
        $parentsById = [];
        $childrenByParentId = [];

        foreach ($rawOptions as $rawOption) {
            if (!is_array($rawOption)) {
                continue;
            }

            $id = isset($rawOption['id']) ? trim((string)$rawOption['id']) : '';
            $value = isset($rawOption['value']) ? trim((string)$rawOption['value']) : '';
            if ($value === '') {
                continue;
            }

            $disabled = normalizeBooleanFlag($rawOption['disabled'] ?? null) ?? false;
            $parentId = isset($rawOption['optionId']) ? trim((string)$rawOption['optionId']) : '';

            if ($parentId === '') {
                if (!isset($parentsById[$id]) || ($parentsById[$id]['disabled'] ?? false) !== false) {
                    $parentsById[$id] = [
                        'id'       => $id !== '' ? $id : null,
                        'value'    => $value,
                        'disabled' => $disabled,
                        'children' => [],
                    ];
                }
            } else {
                $childrenByParentId[$parentId][] = [
                    'id'       => $id !== '' ? $id : null,
                    'value'    => $value,
                    'disabled' => $disabled,
                ];
            }
        }

        foreach ($childrenByParentId as $parentId => $children) {
            if (!isset($parentsById[$parentId])) {
                continue;
            }

            usort($children, static function (array $left, array $right): int {
                return strcmp((string)$left['value'], (string)$right['value']);
            });

            $parentsById[$parentId]['children'] = $children;
        }

        foreach ($parentsById as $parent) {
            $value = $parent['value'];
            if (!isset($normalized[$value]) || ($normalized[$value]['disabled'] ?? false) !== false) {
                $normalized[$value] = $parent;
            }
        }
    }

    if ($normalized === [] && $rawOptions !== []) {
        foreach ($rawOptions as $rawOption) {
            if (!is_array($rawOption)) {
                continue;
            }

            $normalizedOption = normalizeJiraOption($rawOption);
            if ($normalizedOption === null) {
                continue;
            }

            $normalized[$normalizedOption['value']] = $normalizedOption;
        }
    }

    if ($normalized === []) {
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $normalizedOption = normalizeJiraOption($option);
            if ($normalizedOption === null) {
                continue;
            }

            $normalized[$normalizedOption['value']] = $normalizedOption;
        }
    }

    $normalizedValues = array_values($normalized);
    usort($normalizedValues, static function (array $left, array $right): int {
        return strcmp((string)$left['value'], (string)$right['value']);
    });

    return $normalizedValues;
}

function normalizeAllowedValuesPayload($payload): array
{
    if (!is_array($payload)) {
        return [];
    }

    $transformLabel = static function (string $label): string {
        $decodedLabel = decodeAppCustomLabelString($label);
        if ($decodedLabel !== null) {
            return $decodedLabel;
        }

        return trim($label);
    };

    $mode = $payload['mode'] ?? null;
    if ($mode === 'flat') {
        $values = [];
        if (isset($payload['values']) && is_array($payload['values'])) {
            foreach ($payload['values'] as $value) {
                if (!is_array($value)) {
                    continue;
                }

                $label = isset($value['value']) ? $transformLabel((string)$value['value']) : '';
                if ($label === '') {
                    continue;
                }

                $values[$label] = [
                    'id' => isset($value['id']) && trim((string)$value['id']) !== '' ? trim((string)$value['id']) : null,
                    'value' => $label,
                ];
            }
        }

        ksort($values);

        return ['mode' => 'flat', 'values' => array_values($values)];
    }

    if ($mode === 'cascading') {
        $parents = [];
        $dependencies = [];

        if (isset($payload['parents']) && is_array($payload['parents'])) {
            foreach ($payload['parents'] as $parent) {
                if (!is_array($parent)) {
                    continue;
                }

                $value = isset($parent['value']) ? $transformLabel((string)$parent['value']) : '';
                if ($value === '') {
                    continue;
                }

                $parents[$value] = [
                    'id' => isset($parent['id']) && trim((string)$parent['id']) !== '' ? trim((string)$parent['id']) : null,
                    'value' => $value,
                ];
            }
        }

        if (isset($payload['dependencies']) && is_array($payload['dependencies'])) {
            foreach ($payload['dependencies'] as $parentValue => $children) {
                $parentKey = trim((string)$parentValue);
                if ($parentKey === '') {
                    continue;
                }

                $normalizedChildren = [];
                if (is_array($children)) {
                    foreach ($children as $child) {
                        if (!is_array($child)) {
                            continue;
                        }

                        $childValue = isset($child['value']) ? trim((string)$child['value']) : '';
                        if ($childValue === '') {
                            continue;
                        }

                        $normalizedChildren[$childValue] = [
                            'id' => isset($child['id']) && trim((string)$child['id']) !== '' ? trim((string)$child['id']) : null,
                            'value' => $childValue,
                        ];
                    }
                }

                ksort($normalizedChildren);
                $dependencies[$parentKey] = array_values($normalizedChildren);
            }
        }

        ksort($parents);
        ksort($dependencies);

        foreach (array_keys($parents) as $parentValue) {
            if (!isset($dependencies[$parentValue])) {
                $dependencies[$parentValue] = [];
            }
        }

        ksort($dependencies);

        return [
            'mode' => 'cascading',
            'parents' => array_values($parents),
            'dependencies' => $dependencies,
        ];
    }

    return [];
}

function mergeAllowedValuesPayloads(array $existing, array $incoming): array
{
    $base = normalizeAllowedValuesPayload($existing);
    $candidate = normalizeAllowedValuesPayload($incoming);

    if ($candidate === []) {
        return $base;
    }

    if ($base === []) {
        return $candidate;
    }

    $mode = $base['mode'] ?? null;
    if ($mode !== ($candidate['mode'] ?? null)) {
        return $base;
    }

    if ($mode === 'flat') {
        $values = [];

        foreach ($base['values'] as $value) {
            if (!is_array($value)) {
                continue;
            }

            $rawLabel = isset($value['value']) ? (string)$value['value'] : '';
            $label = decodeAppCustomLabelString($rawLabel) ?? trim($rawLabel);
            if ($label === '') {
                continue;
            }

            $values[$label] = [
                'id' => isset($value['id']) && trim((string)$value['id']) !== '' ? trim((string)$value['id']) : null,
                'value' => $label,
            ];
        }

        foreach ($candidate['values'] as $value) {
            if (!is_array($value)) {
                continue;
            }

            $rawLabel = isset($value['value']) ? (string)$value['value'] : '';
            $label = decodeAppCustomLabelString($rawLabel) ?? trim($rawLabel);
            if ($label === '') {
                continue;
            }

            if (!isset($values[$label]) || $values[$label]['id'] === null) {
                $values[$label] = [
                    'id' => isset($value['id']) && trim((string)$value['id']) !== '' ? trim((string)$value['id']) : null,
                    'value' => $label,
                ];
            }
        }

        ksort($values);

        return ['mode' => 'flat', 'values' => array_values($values)];
    }

    if ($mode === 'cascading') {
        $parents = [];
        if (isset($base['parents']) && is_array($base['parents'])) {
            foreach ($base['parents'] as $parent) {
                if (!is_array($parent)) {
                    continue;
                }

                $rawLabel = isset($parent['value']) ? (string)$parent['value'] : '';
                $label = decodeAppCustomLabelString($rawLabel) ?? trim($rawLabel);
                if ($label === '') {
                    continue;
                }

                $parents[$label] = [
                    'id' => isset($parent['id']) && trim((string)$parent['id']) !== '' ? trim((string)$parent['id']) : null,
                    'value' => $label,
                ];
            }
        }

        if (isset($candidate['parents']) && is_array($candidate['parents'])) {
            foreach ($candidate['parents'] as $parent) {
                if (!is_array($parent)) {
                    continue;
                }

                $rawLabel = isset($parent['value']) ? (string)$parent['value'] : '';
                $label = decodeAppCustomLabelString($rawLabel) ?? trim($rawLabel);
                if ($label === '') {
                    continue;
                }

                if (!isset($parents[$label]) || $parents[$label]['id'] === null) {
                    $parents[$label] = [
                        'id' => isset($parent['id']) && trim((string)$parent['id']) !== '' ? trim((string)$parent['id']) : null,
                        'value' => $label,
                    ];
                }
            }
        }

        $dependencies = [];
        if (isset($base['dependencies']) && is_array($base['dependencies'])) {
            foreach ($base['dependencies'] as $parentValue => $children) {
                $parentKey = trim((string)$parentValue);
                if ($parentKey === '') {
                    continue;
                }

                $normalizedChildren = [];
                if (is_array($children)) {
                    foreach ($children as $child) {
                        if (!is_array($child)) {
                            continue;
                        }

                        $childValue = isset($child['value']) ? trim((string)$child['value']) : '';
                        if ($childValue === '') {
                            continue;
                        }

                        $normalizedChildren[$childValue] = [
                            'id' => isset($child['id']) && trim((string)$child['id']) !== '' ? trim((string)$child['id']) : null,
                            'value' => $childValue,
                        ];
                    }
                }

                $dependencies[$parentKey] = $normalizedChildren;
            }
        }

        if (isset($candidate['dependencies']) && is_array($candidate['dependencies'])) {
            foreach ($candidate['dependencies'] as $parentValue => $children) {
                $parentKey = trim((string)$parentValue);
                if ($parentKey === '') {
                    continue;
                }

                if (!isset($dependencies[$parentKey])) {
                    $dependencies[$parentKey] = [];
                }

                if (is_array($children)) {
                    foreach ($children as $child) {
                        if (!is_array($child)) {
                            continue;
                        }

                        $childValue = isset($child['value']) ? trim((string)$child['value']) : '';
                        if ($childValue === '') {
                            continue;
                        }

                        if (!isset($dependencies[$parentKey][$childValue]) || $dependencies[$parentKey][$childValue]['id'] === null) {
                            $dependencies[$parentKey][$childValue] = [
                                'id' => isset($child['id']) && trim((string)$child['id']) !== '' ? trim((string)$child['id']) : null,
                                'value' => $childValue,
                            ];
                        }
                    }
                }
            }
        }

        ksort($parents);
        foreach ($dependencies as $parentValue => $children) {
            ksort($dependencies[$parentValue]);
            $dependencies[$parentValue] = array_values($dependencies[$parentValue]);
        }

        foreach (array_keys($parents) as $parentValue) {
            if (!isset($dependencies[$parentValue])) {
                $dependencies[$parentValue] = [];
            }
        }

        ksort($dependencies);

        return [
            'mode' => 'cascading',
            'parents' => array_values($parents),
            'dependencies' => $dependencies,
        ];
    }

    return $base;
}

function decodeAppCustomLabelString(string $value): ?string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    if ($trimmed[0] !== '{' && $trimmed[0] !== '[') {
        return null;
    }

    try {
        $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return null;
    }

    if (!is_array($decoded) || !isset($decoded['labels']) || !is_array($decoded['labels'])) {
        return null;
    }

    $labels = [];
    foreach ($decoded['labels'] as $label) {
        $normalized = trim((string)$label);
        if ($normalized === '') {
            continue;
        }

        $labels[$normalized] = $normalized;
    }

    if ($labels === []) {
        return null;
    }

    $normalizedLabels = array_values($labels);
    sort($normalizedLabels, SORT_NATURAL | SORT_FLAG_CASE);

    return implode(', ', $normalizedLabels);
}

/**
 * @param array<string, mixed> $allowedValues
 * @return array<int, string>
 */
function flattenAllowedValuesForConcatenation(array $allowedValues): array
{
    $normalized = normalizeAllowedValuesPayload($allowedValues);

    if ($normalized === []) {
        return [];
    }

    if (($normalized['mode'] ?? null) === 'flat') {
        $values = [];

        foreach ($normalized['values'] as $option) {
            if (!is_array($option)) {
                continue;
            }

            $label = isset($option['value']) ? trim((string)$option['value']) : '';
            if ($label === '') {
                continue;
            }

            $values[$label] = $label;
        }

        ksort($values);

        return array_values($values);
    }

    if (($normalized['mode'] ?? null) === 'cascading') {
        $values = [];

        if (isset($normalized['parents']) && is_array($normalized['parents'])) {
            foreach ($normalized['parents'] as $parent) {
                if (!is_array($parent)) {
                    continue;
                }

                $label = isset($parent['value']) ? trim((string)$parent['value']) : '';
                if ($label === '') {
                    continue;
                }

                $values[$label] = $label;

                if (!isset($normalized['dependencies']) || !is_array($normalized['dependencies'])) {
                    continue;
                }

                $children = $normalized['dependencies'][$label] ?? null;
                if (!is_array($children)) {
                    continue;
                }

                foreach ($children as $child) {
                    if (!is_array($child)) {
                        continue;
                    }

                    $childLabel = isset($child['value']) ? trim((string)$child['value']) : '';
                    if ($childLabel === '') {
                        continue;
                    }

                    $values[sprintf('%s → %s', $label, $childLabel)] = sprintf('%s → %s', $label, $childLabel);
                }
            }
        }

        ksort($values);

        return array_values($values);
    }

    return [];
}

/**
 * @return array<string, array{distinct_sets: int, concatenated_values: array<int, string>}>|array
 */
function summarizeAllowedValuesVariations(PDO $pdo): array
{
    $sql = 'SELECT jira_field_id, allowed_values_json FROM staging_jira_project_issue_type_fields WHERE allowed_values_json IS NOT NULL';

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to summarise allowed values per Jira field: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $summaries = [];

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['jira_field_id'])) {
            continue;
        }

        $fieldId = trim((string)$row['jira_field_id']);
        if ($fieldId === '') {
            continue;
        }

        $descriptor = normalizeAllowedValuesPayload(decodeJsonColumn($row['allowed_values_json'] ?? null));
        if ($descriptor === []) {
            continue;
        }

        try {
            $hash = json_encode($descriptor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode allowed values descriptor for variation analysis: ' . $exception->getMessage(), 0, $exception);
        }

        if ($hash === false || $hash === null) {
            continue;
        }

        if (!isset($summaries[$fieldId])) {
            $summaries[$fieldId] = [
                'hashes' => [],
                'concatenated_values' => [],
            ];
        }

        $summaries[$fieldId]['hashes'][$hash] = $hash;

        $flattened = flattenAllowedValuesForConcatenation($descriptor);
        if ($flattened !== []) {
            $summaries[$fieldId]['concatenated_values'] = array_values(array_unique(array_merge(
                $summaries[$fieldId]['concatenated_values'],
                $flattened
            )));
        }
    }

    $results = [];
    foreach ($summaries as $fieldId => $summary) {
        $values = $summary['concatenated_values'];
        sort($values);

        $results[$fieldId] = [
            'distinct_sets' => count($summary['hashes']),
            'concatenated_values' => $values,
        ];
    }

    return $results;
}

function extractAllowedValuesDescriptorFromField(array $fieldData, ?string $schemaType, ?string $schemaCustom): ?array
{
    $allowedValues = locateAllowedValuesArray($fieldData);
    if (!is_array($allowedValues) || $allowedValues === []) {
        return null;
    }

    if (isCascadingJiraField($schemaType, $schemaCustom)) {
        $normalizedOptions = normalizeOptionsWithChildren($allowedValues, $allowedValues);
        if ($normalizedOptions === []) {
            return null;
        }

        $parents = [];
        $dependencies = [];

        foreach ($normalizedOptions as $option) {
            if (!is_array($option)) {
                continue;
            }

            $parentValue = isset($option['value']) ? trim((string)$option['value']) : '';
            if ($parentValue === '') {
                continue;
            }

            if (!isset($parents[$parentValue])) {
                $parents[$parentValue] = [
                    'id' => isset($option['id']) && trim((string)$option['id']) !== '' ? trim((string)$option['id']) : null,
                    'value' => $parentValue,
                ];
            }

            if (!isset($dependencies[$parentValue])) {
                $dependencies[$parentValue] = [];
            }

            if (isset($option['children']) && is_array($option['children'])) {
                foreach ($option['children'] as $child) {
                    if (!is_array($child)) {
                        continue;
                    }

                    $childValue = isset($child['value']) ? trim((string)$child['value']) : '';
                    if ($childValue === '') {
                        continue;
                    }

                    if (!isset($dependencies[$parentValue][$childValue]) || $dependencies[$parentValue][$childValue]['id'] === null) {
                        $dependencies[$parentValue][$childValue] = [
                            'id' => isset($child['id']) && trim((string)$child['id']) !== '' ? trim((string)$child['id']) : null,
                            'value' => $childValue,
                        ];
                    }
                }
            }
        }

        if ($parents === []) {
            return null;
        }

        ksort($parents);
        foreach ($dependencies as $parentValue => $children) {
            ksort($dependencies[$parentValue]);
            $dependencies[$parentValue] = array_values($dependencies[$parentValue]);
        }

        foreach (array_keys($parents) as $parentValue) {
            if (!isset($dependencies[$parentValue])) {
                $dependencies[$parentValue] = [];
            }
        }

        ksort($dependencies);

        return [
            'mode' => 'cascading',
            'parents' => array_values($parents),
            'dependencies' => $dependencies,
        ];
    }

    $normalizedAllowedValues = normalizeJiraAllowedValues($allowedValues);
    if ($normalizedAllowedValues === null) {
        return null;
    }

    return [
        'mode' => 'flat',
        'values' => $normalizedAllowedValues,
    ];
}

function locateAllowedValuesArray(array $fieldData): ?array
{
    if (isset($fieldData['allowedValues']) && is_array($fieldData['allowedValues']) && $fieldData['allowedValues'] !== []) {
        return $fieldData['allowedValues'];
    }

    if (isset($fieldData['configuration']) && is_array($fieldData['configuration'])) {
        foreach (['allowedValues', 'options', 'values'] as $key) {
            if (isset($fieldData['configuration'][$key])
                && is_array($fieldData['configuration'][$key])
                && $fieldData['configuration'][$key] !== []
            ) {
                return $fieldData['configuration'][$key];
            }
        }
    }

    return null;
}

function isCascadingJiraField(?string $schemaType, ?string $schemaCustom): bool
{
    $schemaType = $schemaType !== null ? strtolower($schemaType) : null;
    $schemaCustom = $schemaCustom !== null ? strtolower($schemaCustom) : null;

    if ($schemaType === 'option-with-child') {
        return true;
    }

    if ($schemaCustom !== null && str_contains($schemaCustom, ':cascadingselect')) {
        return true;
    }

    return false;
}

/**
 * @return array<string, array{id: string, name: string|null, schema_type: ?string, schema_custom: ?string, field_category: ?string}>
 */
function fetchEligibleJiraFields(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT id, name, schema_type, schema_custom, field_category
        FROM staging_jira_fields
        WHERE is_custom = 1
          AND field_category IN ('jira_custom', 'app_custom')
        ORDER BY id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch Jira custom field metadata: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $fields = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['id'])) {
            continue;
        }

        $id = trim((string)$row['id']);
        if ($id === '') {
            continue;
        }

        $fields[$id] = [
            'id' => $id,
            'name' => isset($row['name']) ? normalizeString((string)$row['name'], 255) : null,
            'schema_type' => isset($row['schema_type']) ? (string)$row['schema_type'] : null,
            'schema_custom' => isset($row['schema_custom']) ? (string)$row['schema_custom'] : null,
            'field_category' => isset($row['field_category']) ? (string)$row['field_category'] : null,
        ];
    }

    return $fields;
}

/**
 * @return array<string, array<int, array{project_id: string, issue_type_id: string, is_required: bool, allowed_values: array}>>
 */
function loadJiraProjectIssueTypeFieldDetails(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            jira_project_id,
            jira_issue_type_id,
            jira_field_id,
            is_required,
            allowed_values_json,
            schema_type,
            schema_custom,
            raw_field
        FROM staging_jira_project_issue_type_fields
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to load Jira project/issue-type field details: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $map = [];
    $backfillStatement = null;
    $backfilledAssignments = 0;

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['jira_field_id']) || !isset($row['jira_project_id']) || !isset($row['jira_issue_type_id'])) {
            continue;
        }

        $fieldId = trim((string)$row['jira_field_id']);
        $projectId = trim((string)$row['jira_project_id']);
        $issueTypeId = trim((string)$row['jira_issue_type_id']);

        if ($fieldId === '' || $projectId === '' || $issueTypeId === '') {
            continue;
        }

        $allowedValues = normalizeAllowedValuesPayload(decodeJsonColumn($row['allowed_values_json'] ?? null));
        if ($allowedValues === []) {
            $rawFieldPayload = decodeJsonColumn($row['raw_field'] ?? null);
            if (is_array($rawFieldPayload)) {
                $derivedDescriptor = extractAllowedValuesDescriptorFromField(
                    $rawFieldPayload,
                    isset($row['schema_type']) ? (string)$row['schema_type'] : null,
                    isset($row['schema_custom']) ? (string)$row['schema_custom'] : null
                );

                if ($derivedDescriptor !== null) {
                    $normalizedDescriptor = normalizeAllowedValuesPayload($derivedDescriptor);
                    if ($normalizedDescriptor !== []) {
                        $allowedValues = $normalizedDescriptor;

                        if ($backfillStatement === null) {
                            $backfillStatement = $pdo->prepare(<<<SQL
                                UPDATE staging_jira_project_issue_type_fields
                                SET allowed_values_json = :allowed_values_json
                                WHERE jira_project_id = :jira_project_id
                                  AND jira_issue_type_id = :jira_issue_type_id
                                  AND jira_field_id = :jira_field_id
                            SQL);

                            if ($backfillStatement === false) {
                                throw new RuntimeException('Failed to prepare allowed values backfill statement.');
                            }
                        }

                        $backfillStatement->execute([
                            'allowed_values_json' => encodeJsonColumn($normalizedDescriptor),
                            'jira_project_id' => $projectId,
                            'jira_issue_type_id' => $issueTypeId,
                            'jira_field_id' => $fieldId,
                        ]);

                        $backfilledAssignments++;
                    }
                }
            }
        }

        $map[$fieldId][] = [
            'project_id' => $projectId,
            'issue_type_id' => $issueTypeId,
            'allowed_values' => $allowedValues,
            'is_required' => normalizeBooleanFlag($row['is_required'] ?? null) ?? false,
        ];
    }

    if ($backfilledAssignments > 0) {
        printf(
            "  Backfilled allowed values for %d Jira project/issue-type field assignment(s).%s",
            $backfilledAssignments,
            PHP_EOL
        );
    }

    return $map;
}

function backfillProjectIssueTypeFieldsFromIssues(PDO $pdo, string $extractedAt): int
{
    $existingAssignments = [];
    $existingAllowedValues = [];

    try {
        $existingStatement = $pdo->query(<<<SQL
            SELECT
                jira_project_id,
                jira_issue_type_id,
                jira_field_id,
                allowed_values_json,
                raw_field
            FROM staging_jira_project_issue_type_fields
        SQL);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to read existing project/issue-type field assignments: ' . $exception->getMessage(), 0, $exception);
    }

    if ($existingStatement === false) {
        throw new RuntimeException('Failed to iterate existing project/issue-type field assignments.');
    }

    while (($row = $existingStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $projectId = isset($row['jira_project_id']) ? trim((string)$row['jira_project_id']) : '';
        $issueTypeId = isset($row['jira_issue_type_id']) ? trim((string)$row['jira_issue_type_id']) : '';
        $fieldId = isset($row['jira_field_id']) ? trim((string)$row['jira_field_id']) : '';

        if ($projectId === '' || $issueTypeId === '' || $fieldId === '') {
            continue;
        }

        $key = sprintf('%s|%s|%s', $projectId, $issueTypeId, $fieldId);
        $existingAssignments[$key] = true;
        $existingAllowedValues[$key] = normalizeAllowedValuesPayload(decodeJsonColumn($row['allowed_values_json'] ?? null));
    }

    $fieldsById = loadJiraFieldMetadata($pdo);
    if ($fieldsById === []) {
        return 0;
    }

    try {
        $usageStatement = $pdo->query(<<<SQL
            SELECT
                f.id AS field_id,
                f.name AS field_name,
                f.schema_type,
                f.schema_custom,
                f.field_category,
                i.project_id,
                i.issuetype_id,
                COUNT(*) AS issue_count,
                p.project_key,
                p.name AS project_name
            FROM staging_jira_fields f
            JOIN staging_jira_issues i
              ON JSON_EXTRACT(i.raw_payload, CONCAT('$.fields.', f.id)) IS NOT NULL
              AND JSON_EXTRACT(i.raw_payload, CONCAT('$.fields.', f.id)) != JSON_EXTRACT(JSON_OBJECT('x', NULL), '$.x')
              AND JSON_UNQUOTE(JSON_EXTRACT(i.raw_payload, CONCAT('$.fields.', f.id))) <> ''
            LEFT JOIN staging_jira_projects p ON p.id = i.project_id
            WHERE f.is_custom = 1
            GROUP BY
                f.id,
                f.name,
                f.schema_type,
                f.schema_custom,
                f.field_category,
                i.project_id,
                i.issuetype_id,
                p.project_key,
                p.name
            ORDER BY
                f.id,
                i.project_id,
                i.issuetype_id
        SQL);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to aggregate staged issue field usage for backfill: ' . $exception->getMessage(), 0, $exception);
    }

    if ($usageStatement === false) {
        throw new RuntimeException('Failed to iterate staged issue field usage for backfill.');
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_project_issue_type_fields (
            jira_project_id,
            jira_project_key,
            jira_project_name,
            jira_issue_type_id,
            jira_field_id,
            jira_field_name,
            is_custom,
            is_required,
            has_default_value,
            schema_type,
            schema_custom,
            field_category,
            allowed_values_json,
            raw_field,
            extracted_at
        ) VALUES (
            :jira_project_id,
            :jira_project_key,
            :jira_project_name,
            :jira_issue_type_id,
            :jira_field_id,
            :jira_field_name,
            1,
            0,
            NULL,
            :schema_type,
            :schema_custom,
            :field_category,
            :allowed_values_json,
            :raw_field,
            :extracted_at
        )
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare staged issue backfill insert statement.');
    }

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE staging_jira_project_issue_type_fields
        SET allowed_values_json = :allowed_values_json,
            raw_field = :raw_field
        WHERE jira_project_id = :jira_project_id
          AND jira_issue_type_id = :jira_issue_type_id
          AND jira_field_id = :jira_field_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare staged issue backfill update statement.');
    }

    $inserted = 0;
    $updated = 0;

    while (($row = $usageStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['field_id'], $row['project_id'], $row['issuetype_id'])) {
            continue;
        }

        $fieldId = trim((string)$row['field_id']);
        $projectId = trim((string)$row['project_id']);
        $issueTypeId = trim((string)$row['issuetype_id']);

        if ($fieldId === '' || $projectId === '' || $issueTypeId === '') {
            continue;
        }

        $metadata = $fieldsById[$fieldId] ?? null;
        if ($metadata === null) {
            continue;
        }

        $allowedDescriptor = deriveAllowedValuesDescriptorFromIssues($pdo, $fieldId, $projectId, $issueTypeId);
        $normalizedAllowedValues = normalizeAllowedValuesPayload($allowedDescriptor);

        $rawFieldPayload = [
            'source' => 'staging_jira_issues',
            'issue_count' => (int)($row['issue_count'] ?? 0),
            'schema_type' => $metadata['schema_type'],
            'schema_custom' => $metadata['schema_custom'],
        ];

        $key = sprintf('%s|%s|%s', $projectId, $issueTypeId, $fieldId);
        if (!isset($existingAssignments[$key])) {
            $insertStatement->execute([
                'jira_project_id' => $projectId,
                'jira_project_key' => isset($row['project_key']) ? (string)$row['project_key'] : null,
                'jira_project_name' => isset($row['project_name']) ? (string)$row['project_name'] : null,
                'jira_issue_type_id' => $issueTypeId,
                'jira_field_id' => $fieldId,
                'jira_field_name' => isset($row['field_name']) ? (string)$row['field_name'] : ($metadata['name'] ?? $fieldId),
                'schema_type' => $metadata['schema_type'],
                'schema_custom' => $metadata['schema_custom'],
                'field_category' => $metadata['field_category'],
                'allowed_values_json' => $normalizedAllowedValues !== [] ? encodeJsonColumn($normalizedAllowedValues) : null,
                'raw_field' => encodeJsonColumn($rawFieldPayload),
                'extracted_at' => $extractedAt,
            ]);

            $inserted++;
            continue;
        }

        $existingValues = $existingAllowedValues[$key] ?? [];
        if ($existingValues === [] && $normalizedAllowedValues !== []) {
            $updateStatement->execute([
                'allowed_values_json' => encodeJsonColumn($normalizedAllowedValues),
                'raw_field' => encodeJsonColumn($rawFieldPayload),
                'jira_project_id' => $projectId,
                'jira_issue_type_id' => $issueTypeId,
                'jira_field_id' => $fieldId,
            ]);

            $updated++;
        }
    }

    return $inserted + $updated;
}

function loadJiraFieldMetadata(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT id, name, schema_type, schema_custom, field_category FROM staging_jira_fields WHERE is_custom = 1');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to load Jira custom field metadata: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $fields = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['id'])) {
            continue;
        }

        $fieldId = trim((string)$row['id']);
        if ($fieldId === '') {
            continue;
        }

        $fields[$fieldId] = [
            'id' => $fieldId,
            'name' => isset($row['name']) ? (string)$row['name'] : null,
            'schema_type' => isset($row['schema_type']) ? (string)$row['schema_type'] : null,
            'schema_custom' => isset($row['schema_custom']) ? (string)$row['schema_custom'] : null,
            'field_category' => isset($row['field_category']) ? (string)$row['field_category'] : null,
        ];
    }

    return $fields;
}

/**
 * @return array<string, array{total_assignments: int, required_assignments: int, default_values: array<int, string>}>
 */
function summarizeJiraFieldAssignments(PDO $pdo): array
{
    $sql = 'SELECT jira_field_id, is_required, raw_field FROM staging_jira_project_issue_type_fields';

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to summarise Jira field assignments: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $summaries = [];

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['jira_field_id'])) {
            continue;
        }

        $fieldId = trim((string)$row['jira_field_id']);
        if ($fieldId === '') {
            continue;
        }

        if (!isset($summaries[$fieldId])) {
            $summaries[$fieldId] = [
                'total_assignments' => 0,
                'required_assignments' => 0,
                'default_values' => [],
            ];
        }

        $summaries[$fieldId]['total_assignments']++;

        $isRequired = normalizeBooleanFlag($row['is_required'] ?? null);
        if ($isRequired === true) {
            $summaries[$fieldId]['required_assignments']++;
        }

        $rawFieldPayload = decodeJsonColumn($row['raw_field'] ?? null);
        if (is_array($rawFieldPayload)) {
            $defaultValue = extractDefaultValueFromJiraFieldPayload($rawFieldPayload);
            if ($defaultValue !== null && trim($defaultValue) !== '') {
                $summaries[$fieldId]['default_values'][$defaultValue] = $defaultValue;
            }
        }
    }

    return $summaries;
}

/**
 * @return array<string, array{searchable: ?bool, navigable: ?bool}>
 */
function loadJiraFieldSearchability(PDO $pdo): array
{
    $sql = 'SELECT id, raw_payload FROM staging_jira_fields WHERE is_custom = 1';

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to load Jira custom field searchability details: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $results = [];

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['id'])) {
            continue;
        }

        $fieldId = trim((string)$row['id']);
        if ($fieldId === '') {
            continue;
        }

        $payload = decodeJsonColumn($row['raw_payload'] ?? null);
        $results[$fieldId] = [
            'searchable' => is_array($payload) ? normalizeBooleanFlag($payload['searchable'] ?? null) : null,
            'navigable' => is_array($payload) ? normalizeBooleanFlag($payload['navigable'] ?? null) : null,
        ];
    }

    return $results;
}

/**
 * @param array<string, mixed> $rawFieldPayload
 */
function extractDefaultValueFromJiraFieldPayload(array $rawFieldPayload): ?string
{
    $hasDefaultFlag = normalizeBooleanFlag($rawFieldPayload['hasDefaultValue'] ?? null);
    if ($hasDefaultFlag === false) {
        return null;
    }

    $defaultValue = $rawFieldPayload['defaultValue'] ?? ($rawFieldPayload['default_value'] ?? null);
    if ($defaultValue === null) {
        return null;
    }

    if (is_array($defaultValue)) {
        foreach (['value', 'name', 'id'] as $key) {
            if (isset($defaultValue[$key])) {
                $candidate = trim((string)$defaultValue[$key]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        try {
            $encoded = json_encode($defaultValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            return null;
        }

        return $encoded !== false ? $encoded : null;
    }

    if (is_scalar($defaultValue)) {
        $stringValue = trim((string)$defaultValue);
        return $stringValue === '' ? null : $stringValue;
    }

    return null;
}

function deriveAllowedValuesDescriptorFromIssues(PDO $pdo, string $fieldId, string $projectId, string $issueTypeId): array
{
    static $statement = null;

    if ($statement === null) {
        $statement = $pdo->prepare(<<<SQL
            SELECT
                JSON_EXTRACT(raw_payload, CONCAT('$.fields.', :field_id)) AS value,
                JSON_UNQUOTE(JSON_EXTRACT(raw_payload, CONCAT('$.fields.', :field_id))) AS scalar_value,
                JSON_TYPE(JSON_EXTRACT(raw_payload, CONCAT('$.fields.', :field_id))) AS value_type
            FROM staging_jira_issues
            WHERE JSON_VALID(raw_payload)
              AND project_id = :project_id
              AND issuetype_id = :issuetype_id
              AND JSON_EXTRACT(raw_payload, CONCAT('$.fields.', :field_id)) IS NOT NULL
              AND JSON_EXTRACT(raw_payload, CONCAT('$.fields.', :field_id)) != JSON_EXTRACT(JSON_OBJECT('x', NULL), '$.x')
        SQL);

        if ($statement === false) {
            throw new RuntimeException('Failed to prepare staged issue field usage statement for deriveAllowedValuesDescriptorFromIssues().');
        }
    }

    $statement->execute([
        'field_id' => $fieldId,
        'project_id' => $projectId,
        'issuetype_id' => $issueTypeId,
    ]);

    $values = [];

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $valueType = isset($row['value_type']) ? strtoupper((string)$row['value_type']) : '';

        if ($valueType === '' || $valueType === 'NULL' || $valueType === 'MISSING') {
            continue;
        }

        // Eenvoudige scalar-waarden (string/nummer/bool) rechtstreeks gebruiken
        if (in_array($valueType, ['STRING', 'NUMBER', 'BOOLEAN'], true)) {
            $textValue = trim((string)($row['scalar_value'] ?? ''));
            if ($textValue !== '') {
                $values[$textValue] = [
                    'id' => null,
                    'value' => $textValue,
                ];
            }
            continue;
        }

        // Voor OBJECT / ARRAY: eerst het JSON-value parsen
        $decoded = null;
        $rawJson = $row['value'] ?? null;
        if (is_string($rawJson)) {
            try {
                $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $decoded = null;
            }
        }

        if ($decoded === null) {
            continue;
        }

        // ARRAY: bv. multi-select, of plugin die een array van objecten teruggeeft
        if ($valueType === 'ARRAY' && is_array($decoded)) {
            foreach ($decoded as $item) {
                // Speciale case: label-manager-achtige objecten met "labels" array
                if (is_array($item) && array_key_exists('labels', $item) && is_array($item['labels'])) {
                    $seen = [];

                    foreach ($item['labels'] as $label) {
                        $labelText = trim((string)$label);
                        if ($labelText === '') {
                            continue;
                        }

                        // binnen één object dubbele labels negeren
                        if (isset($seen[$labelText])) {
                            continue;
                        }
                        $seen[$labelText] = true;

                        if (!isset($values[$labelText])) {
                            $values[$labelText] = [
                                'id' => null,
                                'value' => $labelText,
                            ];
                        }
                    }

                    // Dit object niet meer door de generieke normalizer sturen
                    continue;
                }

                // Generieke fallback voor "normale" array-items
                $normalized = normalizeIssueFieldValueItem($item);
                if ($normalized === null) {
                    continue;
                }

                $values[$normalized['value']] = $normalized;
            }

            continue;
        }

        // OBJECT: bv. single object-waarde (ook hier special-case voor "labels")
        if ($valueType === 'OBJECT' && is_array($decoded)) {
            // Speciale case: één object met "labels" array
            if (array_key_exists('labels', $decoded) && is_array($decoded['labels'])) {
                $seen = [];

                foreach ($decoded['labels'] as $label) {
                    $labelText = trim((string)$label);
                    if ($labelText === '') {
                        continue;
                    }

                    if (isset($seen[$labelText])) {
                        continue;
                    }
                    $seen[$labelText] = true;

                    if (!isset($values[$labelText])) {
                        $values[$labelText] = [
                            'id' => null,
                            'value' => $labelText,
                        ];
                    }
                }

                continue;
            }

            // Generieke fallback voor "normale" objecten
            $normalized = normalizeIssueFieldValueItem($decoded);
            if ($normalized !== null) {
                $values[$normalized['value']] = $normalized;
            }

            continue;
        }
    }

    $statement->closeCursor();

    if ($values === []) {
        return [];
    }

    ksort($values);

    return [
        'mode' => 'flat',
        'values' => array_values($values),
    ];
}


function normalizeIssueFieldValueItem(mixed $item): ?array
{
    if (is_array($item)) {
        $valueText = null;
        foreach (['value', 'name', 'label', 'title', 'key'] as $key) {
            if (isset($item[$key]) && trim((string)$item[$key]) !== '') {
                $valueText = trim((string)$item[$key]);
                break;
            }
        }

        $id = isset($item['id']) && trim((string)$item['id']) !== '' ? trim((string)$item['id']) : null;

        if ($valueText === null) {
            $valueText = trim(encodeJsonColumn($item) ?? '');
        }

        if ($valueText === '') {
            return null;
        }

        return [
            'id' => $id,
            'value' => $valueText,
        ];
    }

    $valueText = trim((string)$item);
    if ($valueText === '') {
        return null;
    }

    return [
        'id' => null,
        'value' => $valueText,
    ];
}

/**
 * @return array<string, int>
 */
function buildJiraToRedmineProjectLookup(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT jira_project_id, redmine_project_id
        FROM migration_mapping_projects
        WHERE redmine_project_id IS NOT NULL
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build Jira-to-Redmine project lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $lookup = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['jira_project_id']) || !isset($row['redmine_project_id'])) {
            continue;
        }

        $jiraProjectId = trim((string)$row['jira_project_id']);
        if ($jiraProjectId === '') {
            continue;
        }

        $lookup[$jiraProjectId] = (int)$row['redmine_project_id'];
    }

    return $lookup;
}

/**
 * @return array<string, int>
 */
function buildJiraToRedmineTrackerLookup(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT jira_issue_type_id, redmine_tracker_id
        FROM migration_mapping_trackers
        WHERE redmine_tracker_id IS NOT NULL
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build Jira-to-Redmine tracker lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $lookup = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['jira_issue_type_id']) || !isset($row['redmine_tracker_id'])) {
            continue;
        }

        $jiraIssueTypeId = trim((string)$row['jira_issue_type_id']);
        if ($jiraIssueTypeId === '') {
            continue;
        }

        $lookup[$jiraIssueTypeId] = (int)$row['redmine_tracker_id'];
    }

    return $lookup;
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

function purgeOrphanedCustomFieldMappings(PDO $pdo): void
{
    $sql = <<<SQL
        DELETE map
        FROM migration_mapping_custom_fields map
        LEFT JOIN staging_jira_fields jf ON jf.id = map.jira_field_id AND jf.is_custom = 1
        WHERE jf.id IS NULL
    SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to purge orphaned custom field mappings: ' . $exception->getMessage(), 0, $exception);
    }
}

/**
 * @return array{
 *     analysed_fields: int,
 *     fields_with_values: int,
 *     fields_with_non_empty_values: int,
 *     total_issues: int,
 *     last_counted_at: string,
 *     top_fields: array<int, array{field_id: string, field_name: string, issues_with_non_empty_value: int, non_empty_percentage: float}>
 * }
 */
function runCustomFieldUsagePhase(PDO $pdo): array
{
    $analysisTimestamp = formatCurrentTimestamp('Y-m-d H:i:s');

    try {
        $issuesCountStatement = $pdo->query('SELECT COUNT(*) FROM staging_jira_issues');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to count Jira issues for usage analysis: ' . $exception->getMessage(), 0, $exception);
    }

    if ($issuesCountStatement === false) {
        throw new RuntimeException('Failed to query staging_jira_issues for usage analysis.');
    }

    $totalIssues = (int)($issuesCountStatement->fetchColumn() ?? 0);
    $issuesCountStatement->closeCursor();

    try {
        $fieldsStatement = $pdo->query('SELECT id, name FROM staging_jira_fields WHERE is_custom = 1 ORDER BY id');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to query Jira custom fields for usage analysis: ' . $exception->getMessage(), 0, $exception);
    }

    if ($fieldsStatement === false) {
        throw new RuntimeException('Failed to iterate Jira custom fields for usage analysis.');
    }

    $usageStatement = $pdo->prepare(<<<SQL
        SELECT
            SUM(CASE WHEN value_type IS NULL OR value_type IN ('NULL', 'MISSING') THEN 0 ELSE 1 END) AS issues_with_value,
            SUM(CASE
                WHEN value_type IS NULL OR value_type IN ('NULL', 'MISSING') THEN 0
                WHEN value_type = 'STRING' THEN CASE WHEN TRIM(JSON_UNQUOTE(value)) = '' THEN 0 ELSE 1 END
                WHEN value_type IN ('ARRAY', 'OBJECT') THEN CASE WHEN JSON_LENGTH(value) = 0 THEN 0 ELSE 1 END
                ELSE 1
            END) AS issues_with_non_empty_value
        FROM (
            SELECT
                JSON_EXTRACT(raw_payload, CONCAT('$.fields.', :field_id)) AS value,
                JSON_TYPE(JSON_EXTRACT(raw_payload, CONCAT('$.fields.', :field_id))) AS value_type
            FROM staging_jira_issues
            WHERE JSON_VALID(raw_payload)
        ) AS extracted
    SQL);

    if ($usageStatement === false) {
        throw new RuntimeException('Failed to prepare Jira custom field usage aggregation statement.');
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_field_usage (
            field_id,
            usage_scope,
            total_issues,
            issues_with_value,
            issues_with_non_empty_value,
            last_counted_at
        ) VALUES (
            :field_id,
            :usage_scope,
            :total_issues,
            :issues_with_value,
            :issues_with_non_empty_value,
            :last_counted_at
        )
        ON DUPLICATE KEY UPDATE
            total_issues = VALUES(total_issues),
            issues_with_value = VALUES(issues_with_value),
            issues_with_non_empty_value = VALUES(issues_with_non_empty_value),
            last_counted_at = VALUES(last_counted_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare staging_jira_field_usage upsert statement.');
    }

    $analysedFields = 0;
    $fieldsWithValues = 0;
    $fieldsWithNonEmptyValues = 0;
    $topCandidates = [];

    while (true) {
        $field = $fieldsStatement->fetch(PDO::FETCH_ASSOC);
        if ($field === false) {
            break;
        }

        $fieldId = isset($field['id']) ? trim((string)$field['id']) : '';
        if ($fieldId === '') {
            continue;
        }

        $fieldName = isset($field['name']) ? trim((string)$field['name']) : '';
        if ($fieldName === '') {
            $fieldName = $fieldId;
        }

        $analysedFields++;

        $issuesWithValue = 0;
        $issuesWithNonEmptyValue = 0;

        if ($totalIssues > 0) {
            $usageStatement->execute(['field_id' => $fieldId]);
            $usageRow = $usageStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $usageStatement->closeCursor();

            $issuesWithValue = (int)($usageRow['issues_with_value'] ?? 0);
            $issuesWithNonEmptyValue = (int)($usageRow['issues_with_non_empty_value'] ?? 0);
        }

        if ($issuesWithNonEmptyValue > $issuesWithValue) {
            $issuesWithNonEmptyValue = $issuesWithValue;
        }

        if ($issuesWithValue > 0) {
            $fieldsWithValues++;
        }

        if ($issuesWithNonEmptyValue > 0) {
            $fieldsWithNonEmptyValues++;
            $topCandidates[] = [
                'field_id' => $fieldId,
                'field_name' => $fieldName,
                'issues_with_non_empty_value' => $issuesWithNonEmptyValue,
            ];
        }

        $insertStatement->execute([
            'field_id' => $fieldId,
            'usage_scope' => FIELD_USAGE_SCOPE_ISSUE,
            'total_issues' => $totalIssues,
            'issues_with_value' => $issuesWithValue,
            'issues_with_non_empty_value' => $issuesWithNonEmptyValue,
            'last_counted_at' => $analysisTimestamp,
        ]);
    }

    $fieldsStatement->closeCursor();

    usort($topCandidates, static function (array $left, array $right): int {
        return $right['issues_with_non_empty_value'] <=> $left['issues_with_non_empty_value'];
    });

    $topFields = [];
    $denominator = max($totalIssues, 1);
    foreach (array_slice($topCandidates, 0, 10) as $candidate) {
        $topFields[] = [
            'field_id' => $candidate['field_id'],
            'field_name' => $candidate['field_name'],
            'issues_with_non_empty_value' => $candidate['issues_with_non_empty_value'],
            'non_empty_percentage' => $candidate['issues_with_non_empty_value'] / $denominator * 100,
        ];
    }

    return [
        'analysed_fields' => $analysedFields,
        'fields_with_values' => $fieldsWithValues,
        'fields_with_non_empty_values' => $fieldsWithNonEmptyValues,
        'total_issues' => $totalIssues,
        'last_counted_at' => $analysisTimestamp,
        'top_fields' => $topFields,
    ];
}

/**
 * @throws Throwable
 */
function fetchAndStoreRedmineCustomFields(Client $client, PDO $pdo, bool $useExtendedApi, ?string $extendedApiPrefix = null): int
{
    if ($useExtendedApi) {
        if ($extendedApiPrefix === null) {
            throw new RuntimeException('Extended API requested for the Redmine snapshot but no prefix was provided.');
        }

        verifyExtendedApiAvailability($client, $extendedApiPrefix, 'custom_fields.json');
    }

    try {
        $pdo->exec('TRUNCATE TABLE staging_redmine_custom_fields');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to truncate staging_redmine_custom_fields: ' . $exception->getMessage(), 0, $exception);
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_redmine_custom_fields (
            id,
            name,
            customized_type,
            field_format,
            is_required,
            is_filter,
            is_for_all,
            is_multiple,
            possible_values,
            default_value,
            tracker_ids,
            role_ids,
            project_ids,
            raw_payload,
            retrieved_at
        ) VALUES (
            :id,
            :name,
            :customized_type,
            :field_format,
            :is_required,
            :is_filter,
            :is_for_all,
            :is_multiple,
            :possible_values,
            :default_value,
            :tracker_ids,
            :role_ids,
            :project_ids,
            :raw_payload,
            :retrieved_at
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            customized_type = VALUES(customized_type),
            field_format = VALUES(field_format),
            is_required = VALUES(is_required),
            is_filter = VALUES(is_filter),
            is_for_all = VALUES(is_for_all),
            is_multiple = VALUES(is_multiple),
            possible_values = VALUES(possible_values),
            default_value = VALUES(default_value),
            tracker_ids = VALUES(tracker_ids),
            role_ids = VALUES(role_ids),
            project_ids = VALUES(project_ids),
            raw_payload = VALUES(raw_payload),
            retrieved_at = VALUES(retrieved_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_redmine_custom_fields.');
    }

    $retrievedAt = formatCurrentTimestamp('Y-m-d H:i:s');
    $duplicateIds = [];
    $seenIds = [];
    $offset = 0;
    $limit = 100;
    $total = null;
    $processed = 0;

    do {
        $query = http_build_query([
            'limit' => $limit,
            'offset' => $offset,
        ], '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $client->get('/custom_fields.json?' . $query);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = 'Failed to fetch custom fields from Redmine';
            if ($response !== null) {
                $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
            }

            throw new RuntimeException($message, 0, $exception);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Failed to fetch custom fields from Redmine: ' . $exception->getMessage(), 0, $exception);
        }

        $decoded = decodeJsonResponse($response);
        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected Redmine custom field payload format.');
        }

        $customFields = isset($decoded['custom_fields']) && is_array($decoded['custom_fields'])
            ? $decoded['custom_fields']
            : [];

        $total = isset($decoded['total_count']) ? (int)$decoded['total_count'] : null;
        $batchCount = count($customFields);

        foreach ($customFields as $customField) {
            if (!is_array($customField)) {
                continue;
            }

            $id = isset($customField['id']) ? (int)$customField['id'] : null;
            if ($id === null) {
                continue;
            }

            $detailedPayload = fetchRedmineCustomFieldDetails($client, $id, $useExtendedApi, $extendedApiPrefix);
            if (is_array($detailedPayload) && isset($detailedPayload['custom_field']) && is_array($detailedPayload['custom_field'])) {
                $customField = $detailedPayload['custom_field'];
            }

            if (isset($seenIds[$id])) {
                $duplicateIds[$id] = true;
            } else {
                $seenIds[$id] = true;
            }

            $name = isset($customField['name']) ? trim((string)$customField['name']) : (string)$id;
            $customizedType = isset($customField['customized_type']) ? trim((string)$customField['customized_type']) : null;
            $fieldFormat = isset($customField['field_format']) ? trim((string)$customField['field_format']) : null;
            $fieldFormat = normalizeRedmineFieldFormat($fieldFormat);
            $isRequired = normalizeBooleanDatabaseValue($customField['is_required'] ?? null);
            $isFilter = normalizeBooleanDatabaseValue($customField['is_filter'] ?? null);
            $isForAll = normalizeBooleanDatabaseValue($customField['is_for_all'] ?? null);
            $isMultiple = normalizeBooleanDatabaseValue($customField['multiple'] ?? null);

            $possibleValues = null;
            if (isset($customField['possible_values'])) {
                try {
                    $possibleValues = json_encode(
                        $customField['possible_values'],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                } catch (JsonException $exception) {
                    throw new RuntimeException('Failed to encode Redmine custom field possible values: ' . $exception->getMessage(), 0, $exception);
                }
            }

            $defaultValue = isset($customField['default_value']) && $customField['default_value'] !== ''
                ? (string)$customField['default_value']
                : null;

            $trackerIds = extractIdListFromRedmineCustomField($customField['trackers'] ?? null);
            $roleIds = extractIdListFromRedmineCustomField($customField['roles'] ?? null);
            $projectIds = extractIdListFromRedmineCustomField($customField['projects'] ?? null);

            try {
                $rawPayloadSource = $detailedPayload ?? $customField;
                $rawPayload = json_encode($rawPayloadSource, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    sprintf('Failed to encode Redmine custom field payload for %d: %s', $id, $exception->getMessage()),
                    0,
                    $exception
                );
            }

            $insertStatement->execute([
                'id' => $id,
                'name' => $name,
                'customized_type' => $customizedType,
                'field_format' => $fieldFormat,
                'is_required' => $isRequired,
                'is_filter' => $isFilter,
                'is_for_all' => $isForAll,
                'is_multiple' => $isMultiple,
                'possible_values' => $possibleValues,
                'default_value' => $defaultValue,
                'tracker_ids' => $trackerIds,
                'role_ids' => $roleIds,
                'project_ids' => $projectIds,
                'raw_payload' => $rawPayload,
                'retrieved_at' => $retrievedAt,
            ]);

            $processed++;
        }

        $offset += $limit;
        if ($total !== null) {
            if ($offset >= $total) {
                break;
            }
        } elseif ($batchCount === 0 || $batchCount < $limit) {
            break;
        }
    } while (true);

    printf("  Captured %d Redmine custom field records.%s", $processed, PHP_EOL);

    if ($duplicateIds !== []) {
        $duplicateCount = count($duplicateIds);
        printf(
            "  Detected %d duplicate Redmine custom field IDs in the API response; the latest payload was applied.%s",
            $duplicateCount,
            PHP_EOL
        );
    }

    return $processed;
}

/**
 * @return array<string, mixed>|null
 */
function fetchRedmineCustomFieldDetails(Client $client, int $customFieldId, bool $useExtendedApi, ?string $extendedApiPrefix): ?array
{
    $resource = sprintf('custom_fields/%d.json', $customFieldId);
    $queryString = '';
    if ($useExtendedApi) {
        if ($extendedApiPrefix === null) {
            throw new RuntimeException('Extended API prefix is required when useExtendedApi is true.');
        }

        $resource = buildExtendedApiPath($extendedApiPrefix, $resource);
        $queryString = http_build_query([
            'extended_api[mode]' => 'extended',
            'extended_api[fallback_to_native]' => false,
        ], '', '&', PHP_QUERY_RFC3986);
    } else {
        $resource = '/' . ltrim($resource, '/');
    }

    $endpoint = $queryString === '' ? $resource : sprintf('%s?%s', $resource, $queryString);

    try {
        $response = $client->get($endpoint);
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $message = sprintf('Failed to fetch Redmine custom field %d', $customFieldId);
        if ($response !== null) {
            $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
        }

        throw new RuntimeException($message, 0, $exception);
    } catch (GuzzleException $exception) {
        throw new RuntimeException(
            sprintf('Failed to fetch Redmine custom field %d: %s', $customFieldId, $exception->getMessage()),
            0,
            $exception
        );
    }

    $decoded = decodeJsonResponse($response);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * @return string|null
 */
function extractIdListFromRedmineCustomField(mixed $value): ?string
{
    if (!is_array($value) || $value === []) {
        return null;
    }

    $ids = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        if (isset($item['id'])) {
            $ids[] = (int)$item['id'];
        }
    }

    if ($ids === []) {
        return null;
    }

    sort($ids);

    try {
        return json_encode($ids, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode Redmine custom field ID list: ' . $exception->getMessage(), 0, $exception);
    }
}
/**
 * @return array{matched: int, ready_for_creation: int, manual_review: int, manual_overrides: int, ignored_unused: int, skipped: int, unchanged: int, status_counts: array<string, int>}
 */
function runCustomFieldTransformationPhase(PDO $pdo): array
{
    syncCustomFieldMappings($pdo);
    refreshCustomFieldMetadata($pdo);
    logCustomFieldMappingStats($pdo);

    $redmineLookup = buildRedmineCustomFieldLookup($pdo);
    $jiraProjectToRedmine = buildJiraToRedmineProjectLookup($pdo);
    $jiraIssueTypeToTracker = buildJiraToRedmineTrackerLookup($pdo);
    $mappings = fetchCustomFieldMappingsForTransform($pdo);
    $jiraAssignmentSummaries = summarizeJiraFieldAssignments($pdo);
    $jiraSearchability = loadJiraFieldSearchability($pdo);
    $allowedValuesVariations = summarizeAllowedValuesVariations($pdo);
    $objectMultiplicity = buildObjectFieldMultiplicityLookup($pdo);

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_custom_fields
        SET
            redmine_custom_field_id = :redmine_custom_field_id,
            mapping_parent_custom_field_id = :mapping_parent_custom_field_id,
            migration_status = :migration_status,
            notes = :notes,
            proposed_redmine_name = :proposed_redmine_name,
            proposed_field_format = :proposed_field_format,
            proposed_is_required = :proposed_is_required,
            proposed_is_filter = :proposed_is_filter,
            proposed_is_for_all = :proposed_is_for_all,
            proposed_is_multiple = :proposed_is_multiple,
            proposed_possible_values = :proposed_possible_values,
            proposed_value_dependencies = :proposed_value_dependencies,
            proposed_default_value = :proposed_default_value,
            proposed_tracker_ids = :proposed_tracker_ids,
            proposed_role_ids = :proposed_role_ids,
            proposed_project_ids = :proposed_project_ids,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_custom_fields.');
    }

    $cascadingParentInsert = $pdo->prepare(<<<SQL
        INSERT INTO migration_mapping_custom_fields (
            jira_field_id,
            jira_field_name,
            jira_schema_type,
            jira_project_ids,
            jira_issue_type_ids,
            jira_allowed_values,
            redmine_custom_field_id,
            mapping_parent_custom_field_id,
            proposed_redmine_name,
            proposed_field_format,
            proposed_is_required,
            proposed_is_filter,
            proposed_is_for_all,
            proposed_is_multiple,
            proposed_possible_values,
            proposed_value_dependencies,
            proposed_default_value,
            proposed_tracker_ids,
            proposed_role_ids,
            proposed_project_ids,
            migration_status,
            notes,
            automation_hash,
            created_at,
            last_updated_at
        ) VALUES (
            :jira_field_id,
            :jira_field_name,
            :jira_schema_type,
            :jira_project_ids,
            :jira_issue_type_ids,
            :jira_allowed_values,
            NULL,
            NULL,
            :proposed_redmine_name,
            :proposed_field_format,
            :proposed_is_required,
            :proposed_is_filter,
            :proposed_is_for_all,
            :proposed_is_multiple,
            :proposed_possible_values,
            :proposed_value_dependencies,
            :proposed_default_value,
            :proposed_tracker_ids,
            :proposed_role_ids,
            :proposed_project_ids,
            :migration_status,
            :notes,
            :automation_hash,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            jira_field_name = VALUES(jira_field_name),
            jira_schema_type = VALUES(jira_schema_type),
            jira_project_ids = VALUES(jira_project_ids),
            jira_issue_type_ids = VALUES(jira_issue_type_ids),
            jira_allowed_values = VALUES(jira_allowed_values),
            proposed_redmine_name = VALUES(proposed_redmine_name),
            proposed_field_format = VALUES(proposed_field_format),
            proposed_is_required = VALUES(proposed_is_required),
            proposed_is_filter = VALUES(proposed_is_filter),
            proposed_is_for_all = VALUES(proposed_is_for_all),
            proposed_is_multiple = VALUES(proposed_is_multiple),
            proposed_possible_values = VALUES(proposed_possible_values),
            proposed_value_dependencies = VALUES(proposed_value_dependencies),
            proposed_default_value = VALUES(proposed_default_value),
            proposed_tracker_ids = VALUES(proposed_tracker_ids),
            proposed_role_ids = VALUES(proposed_role_ids),
            proposed_project_ids = VALUES(proposed_project_ids),
            migration_status = VALUES(migration_status),
            notes = VALUES(notes),
            automation_hash = VALUES(automation_hash),
            last_updated_at = VALUES(last_updated_at)
    SQL);

    if ($cascadingParentInsert === false) {
        throw new RuntimeException('Failed to prepare cascading parent insert.');
    }

    $cascadingParentLookup = $pdo->prepare(<<<SQL
        SELECT mapping_id, redmine_custom_field_id
        FROM migration_mapping_custom_fields
        WHERE jira_field_id = :jira_field_id
        LIMIT 1
    SQL);

    if ($cascadingParentLookup === false) {
        throw new RuntimeException('Failed to prepare cascading parent lookup.');
    }

    $summary = [
        'matched' => 0,
        'ready_for_creation' => 0,
        'manual_review' => 0,
        'manual_overrides' => 0,
        'ignored_unused' => 0,
        'skipped' => 0,
        'unchanged' => 0,
        'status_counts' => [],
    ];

    $existingMappings = [];
    foreach ($mappings as $row) {
        $existingMappings[(string)$row['jira_field_id']] = $row;
    }

    foreach ($mappings as $row) {
        $currentStatus = (string)$row['migration_status'];
        $allowedStatuses = ['PENDING_ANALYSIS', 'READY_FOR_CREATION', 'READY_FOR_UPDATE', 'MATCH_FOUND', 'CREATION_FAILED'];
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            $summary['skipped']++;
            continue;
        }

        $jiraFieldId = (string)$row['jira_field_id'];
        $jiraFieldName = isset($row['jira_field_name']) ? (string)$row['jira_field_name'] : null;
        $jiraSchemaType = isset($row['jira_schema_type']) ? (string)$row['jira_schema_type'] : null;
        $jiraSchemaCustom = isset($row['jira_schema_custom']) ? (string)$row['jira_schema_custom'] : null;
        $normalizedSchemaType = $jiraSchemaType !== null ? strtolower($jiraSchemaType) : null;
        if (str_starts_with($jiraFieldId, 'cascading_parent:')) {
            continue;
        }
        $currentRedmineId = isset($row['redmine_custom_field_id']) ? (int)$row['redmine_custom_field_id'] : null;
        $currentParentMappingId = isset($row['mapping_parent_custom_field_id']) ? (int)$row['mapping_parent_custom_field_id'] : null;
        $currentRedmineEnumerations = isset($row['redmine_custom_field_enumerations'])
            ? (string)$row['redmine_custom_field_enumerations']
            : null;
        $currentNotes = isset($row['notes']) ? (string)$row['notes'] : null;
        $currentProposedName = isset($row['proposed_redmine_name']) ? (string)$row['proposed_redmine_name'] : null;
        $currentProposedFormat = isset($row['proposed_field_format']) ? (string)$row['proposed_field_format'] : null;
        $currentProposedFormat = normalizeRedmineFieldFormat($currentProposedFormat);
        $currentProposedIsRequired = normalizeBooleanFlag($row['proposed_is_required'] ?? null);
        $currentProposedIsFilter = normalizeBooleanFlag($row['proposed_is_filter'] ?? null);
        $currentProposedIsForAll = normalizeBooleanFlag($row['proposed_is_for_all'] ?? null);
        $currentProposedIsMultiple = normalizeBooleanFlag($row['proposed_is_multiple'] ?? null);
        $currentProposedPossibleValuesRaw = isset($row['proposed_possible_values']) ? (string)$row['proposed_possible_values'] : null;
        $currentProposedValueDependenciesRaw = isset($row['proposed_value_dependencies']) ? (string)$row['proposed_value_dependencies'] : null;
        $currentProposedDefaultValue = isset($row['proposed_default_value']) ? (string)$row['proposed_default_value'] : null;
        $currentProposedTrackerIdsRaw = isset($row['proposed_tracker_ids']) ? (string)$row['proposed_tracker_ids'] : null;
        $currentProposedRoleIdsRaw = isset($row['proposed_role_ids']) ? (string)$row['proposed_role_ids'] : null;
        $currentProposedProjectIdsRaw = isset($row['proposed_project_ids']) ? (string)$row['proposed_project_ids'] : null;
        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);

        $jiraProjectIds = decodeJsonColumn($row['jira_project_ids'] ?? null);
        $jiraProjectIds = is_array($jiraProjectIds)
            ? array_values(array_map('strval', array_filter($jiraProjectIds, static fn($value) => trim((string)$value) !== '')))
            : [];

        $jiraIssueTypeIds = decodeJsonColumn($row['jira_issue_type_ids'] ?? null);
        $jiraIssueTypeIds = is_array($jiraIssueTypeIds)
            ? array_values(array_map('strval', array_filter($jiraIssueTypeIds, static fn($value) => trim((string)$value) !== '')))
            : [];

        $jiraAllowedValuesRaw = decodeJsonColumn($row['jira_allowed_values'] ?? null);
        $hasJiraAllowedValues = $jiraAllowedValuesRaw !== null;
        $jiraAllowedValues = is_array($jiraAllowedValuesRaw) ? $jiraAllowedValuesRaw : [];

        $proposedPossibleValues = decodeJsonColumn($currentProposedPossibleValuesRaw);
        if (is_array($proposedPossibleValues)) {
            $proposedPossibleValues = array_values($proposedPossibleValues);
            if ($proposedPossibleValues === []) {
                $proposedPossibleValues = null;
            }
        } else {
            $proposedPossibleValues = null;
        }

        $proposedValueDependencies = decodeJsonColumn($currentProposedValueDependenciesRaw);
        if (!is_array($proposedValueDependencies) || $proposedValueDependencies === []) {
            $proposedValueDependencies = null;
        }

        $decodedTrackerIds = decodeJsonColumn($currentProposedTrackerIdsRaw);
        if (is_array($decodedTrackerIds)) {
            $proposedTrackerIds = array_values(array_map('intval', $decodedTrackerIds));
            if ($proposedTrackerIds === []) {
                $proposedTrackerIds = null;
            }
        } else {
            $proposedTrackerIds = null;
        }

        $decodedRoleIds = decodeJsonColumn($currentProposedRoleIdsRaw);
        if (is_array($decodedRoleIds)) {
            $proposedRoleIds = array_values(array_map('intval', $decodedRoleIds));
            if ($proposedRoleIds === []) {
                $proposedRoleIds = null;
            }
        } else {
            $proposedRoleIds = null;
        }

        $decodedProjectIds = decodeJsonColumn($currentProposedProjectIdsRaw);
        if (is_array($decodedProjectIds)) {
            $proposedProjectIds = array_values(array_map('intval', $decodedProjectIds));
            if ($proposedProjectIds === []) {
                $proposedProjectIds = null;
            }
        } else {
            $proposedProjectIds = null;
        }

        $usageTotalIssues = isset($row['usage_total_issues']) ? (int)$row['usage_total_issues'] : 0;
        $usageIssuesWithValue = isset($row['usage_issues_with_value']) ? (int)$row['usage_issues_with_value'] : 0;
        $usageIssuesWithNonEmpty = isset($row['usage_issues_with_non_empty_value'])
            ? (int)$row['usage_issues_with_non_empty_value']
            : 0;
        $usageLastCountedAtRaw = isset($row['usage_last_counted_at']) ? (string)$row['usage_last_counted_at'] : null;
        $usageLastCountedAt = $usageLastCountedAtRaw !== '' ? $usageLastCountedAtRaw : 'n/a';

        $fieldCategory = isset($row['jira_field_category']) ? (string)$row['jira_field_category'] : null;
        $manualReasons = [];
        $infoNotes = [];

        if ($usageTotalIssues === 0) {
            $usageNote = 'Usage snapshot: no staged issues counted yet.';
        } else {
            $usageNote = sprintf(
                'Usage snapshot (%s): non-empty values in %d/%d issues (values present in %d/%d).',
                $usageLastCountedAt,
                $usageIssuesWithNonEmpty,
                $usageTotalIssues,
                $usageIssuesWithValue,
                $usageTotalIssues
            );
        }

        $currentAutomationHash = computeCustomFieldAutomationStateHash(
            $currentRedmineId,
            $currentStatus,
            $currentProposedName,
            $currentProposedFormat,
            $currentProposedIsRequired,
            $currentProposedIsFilter,
            $currentProposedIsForAll,
            $currentProposedIsMultiple,
            $currentProposedPossibleValuesRaw,
            $currentProposedValueDependenciesRaw,
            $currentProposedDefaultValue,
            $currentProposedTrackerIdsRaw,
            $currentProposedRoleIdsRaw,
            $currentProposedProjectIdsRaw,
            $currentNotes,
            $currentParentMappingId,
            $currentRedmineEnumerations
        );

        if ($storedAutomationHash !== null && $storedAutomationHash !== $currentAutomationHash) {
            $summary['manual_overrides']++;
            printf(
                "  [preserved] Jira custom field %s has manual overrides; skipping automated changes.%s",
                $jiraFieldName ?? $jiraFieldId,
                PHP_EOL
            );
            continue;
        }

        $defaultName = $jiraFieldName !== null ? normalizeString($jiraFieldName, 255) : null;

        $cascadingParentContext = null;

        $proposedName = $currentProposedName;
        if ($proposedName === null) {
            $proposedName = $defaultName ?? $jiraFieldId;
        }
        $proposedName = $proposedName !== null ? normalizeString($proposedName, 255) : null;

        $proposedFormat = $currentProposedFormat;
        $proposedIsRequired = $currentProposedIsRequired ?? false;
        $proposedIsFilter = $currentProposedIsFilter ?? true;
        $proposedIsForAll = normalizeBooleanFlag($row['proposed_is_for_all'] ?? null);

        if ($proposedIsForAll === null) {
            $proposedIsForAll = empty($currentProposedProjectIds);
        }

        $proposedIsMultiple = $currentProposedIsMultiple ?? false;
        $proposedDefaultValue = $currentProposedDefaultValue;

        $assignmentSummary = $jiraAssignmentSummaries[$jiraFieldId] ?? null;
        if ($assignmentSummary !== null && $assignmentSummary['total_assignments'] > 0) {
            if ($assignmentSummary['required_assignments'] === $assignmentSummary['total_assignments']) {
                $proposedIsRequired = true;
            } elseif ($assignmentSummary['required_assignments'] === 0) {
                $proposedIsRequired = false;
            } else {
                $infoNotes[] = sprintf(
                    'Requirement varies across contexts: %d/%d Jira assignments mark the field as required.',
                    $assignmentSummary['required_assignments'],
                    $assignmentSummary['total_assignments']
                );
                $proposedIsRequired = false;
            }
        }

        $searchability = $jiraSearchability[$jiraFieldId] ?? null;
        if ($searchability !== null && $searchability['searchable'] !== null) {
            $proposedIsFilter = $searchability['searchable'];
        }

        if (($jiraProjectIds === [] || $jiraIssueTypeIds === []) && in_array($currentStatus, $allowedStatuses, true)) {
            $notesParts = [];
            if ($currentNotes !== null && trim($currentNotes) !== '') {
                $notesParts[] = trim($currentNotes);
            }

            $notesParts[] = 'Automatically ignored: jira_project_ids or jira_issue_type_ids is empty; no scope available for mapping.';
            $notes = implode(' ', array_unique($notesParts));

            $proposedPossibleValuesJson = encodeJsonColumn($proposedPossibleValues);
            $proposedValueDependenciesJson = encodeJsonColumn($proposedValueDependencies);
            $proposedTrackerIdsJson = encodeJsonColumn($proposedTrackerIds);
            $proposedRoleIdsJson = encodeJsonColumn($proposedRoleIds);
            $proposedProjectIdsJson = encodeJsonColumn($proposedProjectIds);

            $automationHash = computeCustomFieldAutomationStateHash(
                $currentRedmineId,
                'IGNORED',
                $proposedName,
                $proposedFormat,
                $proposedIsRequired,
                $proposedIsFilter,
                $proposedIsForAll,
                $proposedIsMultiple,
                $proposedPossibleValuesJson,
                $proposedValueDependenciesJson,
                $proposedDefaultValue,
                $proposedTrackerIdsJson,
                $proposedRoleIdsJson,
                $proposedProjectIdsJson,
                $notes,
                $currentParentMappingId,
                $currentRedmineEnumerations
            );

            $updateStatement->execute([
                'redmine_custom_field_id' => $currentRedmineId,
                'mapping_parent_custom_field_id' => $currentParentMappingId,
                'migration_status' => 'IGNORED',
                'notes' => $notes,
                'proposed_redmine_name' => $proposedName,
                'proposed_field_format' => $proposedFormat,
                'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                'proposed_possible_values' => $proposedPossibleValuesJson,
                'proposed_value_dependencies' => $proposedValueDependenciesJson,
                'proposed_default_value' => $proposedDefaultValue,
                'proposed_tracker_ids' => $proposedTrackerIdsJson,
                'proposed_role_ids' => $proposedRoleIdsJson,
                'proposed_project_ids' => $proposedProjectIdsJson,
                'automation_hash' => $automationHash,
                'mapping_id' => (int)$row['mapping_id'],
            ]);

            if ($automationHash === $currentAutomationHash) {
                $summary['ignored_unused']++;
                continue;
            }

            $summary['ignored_unused']++;
            continue;
        }

        $schemaType = $row['jira_schema_type'] !== null ? strtolower((string)$row['jira_schema_type']) : null;

        if (($jiraProjectIds === [] || $jiraIssueTypeIds === []) && in_array($currentStatus, $allowedStatuses, true)) {
            $notesParts = [];
            if ($currentNotes !== null && trim($currentNotes) !== '') {
                $notesParts[] = trim($currentNotes);
            }

            $notesParts[] = 'Automatically ignored: jira_project_ids or jira_issue_type_ids is empty; no scope available for mapping.';
            $notes = implode(' ', array_unique($notesParts));

            $proposedPossibleValuesJson = encodeJsonColumn($proposedPossibleValues);
            $proposedValueDependenciesJson = encodeJsonColumn($proposedValueDependencies);
            $proposedTrackerIdsJson = encodeJsonColumn($proposedTrackerIds);
            $proposedRoleIdsJson = encodeJsonColumn($proposedRoleIds);
            $proposedProjectIdsJson = encodeJsonColumn($proposedProjectIds);

            $automationHash = computeCustomFieldAutomationStateHash(
                $currentRedmineId,
                'IGNORED',
                $proposedName,
                $proposedFormat,
                $proposedIsRequired,
                $proposedIsFilter,
                $proposedIsForAll,
                $proposedIsMultiple,
                $proposedPossibleValuesJson,
                $proposedValueDependenciesJson,
                $proposedDefaultValue,
                $proposedTrackerIdsJson,
                $proposedRoleIdsJson,
                $proposedProjectIdsJson,
                $notes,
                $currentParentMappingId,
                $currentRedmineEnumerations
            );

            $updateStatement->execute([
                'redmine_custom_field_id' => $currentRedmineId,
                'mapping_parent_custom_field_id' => $currentParentMappingId,
                'migration_status' => 'IGNORED',
                'notes' => $notes,
                'proposed_redmine_name' => $proposedName,
                'proposed_field_format' => $proposedFormat,
                'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                'proposed_possible_values' => $proposedPossibleValuesJson,
                'proposed_value_dependencies' => $proposedValueDependenciesJson,
                'proposed_default_value' => $proposedDefaultValue,
                'proposed_tracker_ids' => $proposedTrackerIdsJson,
                'proposed_role_ids' => $proposedRoleIdsJson,
                'proposed_project_ids' => $proposedProjectIdsJson,
                'automation_hash' => $automationHash,
                'mapping_id' => (int)$row['mapping_id'],
            ]);

            if ($automationHash === $currentAutomationHash) {
                $summary['ignored_unused']++;
                continue;
            }

            $summary['ignored_unused']++;
            continue;
        }

        $appCustomAutoStatuses = ['PENDING_ANALYSIS'];

        if ($fieldCategory === 'app_custom') {
            if (!in_array($currentStatus, $appCustomAutoStatuses, true)) {
                $summary['unchanged']++;
                continue;
            }

            $infoNotes[] = 'Jira app custom field; proposal derived from Jira metadata.';
        }

        $infoNotes[] = $usageNote;

        $classification = classifyJiraCustomField($jiraSchemaType, $jiraSchemaCustom);
        if ($proposedFormat === null) {
            $proposedFormat = $classification['field_format'];
        }

        if ($classification['is_multiple'] !== null) {
            $proposedIsMultiple = $classification['is_multiple'];
        }

        // Extra logica voor multipliciteit:
        // - arrays: altijd multiple
        // - objecten: gebruik target_is_multiple uit migration_mapping_custom_object indien beschikbaar
        if ($normalizedSchemaType !== null) {
            if ($normalizedSchemaType === 'array') {
                // Altijd meerdere waardes mogelijk
                $proposedIsMultiple = true;
            } elseif ($normalizedSchemaType === 'object') {
                // Kijk of we een inferred object mapping hebben
                if (isset($objectMultiplicity[$jiraFieldId])) {
                    $fromObjectMapping = normalizeBooleanFlag($objectMultiplicity[$jiraFieldId]);
                    if ($fromObjectMapping !== null) {
                        $proposedIsMultiple = $fromObjectMapping;
                    }
                }
            }
        }

        if ($normalizedSchemaType === 'array' && !$hasJiraAllowedValues && in_array($currentStatus, $allowedStatuses, true)) {
            $notesParts = [];
            if ($currentNotes !== null && trim($currentNotes) !== '') {
                $notesParts[] = trim($currentNotes);
            }

            $notesParts[] = 'Automatically ignored: Jira array field has no allowedValues metadata to derive list options.';
            $notes = implode(' ', array_unique($notesParts));

            $proposedPossibleValuesJson = encodeJsonColumn($proposedPossibleValues);
            $proposedValueDependenciesJson = encodeJsonColumn($proposedValueDependencies);
            $proposedTrackerIdsJson = encodeJsonColumn($proposedTrackerIds);
            $proposedRoleIdsJson = encodeJsonColumn($proposedRoleIds);
            $proposedProjectIdsJson = encodeJsonColumn($proposedProjectIds);

            $automationHash = computeCustomFieldAutomationStateHash(
                $currentRedmineId,
                'IGNORED',
                $proposedName,
                $proposedFormat,
                $proposedIsRequired,
                $proposedIsFilter,
                $proposedIsForAll,
                $proposedIsMultiple,
                $proposedPossibleValuesJson,
                $proposedValueDependenciesJson,
                $proposedDefaultValue,
                $proposedTrackerIdsJson,
                $proposedRoleIdsJson,
                $proposedProjectIdsJson,
                $notes,
                $currentParentMappingId,
                $currentRedmineEnumerations
            );

            $updateStatement->execute([
                'redmine_custom_field_id' => $currentRedmineId,
                'mapping_parent_custom_field_id' => $currentParentMappingId,
                'migration_status' => 'IGNORED',
                'notes' => $notes,
                'proposed_redmine_name' => $proposedName,
                'proposed_field_format' => $proposedFormat,
                'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                'proposed_possible_values' => $proposedPossibleValuesJson,
                'proposed_value_dependencies' => $proposedValueDependenciesJson,
                'proposed_default_value' => $proposedDefaultValue,
                'proposed_tracker_ids' => $proposedTrackerIdsJson,
                'proposed_role_ids' => $proposedRoleIdsJson,
                'proposed_project_ids' => $proposedProjectIdsJson,
                'automation_hash' => $automationHash,
                'mapping_id' => (int)$row['mapping_id'],
            ]);

            if ($automationHash === $currentAutomationHash) {
                $summary['ignored_unused']++;
                continue;
            }

            $summary['ignored_unused']++;
            continue;
        }

        $autoIgnoreUnused = $usageTotalIssues > 0 && $usageIssuesWithValue === 0 && $usageIssuesWithNonEmpty === 0;
        if ($autoIgnoreUnused && in_array($currentStatus, $allowedStatuses, true)) {
            $notesParts = [];
            if ($currentNotes !== null && trim($currentNotes) !== '') {
                $notesParts[] = trim($currentNotes);
            }

            $notesParts[] = 'Automatically ignored: no staged issues contain values for this custom field. ' . $usageNote;
            $notes = implode(' ', array_unique($notesParts));

            $proposedPossibleValuesJson = encodeJsonColumn($proposedPossibleValues);
            $proposedValueDependenciesJson = encodeJsonColumn($proposedValueDependencies);
            $proposedTrackerIdsJson = encodeJsonColumn($proposedTrackerIds);
            $proposedRoleIdsJson = encodeJsonColumn($proposedRoleIds);
            $proposedProjectIdsJson = encodeJsonColumn($proposedProjectIds);

            $automationHash = computeCustomFieldAutomationStateHash(
                $currentRedmineId,
                'IGNORED',
                $proposedName,
                $proposedFormat,
                $proposedIsRequired,
                $proposedIsFilter,
                $proposedIsForAll,
                $proposedIsMultiple,
                $proposedPossibleValuesJson,
                $proposedValueDependenciesJson,
                $proposedDefaultValue,
                $proposedTrackerIdsJson,
                $proposedRoleIdsJson,
                $proposedProjectIdsJson,
                $notes,
                $currentParentMappingId,
                $currentRedmineEnumerations
            );

            $updateStatement->execute([
                'redmine_custom_field_id' => $currentRedmineId,
                'mapping_parent_custom_field_id' => $currentParentMappingId,
                'migration_status' => 'IGNORED',
                'notes' => $notes,
                'proposed_redmine_name' => $proposedName,
                'proposed_field_format' => $proposedFormat,
                'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                'proposed_possible_values' => $proposedPossibleValuesJson,
                'proposed_value_dependencies' => $proposedValueDependenciesJson,
                'proposed_default_value' => $proposedDefaultValue,
                'proposed_tracker_ids' => $proposedTrackerIdsJson,
                'proposed_role_ids' => $proposedRoleIdsJson,
                'proposed_project_ids' => $proposedProjectIdsJson,
                'automation_hash' => $automationHash,
                'mapping_id' => (int)$row['mapping_id'],
            ]);

            if ($automationHash === $currentAutomationHash) {
                $summary['ignored_unused']++;
                continue;
            }

            $summary['ignored_unused']++;
            continue;
        }

        $isCascadingField = !empty($classification['is_cascading'])
            || ($jiraSchemaType !== null && strtolower($jiraSchemaType) === 'option-with-child');
        $cascadingDescriptor = null;
        if ($isCascadingField) {
            $cascadingDescriptor = parseCascadingAllowedValues($jiraAllowedValues);
            $parentMappingKey = sprintf('cascading_parent:%s', $jiraFieldId);

            if ($cascadingDescriptor === null) {
                $manualReasons[] = 'Unable to parse cascading Jira custom field options for dependent field creation.';
            } else {
                $proposedFormat = 'depending_enumeration';

                if ($cascadingDescriptor['child_values'] === []) {
                    $manualReasons[] = 'Cascading Jira custom field does not expose any child options.';
                } else {
                    $proposedPossibleValues = $cascadingDescriptor['child_values'];
                }

                if ($cascadingDescriptor['parents'] === []) {
                    $manualReasons[] = 'Cascading Jira custom field does not expose any parent options.';
                }

                if ($cascadingDescriptor['dependencies'] !== []) {
                    $proposedValueDependencies = $cascadingDescriptor['dependencies'];
                }

                $parentNameBase = normalizeString($proposedName ?? ($defaultName ?? $jiraFieldId), 255)
                    ?? ($proposedName ?? ($defaultName ?? $jiraFieldId));
                $parentName = normalizeString(sprintf('%s (Parent)', $parentNameBase), 255)
                    ?? sprintf('%s (Parent)', $parentNameBase);

                $parentRedmineId = null;
                $parentMappingId = null;
                $parentExisted = isset($existingMappings[$parentMappingKey]);
                if ($parentExisted) {
                    $parentRow = $existingMappings[$parentMappingKey];
                    if (isset($parentRow['redmine_custom_field_id']) && $parentRow['redmine_custom_field_id'] !== null) {
                        $parentRedmineId = (int)$parentRow['redmine_custom_field_id'];
                    }
                    if (isset($parentRow['mapping_id']) && $parentRow['mapping_id'] !== null) {
                        $parentMappingId = (int)$parentRow['mapping_id'];
                    }
                }

                $parentPossibleValuesJson = encodeJsonColumn($cascadingDescriptor['parents']);
                $parentTrackerIdsJson = encodeJsonColumn($proposedTrackerIds);
                $parentRoleIdsJson = encodeJsonColumn($proposedRoleIds);
                $parentProjectIdsJson = encodeJsonColumn($proposedProjectIds);
                $parentNotes = sprintf('Synthetic parent mapping for cascading Jira field %s.', $jiraFieldId);
                $parentAutomationHash = computeCustomFieldAutomationStateHash(
                    null,
                    'READY_FOR_CREATION',
                    $parentName,
                    'depending_enumeration',
                    $proposedIsRequired,
                    $proposedIsFilter,
                    $proposedIsForAll,
                    false,
                    $parentPossibleValuesJson,
                    null,
                    null,
                    $parentTrackerIdsJson,
                    $parentRoleIdsJson,
                    $parentProjectIdsJson,
                    $parentNotes,
                    null,
                    null
                );

                if ($cascadingDescriptor['parents'] !== []) {
                    $cascadingParentInsert->execute([
                        'jira_field_id' => $parentMappingKey,
                        'jira_field_name' => $parentName,
                        'jira_schema_type' => 'cascading-parent',
                        'jira_project_ids' => encodeJsonColumn($jiraProjectIds),
                        'jira_issue_type_ids' => encodeJsonColumn($jiraIssueTypeIds),
                        'jira_allowed_values' => encodeJsonColumn($jiraAllowedValues),
                        'proposed_redmine_name' => $parentName,
                        'proposed_field_format' => 'depending_enumeration',
                        'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                        'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                        'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                        'proposed_is_multiple' => normalizeBooleanDatabaseValue(false),
                        'proposed_possible_values' => $parentPossibleValuesJson,
                        'proposed_value_dependencies' => null,
                        'proposed_default_value' => null,
                        'proposed_tracker_ids' => $parentTrackerIdsJson,
                        'proposed_role_ids' => $parentRoleIdsJson,
                        'proposed_project_ids' => $parentProjectIdsJson,
                        'migration_status' => 'READY_FOR_CREATION',
                        'notes' => $parentNotes,
                        'automation_hash' => $parentAutomationHash,
                    ]);

                    $cascadingParentLookup->execute(['jira_field_id' => $parentMappingKey]);
                    $parentLookupRow = $cascadingParentLookup->fetch(PDO::FETCH_ASSOC) ?: null;
                    $cascadingParentLookup->closeCursor();

                    if (isset($parentLookupRow['mapping_id']) && $parentLookupRow['mapping_id'] !== null) {
                        $parentMappingId = (int)$parentLookupRow['mapping_id'];
                    }

                    if ($parentRedmineId === null && isset($parentLookupRow['redmine_custom_field_id'])
                        && $parentLookupRow['redmine_custom_field_id'] !== null
                    ) {
                        $parentRedmineId = (int)$parentLookupRow['redmine_custom_field_id'];
                    }

                    $existingMappings[$parentMappingKey] = [
                        'jira_field_id' => $parentMappingKey,
                        'mapping_id' => $parentMappingId,
                        'redmine_custom_field_id' => $parentRedmineId,
                        'proposed_redmine_name' => $parentName,
                        'proposed_field_format' => 'depending_enumeration',
                        'proposed_possible_values' => $cascadingDescriptor['parents'],
                        'notes' => $parentNotes,
                        'migration_status' => 'READY_FOR_CREATION',
                        'proposed_tracker_ids' => $proposedTrackerIds,
                        'proposed_role_ids' => $proposedRoleIds,
                        'proposed_project_ids' => $proposedProjectIds,
                    ];

                    $cascadingParentContext = [
                        'mapping_key' => $parentMappingKey,
                        'mapping_id' => $parentMappingId,
                        'redmine_custom_field_id' => $parentRedmineId,
                        'was_created' => !$parentExisted && $parentMappingId !== null,
                        'name' => $parentName,
                        'possible_values' => $cascadingDescriptor['parents'],
                        'notes' => $parentNotes,
                    ];
                }

                if ($parentRedmineId !== null) {
                    $infoNotes[] = sprintf('Linked to existing cascading parent Redmine custom field #%d.', $parentRedmineId);
                }

                if ($parentMappingId !== null) {
                    $currentParentMappingId = $parentMappingId;
                    if ($parentRedmineId === null) {
                        $infoNotes[] = sprintf('Linked to cascading parent mapping entry #%d (pending Redmine ID).', $parentMappingId);
                    }
                }

                $infoNotes[] = sprintf(
                    'Cascading parents: %s; child options: %s',
                    json_encode($cascadingDescriptor['parents']),
                    json_encode($cascadingDescriptor['child_values'])
                );
            }
        }

        if ($classification['requires_manual_review']) {
            if ($classification['note'] !== null) {
                $manualReasons[] = $classification['note'];
            }
        } elseif ($classification['note'] !== null) {
            $infoNotes[] = $classification['note'];
        }

        if ($defaultName === null) {
            $manualReasons[] = 'Missing Jira custom field name in the staging snapshot.';
        }

        if ($classification['requires_possible_values'] && $proposedPossibleValues === null && !$isCascadingField) {
            $derivedValues = [];
            if (isset($jiraAllowedValues['mode']) && $jiraAllowedValues['mode'] === 'flat' && isset($jiraAllowedValues['values']) && is_array($jiraAllowedValues['values'])) {
                foreach ($jiraAllowedValues['values'] as $option) {
                    if (!is_array($option)) {
                        continue;
                    }

                    $rawValue = isset($option['value']) ? (string)$option['value'] : '';
                    $value = decodeAppCustomLabelString($rawValue) ?? trim($rawValue);
                    $disabled = normalizeBooleanFlag($option['disabled'] ?? null) ?? false;
                    if ($value === '' || $disabled) {
                        continue;
                    }

                    $derivedValues[$value] = $value;
                }
            } else {
                foreach ($jiraAllowedValues as $option) {
                    if (!is_array($option)) {
                        continue;
                    }

                    $rawValue = isset($option['value']) ? (string)$option['value'] : '';
                    $value = decodeAppCustomLabelString($rawValue) ?? trim($rawValue);
                    $disabled = normalizeBooleanFlag($option['disabled'] ?? null) ?? false;
                    if ($value === '' || $disabled) {
                        continue;
                    }

                    $derivedValues[$value] = $value;
                }
            }

            if ($derivedValues !== []) {
                $proposedPossibleValues = array_values($derivedValues);
            } else {
                $manualReasons[] = 'List-style Jira field requires allowed option values; Jira metadata exposes no allowedValues payload.';
            }
        }

        if (is_array($proposedPossibleValues) && $schemaType === 'object') {
            $flattened = [];
            foreach ($proposedPossibleValues as $value) {
                $parts = array_map('trim', explode(',', (string)$value));
                foreach ($parts as $part) {
                    if ($part === '') {
                        continue;
                    }

                    $flattened[$part] = $part;
                }
            }

            if ($flattened !== []) {
                $proposedPossibleValues = array_values($flattened);
                sort($proposedPossibleValues, SORT_NATURAL | SORT_FLAG_CASE);
            }
        }

        $lookupCandidates = [];
        if ($proposedName !== null) {
            $lookupCandidates[] = strtolower($proposedName);
        }
        if ($defaultName !== null) {
            $lookupCandidates[] = strtolower($defaultName);
        }

        $matchedRedmine = null;
        foreach ($lookupCandidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (isset($redmineLookup[$candidate])) {
                $matchedRedmine = $redmineLookup[$candidate];
                break;
            }
        }

        $newStatus = $currentStatus;
        $newRedmineId = $currentRedmineId;

        $existingProjectIds = [];
        $existingTrackerIds = [];

        if ($matchedRedmine !== null) {
            $newStatus = 'MATCH_FOUND';
            $newRedmineId = (int)$matchedRedmine['id'];
            $proposedName = normalizeString($matchedRedmine['name'], 255) ?? $proposedName;
            $proposedFormat = normalizeRedmineFieldFormat($matchedRedmine['field_format'] ?? $proposedFormat);
            $proposedIsRequired = normalizeBooleanFlag($matchedRedmine['is_required'] ?? null) ?? $proposedIsRequired;
            $proposedIsFilter = normalizeBooleanFlag($matchedRedmine['is_filter'] ?? null) ?? $proposedIsFilter;
            $proposedIsForAll = normalizeBooleanFlag($matchedRedmine['is_for_all'] ?? null) ?? $proposedIsForAll;
            $proposedIsMultiple = normalizeBooleanFlag($matchedRedmine['is_multiple'] ?? null) ?? $proposedIsMultiple;

            $matchedPossibleValues = decodeJsonColumn($matchedRedmine['possible_values'] ?? null);
            if (is_array($matchedPossibleValues)) {
                $proposedPossibleValues = array_values($matchedPossibleValues);
            }

            $matchedTrackerIds = decodeJsonColumn($matchedRedmine['tracker_ids'] ?? null);
            if (is_array($matchedTrackerIds)) {
                $existingTrackerIds = array_values(array_map('intval', $matchedTrackerIds));
                $proposedTrackerIds = array_values($existingTrackerIds);
            }

            $matchedRoleIds = decodeJsonColumn($matchedRedmine['role_ids'] ?? null);
            if (is_array($matchedRoleIds)) {
                $proposedRoleIds = array_values(array_map('intval', $matchedRoleIds));
            }

            $matchedProjectIds = decodeJsonColumn($matchedRedmine['project_ids'] ?? null);
            if (is_array($matchedProjectIds)) {
                $existingProjectIds = array_values(array_map('intval', $matchedProjectIds));
                $proposedProjectIds = array_values($existingProjectIds);
            }
        }

        // Normalize legacy Redmine "list"/"depending_list" formats before hashing and persistence.
        $proposedFormat = normalizeRedmineFieldFormat($proposedFormat);

        $derivedProjectIds = [];
        $missingProjectIds = [];
        foreach ($jiraProjectIds as $jiraProjectId) {
            if (isset($jiraProjectToRedmine[$jiraProjectId])) {
                $derivedProjectIds[] = $jiraProjectToRedmine[$jiraProjectId];
            } else {
                $missingProjectIds[] = $jiraProjectId;
            }
        }

        if ($derivedProjectIds !== []) {
            $proposedProjectIds = array_values(array_unique(array_merge($proposedProjectIds ?? [], $derivedProjectIds)));
            sort($proposedProjectIds);
            $proposedIsForAll = false;
        }

        if ($missingProjectIds !== []) {
            $manualReasons[] = sprintf('Missing Redmine project mapping for Jira project IDs: %s', implode(', ', $missingProjectIds));
        }

        $derivedTrackerIds = [];
        $missingIssueTypes = [];
        foreach ($jiraIssueTypeIds as $issueTypeId) {
            if (isset($jiraIssueTypeToTracker[$issueTypeId])) {
                $derivedTrackerIds[] = $jiraIssueTypeToTracker[$issueTypeId];
            } else {
                $missingIssueTypes[] = $issueTypeId;
            }
        }

        if ($derivedTrackerIds !== []) {
            $proposedTrackerIds = array_values(array_unique(array_merge($proposedTrackerIds ?? [], $derivedTrackerIds)));
            sort($proposedTrackerIds);
            $proposedIsForAll = false;
        }

        if ($missingIssueTypes !== []) {
            $manualReasons[] = sprintf('Missing Redmine tracker mapping for Jira issue type IDs: %s', implode(', ', $missingIssueTypes));
        }

        if ($matchedRedmine !== null && $manualReasons === []) {
            $desiredProjects = $proposedProjectIds ?? [];
            $desiredTrackers = $proposedTrackerIds ?? [];

            $missingProjects = array_diff($desiredProjects, $existingProjectIds);
            $missingTrackers = array_diff($desiredTrackers, $existingTrackerIds);

            if ($missingProjects !== []) {
                $infoNotes[] = sprintf(
                    'Missing %s project link(s) on Redmine custom field #%d; they will be added during the push.',
                    count($missingProjects),
                    $newRedmineId
                );
            }

            if ($missingTrackers !== []) {
                $infoNotes[] = sprintf(
                    'Missing %s tracker link(s) on Redmine custom field #%d; they will be added during the push.',
                    count($missingTrackers),
                    $newRedmineId
                );
            }

            if ($missingProjects !== [] || $missingTrackers !== []) {
                $newStatus = 'READY_FOR_UPDATE';
            }
        }

        if ($proposedProjectIds !== null && $proposedProjectIds !== []) {
            $proposedIsForAll = false;
        }

        if ($matchedRedmine === null) {
            if ($manualReasons !== []) {
                $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
                $newRedmineId = null;
            } else {
                $newStatus = 'READY_FOR_CREATION';
                $newRedmineId = null;
            }
        }

        $notesParts = $manualReasons !== [] ? $manualReasons : [];
        if ($infoNotes !== []) {
            $notesParts = array_merge($notesParts, $infoNotes);
        }
        $notes = $notesParts !== [] ? implode(' ', array_unique(array_map('trim', $notesParts))) : null;

        $proposedPossibleValuesJson = encodeJsonColumn($proposedPossibleValues);
        $proposedValueDependenciesJson = encodeJsonColumn($proposedValueDependencies);
        $proposedTrackerIdsJson = encodeJsonColumn($proposedTrackerIds);
        $proposedRoleIdsJson = encodeJsonColumn($proposedRoleIds);
        $proposedProjectIdsJson = encodeJsonColumn($proposedProjectIds);

        if (
            $cascadingParentContext !== null
            && ($cascadingParentContext['was_created'] ?? false)
            && ($cascadingParentContext['mapping_id'] ?? null) !== null
        ) {
            $parentPossibleValuesJson = encodeJsonColumn($cascadingParentContext['possible_values'] ?? null);
            $parentTrackerIdsJson = encodeJsonColumn($proposedTrackerIds);
            $parentRoleIdsJson = encodeJsonColumn($proposedRoleIds);
            $parentProjectIdsJson = encodeJsonColumn($proposedProjectIds);
            $parentAutomationHash = computeCustomFieldAutomationStateHash(
                $cascadingParentContext['redmine_custom_field_id'] ?? null,
                'READY_FOR_CREATION',
                $cascadingParentContext['name'] ?? null,
                'depending_enumeration',
                $proposedIsRequired,
                $proposedIsFilter,
                $proposedIsForAll,
                false,
                $parentPossibleValuesJson,
                null,
                null,
                $parentTrackerIdsJson,
                $parentRoleIdsJson,
                $parentProjectIdsJson,
                $cascadingParentContext['notes'] ?? null,
                null,
                null
            );

            $updateStatement->execute([
                'redmine_custom_field_id' => $cascadingParentContext['redmine_custom_field_id'] ?? null,
                'mapping_parent_custom_field_id' => null,
                'migration_status' => 'READY_FOR_CREATION',
                'notes' => $cascadingParentContext['notes'] ?? null,
                'proposed_redmine_name' => $cascadingParentContext['name'] ?? null,
                'proposed_field_format' => 'depending_enumeration',
                'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                'proposed_is_multiple' => normalizeBooleanDatabaseValue(false),
                'proposed_possible_values' => $parentPossibleValuesJson,
                'proposed_value_dependencies' => null,
                'proposed_default_value' => null,
                'proposed_tracker_ids' => $parentTrackerIdsJson,
                'proposed_role_ids' => $parentRoleIdsJson,
                'proposed_project_ids' => $parentProjectIdsJson,
                'automation_hash' => $parentAutomationHash,
                'mapping_id' => (int)$cascadingParentContext['mapping_id'],
            ]);

            $existingMappings[$cascadingParentContext['mapping_key']] = array_merge(
                $existingMappings[$cascadingParentContext['mapping_key']] ?? [],
                [
                    'jira_field_id' => $cascadingParentContext['mapping_key'],
                    'mapping_id' => $cascadingParentContext['mapping_id'],
                    'redmine_custom_field_id' => $cascadingParentContext['redmine_custom_field_id'] ?? null,
                    'proposed_role_ids' => $proposedRoleIds,
                    'proposed_project_ids' => $proposedProjectIds,
                ]
            );
        }

        $automationHash = computeCustomFieldAutomationStateHash(
            $newRedmineId,
            $newStatus,
            $proposedName,
            $proposedFormat,
            $proposedIsRequired,
            $proposedIsFilter,
            $proposedIsForAll,
            $proposedIsMultiple,
            $proposedPossibleValuesJson,
            $proposedValueDependenciesJson,
            $proposedDefaultValue,
            $proposedTrackerIdsJson,
            $proposedRoleIdsJson,
            $proposedProjectIdsJson,
            $notes,
            $currentParentMappingId,
            $currentRedmineEnumerations
        );

        $updateStatement->execute([
            'redmine_custom_field_id' => $newRedmineId,
            'mapping_parent_custom_field_id' => $currentParentMappingId,
            'migration_status' => $newStatus,
            'notes' => $notes,
            'proposed_redmine_name' => $proposedName,
            'proposed_field_format' => $proposedFormat,
            'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
            'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
            'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
            'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
            'proposed_possible_values' => $proposedPossibleValuesJson,
            'proposed_value_dependencies' => $proposedValueDependenciesJson,
            'proposed_default_value' => $proposedDefaultValue,
            'proposed_tracker_ids' => $proposedTrackerIdsJson,
            'proposed_role_ids' => $proposedRoleIdsJson,
            'proposed_project_ids' => $proposedProjectIdsJson,
            'automation_hash' => $automationHash,
            'mapping_id' => (int)$row['mapping_id'],
        ]);

        if ($automationHash === $currentAutomationHash) {
            $summary['unchanged']++;
            continue;
        }

        if ($newStatus === 'MATCH_FOUND') {
            $summary['matched']++;
        } elseif ($newStatus === 'READY_FOR_CREATION') {
            $summary['ready_for_creation']++;
        } elseif ($newStatus === 'MANUAL_INTERVENTION_REQUIRED') {
            $summary['manual_review']++;
            printf(
                "  [manual] Jira custom field %s: %s%s",
                $proposedName ?? ($defaultName ?? $jiraFieldId),
                $notes ?? 'manual review required',
                PHP_EOL
            );
        }
    }

    $summary['status_counts'] = fetchCustomFieldMigrationStatusCounts($pdo);

    $summary['object_field_stats'] = updateObjectFieldMappingProposals($pdo);

    return $summary;
}

function syncCustomFieldMappings(PDO $pdo): void
{
    $fields = fetchEligibleJiraFields($pdo);
    if ($fields === []) {
        return;
    }

    $projectIssueTypeUsageMap = loadJiraProjectIssueTypeFieldDetails($pdo);
    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO migration_mapping_custom_fields (
            jira_field_id,
            jira_project_ids,
            jira_issue_type_ids,
            jira_allowed_values,
            migration_status,
            notes,
            created_at,
            last_updated_at
        ) VALUES (
            :jira_field_id,
            :jira_project_ids,
            :jira_issue_type_ids,
            :jira_allowed_values,
            'PENDING_ANALYSIS',
            NULL,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            jira_project_ids = VALUES(jira_project_ids),
            jira_issue_type_ids = VALUES(jira_issue_type_ids),
            jira_allowed_values = VALUES(jira_allowed_values),
            last_updated_at = VALUES(last_updated_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for migration_mapping_custom_fields.');
    }

    foreach ($fields as $fieldId => $definition) {
        $usageEntries = $projectIssueTypeUsageMap[$fieldId] ?? [];

        $projectIds = [];
        $issueTypeIds = [];
        $aggregatedAllowedValues = [];

        foreach ($usageEntries as $usage) {
            if (!is_array($usage)) {
                continue;
            }

            $projectId = isset($usage['project_id']) ? trim((string)$usage['project_id']) : '';
            $issueTypeId = isset($usage['issue_type_id']) ? trim((string)$usage['issue_type_id']) : '';

            if ($projectId !== '') {
                $projectIds[$projectId] = $projectId;
            }

            if ($issueTypeId !== '') {
                $issueTypeIds[$issueTypeId] = $issueTypeId;
            }

            $aggregatedAllowedValues = mergeAllowedValuesPayloads(
                $aggregatedAllowedValues,
                isset($usage['allowed_values']) && is_array($usage['allowed_values']) ? $usage['allowed_values'] : []
            );
        }

        $projectIds = array_values($projectIds);
        $issueTypeIds = array_values($issueTypeIds);

        sort($projectIds);
        sort($issueTypeIds);

        $normalizedAllowedValues = normalizeAllowedValuesPayload($aggregatedAllowedValues);

        $insertStatement->execute([
            'jira_field_id' => $fieldId,
            'jira_project_ids' => encodeJsonColumn($projectIds),
            'jira_issue_type_ids' => encodeJsonColumn($issueTypeIds),
            'jira_allowed_values' => encodeJsonColumn($normalizedAllowedValues),
        ]);
    }

    purgeOrphanedCustomFieldMappings($pdo);
}

function refreshCustomFieldMetadata(PDO $pdo): void
{
    $sql = <<<SQL
        UPDATE migration_mapping_custom_fields map
        INNER JOIN staging_jira_fields jf ON jf.id = map.jira_field_id
        SET
            map.jira_field_name = jf.name,
            map.jira_schema_type = jf.schema_type,
            map.jira_schema_custom = jf.schema_custom
        WHERE jf.is_custom = 1
          AND jf.field_category IN ('jira_custom', 'app_custom')
    SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to refresh Jira custom field metadata in migration_mapping_custom_fields: ' . $exception->getMessage(), 0, $exception);
    }
}

function logCustomFieldMappingStats(PDO $pdo): void
{
    $sql = <<<SQL
        SELECT
            COUNT(*) AS total_mappings,
            SUM(CASE WHEN jf.schema_type IN ('option', 'array', 'option-with-child') THEN 1 ELSE 0 END) AS list_like,
            SUM(CASE WHEN jf.schema_type = 'option-with-child' THEN 1 ELSE 0 END) AS cascading,
            SUM(CASE WHEN map.jira_allowed_values IS NOT NULL AND JSON_LENGTH(map.jira_allowed_values) > 0 THEN 1 ELSE 0 END) AS with_allowed_values
        FROM migration_mapping_custom_fields map
        LEFT JOIN staging_jira_fields jf ON jf.id = map.jira_field_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to compute custom field mapping statistics: ' . $exception->getMessage(), 0, $exception);
    }

    $row = $statement !== false ? $statement->fetch(PDO::FETCH_ASSOC) : null;

    if ($row === false || $row === null) {
        printf("  No custom field mappings found.%s", PHP_EOL);
        return;
    }

    $total = (int)($row['total_mappings'] ?? 0);
    $listLike = (int)($row['list_like'] ?? 0);
    $cascading = (int)($row['cascading'] ?? 0);
    $withAllowedValues = (int)($row['with_allowed_values'] ?? 0);

    printf(
        "  Custom field mappings: %d total | %d list-like | %d cascading | %d with allowed values.%s",
        $total,
        $listLike,
        $cascading,
        $withAllowedValues,
        PHP_EOL
    );
}

/**
 * @return array{evaluated: int, created: int, updated: int, unchanged: int, missing_samples: int}
 */
function updateObjectFieldMappingProposals(PDO $pdo): array
{
    $definitions = loadObjectFieldDefinitionsForCustomFields($pdo);
    if ($definitions === []) {
        return [
            'evaluated' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing_samples' => 0,
        ];
    }

    $statsStatement = $pdo->prepare(<<<SQL
        SELECT
            COUNT(*) AS kv_count,
            COUNT(DISTINCT issue_key) AS issue_count,
            MAX(ordinal) AS max_ordinal
        FROM staging_jira_object_kv
        WHERE field_id = :field_id
    SQL);

    $pathStatement = $pdo->prepare(<<<SQL
        SELECT path, COUNT(*) AS path_count, MAX(ordinal) AS max_ordinal
        FROM staging_jira_object_kv
        WHERE field_id = :field_id
        GROUP BY path
        ORDER BY path_count DESC
        LIMIT 10
    SQL);

    $typeStatement = $pdo->prepare(<<<SQL
        SELECT value_type, COUNT(*) AS type_count
        FROM staging_jira_object_kv
        WHERE field_id = :field_id
        GROUP BY value_type
        ORDER BY type_count DESC
        LIMIT 1
    SQL);

    $existingStatement = $pdo->prepare(<<<SQL
        SELECT proposal_hash
        FROM migration_mapping_custom_object
        WHERE jira_field_id = :field_id AND path <=> :path AND source = 'inferred'
        LIMIT 1
    SQL);

    $upsertStatement = $pdo->prepare(<<<SQL
        INSERT INTO migration_mapping_custom_object (
            jira_field_id,
            jira_field_name,
            jira_schema_custom,
            path,
            target_field_name,
            target_field_format,
            target_is_multiple,
            value_source_path,
            key_source_path,
            source,
            notes,
            proposal_hash,
            created_at,
            last_updated_at
        ) VALUES (
            :jira_field_id,
            :jira_field_name,
            :jira_schema_custom,
            :path,
            :target_field_name,
            :target_field_format,
            :target_is_multiple,
            :value_source_path,
            :key_source_path,
            'inferred',
            :notes,
            :proposal_hash,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            jira_field_name = VALUES(jira_field_name),
            jira_schema_custom = VALUES(jira_schema_custom),
            target_field_name = VALUES(target_field_name),
            target_field_format = VALUES(target_field_format),
            target_is_multiple = VALUES(target_is_multiple),
            value_source_path = VALUES(value_source_path),
            key_source_path = VALUES(key_source_path),
            notes = VALUES(notes),
            proposal_hash = VALUES(proposal_hash),
            last_updated_at = VALUES(last_updated_at)
    SQL);

    foreach ([$statsStatement, $pathStatement, $typeStatement, $existingStatement, $upsertStatement] as $statement) {
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare object field mapping statements.');
        }
    }

    $summary = [
        'evaluated' => 0,
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'missing_samples' => 0,
    ];

    foreach ($definitions as $fieldId => $definition) {
        $summary['evaluated']++;

        $statsStatement->execute(['field_id' => $fieldId]);
        $statsRow = $statsStatement->fetch(PDO::FETCH_ASSOC) ?: ['kv_count' => 0, 'issue_count' => 0, 'max_ordinal' => 0];
        $kvCount = (int)$statsRow['kv_count'];
        $issueCount = (int)$statsRow['issue_count'];
        $maxOrdinal = (int)($statsRow['max_ordinal'] ?? 0);

        $pathStatement->execute(['field_id' => $fieldId]);
        $pathRows = $pathStatement->fetchAll(PDO::FETCH_ASSOC);
        $paths = array_map(static fn(array $row) => (string)$row['path'], $pathRows);

        $typeStatement->execute(['field_id' => $fieldId]);
        $typeRow = $typeStatement->fetch(PDO::FETCH_ASSOC) ?: ['value_type' => 'string'];
        $dominantType = isset($typeRow['value_type']) ? (string)$typeRow['value_type'] : 'string';

        $isArray = $maxOrdinal > 0;
        $enumLike = in_array('id', $paths, true) && in_array('name', $paths, true);
        $dominantPath = $paths !== [] ? $paths[0] : null;
        $path = $dominantPath;

        if ($kvCount === 0) {
            $summary['missing_samples']++;
        }

        if ($enumLike) {
            $targetFormat = 'key_value_list';
            $valueSourcePath = 'name';
            $keySourcePath = 'id';
        } else {
            $valueSourcePath = $dominantPath;
            $keySourcePath = null;
            $targetFormat = match ($dominantType) {
                'boolean' => 'bool',
                'number' => 'int',
                default => 'text',
            };
        }

        $notes = [];
        $notes[] = sprintf('Samples: %d rows across %d issue(s).', $kvCount, $issueCount);
        if ($enumLike) {
            $notes[] = 'Detected id/name object pattern.';
        }
        if ($isArray) {
            $notes[] = 'Appears as an array field (multiple values).';
        }
        if ($valueSourcePath === null) {
            $notes[] = 'No dominant path detected; inspect staging_jira_object_kv manually.';
        }

        $proposedName = $definition['name'] ?? $fieldId;
        $proposalHash = computeObjectProposalHash(
            $fieldId,
            $definition['schema_custom'] ?? null,
            $path,
            $proposedName,
            $targetFormat,
            $isArray,
            $valueSourcePath,
            $keySourcePath,
            implode(' ', $notes)
        );

        $existingStatement->execute(['field_id' => $fieldId, 'path' => $path]);
        $existingHash = $existingStatement->fetchColumn();

        $upsertStatement->execute([
            'jira_field_id' => $fieldId,
            'jira_field_name' => $definition['name'] ?? null,
            'jira_schema_custom' => $definition['schema_custom'] ?? null,
            'path' => $path,
            'target_field_name' => $proposedName,
            'target_field_format' => $targetFormat,
            'target_is_multiple' => normalizeBooleanDatabaseValue($isArray),
            'value_source_path' => $valueSourcePath,
            'key_source_path' => $keySourcePath,
            'notes' => implode(' ', $notes),
            'proposal_hash' => $proposalHash,
        ]);

        if ($existingHash === false) {
            $summary['created']++;
        } elseif ($existingHash === $proposalHash) {
            $summary['unchanged']++;
        } else {
            $summary['updated']++;
        }
    }

    return $summary;
}

/**
 * @return array<string, array{name: ?string, schema_custom: ?string}>
 */
function loadObjectFieldDefinitionsForCustomFields(PDO $pdo): array
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

    /** @var array<int, array{id: string, name?: ?string, schema_custom?: ?string}> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $definitions = [];
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

function computeObjectProposalHash(
    string $fieldId,
    ?string $schemaCustom,
    ?string $path,
    ?string $targetFieldName,
    string $targetFieldFormat,
    bool $targetIsMultiple,
    ?string $valueSourcePath,
    ?string $keySourcePath,
    string $notes
): string {
    // Notes are intentionally excluded from the automation hash to avoid noise from manual iterations.
    $payload = [
        'field_id' => $fieldId,
        'schema_custom' => $schemaCustom,
        'path' => $path,
        'target_field_name' => $targetFieldName,
        'target_field_format' => $targetFieldFormat,
        'target_is_multiple' => $targetIsMultiple,
        'value_source_path' => $valueSourcePath,
        'key_source_path' => $keySourcePath,
        'notes' => $notes,
    ];

    try {
        return sha1(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to compute object proposal hash: ' . $exception->getMessage(), 0, $exception);
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function buildRedmineCustomFieldLookup(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            id,
            name,
            customized_type,
            field_format,
            is_required,
            is_filter,
            is_for_all,
            is_multiple,
            possible_values,
            default_value,
            tracker_ids,
            role_ids,
            project_ids
        FROM staging_redmine_custom_fields
        WHERE customized_type = 'issue'
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build Redmine custom field lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $lookup = [];

    foreach ($rows as $row) {
        if (!isset($row['name'])) {
            continue;
        }

        $name = normalizeString($row['name'], 255);
        if ($name === null) {
            continue;
        }

        $lookup[strtolower($name)] = $row;
    }

    return $lookup;
}

/**
 * Bouwt een lookup voor objectvelden op basis van migration_mapping_custom_object.
 *
 * @return array<string, bool>  [jira_field_id => target_is_multiple]
 */
function buildObjectFieldMultiplicityLookup(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT jira_field_id, target_is_multiple
        FROM migration_mapping_custom_object
        WHERE source = 'inferred'
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build object field multiplicity lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $lookup = [];

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $fieldId = isset($row['jira_field_id']) ? trim((string)$row['jira_field_id']) : '';
        if ($fieldId === '') {
            continue;
        }

        // target_is_multiple is een TINYINT(1) of vergelijkbaar; normaliseer naar bool
        $rawFlag = $row['target_is_multiple'] ?? null;
        $flag = normalizeBooleanFlag($rawFlag);

        // Alleen opnemen als we een duidelijke waarde hebben
        if ($flag !== null) {
            $lookup[$fieldId] = $flag;
        }
    }

    $statement->closeCursor();

    return $lookup;
}


/**
 * @return array<int, array<string, mixed>>
 */
function fetchCustomFieldMappingsForTransform(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_field_id,
            map.jira_field_name,
            map.jira_schema_type,
            map.jira_schema_custom,
            jf.field_category AS jira_field_category,
            map.jira_project_ids,
            map.jira_issue_type_ids,
            map.jira_allowed_values,
            map.redmine_custom_field_id,
            map.mapping_parent_custom_field_id,
            map.redmine_custom_field_enumerations,
            map.migration_status,
            map.notes,
            map.proposed_redmine_name,
            map.proposed_field_format,
            map.proposed_is_required,
            map.proposed_is_filter,
            map.proposed_is_for_all,
            map.proposed_is_multiple,
            map.proposed_possible_values,
            map.proposed_value_dependencies,
            map.proposed_default_value,
            map.proposed_tracker_ids,
            map.proposed_role_ids,
            map.proposed_project_ids,
            map.automation_hash,
            usage_stats.total_issues AS usage_total_issues,
            usage_stats.issues_with_value AS usage_issues_with_value,
            usage_stats.issues_with_non_empty_value AS usage_issues_with_non_empty_value,
            usage_stats.last_counted_at AS usage_last_counted_at
        FROM migration_mapping_custom_fields map
        LEFT JOIN staging_jira_fields jf
            ON jf.id = map.jira_field_id
        LEFT JOIN staging_jira_field_usage usage_stats ON usage_stats.field_id = map.jira_field_id
            AND usage_stats.usage_scope = :usage_scope
        ORDER BY map.jira_field_name IS NULL, map.jira_field_name, map.jira_field_id
    SQL;

    try {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            return [];
        }

        $statement->execute(['usage_scope' => FIELD_USAGE_SCOPE_ISSUE]);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch migration mapping rows for custom fields: ' . $exception->getMessage(), 0, $exception);
    }

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function deriveIsMultipleFromSchemaType(?string $schemaType): ?bool
{
    $normalizedType = $schemaType !== null ? strtolower($schemaType) : null;

    return match ($normalizedType) {
        'array', 'object' => true,
        'any', 'team', 'option', 'sd-customerrequesttype', 'option-with-child', 'option2', 'sd-approvals' => false,
        default => null,
    };
}

function normalizeRedmineFieldFormat(?string $fieldFormat): ?string
{
    if ($fieldFormat === null) {
        return null;
    }

    $normalized = strtolower(trim($fieldFormat));
    if ($normalized === '') {
        return null;
    }

    return match ($normalized) {
        'list' => 'enumeration',
        'depending_list' => 'depending_enumeration',
        default => $normalized,
    };
}

/**
 * @return array{field_format: ?string, is_multiple: ?bool, requires_possible_values: bool, requires_manual_review: bool, note: ?string}
 */
function classifyJiraCustomField(?string $schemaType, ?string $schemaCustom): array
{
    $normalizedCustom = $schemaCustom !== null ? strtolower($schemaCustom) : null;
    $normalizedType = $schemaType !== null ? strtolower($schemaType) : null;

    $result = [
        'field_format' => null,
        'is_multiple' => null,
        'requires_possible_values' => false,
        'requires_manual_review' => false,
        'note' => null,
        'is_cascading' => false,
    ];

    if ($normalizedCustom !== null) {
        if (str_contains($normalizedCustom, ':textarea')) {
            $result['field_format'] = 'text';
            return $result;
        }

        if (str_contains($normalizedCustom, ':textfield')) {
            $result['field_format'] = 'string';
            return $result;
        }

        if (str_contains($normalizedCustom, ':datepicker')) {
            $result['field_format'] = 'date';
            return $result;
        }

        if (str_contains($normalizedCustom, ':datetime')) {
            $result['field_format'] = 'datetime';
            return $result;
        }

        if (str_contains($normalizedCustom, ':float')) {
            $result['field_format'] = 'float';
            return $result;
        }

        if (str_contains($normalizedCustom, ':grouppicker') || str_contains($normalizedCustom, ':userpicker')) {
            $result['field_format'] = null;
            $result['requires_manual_review'] = true;
            $result['note'] = 'User and group pickers require manual mapping to Redmine user/group custom fields.';
            return $result;
        }

        if (str_contains($normalizedCustom, ':labels') || str_contains($normalizedCustom, ':multiselect') || str_contains($normalizedCustom, ':select') || str_contains($normalizedCustom, ':checkboxes') || str_contains($normalizedCustom, ':radiobuttons')) {
            $result['field_format'] = 'enumeration';
            $result['requires_possible_values'] = true;
            if (str_contains($normalizedCustom, ':multiselect') || str_contains($normalizedCustom, ':checkboxes')) {
                $result['is_multiple'] = true;
            }
            return $result;
        }

        if (str_contains($normalizedCustom, ':cascadingselect')) {
            $result['field_format'] = 'depending_enumeration';
            $result['requires_possible_values'] = true;
            $result['is_cascading'] = true;
            $result['note'] = 'Requires the redmine_depending_custom_fields plugin to migrate cascading selects.';
            return $result;
        }

        if (str_contains($normalizedCustom, ':url')) {
            $result['field_format'] = 'string';
            $result['note'] = 'Review whether the Redmine field should use the URL format or remain a plain string.';
            return $result;
        }
    }

        if ($normalizedType !== null) {
            switch ($normalizedType) {
                case 'object':
                    $result['field_format'] = 'enumeration';
                    $result['requires_possible_values'] = true;
                    $result['note'] = 'Object-type Jira field; using allowed values from Jira create metadata.';
                    break;
                case 'team':
                case 'sd-customerrequesttype':
                    $result['field_format'] = 'enumeration';
                    $result['requires_possible_values'] = true;
                    $result['note'] = 'App/Service Desk selector; will derive option labels from Jira allowed values. Consider a key/value list if you need stable IDs.';
                    break;
                case 'option':
                case 'option2':
                    $result['field_format'] = 'enumeration';
                    $result['requires_possible_values'] = true;
                    $result['note'] = 'Single-select option field; populating Redmine enumeration from Jira allowed values.';
                    break;
                case 'sd-approvals':
                    $result['field_format'] = 'text';
                    $result['note'] = 'Service Desk approvals payload; defaulting to text. Consider manual mapping if approvals must be preserved.';
                    break;
            case 'any':
                $result['field_format'] = 'text';
                $result['note'] = 'Generic "any" schema from Jira; defaulting to text capture.';
                break;
            case 'string':
                $result['field_format'] = 'string';
                break;
            case 'number':
                $result['field_format'] = 'float';
                break;
            case 'datetime':
                $result['field_format'] = 'datetime';
                break;
            case 'date':
                $result['field_format'] = 'date';
                break;
                case 'option-with-child':
                    $result['field_format'] = 'depending_enumeration';
                    $result['requires_possible_values'] = true;
                    $result['is_cascading'] = true;
                    $result['note'] = 'Requires the redmine_depending_custom_fields plugin to migrate cascading selects.';
                    break;
                case 'array':
                    $result['field_format'] = 'enumeration';
                    $result['requires_possible_values'] = true;
                    $result['note'] = 'Array-type Jira field mapped to a Redmine enumeration; deriving options from Jira allowed values.';
                    break;
            default:
                $result['requires_manual_review'] = true;
                $result['note'] = sprintf('Unhandled Jira schema type "%s"; review manually.', $schemaType ?? 'unknown');
        }
    } else {
        $result['requires_manual_review'] = true;
        $result['note'] = 'Unable to detect Jira schema type; review manually.';
    }

    $schemaTypeIsMultiple = deriveIsMultipleFromSchemaType($schemaType);
    if ($result['is_multiple'] === null && $schemaTypeIsMultiple !== null) {
        $result['is_multiple'] = $schemaTypeIsMultiple;
    }

    return $result;
}

function computeCustomFieldAutomationStateHash(
    ?int $redmineCustomFieldId,
    string $migrationStatus,
    ?string $proposedName,
    ?string $proposedFieldFormat,
    ?bool $proposedIsRequired,
    ?bool $proposedIsFilter,
    ?bool $proposedIsForAll,
    ?bool $proposedIsMultiple,
    ?string $proposedPossibleValues,
    ?string $proposedValueDependencies,
    ?string $proposedDefaultValue,
    ?string $proposedTrackerIds,
    ?string $proposedRoleIds,
    ?string $proposedProjectIds,
    ?string $notes,
    ?int $mappingParentCustomFieldId = null,
    ?string $redmineCustomFieldEnumerations = null
): string {
    $proposedFieldFormat = normalizeRedmineFieldFormat($proposedFieldFormat);

    // Notes are intentionally excluded from the automation hash to avoid noise from manual iterations.
    $payload = [
        'redmine_custom_field_id' => $redmineCustomFieldId,
        'migration_status' => $migrationStatus,
        'proposed_redmine_name' => normalizeStringForHash($proposedName),
        'proposed_field_format' => normalizeStringForHash($proposedFieldFormat),
        'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
        'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
        'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
        'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
        'proposed_possible_values' => normalizeJsonForHash($proposedPossibleValues),
        'proposed_value_dependencies' => normalizeJsonForHash($proposedValueDependencies),
        'proposed_default_value' => normalizeStringForHash($proposedDefaultValue),
        'proposed_tracker_ids' => normalizeJsonForHash($proposedTrackerIds),
        'proposed_role_ids' => normalizeJsonForHash($proposedRoleIds),
        'proposed_project_ids' => normalizeJsonForHash($proposedProjectIds),
        'mapping_parent_custom_field_id' => $mappingParentCustomFieldId,
        'redmine_custom_field_enumerations' => normalizeJsonForHash($redmineCustomFieldEnumerations),
    ];

    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to compute automation hash for custom field mapping: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', $json);
}

function normalizeJsonForHash(?string $json): ?string
{
    if ($json === null) {
        return null;
    }

    $trimmed = trim($json);
    if ($trimmed === '') {
        return null;
    }

    try {
        $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return $trimmed;
    }

    $normalized = normalizeStructureForHash($decoded);

    try {
        return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        return $trimmed;
    }
}

/**
 * @param mixed $value
 * @return mixed
 */
function normalizeStructureForHash(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $isSequential = array_keys($value) === range(0, count($value) - 1);

    if ($isSequential) {
        $normalized = array_map('normalizeStructureForHash', $value);

        $allScalar = array_reduce(
            $normalized,
            static fn(bool $carry, mixed $item): bool => $carry && !is_array($item),
            true
        );

        if ($allScalar) {
            sort($normalized, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $normalized;
    }

    $normalized = [];
    foreach ($value as $key => $item) {
        $normalized[(string)$key] = normalizeStructureForHash($item);
    }

    ksort($normalized, SORT_NATURAL | SORT_FLAG_CASE);

    return $normalized;
}

function normalizeStringForHash(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    return $trimmed;
}

function fetchCustomFieldMigrationStatusCounts(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT migration_status, COUNT(*) AS total FROM migration_mapping_custom_fields GROUP BY migration_status');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to compute custom field migration breakdown: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $results = [];

    foreach ($rows as $row) {
        if (!isset($row['migration_status'])) {
            continue;
        }

        $status = (string)$row['migration_status'];
        $results[$status] = isset($row['total']) ? (int)$row['total'] : 0;
    }

    return $results;
}

/**
 * @return array<int, array{name: string, project_ids: array<int, int>, tracker_ids: array<int, int>}>
 */
function loadRedmineCustomFieldAssociationSnapshot(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            redmine_custom_field_id,
            COALESCE(proposed_redmine_name, jira_field_name, jira_field_id) AS label,
            proposed_project_ids,
            proposed_tracker_ids
        FROM migration_mapping_custom_fields
        WHERE redmine_custom_field_id IS NOT NULL
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to load Redmine custom field associations: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $snapshot = [];

    foreach ($rows as $row) {
        $id = normalizeInteger($row['redmine_custom_field_id'] ?? null);
        if ($id === null) {
            continue;
        }

        $snapshot[$id] = [
            'name' => isset($row['label']) ? (string)$row['label'] : sprintf('Custom field #%d', $id),
            'project_ids' => normalizeIntegerListColumn($row['proposed_project_ids'] ?? null),
            'tracker_ids' => normalizeIntegerListColumn($row['proposed_tracker_ids'] ?? null),
        ];
    }

    return $snapshot;
}

/**
 * @return array<int, int>
 */
function normalizeIntegerListColumn(mixed $value): array
{
    $decoded = decodeJsonColumn($value);
    if (!is_array($decoded)) {
        return [];
    }

    $ids = [];
    foreach ($decoded as $item) {
        $normalized = normalizeInteger($item);
        if ($normalized !== null) {
            $ids[] = $normalized;
        }
    }

    $ids = array_values(array_unique($ids));
    sort($ids);

    return $ids;
}

/**
 * @param array<int, int> ...$lists
 * @return array<int, int>
 */
function mergeIntegerLists(array ...$lists): array
{
    $merged = [];
    foreach ($lists as $list) {
        foreach ($list as $value) {
            $merged[] = (int)$value;
        }
    }

    $merged = array_values(array_unique($merged));
    sort($merged);

    return $merged;
}

/**
 * @return array{plan: array<int, array<string, mixed>>, warnings: array<int, string>}
 */
function collectCustomFieldUpdatePlan(PDO $pdo): array
{
    $snapshot = loadRedmineCustomFieldAssociationSnapshot($pdo);

    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_field_id,
            map.jira_field_name,
            map.redmine_custom_field_id,
            map.mapping_parent_custom_field_id,
            parent.redmine_custom_field_id AS parent_redmine_custom_field_id,
            map.redmine_custom_field_enumerations,
            parent.redmine_custom_field_enumerations AS parent_redmine_custom_field_enumerations,
            map.proposed_redmine_name,
            map.proposed_field_format,
            map.proposed_is_required,
            map.proposed_is_filter,
            map.proposed_is_for_all,
            map.proposed_is_multiple,
            map.proposed_possible_values,
            map.proposed_value_dependencies,
            map.proposed_default_value,
            map.proposed_tracker_ids,
            map.proposed_role_ids,
            map.proposed_project_ids,
            map.migration_status,
            map.notes
        FROM migration_mapping_custom_fields map
        LEFT JOIN migration_mapping_custom_fields parent
            ON parent.mapping_id = map.mapping_parent_custom_field_id
        WHERE
            map.redmine_custom_field_id IS NOT NULL
            AND map.migration_status IN ('READY_FOR_UPDATE', 'CREATION_SUCCESS')
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to collect the custom field update plan: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return ['plan' => [], 'warnings' => []];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $plan = [];
    $warnings = [];

    foreach ($rows as $row) {
        $redmineId = normalizeInteger($row['redmine_custom_field_id'] ?? null);
        $parentMappingId = normalizeInteger($row['mapping_parent_custom_field_id'] ?? null);
        $parentRedmineId = normalizeInteger($row['parent_redmine_custom_field_id'] ?? null);

        if ($redmineId === null) {
            continue;
        }

        if (!isset($snapshot[$redmineId])) {
            $warnings[] = sprintf('Missing Redmine snapshot entry for custom field #%d; re-run the redmine phase.', $redmineId);
            continue;
        }

        $proposedProjects = normalizeIntegerListColumn($row['proposed_project_ids'] ?? null);
        $proposedTrackers = normalizeIntegerListColumn($row['proposed_tracker_ids'] ?? null);

        $existingProjects = $snapshot[$redmineId]['project_ids'];
        $existingTrackers = $snapshot[$redmineId]['tracker_ids'];

        $mergedProjects = mergeIntegerLists($existingProjects, $proposedProjects);
        $mergedTrackers = mergeIntegerLists($existingTrackers, $proposedTrackers);

        $missingProjects = array_diff($mergedProjects, $existingProjects);
        $missingTrackers = array_diff($mergedTrackers, $existingTrackers);

        $parentProjects = [];
        $parentTrackers = [];
        $parentMissingProjects = [];
        $parentMissingTrackers = [];

        if ($parentMappingId !== null) {
            if ($parentRedmineId === null) {
                $warnings[] = sprintf(
                    'Missing Redmine ID for parent mapping #%d; ensure the cascading parent was created.',
                    $parentMappingId
                );
                continue;
            }

            if (!isset($snapshot[$parentRedmineId])) {
                $warnings[] = sprintf(
                    'Missing Redmine snapshot entry for parent custom field #%d; re-run the redmine phase.',
                    $parentRedmineId
                );
                continue;
            }

            $parentProjects = mergeIntegerLists($snapshot[$parentRedmineId]['project_ids'], $mergedProjects);
            $parentTrackers = mergeIntegerLists($snapshot[$parentRedmineId]['tracker_ids'], $mergedTrackers);
            $parentMissingProjects = array_diff($parentProjects, $snapshot[$parentRedmineId]['project_ids']);
            $parentMissingTrackers = array_diff($parentTrackers, $snapshot[$parentRedmineId]['tracker_ids']);
        }

        if ($missingProjects === [] && $missingTrackers === [] && $parentMissingProjects === [] && $parentMissingTrackers === []) {
            continue;
        }

        $plan[] = [
            'mapping_id' => (int)$row['mapping_id'],
            'jira_field_id' => (string)$row['jira_field_id'],
            'jira_field_name' => $row['jira_field_name'] ?? null,
            'redmine_custom_field_id' => $redmineId,
            'mapping_parent_custom_field_id' => $parentMappingId,
            'parent_redmine_custom_field_id' => $parentRedmineId,
            'proposed_redmine_name' => $row['proposed_redmine_name'] ?? null,
            'proposed_field_format' => $row['proposed_field_format'] ?? null,
            'proposed_is_required' => normalizeBooleanFlag($row['proposed_is_required'] ?? null),
            'proposed_is_filter' => normalizeBooleanFlag($row['proposed_is_filter'] ?? null),
            'proposed_is_for_all' => normalizeBooleanFlag($row['proposed_is_for_all'] ?? null),
            'proposed_is_multiple' => normalizeBooleanFlag($row['proposed_is_multiple'] ?? null),
            'proposed_possible_values' => $row['proposed_possible_values'] ?? null,
            'proposed_value_dependencies' => $row['proposed_value_dependencies'] ?? null,
            'proposed_default_value' => $row['proposed_default_value'] ?? null,
            'proposed_role_ids' => $row['proposed_role_ids'] ?? null,
            'redmine_custom_field_enumerations' => $row['redmine_custom_field_enumerations'] ?? null,
            'parent_redmine_custom_field_enumerations' => $row['parent_redmine_custom_field_enumerations'] ?? null,
            'target_project_ids' => $mergedProjects,
            'target_tracker_ids' => $mergedTrackers,
            'parent_project_ids' => $parentProjects,
            'parent_tracker_ids' => $parentTrackers,
            'missing_projects' => $missingProjects,
            'missing_trackers' => $missingTrackers,
            'parent_missing_projects' => $parentMissingProjects,
            'parent_missing_trackers' => $parentMissingTrackers,
            'current_status' => (string)$row['migration_status'],
            'notes' => $row['notes'] ?? null,
        ];
    }

    return ['plan' => $plan, 'warnings' => $warnings];
}

/**
 * @param array<int, array<string, mixed>> $plan
 */
function renderCustomFieldUpdatePlan(array $plan, bool $useExtendedApi): void
{
    if ($plan === []) {
        printf("  No existing Redmine custom fields require association updates.%s", PHP_EOL);
        return;
    }

    printf(
        '  %d custom field(s) %s project/tracker association updates.%s',
        count($plan),
        $useExtendedApi ? 'will receive' : 'require',
        PHP_EOL
    );

    foreach ($plan as $item) {
        $label = $item['jira_field_name'] ?? $item['jira_field_id'];
        $projectText = $item['target_project_ids'] === [] ? '[none]' : json_encode($item['target_project_ids']);
        $trackerText = $item['target_tracker_ids'] === [] ? '[none]' : json_encode($item['target_tracker_ids']);
        $missingProjects = $item['missing_projects'] ?? [];
        $missingTrackers = $item['missing_trackers'] ?? [];

        printf(
            '  - Jira custom field %s (Redmine #%d): projects %s; trackers %s%s',
            $label ?? '[unknown]',
            $item['redmine_custom_field_id'],
            $projectText,
            $trackerText,
            PHP_EOL
        );

        if ($missingProjects !== [] || $missingTrackers !== []) {
            if ($missingProjects !== []) {
                printf('    Missing projects to add: %s%s', json_encode(array_values($missingProjects)), PHP_EOL);
            }
            if ($missingTrackers !== []) {
                printf('    Missing trackers to add: %s%s', json_encode(array_values($missingTrackers)), PHP_EOL);
            }
        }

        if (isset($item['mapping_parent_custom_field_id']) && $item['mapping_parent_custom_field_id'] !== null) {
            $parentProjects = $item['parent_project_ids'] === [] ? '[none]' : json_encode($item['parent_project_ids']);
            $parentTrackers = $item['parent_tracker_ids'] === [] ? '[none]' : json_encode($item['parent_tracker_ids']);
            $parentRedmineLabel = $item['parent_redmine_custom_field_id'] !== null
                ? sprintf('#%d', $item['parent_redmine_custom_field_id'])
                : '[pending]';

            printf(
                '    Parent custom field %s (mapping #%d): projects %s; trackers %s%s',
                $parentRedmineLabel,
                $item['mapping_parent_custom_field_id'],
                $parentProjects,
                $parentTrackers,
                PHP_EOL
            );
        }
    }
}
function runCustomFieldPushPhase(PDO $pdo, bool $confirmPush, bool $isDryRun, array $redmineConfig, bool $useExtendedApi): void
{
    $pendingFields = fetchCustomFieldsReadyForCreation($pdo);
    usort(
        $pendingFields,
        static function (array $left, array $right): int {
            $leftIsParent = isset($left['jira_field_id']) && str_starts_with((string)$left['jira_field_id'], 'cascading_parent:');
            $rightIsParent = isset($right['jira_field_id']) && str_starts_with((string)$right['jira_field_id'], 'cascading_parent:');

            if ($leftIsParent !== $rightIsParent) {
                return $leftIsParent ? -1 : 1;
            }

            $leftDepending = normalizeRedmineFieldFormat($left['proposed_field_format'] ?? null) === 'depending_enumeration';
            $rightDepending = normalizeRedmineFieldFormat($right['proposed_field_format'] ?? null) === 'depending_enumeration';

            if ($leftDepending !== $rightDepending) {
                return $leftDepending ? 1 : -1;
            }

            return strcmp((string)($left['jira_field_name'] ?? $left['jira_field_id']), (string)($right['jira_field_name'] ?? $right['jira_field_id']));
        }
    );
    $pendingCount = count($pendingFields);

    if ($useExtendedApi) {
        printf("[%s] Starting push phase (Redmine extended API)...%s", formatCurrentTimestamp(), PHP_EOL);

        if ($pendingCount === 0) {
            printf("  No Jira custom fields are marked as READY_FOR_CREATION.%s", PHP_EOL);
            if ($isDryRun) {
                printf("  --dry-run flag enabled: no API calls will be made.%s", PHP_EOL);
            }
            if ($confirmPush) {
                printf("  --confirm-push provided but there is nothing to process.%s", PHP_EOL);
            }
        }

        $redmineClient = createRedmineClient($redmineConfig);
        $extendedApiPrefix = resolveExtendedApiPrefix($redmineConfig);
        verifyExtendedApiAvailability($redmineClient, $extendedApiPrefix, 'custom_fields.json');

        $endpoint = buildExtendedApiPath($extendedApiPrefix, 'custom_fields.json');

        printf("  %d custom field(s) queued for creation via the extended API.%s", $pendingCount, PHP_EOL);
        foreach ($pendingFields as $field) {
            $jiraName = $field['jira_field_name'] ?? null;
            $jiraId = (string)$field['jira_field_id'];
            $proposedName = $field['proposed_redmine_name'] ?? null;
            $proposedFormat = normalizeRedmineFieldFormat($field['proposed_field_format'] ?? null);
            $proposedIsRequired = normalizeBooleanFlag($field['proposed_is_required'] ?? null) ?? false;
            $proposedIsFilter = normalizeBooleanFlag($field['proposed_is_filter'] ?? null) ?? true;
            $proposedIsForAll = normalizeBooleanFlag($field['proposed_is_for_all'] ?? null) ?? true;
            $proposedIsMultiple = normalizeBooleanFlag($field['proposed_is_multiple'] ?? null) ?? false;
            $proposedPossibleValues = decodeJsonColumn($field['proposed_possible_values'] ?? null);
            $proposedValueDependencies = decodeJsonColumn($field['proposed_value_dependencies'] ?? null);
            $proposedDefaultValue = $field['proposed_default_value'] ?? null;
            $proposedTrackerIds = decodeJsonColumn($field['proposed_tracker_ids'] ?? null);
            $proposedRoleIds = decodeJsonColumn($field['proposed_role_ids'] ?? null);
            $proposedProjectIds = decodeJsonColumn($field['proposed_project_ids'] ?? null);
            $notes = $field['notes'] ?? null;
            $jiraAllowedValuesPreview = decodeJsonColumn($field['jira_allowed_values'] ?? null);
            $jiraAllowedValuesPreview = is_array($jiraAllowedValuesPreview) ? $jiraAllowedValuesPreview : [];
            $rawPreviewParentId = $field['mapping_parent_custom_field_id'] ?? null;
            $existingPreviewParentId = null;
            if ($rawPreviewParentId !== null && trim((string)$rawPreviewParentId) !== '') {
                $existingPreviewParentId = (int)$rawPreviewParentId;
            }

            $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
            $effectiveFormat = $proposedFormat ?? 'string';
            $isDependingPreview = $effectiveFormat === 'depending_enumeration';
            $dependingPreviewDescriptor = $isDependingPreview ? parseCascadingAllowedValues($jiraAllowedValuesPreview) : null;
            if ($isDependingPreview && $dependingPreviewDescriptor === null && is_array($proposedValueDependencies)) {
                $normalizedDependencies = [];
                $childUnion = [];

                foreach ($proposedValueDependencies as $parentKey => $children) {
                    $parentLabel = trim((string)$parentKey);
                    if ($parentLabel === '') {
                        continue;
                    }

                    $normalizedDependencies[$parentLabel] = [];
                    if (!is_array($children)) {
                        continue;
                    }

                    foreach ($children as $child) {
                        $childLabel = is_array($child)
                            ? (isset($child['value']) ? trim((string)$child['value']) : '')
                            : trim((string)$child);

                        if ($childLabel === '') {
                            continue;
                        }

                        $normalizedDependencies[$parentLabel][] = $childLabel;
                        $childUnion[$childLabel] = $childLabel;
                    }

                    sort($normalizedDependencies[$parentLabel]);
                }

                ksort($normalizedDependencies);
                ksort($childUnion);

                $dependingPreviewDescriptor = [
                    'parents' => $proposedPossibleValues ?? array_keys($normalizedDependencies),
                    'dependencies' => $normalizedDependencies,
                    'child_values' => array_values($childUnion),
                ];
            }

            printf(
                "  - Jira custom field %s (ID: %s) -> Redmine \"%s\" (format: %s).%s",
                $jiraName ?? '[missing name]',
                $jiraId,
                $effectiveName,
                $effectiveFormat,
                PHP_EOL
            );
            printf(
                "    Required: %s, For all: %s, Filter: %s, Multiple: %s%s",
                formatBooleanForDisplay($proposedIsRequired),
                formatBooleanForDisplay($proposedIsForAll),
                formatBooleanForDisplay($proposedIsFilter),
                formatBooleanForDisplay($proposedIsMultiple),
                PHP_EOL
            );
            if ($proposedPossibleValues !== null) {
                printf("    Possible values: %s%s", json_encode($proposedPossibleValues), PHP_EOL);
            }
            if ($proposedDefaultValue !== null) {
                printf("    Default value: %s%s", $proposedDefaultValue, PHP_EOL);
            }
            if ($proposedTrackerIds !== null) {
                printf("    Tracker IDs: %s%s", json_encode($proposedTrackerIds), PHP_EOL);
            }
            if ($proposedRoleIds !== null) {
                printf("    Role IDs: %s%s", json_encode($proposedRoleIds), PHP_EOL);
            }
            if ($proposedProjectIds !== null) {
                printf("    Project IDs: %s%s", json_encode($proposedProjectIds), PHP_EOL);
            }
            if ($isDependingPreview) {
                printf(
                    "    Parent field ID: %s%s",
                    $existingPreviewParentId !== null ? (string)$existingPreviewParentId : '[pending]',
                    PHP_EOL
                );
                if ($dependingPreviewDescriptor !== null) {
                    printf("    Parent options: %s%s", json_encode($dependingPreviewDescriptor['parents']), PHP_EOL);
                    printf("    Dependencies: %s%s", json_encode($dependingPreviewDescriptor['dependencies']), PHP_EOL);
                } else {
                    printf("    Dependencies: [unavailable]%s", PHP_EOL);
                }
            }
            if ($notes !== null) {
                printf("    Notes: %s%s", $notes, PHP_EOL);
            }
        }

        if (!$confirmPush) {
            printf("  --confirm-push missing: reviewed payloads only, no data was sent to Redmine.%s", PHP_EOL);
        } elseif ($isDryRun) {
            printf("  --dry-run enabled: skipping API calls after previewing the payloads.%s", PHP_EOL);
        } else {

        $updateStatement = $pdo->prepare(<<<SQL
            UPDATE migration_mapping_custom_fields
            SET
                redmine_custom_field_id = :redmine_custom_field_id,
                redmine_custom_field_enumerations = :redmine_custom_field_enumerations,
                migration_status = :migration_status,
                notes = :notes,
                proposed_redmine_name = :proposed_redmine_name,
                proposed_field_format = :proposed_field_format,
                proposed_is_required = :proposed_is_required,
                proposed_is_filter = :proposed_is_filter,
                proposed_is_for_all = :proposed_is_for_all,
                proposed_is_multiple = :proposed_is_multiple,
                proposed_possible_values = :proposed_possible_values,
                proposed_value_dependencies = :proposed_value_dependencies,
                proposed_default_value = :proposed_default_value,
                proposed_tracker_ids = :proposed_tracker_ids,
                proposed_role_ids = :proposed_role_ids,
                proposed_project_ids = :proposed_project_ids,
                automation_hash = :automation_hash
            WHERE mapping_id = :mapping_id
        SQL);

        if ($updateStatement === false) {
            throw new RuntimeException('Failed to prepare update statement for migration_mapping_custom_fields during the push phase.');
        }

        $successCount = 0;
        $failureCount = 0;
        $dependingApiChecked = false;
        $createdFieldCache = [];

        foreach ($pendingFields as $field) {
            $mappingId = (int)$field['mapping_id'];
            $jiraId = (string)$field['jira_field_id'];
            $jiraName = $field['jira_field_name'] ?? null;
            $proposedName = $field['proposed_redmine_name'] ?? null;
            $proposedFormat = normalizeRedmineFieldFormat($field['proposed_field_format'] ?? null);
            $proposedIsRequired = normalizeBooleanFlag($field['proposed_is_required'] ?? null) ?? false;
            $proposedIsFilter = normalizeBooleanFlag($field['proposed_is_filter'] ?? null) ?? true;
            $proposedIsForAll = normalizeBooleanFlag($field['proposed_is_for_all'] ?? null) ?? true;
            $proposedIsMultiple = normalizeBooleanFlag($field['proposed_is_multiple'] ?? null) ?? false;
            $proposedPossibleValues = decodeJsonColumn($field['proposed_possible_values'] ?? null);
            $proposedValueDependencies = decodeJsonColumn($field['proposed_value_dependencies'] ?? null);
            $proposedDefaultValue = $field['proposed_default_value'] ?? null;
            $proposedTrackerIds = decodeJsonColumn($field['proposed_tracker_ids'] ?? null);
            $proposedRoleIds = decodeJsonColumn($field['proposed_role_ids'] ?? null);
            $proposedProjectIds = decodeJsonColumn($field['proposed_project_ids'] ?? null);
            $notes = $field['notes'] ?? null;
            $jiraAllowedValues = decodeJsonColumn($field['jira_allowed_values'] ?? null);
            $jiraAllowedValues = is_array($jiraAllowedValues) ? $jiraAllowedValues : [];

            $rawParentId = $field['mapping_parent_custom_field_id'] ?? null;
            $redmineParentId = null;
            $parentMappingId = null;
            $parentEnumerations = [];
            if ($rawParentId !== null && trim((string)$rawParentId) !== '') {
                $normalizedParent = (int)$rawParentId;
                if (isset($createdFieldCache[$normalizedParent])) {
                    $redmineParentId = $createdFieldCache[$normalizedParent]['id'];
                    $parentEnumerations = $createdFieldCache[$normalizedParent]['enumerations'] ?? [];
                    $parentMappingId = $normalizedParent;
                } else {
                    $parentLookup = fetchCustomFieldMappingById($pdo, $normalizedParent);
                    if ($parentLookup !== null) {
                        $parentMappingId = $normalizedParent;
                        if (isset($parentLookup['redmine_custom_field_id']) && $parentLookup['redmine_custom_field_id'] !== null) {
                            $redmineParentId = (int)$parentLookup['redmine_custom_field_id'];
                        }
                        $parentEnumerationsRaw = $parentLookup['redmine_custom_field_enumerations'] ?? null;
                        $parentEnumerationsDecoded = decodeJsonColumn($parentEnumerationsRaw);
                        if (is_array($parentEnumerationsDecoded)) {
                            $parentEnumerations = $parentEnumerationsDecoded;
                        }
                    } else {
                        $redmineParentId = $normalizedParent;
                    }
                }
            }

            $isCascadingParent = isset($field['jira_field_id']) && str_starts_with((string)$field['jira_field_id'], 'cascading_parent:');
            $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
            $effectiveFormat = $isCascadingParent ? 'enumeration' : ($proposedFormat ?? 'string');
            $isDependingField = !$isCascadingParent && $effectiveFormat === 'depending_enumeration';

            if ($isDependingField) {
                if (!$dependingApiChecked) {
                    verifyDependingCustomFieldsApi($redmineClient);
                    $dependingApiChecked = true;
                }

                $enumerationPayload = [];
                if (is_array($proposedPossibleValues)) {
                    $position = 1;
                    foreach ($proposedPossibleValues as $value) {
                        $label = is_array($value)
                            ? ($value['value'] ?? ($value['name'] ?? null))
                            : $value;
                        if ($label === null) {
                            continue;
                        }
                        $enumerationPayload[] = ['name' => (string)$label, 'position' => $position++];
                    }
                }

                if ($redmineParentId === null && $parentMappingId !== null && !$isDryRun) {
                    $parentRow = fetchCustomFieldMappingById($pdo, $parentMappingId);
                    if ($parentRow !== null) {
                        $parentCreation = createStandardCustomField(
                            $pdo,
                            $redmineClient,
                            $endpoint,
                            $parentRow,
                            $updateStatement,
                            $createdFieldCache,
                            $isDryRun,
                            true,
                            true,
                            $useExtendedApi,
                            $extendedApiPrefix
                        );
                        if ($parentCreation !== null) {
                            $redmineParentId = $parentCreation['id'];
                            $parentEnumerations = $parentCreation['enumerations'];
                        }
                    }
                }

                if ($redmineParentId === null) {
                    $manualMessage = 'Cascading custom fields require a resolved parent custom field before creation.';
                    $automationHash = computeCustomFieldAutomationStateHash(
                        null,
                        'CREATION_FAILED',
                        $effectiveName,
                        $effectiveFormat,
                        $proposedIsRequired,
                        $proposedIsFilter,
                        $proposedIsForAll,
                        $proposedIsMultiple,
                        encodeJsonColumn($proposedPossibleValues),
                        encodeJsonColumn($proposedValueDependencies),
                        $proposedDefaultValue,
                        encodeJsonColumn($proposedTrackerIds),
                        encodeJsonColumn($proposedRoleIds),
                        encodeJsonColumn($proposedProjectIds),
                        $manualMessage,
                        $parentMappingId,
                        null
                    );

                    $updateStatement->execute([
                        'redmine_custom_field_id' => null,
                        'redmine_custom_field_enumerations' => null,
                        'migration_status' => 'CREATION_FAILED',
                        'notes' => $manualMessage,
                        'proposed_redmine_name' => $effectiveName,
                        'proposed_field_format' => $effectiveFormat,
                        'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                        'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                        'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                        'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                        'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
                        'proposed_value_dependencies' => encodeJsonColumn($proposedValueDependencies),
                        'proposed_default_value' => $proposedDefaultValue,
                        'proposed_tracker_ids' => encodeJsonColumn($proposedTrackerIds),
                        'proposed_role_ids' => encodeJsonColumn($proposedRoleIds),
                        'proposed_project_ids' => encodeJsonColumn($proposedProjectIds),
                        'automation_hash' => $automationHash,
                        'mapping_id' => $mappingId,
                    ]);

                    printf(
                        "  [failed] Jira custom field %s (%s): unable to resolve cascading parent ID.%s",
                        $jiraName ?? $jiraId,
                        $jiraId,
                        PHP_EOL
                    );
                    $failureCount++;
                    continue;
                }

                $parentLabelToId = [];
                foreach ($parentEnumerations as $parentEnum) {
                    $parentName = trim((string)($parentEnum['name'] ?? ($parentEnum['value'] ?? '')));
                    if ($parentName === '') {
                        continue;
                    }
                    $parentId = $parentEnum['id'] ?? null;
                    if ($parentId !== null) {
                        $parentLabelToId[$parentName] = (int)$parentId;
                    }
                }

                $normalizedDependencies = [];
                if (is_array($proposedValueDependencies)) {
                    foreach ($proposedValueDependencies as $parentLabel => $children) {
                        $parentKey = $parentLabel;
                        if (isset($parentLabelToId[$parentLabel])) {
                            $parentKey = (string)$parentLabelToId[$parentLabel];
                        }
                        $normalizedDependencies[$parentKey] = [];
                        if (!is_array($children)) {
                            continue;
                        }
                        foreach ($children as $childValue) {
                            $childLabel = is_array($childValue)
                                ? ($childValue['value'] ?? ($childValue['name'] ?? null))
                                : $childValue;
                            if ($childLabel === null) {
                                continue;
                            }
                            $normalizedDependencies[$parentKey][] = (string)$childLabel;
                        }
                    }
                }

                $dependingPayload = [
                    'custom_field' => [
                        'name' => $effectiveName,
                        'type' => 'IssueCustomField',
                        'field_format' => $effectiveFormat,
                        'enumerations' => $enumerationPayload,
                        'is_required' => $proposedIsRequired,
                        'is_filter' => $proposedIsFilter,
                        'is_for_all' => $proposedIsForAll,
                        'multiple' => $proposedIsMultiple,
                        'parent_custom_field_id' => $redmineParentId,
                    ],
                ];

                if ($normalizedDependencies !== []) {
                    $dependingPayload['custom_field']['value_dependencies'] = $normalizedDependencies;
                }
                if ($proposedTrackerIds !== null) {
                    $dependingPayload['custom_field']['tracker_ids'] = $proposedTrackerIds;
                }
                if ($proposedRoleIds !== null) {
                    $dependingPayload['custom_field']['role_ids'] = $proposedRoleIds;
                }
                if ($proposedProjectIds !== null) {
                    $dependingPayload['custom_field']['project_ids'] = $proposedProjectIds;
                }

                try {
                    $response = $redmineClient->post('/depending_custom_fields.json', ['json' => $dependingPayload]);
                    $decoded = decodeJsonResponse($response);
                    $newFieldId = extractCreatedCustomFieldId($decoded);
                    $newEnumerations = extractCreatedCustomFieldEnumerations($decoded);
                    if ($newEnumerations === null) {
                        $newEnumerations = fetchRedmineCustomFieldEnumerations(
                            $redmineClient,
                            $newFieldId,
                            $useExtendedApi,
                            $extendedApiPrefix
                        );
                    }
                    $encodedEnumerations = encodeJsonColumn($newEnumerations);

                    $automationHash = computeCustomFieldAutomationStateHash(
                        $newFieldId,
                        'CREATION_SUCCESS',
                        $effectiveName,
                        $effectiveFormat,
                        $proposedIsRequired,
                        $proposedIsFilter,
                        $proposedIsForAll,
                        $proposedIsMultiple,
                        encodeJsonColumn($proposedPossibleValues),
                        encodeJsonColumn($proposedValueDependencies),
                        $proposedDefaultValue,
                        encodeJsonColumn($proposedTrackerIds),
                        encodeJsonColumn($proposedRoleIds),
                        encodeJsonColumn($proposedProjectIds),
                        null,
                        $parentMappingId,
                        $encodedEnumerations
                    );

                    $updateStatement->execute([
                        'redmine_custom_field_id' => $newFieldId,
                        'redmine_custom_field_enumerations' => $encodedEnumerations,
                        'migration_status' => 'CREATION_SUCCESS',
                        'notes' => null,
                        'proposed_redmine_name' => $effectiveName,
                        'proposed_field_format' => $effectiveFormat,
                        'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                        'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                        'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                        'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                        'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
                        'proposed_value_dependencies' => encodeJsonColumn($proposedValueDependencies),
                        'proposed_default_value' => $proposedDefaultValue,
                        'proposed_tracker_ids' => encodeJsonColumn($proposedTrackerIds),
                        'proposed_role_ids' => encodeJsonColumn($proposedRoleIds),
                        'proposed_project_ids' => encodeJsonColumn($proposedProjectIds),
                        'automation_hash' => $automationHash,
                        'mapping_id' => $mappingId,
                    ]);

                    $createdFieldCache[$mappingId] = ['id' => $newFieldId, 'enumerations' => $newEnumerations];

                    printf(
                        "  [created] Jira custom field %s (%s) -> Redmine depending field #%d (parent #%d).%s",
                        $jiraName ?? $jiraId,
                        $jiraId,
                        $newFieldId,
                        $redmineParentId,
                        PHP_EOL
                    );

                    $successCount++;
                } catch (Throwable $exception) {
                    $errorMessage = summarizeExtendedApiError($exception);
                    $automationHash = computeCustomFieldAutomationStateHash(
                        null,
                        'CREATION_FAILED',
                        $effectiveName,
                        $effectiveFormat,
                        $proposedIsRequired,
                        $proposedIsFilter,
                        $proposedIsForAll,
                        $proposedIsMultiple,
                        encodeJsonColumn($proposedPossibleValues),
                        encodeJsonColumn($proposedValueDependencies),
                        $proposedDefaultValue,
                        encodeJsonColumn($proposedTrackerIds),
                        encodeJsonColumn($proposedRoleIds),
                        encodeJsonColumn($proposedProjectIds),
                        $errorMessage,
                        $parentMappingId,
                        null
                    );

                    $updateStatement->execute([
                        'redmine_custom_field_id' => null,
                        'redmine_custom_field_enumerations' => null,
                        'migration_status' => 'CREATION_FAILED',
                        'notes' => $errorMessage,
                        'proposed_redmine_name' => $effectiveName,
                        'proposed_field_format' => $effectiveFormat,
                        'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                        'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                        'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                        'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                        'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
                        'proposed_value_dependencies' => encodeJsonColumn($proposedValueDependencies),
                        'proposed_default_value' => $proposedDefaultValue,
                        'proposed_tracker_ids' => encodeJsonColumn($proposedTrackerIds),
                        'proposed_role_ids' => encodeJsonColumn($proposedRoleIds),
                        'proposed_project_ids' => encodeJsonColumn($proposedProjectIds),
                        'automation_hash' => $automationHash,
                        'mapping_id' => $mappingId,
                    ]);

                    printf(
                        "  [failed] Jira custom field %s (%s): %s%s",
                        $jiraName ?? $jiraId,
                        $jiraId,
                        $errorMessage,
                        PHP_EOL
                    );

                    $failureCount++;
                }

                continue;
            }

            // Standard custom field creation via extended API
            $payload = [
                'type' => 'IssueCustomField',   // <-- ROOT, niet in custom_field
                'custom_field' => [
                    'name'         => $effectiveName,
                    'field_format' => $effectiveFormat,
                    'is_required'  => $proposedIsRequired,
                    'is_filter'    => $proposedIsFilter,
                    'multiple'     => $proposedIsMultiple,
                    // is_for_all vullen we hieronder afhankelijk van project_ids
                ],
            ];

            if ($proposedPossibleValues !== null) {
                $payload['custom_field']['possible_values'] = $proposedPossibleValues;
            }
            if ($effectiveFormat === 'enumeration') {
                $enumerationPayload = buildEnumerationPayloadFromPossibleValues($proposedPossibleValues);
                if ($enumerationPayload !== []) {
                    $payload['custom_field']['enumerations'] = $enumerationPayload;
                }
            }
            if ($proposedDefaultValue !== null) {
                $payload['custom_field']['default_value'] = $proposedDefaultValue;
            }
            if ($proposedTrackerIds !== null) {
                $payload['custom_field']['tracker_ids'] = $proposedTrackerIds;
            }
            if ($proposedRoleIds !== null && $proposedRoleIds !== []) {
                $payload['custom_field']['role_ids'] = $proposedRoleIds;
            }

            if ($proposedProjectIds !== null && $proposedProjectIds !== []) {
                // veld enkel voor bepaalde projecten
                $payload['custom_field']['project_ids'] = $proposedProjectIds;
                $payload['custom_field']['is_for_all']   = false;
            } else {
                // geen expliciete projecten → laat voorstel beslissen, of default naar true
                $payload['custom_field']['is_for_all'] = $proposedIsForAll ?? true;
            }

            try {
                $response = $redmineClient->post($endpoint, ['json' => $payload]);
                $decoded = decodeJsonResponse($response);
                $newFieldId = extractCreatedCustomFieldId($decoded);
                $newEnumerations = extractCreatedCustomFieldEnumerations($decoded);
                if ($newEnumerations === null) {
                    $newEnumerations = fetchRedmineCustomFieldEnumerations(
                        $redmineClient,
                        $newFieldId,
                        $useExtendedApi,
                        $extendedApiPrefix
                    );
                }
                $encodedEnumerations = encodeJsonColumn($newEnumerations);

                $automationHash = computeCustomFieldAutomationStateHash(
                    $newFieldId,
                    'CREATION_SUCCESS',
                    $effectiveName,
                    $effectiveFormat,
                    $proposedIsRequired,
                    $proposedIsFilter,
                    $proposedIsForAll,
                    $proposedIsMultiple,
                    encodeJsonColumn($proposedPossibleValues),
                    encodeJsonColumn($proposedValueDependencies),
                    $proposedDefaultValue,
                    encodeJsonColumn($proposedTrackerIds),
                    encodeJsonColumn($proposedRoleIds),
                    encodeJsonColumn($proposedProjectIds),
                    null,
                    null,
                    $encodedEnumerations
                );

                $updateStatement->execute([
                    'redmine_custom_field_id' => $newFieldId,
                    'redmine_custom_field_enumerations' => $encodedEnumerations,
                    'migration_status' => 'CREATION_SUCCESS',
                    'notes' => null,
                    'proposed_redmine_name' => $effectiveName,
                    'proposed_field_format' => $effectiveFormat,
                    'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                    'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                    'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                    'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                    'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
                    'proposed_value_dependencies' => encodeJsonColumn($proposedValueDependencies),
                    'proposed_default_value' => $proposedDefaultValue,
                    'proposed_tracker_ids' => encodeJsonColumn($proposedTrackerIds),
                    'proposed_role_ids' => encodeJsonColumn($proposedRoleIds),
                    'proposed_project_ids' => encodeJsonColumn($proposedProjectIds),
                    'automation_hash' => $automationHash,
                    'mapping_id' => $mappingId,
                ]);

                $createdFieldCache[$mappingId] = ['id' => $newFieldId, 'enumerations' => $newEnumerations];

                printf(
                    "  [created] Jira custom field %s (%s) -> Redmine custom field #%d.%s",
                    $jiraName ?? $jiraId,
                    $jiraId,
                    $newFieldId,
                    PHP_EOL
                );

                $successCount++;
            } catch (Throwable $exception) {
                $errorMessage = summarizeExtendedApiError($exception);
                $automationHash = computeCustomFieldAutomationStateHash(
                    null,
                    'CREATION_FAILED',
                    $effectiveName,
                    $effectiveFormat,
                    $proposedIsRequired,
                    $proposedIsFilter,
                    $proposedIsForAll,
                    $proposedIsMultiple,
                    encodeJsonColumn($proposedPossibleValues),
                    encodeJsonColumn($proposedValueDependencies),
                    $proposedDefaultValue,
                    encodeJsonColumn($proposedTrackerIds),
                    encodeJsonColumn($proposedRoleIds),
                    encodeJsonColumn($proposedProjectIds),
                    $errorMessage,
                    null,
                    null
                );

                $updateStatement->execute([
                    'redmine_custom_field_id' => null,
                    'redmine_custom_field_enumerations' => null,
                    'migration_status' => 'CREATION_FAILED',
                    'notes' => $errorMessage,
                    'proposed_redmine_name' => $effectiveName,
                    'proposed_field_format' => $effectiveFormat,
                    'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                    'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                    'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                    'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                    'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
                    'proposed_value_dependencies' => encodeJsonColumn($proposedValueDependencies),
                    'proposed_default_value' => $proposedDefaultValue,
                    'proposed_tracker_ids' => encodeJsonColumn($proposedTrackerIds),
                    'proposed_role_ids' => encodeJsonColumn($proposedRoleIds),
                    'proposed_project_ids' => encodeJsonColumn($proposedProjectIds),
                    'automation_hash' => $automationHash,
                    'mapping_id' => $mappingId,
                ]);

                printf(
                    "  [failed] Jira custom field %s (%s): %s%s",
                    $jiraName ?? $jiraId,
                    $jiraId,
                    $errorMessage,
                    PHP_EOL
                );

                if ($notes !== null) {
                    printf("    Previous notes: %s%s", $notes, PHP_EOL);
                }

                $failureCount++;
            }
        }

        printf(
            "  Completed extended API push. Success: %d, Failed: %d.%s",
            $successCount,
            $failureCount,
            PHP_EOL
        );

        }

        $planResult = collectCustomFieldUpdatePlan($pdo);
        foreach ($planResult['warnings'] as $warning) {
            printf("  [warning] %s%s", $warning, PHP_EOL);
        }

        renderCustomFieldUpdatePlan($planResult['plan'], true);

        if ($planResult['plan'] !== []) {
            if ($isDryRun) {
                printf("  --dry-run enabled: skipping extended API calls for association updates.%s", PHP_EOL);
            } elseif (!$confirmPush) {
                printf("  Provide --confirm-push to update custom field associations via the extended API.%s", PHP_EOL);
            } else {
                synchronizeCustomFieldAssociations($pdo, $redmineClient, $extendedApiPrefix, $planResult['plan'], false);
            }
        }

        return;
    }

    printf("[%s] Starting push phase (manual custom field checklist)...%s", formatCurrentTimestamp(), PHP_EOL);

    if ($pendingCount === 0) {
        printf("  No Jira custom fields are marked as READY_FOR_CREATION.%s", PHP_EOL);
        if ($isDryRun) {
            printf("  --dry-run flag enabled: no database changes will be made.%s", PHP_EOL);
        }
        if ($confirmPush) {
            printf("  --confirm-push provided but there is nothing to acknowledge.%s", PHP_EOL);
        } else {
            printf("  Provide --confirm-push after manually creating any outstanding custom fields in Redmine.%s", PHP_EOL);
        }
    }

    printf("  %d custom field(s) require manual creation in Redmine.%s", $pendingCount, PHP_EOL);
    foreach ($pendingFields as $field) {
        $jiraName = $field['jira_field_name'] ?? null;
        $jiraId = (string)$field['jira_field_id'];
        $proposedName = $field['proposed_redmine_name'] ?? null;
        $proposedFormat = normalizeRedmineFieldFormat($field['proposed_field_format'] ?? null);
        $proposedIsRequired = normalizeBooleanFlag($field['proposed_is_required'] ?? null) ?? false;
        $proposedIsFilter = normalizeBooleanFlag($field['proposed_is_filter'] ?? null) ?? true;
        $proposedIsForAll = normalizeBooleanFlag($field['proposed_is_for_all'] ?? null) ?? true;
        $proposedIsMultiple = normalizeBooleanFlag($field['proposed_is_multiple'] ?? null) ?? false;
        $proposedPossibleValues = decodeJsonColumn($field['proposed_possible_values'] ?? null);
        $proposedValueDependencies = decodeJsonColumn($field['proposed_value_dependencies'] ?? null);
        $proposedDefaultValue = $field['proposed_default_value'] ?? null;
        $proposedTrackerIds = decodeJsonColumn($field['proposed_tracker_ids'] ?? null);
        $proposedRoleIds = decodeJsonColumn($field['proposed_role_ids'] ?? null);
        $proposedProjectIds = decodeJsonColumn($field['proposed_project_ids'] ?? null);
        $notes = $field['notes'] ?? null;
        $previewAllowedValues = decodeJsonColumn($field['jira_allowed_values'] ?? null);
        $previewAllowedValues = is_array($previewAllowedValues) ? $previewAllowedValues : [];
        $rawPreviewParentId = $field['mapping_parent_custom_field_id'] ?? null;
        $existingPreviewParentId = null;
        if ($rawPreviewParentId !== null && trim((string)$rawPreviewParentId) !== '') {
            $existingPreviewParentId = (int)$rawPreviewParentId;
        }

        $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
        $effectiveFormat = $proposedFormat ?? 'string';
        $isDependingPreview = $effectiveFormat === 'depending_enumeration';
        $dependingPreviewDescriptor = $isDependingPreview ? parseCascadingAllowedValues($previewAllowedValues) : null;

        printf(
            "  - Jira custom field %s (ID: %s) -> Redmine \"%s\" (format: %s).%s",
            $jiraName ?? '[missing name]',
            $jiraId,
            $effectiveName,
            $effectiveFormat,
            PHP_EOL
        );
        printf(
            "    Required: %s, For all: %s, Filter: %s, Multiple: %s%s",
            formatBooleanForDisplay($proposedIsRequired),
            formatBooleanForDisplay($proposedIsForAll),
            formatBooleanForDisplay($proposedIsFilter),
            formatBooleanForDisplay($proposedIsMultiple),
            PHP_EOL
        );
        if ($proposedPossibleValues !== null) {
            printf("    Possible values: %s%s", json_encode($proposedPossibleValues), PHP_EOL);
        }
        if ($proposedDefaultValue !== null) {
            printf("    Default value: %s%s", $proposedDefaultValue, PHP_EOL);
        }
        if ($proposedTrackerIds !== null) {
            printf("    Tracker IDs: %s%s", json_encode($proposedTrackerIds), PHP_EOL);
        }
        if ($proposedRoleIds !== null) {
            printf("    Role IDs: %s%s", json_encode($proposedRoleIds), PHP_EOL);
        }
        if ($proposedProjectIds !== null) {
            printf("    Project IDs: %s%s", json_encode($proposedProjectIds), PHP_EOL);
        }
        if ($isDependingPreview) {
            printf(
                "    Parent field ID: %s%s",
                $existingPreviewParentId !== null ? (string)$existingPreviewParentId : '[pending]',
                PHP_EOL
            );
            if ($dependingPreviewDescriptor !== null) {
                printf("    Parent options: %s%s", json_encode($dependingPreviewDescriptor['parents']), PHP_EOL);
                printf("    Dependencies: %s%s", json_encode($dependingPreviewDescriptor['dependencies']), PHP_EOL);
            } else {
                printf("    Dependencies: [unavailable]%s", PHP_EOL);
            }
        }
        if ($notes !== null) {
            printf("    Notes: %s%s", $notes, PHP_EOL);
        }
    }

    if (!$confirmPush) {
        printf("  Supply --confirm-push after completing the manual Redmine updates to acknowledge the checklist.%s", PHP_EOL);
    } elseif ($isDryRun) {
        printf("  --dry-run enabled: skipping migration status updates after the manual review.%s", PHP_EOL);
    } else {
        $updateStatement = $pdo->prepare(<<<SQL
            UPDATE migration_mapping_custom_fields
            SET
                migration_status = 'CREATION_SUCCESS',
                notes = NULL,
                automation_hash = :automation_hash
            WHERE mapping_id = :mapping_id
        SQL);

        if ($updateStatement === false) {
            throw new RuntimeException('Failed to prepare manual acknowledgement update for migration_mapping_custom_fields.');
        }

        foreach ($pendingFields as $field) {
            $mappingId = (int)$field['mapping_id'];
            $jiraId = (string)$field['jira_field_id'];
            $jiraName = $field['jira_field_name'] ?? null;

            $proposedFormat = normalizeRedmineFieldFormat($field['proposed_field_format'] ?? 'string');
            $automationHash = computeCustomFieldAutomationStateHash(
                null,
                'CREATION_SUCCESS',
                $field['proposed_redmine_name'] ?? ($jiraName ?? $jiraId),
                $proposedFormat ?? 'string',
                normalizeBooleanFlag($field['proposed_is_required'] ?? null) ?? false,
                normalizeBooleanFlag($field['proposed_is_filter'] ?? null) ?? true,
                normalizeBooleanFlag($field['proposed_is_for_all'] ?? null) ?? true,
                normalizeBooleanFlag($field['proposed_is_multiple'] ?? null) ?? false,
                $field['proposed_possible_values'] ?? null,
                $field['proposed_value_dependencies'] ?? null,
                $field['proposed_default_value'] ?? null,
                $field['proposed_tracker_ids'] ?? null,
                $field['proposed_role_ids'] ?? null,
                $field['proposed_project_ids'] ?? null,
                null,
                isset($field['mapping_parent_custom_field_id']) && trim((string)$field['mapping_parent_custom_field_id']) !== ''
                    ? (int)$field['mapping_parent_custom_field_id']
                    : null
            );

            $updateStatement->execute([
                'automation_hash' => $automationHash,
                'mapping_id' => $mappingId,
            ]);
        }

        printf("  Marked %d custom field(s) as acknowledged after manual creation.%s", $pendingCount, PHP_EOL);
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchCustomFieldsReadyForCreation(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            mapping_id,
            jira_field_id,
            jira_field_name,
            proposed_redmine_name,
            proposed_field_format,
            proposed_text_formatting,
            proposed_is_required,
            proposed_is_filter,
            proposed_is_for_all,
            proposed_is_multiple,
            proposed_possible_values,
            proposed_value_dependencies,
            proposed_default_value,
            proposed_tracker_ids,
            proposed_role_ids,
            proposed_project_ids,
            notes,
            jira_allowed_values,
            mapping_parent_custom_field_id
        FROM migration_mapping_custom_fields
        WHERE migration_status = 'READY_FOR_CREATION'
        ORDER BY jira_field_name IS NULL, jira_field_name, jira_field_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch custom fields ready for creation: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string, mixed>|null
 */
function fetchCustomFieldMappingById(PDO $pdo, int $mappingId): ?array
{
    $sql = <<<SQL
        SELECT
            mapping_id,
            jira_field_id,
            jira_field_name,
            proposed_redmine_name,
            proposed_field_format,
            proposed_text_formatting,
            proposed_is_required,
            proposed_is_filter,
            proposed_is_for_all,
            proposed_is_multiple,
            proposed_possible_values,
            proposed_value_dependencies,
            proposed_default_value,
            proposed_tracker_ids,
            proposed_role_ids,
            proposed_project_ids,
            notes,
            jira_allowed_values,
            mapping_parent_custom_field_id,
            redmine_custom_field_id,
            redmine_custom_field_enumerations,
            migration_status
        FROM migration_mapping_custom_fields
        WHERE mapping_id = :mapping_id
        LIMIT 1
    SQL;

    $statement = $pdo->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare custom field mapping lookup statement.');
    }

    $statement->execute(['mapping_id' => $mappingId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    $statement->closeCursor();

    return $row !== false ? $row : null;
}

/**
 * Bootstrap creation of a standard Redmine custom field via the extended API.
 *
 * @param array<string, mixed> $field
 * @param array<int, array{id: int, enumerations: array<int, mixed>|null}> $createdFieldCache
 * @return array{id: int, enumerations: array<int, mixed>|null}|null
 */
function buildEnumerationPayloadFromPossibleValues(mixed $possibleValues): array
{
    $values = is_array($possibleValues) ? $possibleValues : decodeJsonColumn($possibleValues);
    if (!is_array($values)) {
        return [];
    }

    $enumerations = [];
    $position = 1;
    foreach ($values as $value) {
        $label = is_array($value)
            ? ($value['value'] ?? ($value['name'] ?? null))
            : $value;

        if ($label === null) {
            continue;
        }

        $enumerations[] = ['name' => (string)$label, 'position' => $position++];
    }

    return $enumerations;
}

function createStandardCustomField(
    PDO $pdo,
    Client $redmineClient,
    string $endpoint,
    array $field,
    PDOStatement $updateStatement,
    array &$createdFieldCache,
    bool $isDryRun,
    bool $suppressOutput = false,
    bool $forceEnumerationFormat = false,
    bool $useExtendedApi = false,
    ?string $extendedApiPrefix = null
): ?array {
    $mappingId = (int)$field['mapping_id'];
    $jiraId = (string)($field['jira_field_id'] ?? $mappingId);
    $jiraName = $field['jira_field_name'] ?? null;
    $proposedName = $field['proposed_redmine_name'] ?? null;
    $proposedFormat = normalizeRedmineFieldFormat($field['proposed_field_format'] ?? null);
    $proposedTextFormatting = normalizeBooleanFlag($field['proposed_text_formatting'] ?? null);
    $proposedIsRequired = normalizeBooleanFlag($field['proposed_is_required'] ?? null) ?? false;
    $proposedIsFilter = normalizeBooleanFlag($field['proposed_is_filter'] ?? null) ?? true;
    $proposedIsForAll = normalizeBooleanFlag($field['proposed_is_for_all'] ?? null) ?? true;
    $proposedIsMultiple = normalizeBooleanFlag($field['proposed_is_multiple'] ?? null) ?? false;
    $proposedPossibleValues = decodeJsonColumn($field['proposed_possible_values'] ?? null);
    $proposedValueDependencies = decodeJsonColumn($field['proposed_value_dependencies'] ?? null);
    $proposedDefaultValue = $field['proposed_default_value'] ?? null;
    $proposedTrackerIds = decodeJsonColumn($field['proposed_tracker_ids'] ?? null);
    $proposedRoleIds = decodeJsonColumn($field['proposed_role_ids'] ?? null);
    $proposedProjectIds = decodeJsonColumn($field['proposed_project_ids'] ?? null);
    $notes = $field['notes'] ?? null;

    $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
    $effectiveFormat = $forceEnumerationFormat ? 'enumeration' : ($proposedFormat ?? 'string');

    $payload = [
        'type' => 'IssueCustomField',
        'custom_field' => [
            'name' => $effectiveName,
            'field_format' => $effectiveFormat,
            'customized_type' => 'issue',
            'is_required' => $proposedIsRequired,
            'is_filter' => $proposedIsFilter,
            'multiple' => $proposedIsMultiple,
        ],
    ];

    if ($proposedProjectIds !== null && $proposedProjectIds !== []) {
        $payload['custom_field']['project_ids'] = $proposedProjectIds;
        $payload['custom_field']['is_for_all'] = false;
    } else {
        // geen project_ids → alle projecten
        $payload['custom_field']['is_for_all'] = true;
    }

    if ($proposedPossibleValues !== null) {
        $payload['custom_field']['possible_values'] = $proposedPossibleValues;
    }
    if ($effectiveFormat === 'enumeration') {
        $enumerationPayload = buildEnumerationPayloadFromPossibleValues($proposedPossibleValues);
        if ($enumerationPayload !== []) {
            $payload['custom_field']['enumerations'] = $enumerationPayload;
        }
    }
    if ($proposedDefaultValue !== null) {
            $payload['custom_field']['default_value'] = $proposedDefaultValue;
        }
        if ($proposedTextFormatting === true && in_array($effectiveFormat, ['text', 'string'], true)) {
            $payload['custom_field']['text_formatting'] = true;
        }
    if ($proposedTrackerIds !== null) {
        $payload['custom_field']['tracker_ids'] = $proposedTrackerIds;
    }
    if ($proposedRoleIds !== null && $proposedRoleIds !== []) {
        $payload['custom_field']['role_ids'] = $proposedRoleIds;
    }

    if ($isDryRun) {
        return null;
    }

    try {
        $response = $redmineClient->post($endpoint, ['json' => $payload]);
        $decoded = decodeJsonResponse($response);
        $newFieldId = extractCreatedCustomFieldId($decoded);
        $newEnumerations = extractCreatedCustomFieldEnumerations($decoded);
        if ($newEnumerations === null) {
            $newEnumerations = fetchRedmineCustomFieldEnumerations(
                $redmineClient,
                $newFieldId,
                $useExtendedApi,
                $extendedApiPrefix
            );
        }
        $encodedEnumerations = encodeJsonColumn($newEnumerations);

        $automationHash = computeCustomFieldAutomationStateHash(
            $newFieldId,
            'CREATION_SUCCESS',
            $effectiveName,
            $effectiveFormat,
            $proposedIsRequired,
            $proposedIsFilter,
            $proposedIsForAll,
            $proposedIsMultiple,
            encodeJsonColumn($proposedPossibleValues),
            encodeJsonColumn($proposedValueDependencies),
            $proposedDefaultValue,
            encodeJsonColumn($proposedTrackerIds),
            encodeJsonColumn($proposedRoleIds),
            encodeJsonColumn($proposedProjectIds),
            null,
            null,
            $encodedEnumerations
        );

        $updateStatement->execute([
            'redmine_custom_field_id' => $newFieldId,
            'redmine_custom_field_enumerations' => $encodedEnumerations,
            'migration_status' => 'CREATION_SUCCESS',
            'notes' => $notes,
            'proposed_redmine_name' => $effectiveName,
            'proposed_field_format' => $effectiveFormat,
            'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
            'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
            'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
            'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
            'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
            'proposed_value_dependencies' => encodeJsonColumn($proposedValueDependencies),
            'proposed_default_value' => $proposedDefaultValue,
            'proposed_tracker_ids' => encodeJsonColumn($proposedTrackerIds),
            'proposed_role_ids' => encodeJsonColumn($proposedRoleIds),
            'proposed_project_ids' => encodeJsonColumn($proposedProjectIds),
            'automation_hash' => $automationHash,
            'mapping_id' => $mappingId,
        ]);

        $createdFieldCache[$mappingId] = ['id' => $newFieldId, 'enumerations' => $newEnumerations];

        if (!$suppressOutput) {
            printf(
                "  [created] Jira custom field %s (%s) -> Redmine custom field #%d.%s",
                $jiraName ?? $jiraId,
                $jiraId,
                $newFieldId,
                PHP_EOL
            );
        }

        return ['id' => $newFieldId, 'enumerations' => $newEnumerations];
    } catch (Throwable $exception) {
        $errorMessage = summarizeExtendedApiError($exception);
        $automationHash = computeCustomFieldAutomationStateHash(
            null,
            'CREATION_FAILED',
            $effectiveName,
            $effectiveFormat,
            $proposedIsRequired,
            $proposedIsFilter,
            $proposedIsForAll,
            $proposedIsMultiple,
            encodeJsonColumn($proposedPossibleValues),
            encodeJsonColumn($proposedValueDependencies),
            $proposedDefaultValue,
            encodeJsonColumn($proposedTrackerIds),
            encodeJsonColumn($proposedRoleIds),
            encodeJsonColumn($proposedProjectIds),
            $errorMessage,
            null,
            null
        );

        $updateStatement->execute([
            'redmine_custom_field_id' => null,
            'redmine_custom_field_enumerations' => null,
            'migration_status' => 'CREATION_FAILED',
            'notes' => $errorMessage,
            'proposed_redmine_name' => $effectiveName,
            'proposed_field_format' => $effectiveFormat,
            'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
            'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
            'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
            'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
            'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
            'proposed_value_dependencies' => encodeJsonColumn($proposedValueDependencies),
            'proposed_default_value' => $proposedDefaultValue,
            'proposed_tracker_ids' => encodeJsonColumn($proposedTrackerIds),
            'proposed_role_ids' => encodeJsonColumn($proposedRoleIds),
            'proposed_project_ids' => encodeJsonColumn($proposedProjectIds),
            'automation_hash' => $automationHash,
            'mapping_id' => $mappingId,
        ]);

        if (!$suppressOutput) {
            printf(
                "  [failed] Jira custom field %s (%s): %s%s",
                $jiraName ?? $jiraId,
                $jiraId,
                $errorMessage,
                PHP_EOL
            );
        }
    }

    return null;
}

/**
 * @param array<int, array<string, mixed>> $plan
 */
function synchronizeCustomFieldAssociations(PDO $pdo, Client $client, string $extendedPrefix, array $plan, bool $isDryRun): void
{
    if ($plan === []) {
        printf("  No association updates are pending.%s", PHP_EOL);
        return;
    }

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_custom_fields
        SET
            migration_status = :migration_status,
            notes = :notes,
            proposed_tracker_ids = :proposed_tracker_ids,
            proposed_project_ids = :proposed_project_ids,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare custom field association update statement.');
    }

    foreach ($plan as $item) {
        $mappingId = (int)$item['mapping_id'];
        $redmineId = (int)$item['redmine_custom_field_id'];
        $parentMappingId = isset($item['mapping_parent_custom_field_id']) && $item['mapping_parent_custom_field_id'] !== null
            ? (int)$item['mapping_parent_custom_field_id']
            : null;
        $parentRedmineId = isset($item['parent_redmine_custom_field_id']) && $item['parent_redmine_custom_field_id'] !== null
            ? (int)$item['parent_redmine_custom_field_id']
            : null;
        $projects = $item['target_project_ids'] ?? [];
        $trackers = $item['target_tracker_ids'] ?? [];
        $currentStatus = $item['current_status'] ?? 'MATCH_FOUND';
        $nextStatus = $currentStatus === 'READY_FOR_UPDATE' ? 'MATCH_FOUND' : $currentStatus;
        $format = normalizeRedmineFieldFormat($item['proposed_field_format'] ?? null);

        $payload = ['custom_field' => []];
        $proposedValueDependencies = decodeJsonColumn($item['proposed_value_dependencies'] ?? null);
        if ($projects !== []) {
            $payload['custom_field']['project_ids'] = $projects;
        }
        if ($trackers !== []) {
            $payload['custom_field']['tracker_ids'] = $trackers;
        }

        $path = buildExtendedApiPath($extendedPrefix, sprintf('custom_fields/%d.json', $redmineId));
        $isDependingField = $format === 'depending_enumeration';

        if ($isDependingField) {
            verifyDependingCustomFieldsApi($client);
            $path = sprintf('/depending_custom_fields/%d.json', $redmineId);

            $childEnumerations = decodeJsonColumn($item['redmine_custom_field_enumerations'] ?? null);
            $parentEnumerations = decodeJsonColumn($item['parent_redmine_custom_field_enumerations'] ?? null);

            if (!is_array($childEnumerations)) {
                $mappingRow = fetchCustomFieldMappingById($pdo, $mappingId);
                $childEnumerations = decodeJsonColumn($mappingRow['redmine_custom_field_enumerations'] ?? null);
            }

            if (!is_array($parentEnumerations) && $parentMappingId !== null) {
                $parentRow = fetchCustomFieldMappingById($pdo, $parentMappingId);
                $parentEnumerations = decodeJsonColumn($parentRow['redmine_custom_field_enumerations'] ?? null);
            }

            if (!is_array($childEnumerations)) {
                $childEnumerations = fetchRedmineCustomFieldEnumerations($client, $redmineId, true, $extendedPrefix) ?? [];
            }

            if (!is_array($parentEnumerations) && $parentRedmineId !== null) {
                $parentEnumerations = fetchRedmineCustomFieldEnumerations($client, $parentRedmineId, true, $extendedPrefix) ?? [];
            }

            if ($parentRedmineId !== null) {
                $payload['custom_field']['parent_custom_field_id'] = $parentRedmineId;
            }

            if (is_array($proposedValueDependencies) && $proposedValueDependencies !== []) {
                $payload['custom_field']['value_dependencies'] = normalizeDependingValueDependencies(
                    $proposedValueDependencies,
                    $parentEnumerations,
                    $childEnumerations
                );
            }
        }

        if ($parentMappingId !== null) {
            $parentPayload = ['custom_field' => []];
            if ($projects !== []) {
                $parentPayload['custom_field']['project_ids'] = $projects;
            }
            if ($trackers !== []) {
                $parentPayload['custom_field']['tracker_ids'] = $trackers;
            }

            $parentLabel = $parentRedmineId !== null ? sprintf('#%d', $parentRedmineId) : '[pending]';
            printf(
                "  [plan] Updating parent custom field %s (mapping #%d) projects %s, trackers %s%s",
                $parentLabel,
                $parentMappingId,
                $projects === [] ? '[none]' : json_encode($projects),
                $trackers === [] ? '[none]' : json_encode($trackers),
                PHP_EOL
            );

            if (!$isDryRun && $parentRedmineId !== null) {
                try {
                    $parentPath = buildExtendedApiPath($extendedPrefix, sprintf('custom_fields/%d.json', $parentRedmineId));
                    $client->put($parentPath, ['json' => $parentPayload]);
                } catch (Throwable $exception) {
                    $errorMessage = summarizeExtendedApiError($exception);
                    $automationHash = computeCustomFieldAutomationStateHash(
                        $redmineId,
                        'CREATION_FAILED',
                        $item['proposed_redmine_name'] ?? $item['jira_field_id'],
                        $format,
                        $item['proposed_is_required'] ?? null,
                        $item['proposed_is_filter'] ?? null,
                        $item['proposed_is_for_all'] ?? null,
                        $item['proposed_is_multiple'] ?? null,
                        $item['proposed_possible_values'] ?? null,
                        $item['proposed_value_dependencies'] ?? null,
                        $item['proposed_default_value'] ?? null,
                        encodeJsonColumn($trackers),
                        $item['proposed_role_ids'] ?? null,
                        encodeJsonColumn($projects),
                        $errorMessage,
                        $parentMappingId
                    );

                    $updateStatement->execute([
                        'migration_status' => 'CREATION_FAILED',
                        'notes' => $errorMessage,
                        'proposed_tracker_ids' => encodeJsonColumn($trackers),
                        'proposed_project_ids' => encodeJsonColumn($projects),
                        'automation_hash' => $automationHash,
                        'mapping_id' => $mappingId,
                    ]);

                    $failedParentLabel = $parentRedmineId !== null ? sprintf('#%d', $parentRedmineId) : sprintf('mapping #%d', $parentMappingId);
                    printf("  [failed] Parent update for custom field %s: %s%s", $failedParentLabel, $errorMessage, PHP_EOL);
                    continue;
                }
            }
        }

        printf(
            "  [plan] Updating Redmine custom field #%d projects %s, trackers %s%s",
            $redmineId,
            $projects === [] ? '[none]' : json_encode($projects),
            $trackers === [] ? '[none]' : json_encode($trackers),
            PHP_EOL
        );

        if ($isDryRun) {
            continue;
        }

        try {
            $client->put($path, ['json' => $payload]);

            $automationHash = computeCustomFieldAutomationStateHash(
                $redmineId,
                $nextStatus,
                $item['proposed_redmine_name'] ?? ($item['jira_field_name'] ?? $item['jira_field_id']),
                $format,
                $item['proposed_is_required'] ?? null,
                $item['proposed_is_filter'] ?? null,
                $item['proposed_is_for_all'] ?? null,
                $item['proposed_is_multiple'] ?? null,
                $item['proposed_possible_values'] ?? null,
                $item['proposed_value_dependencies'] ?? null,
                $item['proposed_default_value'] ?? null,
                encodeJsonColumn($trackers),
                $item['proposed_role_ids'] ?? null,
                encodeJsonColumn($projects),
                null,
                $parentMappingId
            );

            $updateStatement->execute([
                'migration_status' => $nextStatus,
                'notes' => null,
                'proposed_tracker_ids' => encodeJsonColumn($trackers),
                'proposed_project_ids' => encodeJsonColumn($projects),
                'automation_hash' => $automationHash,
                'mapping_id' => $mappingId,
            ]);
        } catch (Throwable $exception) {
            $errorMessage = summarizeExtendedApiError($exception);
            $automationHash = computeCustomFieldAutomationStateHash(
                $redmineId,
                'CREATION_FAILED',
                $item['proposed_redmine_name'] ?? ($item['jira_field_name'] ?? $item['jira_field_id']),
                $format,
                $item['proposed_is_required'] ?? null,
                $item['proposed_is_filter'] ?? null,
                $item['proposed_is_for_all'] ?? null,
                $item['proposed_is_multiple'] ?? null,
                $item['proposed_possible_values'] ?? null,
                $item['proposed_value_dependencies'] ?? null,
                $item['proposed_default_value'] ?? null,
                encodeJsonColumn($trackers),
                $item['proposed_role_ids'] ?? null,
                encodeJsonColumn($projects),
                $errorMessage,
                $parentMappingId
            );

            $updateStatement->execute([
                'migration_status' => 'CREATION_FAILED',
                'notes' => $errorMessage,
                'proposed_tracker_ids' => encodeJsonColumn($trackers),
                'proposed_project_ids' => encodeJsonColumn($projects),
                'automation_hash' => $automationHash,
                'mapping_id' => $mappingId,
            ]);

            printf(
                "  [failed] Association update for Redmine custom field #%d: %s%s",
                $redmineId,
                $errorMessage,
                PHP_EOL
            );
        }
    }
}

/**
 * @param array<int, array<string, mixed>> $enumerations
 *
 * @return array<string, int>
 */
function buildEnumerationLabelToIdMap(array $enumerations): array
{
    $map = [];

    foreach ($enumerations as $enumeration) {
        $label = trim((string)($enumeration['name'] ?? ($enumeration['value'] ?? '')));
        $id = isset($enumeration['id']) ? normalizeInteger($enumeration['id']) : null;

        if ($label === '' || $id === null) {
            continue;
        }

        $map[$label] = $id;
    }

    return $map;
}

/**
 * Normalize depending value dependencies to the enumeration identifiers expected by the plugin API.
 *
 * @param array<string, mixed> $dependencies
 * @param array<int, array<string, mixed>> $parentEnumerations
 * @param array<int, array<string, mixed>> $childEnumerations
 *
 * @return array<string, array<int|string>>
 */
function normalizeDependingValueDependencies(array $dependencies, array $parentEnumerations, array $childEnumerations): array
{
    $parentMap = buildEnumerationLabelToIdMap($parentEnumerations);
    $childMap = buildEnumerationLabelToIdMap($childEnumerations);
    $normalized = [];

    foreach ($dependencies as $parentKey => $children) {
        $resolvedParentId = normalizeInteger($parentKey);

        if ($resolvedParentId === null && isset($parentMap[$parentKey])) {
            $resolvedParentId = $parentMap[$parentKey];
        }

        $normalizedParentKey = $resolvedParentId !== null ? (string)$resolvedParentId : (string)$parentKey;
        $normalized[$normalizedParentKey] = [];

        if (!is_array($children)) {
            continue;
        }

        foreach ($children as $childValue) {
            $childLabel = is_array($childValue)
                ? ($childValue['value'] ?? ($childValue['name'] ?? null))
                : $childValue;

            if ($childLabel === null) {
                continue;
            }

            $resolvedChildId = normalizeInteger($childLabel);

            if ($resolvedChildId === null && isset($childMap[$childLabel])) {
                $resolvedChildId = $childMap[$childLabel];
            }

            $normalized[$normalizedParentKey][] = $resolvedChildId !== null ? $resolvedChildId : (string)$childLabel;
        }
    }

    return $normalized;
}

/**
 * @param array<int, array<string, mixed>> $plan
 */
function decodeJsonColumn(mixed $value): array|string|null
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
        $decoded = json_decode($stringValue, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return $stringValue;
    }

    return $decoded;
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
function determinePhasesToRun(array $cliOptions): array
{
    $requestedPhases = [];
    $skippedPhases = [];

    if (!empty($cliOptions['phases'])) {
        $requestedPhases = array_filter(array_map('trim', explode(',', (string)$cliOptions['phases'])));
    }

    if (!empty($cliOptions['skip'])) {
        $skippedPhases = array_filter(array_map('trim', explode(',', (string)$cliOptions['skip'])));
    }

    if ($requestedPhases === []) {
        $requestedPhases = array_keys(AVAILABLE_PHASES);
    }

    $phases = [];
    foreach ($requestedPhases as $phase) {
        $normalizedPhase = strtolower($phase);
        if (!isset(AVAILABLE_PHASES[$normalizedPhase])) {
            throw new RuntimeException(sprintf('Unknown phase "%s".', $phase));
        }

        if (in_array($normalizedPhase, $skippedPhases, true)) {
            continue;
        }

        $phases[] = $normalizedPhase;
    }

    if ($phases === []) {
        throw new RuntimeException('No phases selected after applying --phases/--skip filters.');
    }

    return $phases;
}

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function extractArrayConfig(array $config, string $key): array
{
    if (!isset($config[$key]) || !is_array($config[$key])) {
        throw new RuntimeException(sprintf('Missing configuration section "%s".', $key));
    }

    return $config[$key];
}

/**
 * @param array<string, mixed> $databaseConfig
 */
function createDatabaseConnection(array $databaseConfig): PDO
{
    $dsn = isset($databaseConfig['dsn']) ? (string)$databaseConfig['dsn'] : '';
    if ($dsn === '') {
        throw new RuntimeException('Database DSN not configured.');
    }

    $username = isset($databaseConfig['username']) ? (string)$databaseConfig['username'] : '';
    $password = isset($databaseConfig['password']) ? (string)$databaseConfig['password'] : '';
    $options = isset($databaseConfig['options']) && is_array($databaseConfig['options']) ? $databaseConfig['options'] : [];

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to connect to the database: ' . $exception->getMessage(), 0, $exception);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

/**
 * @param array<string, mixed> $jiraConfig
 */
function createJiraClient(array $jiraConfig): Client
{
    $baseUrl = isset($jiraConfig['base_url']) ? rtrim((string)$jiraConfig['base_url'], '/') : '';
    $username = isset($jiraConfig['username']) ? (string)$jiraConfig['username'] : '';
    $apiToken = isset($jiraConfig['api_token']) ? (string)$jiraConfig['api_token'] : '';

    if ($baseUrl === '' || $username === '' || $apiToken === '') {
        throw new RuntimeException('Incomplete Jira configuration. base_url, username, and api_token are required.');
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
 * @param array<string, mixed> $redmineConfig
 */
function createRedmineClient(array $redmineConfig): Client
{
    $baseUrl = isset($redmineConfig['base_url']) ? rtrim((string)$redmineConfig['base_url'], '/') : '';
    $apiKey = isset($redmineConfig['api_key']) ? (string)$redmineConfig['api_key'] : '';

    if ($baseUrl === '' || $apiKey === '') {
        throw new RuntimeException('Incomplete Redmine configuration. base_url and api_key are required.');
    }

    return new Client([
        'base_uri' => $baseUrl,
        'headers' => [
            'Accept' => 'application/json',
            'X-Redmine-API-Key' => $apiKey,
        ],
    ]);
}

function decodeJsonResponse(ResponseInterface $response): mixed
{
    $body = trim((string)$response->getBody());
    if ($body === '') {
        return null;
    }

    try {
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $preview = preg_replace('/\s+/', ' ', substr($body, 0, 300));
        if ($preview === false) {
            $preview = '';
        }
        if (strlen($body) > 300) {
            $preview .= '...';
        }

        $message = 'Failed to decode JSON response: ' . $exception->getMessage();
        if ($preview !== '') {
            $message .= ' (body preview: ' . $preview . ')';
        }

        throw new RuntimeException($message, 0, $exception);
    }
}

function shouldUseExtendedApi(array $redmineConfig, bool $cliFlag): bool
{
    if ($cliFlag) {
        return true;
    }

    if (!isset($redmineConfig['extended_api']) || !is_array($redmineConfig['extended_api'])) {
        return false;
    }

    $extendedConfig = $redmineConfig['extended_api'];
    return !empty($extendedConfig['enabled']);
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

    return $prefix;
}

function buildExtendedApiPath(string $prefix, string $resource): string
{
    $normalizedPrefix = '/' . ltrim($prefix, '/');
    $normalizedResource = ltrim($resource, '/');

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

function verifyDependingCustomFieldsApi(Client $client): void
{
    try {
        $response = $client->get('/depending_custom_fields.json', ['query' => ['limit' => 1]]);
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf('redmine_depending_custom_fields endpoint returned HTTP %d.', $statusCode));
        }
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $statusCode = $response ? $response->getStatusCode() : 0;
        $reason = $response ? $response->getReasonPhrase() : 'unknown error';
        throw new RuntimeException(
            sprintf('redmine_depending_custom_fields availability check failed: HTTP %d %s', $statusCode, $reason),
            0,
            $exception
        );
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Failed to reach redmine_depending_custom_fields: ' . $exception->getMessage(), 0, $exception);
    }
}

function summarizeExtendedApiError(Throwable $exception): string
{
    if ($exception instanceof BadResponseException) {
        $response = $exception->getResponse();
        if ($response !== null) {
            $message = sprintf('HTTP %d %s', $response->getStatusCode(), $response->getReasonPhrase());
            $details = extractExtendedApiErrorDetails($response);
            if ($details !== null) {
                $message .= ': ' . $details;
            }

            return $message;
        }
    }

    return $exception->getMessage();
}

function extractCreatedCustomFieldId(mixed $decoded): int
{
    if (is_array($decoded)) {
        if (
            isset($decoded['custom_field'])
            && is_array($decoded['custom_field'])
            && isset($decoded['custom_field']['id'])
        ) {
            return (int)$decoded['custom_field']['id'];
        }

        if (
            isset($decoded['depending_custom_field'])
            && is_array($decoded['depending_custom_field'])
            && isset($decoded['depending_custom_field']['id'])
        ) {
            return (int)$decoded['depending_custom_field']['id'];
        }

        if (isset($decoded['id'])) {
            return (int)$decoded['id'];
        }
    }

    throw new RuntimeException('Unable to determine the new Redmine custom field ID from the extended API response.');
}

/**
 * @return array<int, mixed>|null
 */
function extractCreatedCustomFieldEnumerations(mixed $decoded): ?array
{
    if (!is_array($decoded)) {
        return null;
    }

    $candidates = [];
    if (isset($decoded['custom_field']) && is_array($decoded['custom_field'])) {
        $candidates[] = $decoded['custom_field']['enumerations'] ?? null;
    }
    if (isset($decoded['depending_custom_field']) && is_array($decoded['depending_custom_field'])) {
        $candidates[] = $decoded['depending_custom_field']['enumerations'] ?? null;
    }
    $candidates[] = $decoded['enumerations'] ?? null;

    foreach ($candidates as $candidate) {
        if (is_array($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function fetchRedmineCustomFieldEnumerations(
    Client $client,
    int $customFieldId,
    bool $useExtendedApi,
    ?string $extendedApiPrefix
): ?array {
    $path = $useExtendedApi && $extendedApiPrefix !== null
        ? buildExtendedApiPath($extendedApiPrefix, sprintf('custom_fields/%d.json', $customFieldId))
        : sprintf('/custom_fields/%d.json', $customFieldId);

    try {
        $response = $client->get($path);
        $decoded = decodeJsonResponse($response);

        return extractCreatedCustomFieldEnumerations($decoded);
    } catch (Throwable $exception) {
        return null;
    }
}

function extractExtendedApiErrorDetails(ResponseInterface $response): ?string
{
    $body = trim((string)$response->getBody());
    if ($body === '') {
        return null;
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return $body !== '' ? $body : null;
    }

    if (is_array($decoded)) {
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            return implode('; ', array_map('strval', $decoded['errors']));
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }
    }

    return $body !== '' ? $body : null;
}

function formatCurrentTimestamp(?string $format = null): string
{
    $format ??= DateTimeInterface::ATOM;

    return date($format);
}

function normalizeString(mixed $value, int $maxLength): ?string
{
    if (!is_scalar($value)) {
        return null;
    }

    $string = trim((string)$value);
    if ($string === '') {
        return null;
    }

    if (mb_strlen($string) > $maxLength) {
        $string = mb_substr($string, 0, $maxLength);
    }

    return $string;
}

function normalizeBooleanFlag(mixed $value): ?bool
{
    if ($value === null) {
        return null;
    }

    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value !== 0;
    }

    if (is_string($value)) {
        $lower = strtolower(trim($value));
        if ($lower === '' || $lower === 'null') {
            return null;
        }

        return in_array($lower, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    return null;
}

function normalizeBooleanDatabaseValue(mixed $value): ?int
{
    $normalized = normalizeBooleanFlag($value);
    if ($normalized === null) {
        return null;
    }

    return $normalized ? 1 : 0;
}

function normalizeInteger(mixed $value, ?int $default = null): ?int
{
    if ($value === null) {
        return $default;
    }

    if (is_int($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int)$value;
    }

    return $default;
}

function normalizeStoredAutomationHash(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $hash = trim((string)$value);
    if ($hash === '') {
        return null;
    }

    return $hash;
}

function formatBooleanForDisplay(?bool $value): string
{
    if ($value === null) {
        return 'n/a';
    }

    return $value ? 'yes' : 'no';
}

function formatIntegerForDisplay(?int $value): string
{
    return $value !== null ? (string)$value : 'n/a';
}

/**
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

    $argumentCount = count($argv);
    for ($i = 1; $i < $argumentCount; $i++) {
        $argument = $argv[$i];

        if ($argument === '-h' || $argument === '--help') {
            $options['help'] = true;
            continue;
        }

        if ($argument === '-V' || $argument === '--version') {
            $options['version'] = true;
            continue;
        }

        if (str_starts_with($argument, '--phases=')) {
            $options['phases'] = substr($argument, strlen('--phases='));
            continue;
        }

        if (str_starts_with($argument, '--skip=')) {
            $options['skip'] = substr($argument, strlen('--skip='));
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

        if (str_starts_with($argument, '-')) {
            throw new RuntimeException(sprintf('Unknown option "%s".', $argument));
        }

        $positional[] = $argument;
    }

    return [$options, $positional];
}

function printUsage(): void
{
    $scriptName = basename(__FILE__);

    echo sprintf(
        "%s (version %s)%s",
        $scriptName,
        MIGRATE_CUSTOM_FIELDS_SCRIPT_VERSION,
        PHP_EOL
    );
    echo sprintf('Usage: php %s [options]%s', $scriptName, PHP_EOL);
    echo PHP_EOL;
    echo "Options:" . PHP_EOL;
    echo "  -h, --help           Display this help message and exit." . PHP_EOL;
    echo "  -V, --version        Print the script version and exit." . PHP_EOL;
    echo "      --phases=LIST    Comma-separated list of phases to execute." . PHP_EOL;
    echo "      --skip=LIST      Comma-separated list of phases to skip." . PHP_EOL;
    echo "      --confirm-push   Mark custom fields as acknowledged after manual review." . PHP_EOL;
    echo "      --dry-run        Preview push-phase actions without updating migration status." . PHP_EOL;
    echo "      --use-extended-api  Push new custom fields through the redmine_extended_api plugin." . PHP_EOL;
    echo PHP_EOL;
    echo "Available phases:" . PHP_EOL;
    foreach (AVAILABLE_PHASES as $phase => $description) {
        echo sprintf("  %-7s %s%s", $phase, $description, PHP_EOL);
    }
    echo PHP_EOL;
    echo "Examples:" . PHP_EOL;
    echo sprintf('  php %s --help%s', $scriptName, PHP_EOL);
    echo sprintf('  php %s --phases=usage%s', $scriptName, PHP_EOL);
    echo sprintf('  php %s --phases=redmine%s', $scriptName, PHP_EOL);
    echo sprintf('  php %s --skip=jira%s', $scriptName, PHP_EOL);
    echo sprintf('  php %s --phases=push --dry-run%s', $scriptName, PHP_EOL);
    echo PHP_EOL;
}

function printVersion(): void
{
    printf('%s version %s%s', basename(__FILE__), MIGRATE_CUSTOM_FIELDS_SCRIPT_VERSION, PHP_EOL);
}

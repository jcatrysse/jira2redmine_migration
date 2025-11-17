<?php /** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_CUSTOM_FIELDS_SCRIPT_VERSION = '0.0.14';
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
        $redmineClient = createRedmineClient($redmineConfig);

        printf("[%s] Starting Redmine custom field snapshot...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalRedmineProcessed = fetchAndStoreRedmineCustomFields($redmineClient, $pdo);

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
        $useExtendedApi = shouldUseExtendedApi($redmineConfig, (bool)($cliOptions['use_extended_api'] ?? false));

        runCustomFieldPushPhase($pdo, $confirmPush, $isDryRun, $redmineConfig, $useExtendedApi);
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
        INSERT INTO staging_jira_fields (id, name, is_custom, schema_type, schema_custom, searcher_key, raw_payload, extracted_at)
        VALUES (:id, :name, :is_custom, :schema_type, :schema_custom, :searcher_key, :raw_payload, :extracted_at)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            is_custom = VALUES(is_custom),
            schema_type = VALUES(schema_type),
            schema_custom = VALUES(schema_custom),
            searcher_key = VALUES(searcher_key),
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
        $searcherKey = isset($field['searcherKey']) ? trim((string)$field['searcherKey']) : null;

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
            'searcher_key' => $searcherKey,
            'raw_payload' => $rawPayload,
            'extracted_at' => $now,
        ]);

        $processed++;

        if ($isCustom === 1) {
            $customFieldIds[] = $fieldId;
        }
    }

    printf("  Captured %d Jira field records.%s", $processed, PHP_EOL);

    if ($customFieldIds !== []) {
        refreshJiraFieldContexts($client, $pdo, array_values(array_unique($customFieldIds)));
    } else {
        printf("  No Jira custom fields detected; skipping context refresh.%s", PHP_EOL);
    }

    return $processed;
}

function refreshJiraFieldContexts(Client $client, PDO $pdo, array $customFieldIds): void
{
    printf("  Refreshing Jira custom field contexts and allowed values...%s", PHP_EOL);

    try {
        $pdo->exec('TRUNCATE TABLE staging_jira_field_contexts');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to truncate staging_jira_field_contexts: ' . $exception->getMessage(), 0, $exception);
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_field_contexts (
            field_id,
            context_id,
            name,
            is_global,
            is_any_issue_type,
            project_ids,
            issue_type_ids,
            options,
            raw_context,
            raw_options,
            extracted_at
        ) VALUES (
            :field_id,
            :context_id,
            :name,
            :is_global,
            :is_any_issue_type,
            :project_ids,
            :issue_type_ids,
            :options,
            :raw_context,
            :raw_options,
            :extracted_at
        )
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_field_contexts.');
    }

    $extractedAt = formatCurrentTimestamp('Y-m-d H:i:s');
    $fieldsWithContexts = 0;

    foreach ($customFieldIds as $fieldId) {
        $fieldId = trim((string)$fieldId);
        if ($fieldId === '') {
            continue;
        }

        $contexts = fetchJiraFieldContextsFromApi($client, $fieldId);
        if ($contexts === []) {
            continue;
        }

        $contextIds = [];
        foreach ($contexts as $context) {
            if (!isset($context['id'])) {
                continue;
            }

            $contextIds[] = (string)$context['id'];
        }

        $optionsByContext = [];
        if ($contextIds !== []) {
            $optionsByContext = fetchJiraFieldContextOptionsFromApi($client, $fieldId, $contextIds);
        }

        foreach ($contexts as $context) {
            if (!is_array($context) || !isset($context['id'])) {
                continue;
            }

            $contextId = (int)$context['id'];
            $contextName = isset($context['name']) ? trim((string)$context['name']) : null;
            $isGlobal = normalizeBooleanDatabaseValue($context['isGlobalContext'] ?? null);
            $isAnyIssueType = normalizeBooleanDatabaseValue($context['isAnyIssueType'] ?? null);

            $projectIds = [];
            if (isset($context['projectIds']) && is_array($context['projectIds'])) {
                foreach ($context['projectIds'] as $projectId) {
                    $projectIdString = trim((string)$projectId);
                    if ($projectIdString !== '') {
                        $projectIds[] = $projectIdString;
                    }
                }
            }

            $issueTypeIds = [];
            if (isset($context['issueTypeIds']) && is_array($context['issueTypeIds'])) {
                foreach ($context['issueTypeIds'] as $issueTypeId) {
                    $issueTypeIdString = trim((string)$issueTypeId);
                    if ($issueTypeIdString !== '') {
                        $issueTypeIds[] = $issueTypeIdString;
                    }
                }
            }

            $normalizedOptions = $optionsByContext[(string)$context['id']] ?? [];
            $rawOptions = $optionsByContext[(string)$context['id'] . '_raw'] ?? null;

            try {
                $rawContextJson = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException(sprintf('Failed to encode Jira context payload for field %s: %s', $fieldId, $exception->getMessage()), 0, $exception);
            }

            $insertStatement->execute([
                'field_id' => $fieldId,
                'context_id' => $contextId,
                'name' => $contextName,
                'is_global' => $isGlobal,
                'is_any_issue_type' => $isAnyIssueType,
                'project_ids' => encodeJsonColumn($projectIds),
                'issue_type_ids' => encodeJsonColumn($issueTypeIds),
                'options' => encodeJsonColumn($normalizedOptions),
                'raw_context' => $rawContextJson,
                'raw_options' => encodeJsonColumn($rawOptions),
                'extracted_at' => $extractedAt,
            ]);
        }

        $fieldsWithContexts++;
    }

    printf("  Captured context metadata for %d Jira custom fields.%s", $fieldsWithContexts, PHP_EOL);
}

function fetchJiraFieldContextsFromApi(Client $client, string $fieldId): array
{
    $contexts = [];
    $startAt = 0;
    $maxResults = 50;

    do {
        $query = http_build_query([
            'startAt' => $startAt,
            'maxResults' => $maxResults,
        ], '', '&', PHP_QUERY_RFC3986);

        $endpoint = sprintf('/rest/api/3/field/%s/context?%s', rawurlencode($fieldId), $query);

        try {
            $response = $client->get($endpoint);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = sprintf('Failed to fetch contexts for Jira field %s', $fieldId);
            if ($response !== null) {
                $status = $response->getStatusCode();
                if ($status === 404) {
                    printf("  [warn] Jira field %s has no context endpoint (HTTP 404).%s", $fieldId, PHP_EOL);
                    return [];
                }

                $message .= sprintf(' (HTTP %d)', $status);
            }

            throw new RuntimeException($message, 0, $exception);
        } catch (GuzzleException $exception) {
            throw new RuntimeException(sprintf('Failed to fetch contexts for Jira field %s: %s', $fieldId, $exception->getMessage()), 0, $exception);
        }

        $decoded = decodeJsonResponse($response);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Unexpected Jira context payload for field %s.', $fieldId));
        }

        $values = isset($decoded['values']) && is_array($decoded['values']) ? $decoded['values'] : [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $contexts[] = $value;
            }
        }

        $isLast = false;
        if (isset($decoded['isLast'])) {
            $isLast = (bool)$decoded['isLast'];
        } elseif (isset($decoded['total']) && isset($decoded['maxResults'])) {
            $total = (int)$decoded['total'];
            $max = (int)$decoded['maxResults'];
            $isLast = ($startAt + $max) >= $total;
        } else {
            $isLast = count($values) < $maxResults;
        }

        $startAt += count($values);
    } while (!$isLast);

    return $contexts;
}

function fetchJiraFieldContextOptionsFromApi(Client $client, string $fieldId, array $contextIds): array
{
    $optionsByContext = [];

    foreach ($contextIds as $contextId) {
        $contextId = trim((string)$contextId);
        if ($contextId === '') {
            continue;
        }

        $startAt = 0;
        $maxResults = 50;
        $collectedOptions = [];
        $rawOptions = [];

        do {
            $query = http_build_query([
                'startAt' => $startAt,
                'maxResults' => $maxResults,
            ], '', '&', PHP_QUERY_RFC3986);

            $endpoint = sprintf(
                '/rest/api/3/field/%s/context/%s/option?%s',
                rawurlencode($fieldId),
                rawurlencode($contextId),
                $query
            );

            try {
                $response = $client->get($endpoint);
            } catch (BadResponseException $exception) {
                $response = $exception->getResponse();
                if ($response !== null && in_array($response->getStatusCode(), [400, 404], true)) {
                    // The field either does not support options or the context has none.
                    $collectedOptions = [];
                    $rawOptions = [];
                    break;
                }

                $message = sprintf('Failed to fetch options for Jira field %s context %s', $fieldId, $contextId);
                if ($response !== null) {
                    $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                }

                throw new RuntimeException($message, 0, $exception);
            } catch (GuzzleException $exception) {
                throw new RuntimeException(sprintf('Failed to fetch options for Jira field %s context %s: %s', $fieldId, $contextId, $exception->getMessage()), 0, $exception);
            }

            $decoded = decodeJsonResponse($response);
            if (!is_array($decoded)) {
                throw new RuntimeException(sprintf('Unexpected Jira context option payload for field %s.', $fieldId));
            }

            $values = isset($decoded['values']) && is_array($decoded['values']) ? $decoded['values'] : [];
            foreach ($values as $value) {
                if (!is_array($value)) {
                    continue;
                }

                $rawOptions[] = $value;
                $normalized = normalizeJiraOption($value);
                if ($normalized === null) {
                    continue;
                }

                $collectedOptions[] = $normalized;
            }

            $isLast = false;
            if (isset($decoded['isLast'])) {
                $isLast = (bool)$decoded['isLast'];
            } elseif (isset($decoded['total']) && isset($decoded['maxResults'])) {
                $total = (int)$decoded['total'];
                $max = (int)$decoded['maxResults'];
                $isLast = ($startAt + $max) >= $total;
            } else {
                $isLast = count($values) < $maxResults;
            }

            $startAt += count($values);
        } while (!$isLast);

        if ($collectedOptions !== []) {
            usort($collectedOptions, static function (array $left, array $right): int {
                return strcmp((string)$left['value'], (string)$right['value']);
            });
        }

        $optionsByContext[$contextId] = $collectedOptions;
        if ($rawOptions !== []) {
            $optionsByContext[$contextId . '_raw'] = $rawOptions;
        }
    }

    return $optionsByContext;
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

    if (isset($option['cascadingOptions']) && is_array($option['cascadingOptions'])) {
        foreach ($option['cascadingOptions'] as $child) {
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

        if ($normalized['children'] !== []) {
            usort($normalized['children'], static function (array $left, array $right): int {
                return strcmp((string)$left['value'], (string)$right['value']);
            });
        }
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

/**
 * @return array<int, string>
 */
function fetchCustomJiraFieldIds(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT id
        FROM staging_jira_fields
        WHERE is_custom = 1
        ORDER BY id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch Jira custom field identifiers: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $ids = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['id'])) {
            continue;
        }

        $id = trim((string)$row['id']);
        if ($id !== '') {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * @return array<string, array<int, array<string, mixed>>>
 */
function loadJiraCustomFieldContextMap(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            field_id,
            context_id,
            name,
            is_global,
            is_any_issue_type,
            project_ids,
            issue_type_ids,
            options,
            raw_options
        FROM staging_jira_field_contexts
        ORDER BY field_id, context_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to load Jira custom field contexts: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $map = [];

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['field_id']) || !isset($row['context_id'])) {
            continue;
        }

        $fieldId = trim((string)$row['field_id']);
        if ($fieldId === '') {
            continue;
        }

        $context = [
            'context_id' => (string)$row['context_id'],
            'name' => isset($row['name']) ? trim((string)$row['name']) : null,
            'is_global' => normalizeBooleanFlag($row['is_global'] ?? null) ?? null,
            'is_any_issue_type' => normalizeBooleanFlag($row['is_any_issue_type'] ?? null) ?? null,
            'project_ids' => array_values(array_filter((array)decodeJsonColumn($row['project_ids'] ?? null), static function ($value): bool {
                return trim((string)$value) !== '';
            })),
            'issue_type_ids' => array_values(array_filter((array)decodeJsonColumn($row['issue_type_ids'] ?? null), static function ($value): bool {
                return trim((string)$value) !== '';
            })),
            'options' => (array)decodeJsonColumn($row['options'] ?? null),
            'raw_options' => (array)decodeJsonColumn($row['raw_options'] ?? null),
        ];

        $map[$fieldId][] = $context;
    }

    return $map;
}

/**
 * @return array<string, string>
 */
function buildJiraProjectNameLookup(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT id, name
        FROM staging_jira_projects
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build Jira project name lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $lookup = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['id'])) {
            continue;
        }

        $projectId = trim((string)$row['id']);
        if ($projectId === '') {
            continue;
        }

        $lookup[$projectId] = isset($row['name']) ? trim((string)$row['name']) : $projectId;
    }

    return $lookup;
}

/**
 * @return array<string, string>
 */
function buildJiraIssueTypeNameLookup(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT id, name
        FROM staging_jira_issue_types
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build Jira issue type name lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $lookup = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['id'])) {
            continue;
        }

        $issueTypeId = trim((string)$row['id']);
        if ($issueTypeId === '') {
            continue;
        }

        $lookup[$issueTypeId] = isset($row['name']) ? trim((string)$row['name']) : $issueTypeId;
    }

    return $lookup;
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
 * @param array<int, array<string, mixed>> $contexts
 * @return array<int, array<string, mixed>>
 */
function buildJiraFieldContextGroups(string $fieldId, array $contexts, array $projectNames, array $issueTypeNames): array
{
    if ($contexts === []) {
        return [];
    }

    $groups = [];

    foreach ($contexts as $context) {
        $options = isset($context['options']) && is_array($context['options']) ? $context['options'] : [];
        $rawOptions = isset($context['raw_options']) && is_array($context['raw_options']) ? $context['raw_options'] : [];
        $normalizedOptions = normalizeOptionsWithChildren($options, $rawOptions);
        $fingerprint = computeContextOptionsFingerprint($normalizedOptions);

        if (!isset($groups[$fingerprint])) {
            $groups[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'contexts' => [],
            ];
        }

        $groups[$fingerprint]['contexts'][] = array_merge($context, [
            'normalized_options' => $normalizedOptions,
        ]);
    }

    $result = [];

    foreach ($groups as $group) {
        $contextIds = [];
        $projectIds = [];
        $issueTypeIds = [];
        $parentOptions = [];
        $childOptionMap = [];
        $isCascading = false;

        foreach ($group['contexts'] as $context) {
            $contextId = isset($context['context_id']) ? trim((string)$context['context_id']) : '';
            if ($contextId !== '') {
                $contextIds[] = $contextId;
            }

            if (isset($context['project_ids']) && is_array($context['project_ids'])) {
                foreach ($context['project_ids'] as $projectId) {
                    $projectIdString = trim((string)$projectId);
                    if ($projectIdString !== '') {
                        $projectIds[] = $projectIdString;
                    }
                }
            }

            if (isset($context['issue_type_ids']) && is_array($context['issue_type_ids'])) {
                foreach ($context['issue_type_ids'] as $issueTypeId) {
                    $issueTypeIdString = trim((string)$issueTypeId);
                    if ($issueTypeIdString !== '') {
                        $issueTypeIds[] = $issueTypeIdString;
                    }
                }
            }

            $normalizedOptions = isset($context['normalized_options']) && is_array($context['normalized_options'])
                ? $context['normalized_options']
                : [];

            foreach ($normalizedOptions as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $value = isset($option['value']) ? trim((string)$option['value']) : '';
                if ($value === '') {
                    continue;
                }

                $disabled = normalizeBooleanFlag($option['disabled'] ?? null) ?? false;
                if (!isset($parentOptions[$value]) || ($parentOptions[$value]['disabled'] ?? false) !== false) {
                    $parentOptions[$value] = [
                        'id' => isset($option['id']) ? (string)$option['id'] : null,
                        'value' => $value,
                        'disabled' => $disabled,
                    ];
                }

                if (isset($option['children']) && is_array($option['children']) && $option['children'] !== []) {
                    $isCascading = true;
                    foreach ($option['children'] as $child) {
                        if (!is_array($child)) {
                            continue;
                        }

                        $childValue = isset($child['value']) ? trim((string)$child['value']) : '';
                        if ($childValue === '') {
                            continue;
                        }

                        $childDisabled = normalizeBooleanFlag($child['disabled'] ?? null) ?? false;
                        if ($childDisabled) {
                            continue;
                        }

                        $childOptionMap[$value][$childValue] = [
                            'id' => isset($child['id']) ? (string)$child['id'] : null,
                            'value' => $childValue,
                        ];
                    }
                }
            }
        }

        sort($contextIds);
        sort($projectIds);
        sort($issueTypeIds);
        ksort($parentOptions);

        if ($isCascading) {
            $structuredParents = [];
            foreach ($parentOptions as $parentValue => $parentOption) {
                if (($parentOption['disabled'] ?? false) === true) {
                    continue;
                }

                $structuredParents[] = [
                    'id' => $parentOption['id'] ?? null,
                    'value' => $parentValue,
                ];
            }

            $structuredDependencies = [];
            foreach ($parentOptions as $parentValue => $parentOption) {
                $children = $childOptionMap[$parentValue] ?? [];
                ksort($children);
                $structuredDependencies[$parentValue] = array_values($children);
            }

            $allowedPayload = [
                'mode' => 'cascading',
                'parents' => $structuredParents,
                'dependencies' => $structuredDependencies,
            ];
        } else {
            $flatValues = [];
            foreach ($parentOptions as $parentValue => $parentOption) {
                if (($parentOption['disabled'] ?? false) === true) {
                    continue;
                }

                $flatValues[] = [
                    'id' => $parentOption['id'] ?? null,
                    'value' => $parentValue,
                ];
            }

            $allowedPayload = [
                'mode' => 'flat',
                'values' => $flatValues,
            ];
        }

        try {
            $contextScopeHash = sha1(json_encode([
                'field' => $fieldId,
                'fingerprint' => $group['fingerprint'],
                'contexts' => $contextIds,
                'projects' => $projectIds,
                'issue_types' => $issueTypeIds,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to compute custom field context hash: ' . $exception->getMessage(), 0, $exception);
        }

        $contextScopeLabel = deriveContextScopeLabel($group['contexts'], $projectIds, $issueTypeIds, $projectNames, $issueTypeNames);

        $result[] = [
            'context_scope_hash' => $contextScopeHash,
            'context_scope_label' => $contextScopeLabel,
            'context_ids' => $contextIds,
            'project_ids' => array_values(array_unique($projectIds)),
            'issue_type_ids' => array_values(array_unique($issueTypeIds)),
            'allowed_values' => $allowedPayload,
        ];
    }

    return $result;
}

function computeContextOptionsFingerprint(array $options): string
{
    if ($options === []) {
        return sha1('no-options');
    }

    $normalized = [];
    foreach ($options as $option) {
        if (!is_array($option)) {
            continue;
        }

        $value = isset($option['value']) ? trim((string)$option['value']) : '';
        $disabled = normalizeBooleanFlag($option['disabled'] ?? null) ?? false;
        $children = [];

        if (isset($option['children']) && is_array($option['children'])) {
            foreach ($option['children'] as $child) {
                if (!is_array($child)) {
                    continue;
                }

                $childValue = isset($child['value']) ? trim((string)$child['value']) : '';
                if ($childValue === '') {
                    continue;
                }

                $children[] = [
                    'value' => $childValue,
                    'disabled' => normalizeBooleanFlag($child['disabled'] ?? null) ?? false,
                ];
            }

            if ($children !== []) {
                usort($children, static function (array $left, array $right): int {
                    return strcmp($left['value'], $right['value']);
                });
            }
        }

        $normalized[] = [
            'value' => $value,
            'disabled' => $disabled,
            'children' => $children,
        ];
    }

    usort($normalized, static function (array $left, array $right): int {
        return strcmp($left['value'], $right['value']);
    });

    try {
        return sha1(json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to compute Jira context fingerprint: ' . $exception->getMessage(), 0, $exception);
    }
}

function parseCascadingAllowedValues(array $allowedValues): ?array
{
    if (!isset($allowedValues['mode']) || $allowedValues['mode'] !== 'cascading') {
        return null;
    }

    $parents = [];
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
        }
    }

    ksort($parents);

    $dependencies = [];
    $childUnion = [];

    if (isset($allowedValues['dependencies']) && is_array($allowedValues['dependencies'])) {
        foreach ($allowedValues['dependencies'] as $parentValue => $children) {
            $parentKey = trim((string)$parentValue);
            if ($parentKey === '') {
                continue;
            }

            $normalizedChildren = [];
            if (is_array($children)) {
                foreach ($children as $child) {
                    if (is_array($child)) {
                        $childValue = isset($child['value']) ? trim((string)$child['value']) : '';
                    } else {
                        $childValue = trim((string)$child);
                    }

                    if ($childValue === '') {
                        continue;
                    }

                    $normalizedChildren[$childValue] = $childValue;
                    $childUnion[$childValue] = $childValue;
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
    ];
}

/**
 * @param array<int, array<string, mixed>> $contexts
 * @param array<int, string> $projectIds
 * @param array<int, string> $issueTypeIds
 * @return string
 */
function deriveContextScopeLabel(array $contexts, array $projectIds, array $issueTypeIds, array $projectNames, array $issueTypeNames): string
{
    $contextNames = [];
    foreach ($contexts as $context) {
        if (!isset($context['name'])) {
            continue;
        }

        $name = trim((string)$context['name']);
        if ($name === '') {
            continue;
        }

        if (!isset($contextNames[$name])) {
            $contextNames[$name] = $name;
        }
    }

    if ($contextNames !== []) {
        $label = summarizeContextScopeValues(array_values($contextNames));
        $normalized = normalizeString($label, 255);

        return $normalized ?? 'Global';
    }

    $parts = [];

    $projectIds = array_values(array_unique($projectIds));
    if ($projectIds !== []) {
        $labels = [];
        foreach ($projectIds as $projectId) {
            $label = $projectNames[$projectId] ?? $projectId;
            $labels[$label] = $label;
        }

        $labels = array_values($labels);
        $summary = summarizeContextScopeValues($labels);
        if ($summary !== '') {
            $prefix = count($labels) > 1 ? 'Projects ' : 'Project ';
            $parts[] = $prefix . $summary;
        }
    }

    $issueTypeIds = array_values(array_unique($issueTypeIds));
    if ($issueTypeIds !== []) {
        $labels = [];
        foreach ($issueTypeIds as $issueTypeId) {
            $label = $issueTypeNames[$issueTypeId] ?? $issueTypeId;
            $labels[$label] = $label;
        }

        $labels = array_values($labels);
        $summary = summarizeContextScopeValues($labels);
        if ($summary !== '') {
            $prefix = count($labels) > 1 ? 'Issue types ' : 'Issue type ';
            $parts[] = $prefix . $summary;
        }
    }

    if ($parts === []) {
        return 'Global';
    }

    $label = implode(' / ', $parts);
    $normalized = normalizeString($label, 255);

    return $normalized ?? 'Global';
}

/**
 * Summarize a list of project or issue-type labels into a compact, human-friendly description.
 *
 * @param array<int, string> $values
 */
function summarizeContextScopeValues(array $values, int $maxValues = 5, int $maxLength = 200): string
{
    $deduplicated = [];
    foreach ($values as $value) {
        if (!is_scalar($value)) {
            continue;
        }

        $stringValue = trim((string)$value);
        if ($stringValue === '') {
            continue;
        }

        if (mb_strlen($stringValue) > 60) {
            $stringValue = rtrim(mb_substr($stringValue, 0, 57)) . '…';
        }

        if (!isset($deduplicated[$stringValue])) {
            $deduplicated[$stringValue] = $stringValue;
        }
    }

    $values = array_values($deduplicated);
    $total = count($values);
    if ($total === 0) {
        return '';
    }

    if ($maxValues > 0 && $total > $maxValues) {
        $display = array_slice($values, 0, $maxValues);
        $summary = implode(', ', $display) . sprintf(' (+%d more)', $total - $maxValues);
    } else {
        $summary = implode(', ', $values);
    }

    if ($maxLength > 0 && mb_strlen($summary) > $maxLength) {
        $summary = rtrim(mb_substr($summary, 0, $maxLength - 1)) . '…';
    }

    return $summary;
}

function removeObsoleteCustomFieldMappings(PDO $pdo, string $fieldId, array $validHashes): void
{
    $fieldId = trim($fieldId);
    if ($fieldId === '') {
        return;
    }

    if ($validHashes === []) {
        $sql = 'DELETE FROM migration_mapping_custom_fields WHERE jira_field_id = :field_id';
        $params = ['field_id' => $fieldId];
    } else {
        $placeholders = implode(', ', array_fill(0, count($validHashes), '?'));
        $sql = sprintf(
            'DELETE FROM migration_mapping_custom_fields WHERE jira_field_id = ? AND context_scope_hash NOT IN (%s)',
            $placeholders
        );
        $params = array_merge([$fieldId], $validHashes);
    }

    try {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare statement for pruning custom field mappings.');
        }

        $statement->execute($params);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to prune obsolete custom field mappings: ' . $exception->getMessage(), 0, $exception);
    }
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
function fetchAndStoreRedmineCustomFields(Client $client, PDO $pdo): int
{
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

            if (isset($seenIds[$id])) {
                $duplicateIds[$id] = true;
            } else {
                $seenIds[$id] = true;
            }

            $name = isset($customField['name']) ? trim((string)$customField['name']) : (string)$id;
            $customizedType = isset($customField['customized_type']) ? trim((string)$customField['customized_type']) : null;
            $fieldFormat = isset($customField['field_format']) ? trim((string)$customField['field_format']) : null;
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
                $rawPayload = json_encode($customField, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    $redmineLookup = buildRedmineCustomFieldLookup($pdo);
    $jiraProjectToRedmine = buildJiraToRedmineProjectLookup($pdo);
    $jiraIssueTypeToTracker = buildJiraToRedmineTrackerLookup($pdo);
    $mappings = fetchCustomFieldMappingsForTransform($pdo);

    $fieldGroupCounts = [];
    foreach ($mappings as $row) {
        if (!isset($row['jira_field_id'])) {
            continue;
        }

        $fieldId = (string)$row['jira_field_id'];
        if ($fieldId === '') {
            continue;
        }

        $fieldGroupCounts[$fieldId] = ($fieldGroupCounts[$fieldId] ?? 0) + 1;
    }

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_custom_fields
        SET
            redmine_custom_field_id = :redmine_custom_field_id,
            migration_status = :migration_status,
            notes = :notes,
            proposed_redmine_name = :proposed_redmine_name,
            proposed_field_format = :proposed_field_format,
            proposed_is_required = :proposed_is_required,
            proposed_is_filter = :proposed_is_filter,
            proposed_is_for_all = :proposed_is_for_all,
            proposed_is_multiple = :proposed_is_multiple,
            proposed_possible_values = :proposed_possible_values,
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
        $contextScopeLabel = isset($row['context_scope_label']) && $row['context_scope_label'] !== null
            ? trim((string)$row['context_scope_label'])
            : null;

        $currentRedmineId = isset($row['redmine_custom_field_id']) ? (int)$row['redmine_custom_field_id'] : null;
        $currentRedmineParentId = isset($row['redmine_parent_custom_field_id']) ? (int)$row['redmine_parent_custom_field_id'] : null;
        $currentNotes = isset($row['notes']) ? (string)$row['notes'] : null;
        $currentProposedName = isset($row['proposed_redmine_name']) ? (string)$row['proposed_redmine_name'] : null;
        $currentProposedFormat = isset($row['proposed_field_format']) ? (string)$row['proposed_field_format'] : null;
        $currentProposedIsRequired = normalizeBooleanFlag($row['proposed_is_required'] ?? null);
        $currentProposedIsFilter = normalizeBooleanFlag($row['proposed_is_filter'] ?? null);
        $currentProposedIsForAll = normalizeBooleanFlag($row['proposed_is_for_all'] ?? null);
        $currentProposedIsMultiple = normalizeBooleanFlag($row['proposed_is_multiple'] ?? null);
        $currentProposedPossibleValuesRaw = isset($row['proposed_possible_values']) ? (string)$row['proposed_possible_values'] : null;
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
            $currentProposedDefaultValue,
            $currentProposedTrackerIdsRaw,
            $currentProposedRoleIdsRaw,
            $currentProposedProjectIdsRaw,
            $currentNotes,
            $currentRedmineParentId
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
        $hasMultipleGroups = ($fieldGroupCounts[$jiraFieldId] ?? 1) > 1;
        $contextSuffix = $contextScopeLabel !== null && $contextScopeLabel !== '' ? $contextScopeLabel : null;

        $contextualDefaultName = null;
        if ($defaultName !== null) {
            $contextualDefaultName = $hasMultipleGroups && $contextSuffix !== null && strtolower($contextSuffix) !== 'global'
                ? normalizeString(sprintf('%s (Context %s)', $defaultName, $contextSuffix), 255)
                : $defaultName;
        }

        $proposedName = $currentProposedName;
        if ($proposedName === null) {
            $proposedName = $contextualDefaultName ?? $defaultName ?? $jiraFieldId;
        } elseif ($contextualDefaultName !== null && $proposedName === $defaultName) {
            $proposedName = $contextualDefaultName;
        }
        $proposedName = $proposedName !== null ? normalizeString($proposedName, 255) : null;

        $proposedFormat = $currentProposedFormat;
        $proposedIsRequired = $currentProposedIsRequired ?? false;
        $proposedIsFilter = $currentProposedIsFilter ?? true;
        $proposedIsForAll = $currentProposedIsForAll ?? true;
        $proposedIsMultiple = $currentProposedIsMultiple ?? false;
        $proposedDefaultValue = $currentProposedDefaultValue;

        $classification = classifyJiraCustomField($jiraSchemaType, $jiraSchemaCustom);
        if ($proposedFormat === null) {
            $proposedFormat = $classification['field_format'];
        }

        if ($classification['is_multiple'] !== null) {
            $proposedIsMultiple = $classification['is_multiple'];
        }

        $manualReasons = [];
        $infoNotes = [$usageNote];

        $autoIgnoreUnused = $usageTotalIssues > 0 && $usageIssuesWithValue === 0 && $usageIssuesWithNonEmpty === 0;
        if ($autoIgnoreUnused && in_array($currentStatus, $allowedStatuses, true)) {
            $notesParts = [];
            if ($currentNotes !== null && trim($currentNotes) !== '') {
                $notesParts[] = trim($currentNotes);
            }

            $notesParts[] = 'Automatically ignored: no staged issues contain values for this custom field. ' . $usageNote;
            $notes = implode(' ', array_unique($notesParts));

            $proposedPossibleValuesJson = encodeJsonColumn($proposedPossibleValues);
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
                $proposedDefaultValue,
                $proposedTrackerIdsJson,
                $proposedRoleIdsJson,
                $proposedProjectIdsJson,
                $notes,
                $currentRedmineParentId
            );

            $updateStatement->execute([
                'redmine_custom_field_id' => $currentRedmineId,
                'migration_status' => 'IGNORED',
                'notes' => $notes,
                'proposed_redmine_name' => $proposedName,
                'proposed_field_format' => $proposedFormat,
                'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                'proposed_possible_values' => $proposedPossibleValuesJson,
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

        $isCascadingField = !empty($classification['is_cascading']);
        $cascadingDescriptor = null;
        if ($isCascadingField) {
            $cascadingDescriptor = parseCascadingAllowedValues($jiraAllowedValues);
            if ($cascadingDescriptor === null) {
                $manualReasons[] = 'Unable to parse cascading Jira custom field options for dependent field creation.';
            } else {
                if ($cascadingDescriptor['child_values'] === []) {
                    $manualReasons[] = 'Cascading Jira custom field does not expose any child options.';
                } else {
                    $proposedPossibleValues = $cascadingDescriptor['child_values'];
                }

                if ($cascadingDescriptor['parents'] === []) {
                    $manualReasons[] = 'Cascading Jira custom field does not expose any parent options.';
                }

                $infoNotes[] = 'Will use the redmine_depending_custom_fields API for dependent list creation.';
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

                    $value = isset($option['value']) ? trim((string)$option['value']) : '';
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

                    $value = isset($option['value']) ? trim((string)$option['value']) : '';
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
                $manualReasons[] = 'List-style Jira field requires allowed option values; unable to derive from contexts.';
            }
        }

        $lookupCandidates = [];
        if ($proposedName !== null) {
            $lookupCandidates[] = strtolower($proposedName);
        }
        if ($contextualDefaultName !== null) {
            $lookupCandidates[] = strtolower($contextualDefaultName);
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
            $proposedFormat = $matchedRedmine['field_format'] ?? $proposedFormat;
            $proposedIsRequired = normalizeBooleanFlag($matchedRedmine['is_required'] ?? null) ?? $proposedIsRequired;
            $proposedIsFilter = normalizeBooleanFlag($matchedRedmine['is_filter'] ?? null) ?? $proposedIsFilter;
            $proposedIsForAll = normalizeBooleanFlag($matchedRedmine['is_for_all'] ?? null) ?? $proposedIsForAll;
            $proposedIsMultiple = normalizeBooleanFlag($matchedRedmine['is_multiple'] ?? null) ?? $proposedIsMultiple;

            $matchedPossibleValues = decodeJsonColumn($matchedRedmine['possible_values'] ?? null);
            if (is_array($matchedPossibleValues)) {
                $proposedPossibleValues = array_values($matchedPossibleValues);
            }

            if (isset($matchedRedmine['default_value']) && $matchedRedmine['default_value'] !== null) {
                $proposedDefaultValue = (string)$matchedRedmine['default_value'];
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

        if ($hasMultipleGroups) {
            $infoNotes[] = sprintf('Context-specific variant covering %s.', $contextSuffix ?? 'selected Jira contexts');
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
        $proposedTrackerIdsJson = encodeJsonColumn($proposedTrackerIds);
        $proposedRoleIdsJson = encodeJsonColumn($proposedRoleIds);
        $proposedProjectIdsJson = encodeJsonColumn($proposedProjectIds);

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
            $proposedDefaultValue,
            $proposedTrackerIdsJson,
            $proposedRoleIdsJson,
            $proposedProjectIdsJson,
            $notes,
            $currentRedmineParentId
        );

        $updateStatement->execute([
            'redmine_custom_field_id' => $newRedmineId,
            'migration_status' => $newStatus,
            'notes' => $notes,
            'proposed_redmine_name' => $proposedName,
            'proposed_field_format' => $proposedFormat,
            'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
            'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
            'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
            'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
            'proposed_possible_values' => $proposedPossibleValuesJson,
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
    $fields = fetchCustomJiraFieldIds($pdo);
    if ($fields === []) {
        return;
    }

    $contextMap = loadJiraCustomFieldContextMap($pdo);
    $projectNameLookup = buildJiraProjectNameLookup($pdo);
    $issueTypeNameLookup = buildJiraIssueTypeNameLookup($pdo);

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO migration_mapping_custom_fields (
            jira_field_id,
            context_scope_hash,
            context_scope_label,
            jira_context_ids,
            jira_project_ids,
            jira_issue_type_ids,
            jira_allowed_values,
            migration_status,
            notes,
            created_at,
            last_updated_at
        ) VALUES (
            :jira_field_id,
            :context_scope_hash,
            :context_scope_label,
            :jira_context_ids,
            :jira_project_ids,
            :jira_issue_type_ids,
            :jira_allowed_values,
            'PENDING_ANALYSIS',
            NULL,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            context_scope_label = VALUES(context_scope_label),
            jira_context_ids = VALUES(jira_context_ids),
            jira_project_ids = VALUES(jira_project_ids),
            jira_issue_type_ids = VALUES(jira_issue_type_ids),
            jira_allowed_values = VALUES(jira_allowed_values),
            last_updated_at = VALUES(last_updated_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for migration_mapping_custom_fields.');
    }

    foreach ($fields as $fieldId) {
        $contexts = $contextMap[$fieldId] ?? [];
        $groups = buildJiraFieldContextGroups($fieldId, $contexts, $projectNameLookup, $issueTypeNameLookup);
        if ($groups === []) {
            $groups = [[
                'context_scope_hash' => sha1('global'),
                'context_scope_label' => 'Global',
                'context_ids' => [],
                'project_ids' => [],
                'issue_type_ids' => [],
                'allowed_values' => [],
            ]];
        }

        $validHashes = [];
        foreach ($groups as $group) {
            $contextScopeHash = (string)$group['context_scope_hash'];
            $validHashes[] = $contextScopeHash;

            $insertStatement->execute([
                'jira_field_id' => $fieldId,
                'context_scope_hash' => $contextScopeHash,
                'context_scope_label' => $group['context_scope_label'] ?? null,
                'jira_context_ids' => encodeJsonColumn($group['context_ids'] ?? []),
                'jira_project_ids' => encodeJsonColumn($group['project_ids'] ?? []),
                'jira_issue_type_ids' => encodeJsonColumn($group['issue_type_ids'] ?? []),
                'jira_allowed_values' => encodeJsonColumn($group['allowed_values'] ?? []),
            ]);
        }

        removeObsoleteCustomFieldMappings($pdo, $fieldId, $validHashes);
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
            map.jira_schema_custom = jf.schema_custom,
            map.jira_searcher_key = jf.searcher_key
        WHERE jf.is_custom = 1
    SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to refresh Jira custom field metadata in migration_mapping_custom_fields: ' . $exception->getMessage(), 0, $exception);
    }
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
        WHERE jira_field_id = :field_id AND path IS NULL AND source = 'inferred'
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
            NULL,
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
            $proposedName,
            $targetFormat,
            $isArray,
            $valueSourcePath,
            $keySourcePath,
            implode(' ', $notes)
        );

        $existingStatement->execute(['field_id' => $fieldId]);
        $existingHash = $existingStatement->fetchColumn();

        $upsertStatement->execute([
            'jira_field_id' => $fieldId,
            'jira_field_name' => $definition['name'] ?? null,
            'jira_schema_custom' => $definition['schema_custom'] ?? null,
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
    ?string $targetFieldName,
    string $targetFieldFormat,
    bool $targetIsMultiple,
    ?string $valueSourcePath,
    ?string $keySourcePath,
    string $notes
): string {
    $payload = [
        'field_id' => $fieldId,
        'schema_custom' => $schemaCustom,
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
            map.jira_searcher_key,
            map.context_scope_hash,
            map.context_scope_label,
            map.jira_context_ids,
            map.jira_project_ids,
            map.jira_issue_type_ids,
            map.jira_allowed_values,
            map.redmine_custom_field_id,
            map.redmine_parent_custom_field_id,
            map.migration_status,
            map.notes,
            map.proposed_redmine_name,
            map.proposed_field_format,
            map.proposed_is_required,
            map.proposed_is_filter,
            map.proposed_is_for_all,
            map.proposed_is_multiple,
            map.proposed_possible_values,
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
            $result['field_format'] = 'list';
            $result['requires_possible_values'] = true;
            if (str_contains($normalizedCustom, ':multiselect') || str_contains($normalizedCustom, ':checkboxes')) {
                $result['is_multiple'] = true;
            }
            return $result;
        }

        if (str_contains($normalizedCustom, ':cascadingselect')) {
            $result['field_format'] = 'depending_list';
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
                $result['field_format'] = 'text';
                $result['note'] = 'Object-type Jira field; review inferred proposals in migration_mapping_custom_object.';
                break;
            case 'team':
            case 'sd-customerrequesttype':
                $result['field_format'] = 'list';
                $result['requires_possible_values'] = true;
                $result['note'] = 'App/Service Desk selector; will derive option labels from Jira allowed values. Consider a key/value list if you need stable IDs.';
                break;
            case 'option':
            case 'option2':
                $result['field_format'] = 'list';
                $result['requires_possible_values'] = true;
                $result['note'] = 'Single-select option field; populating Redmine list from Jira allowed values.';
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
            case 'array':
                $result['field_format'] = 'text';
                $result['note'] = 'Array-type Jira field mapped to Redmine text. Review if a list with multiple values is required.';
                break;
            default:
                $result['requires_manual_review'] = true;
                $result['note'] = sprintf('Unhandled Jira schema type "%s"; review manually.', $schemaType ?? 'unknown');
        }
    } else {
        $result['requires_manual_review'] = true;
        $result['note'] = 'Unable to detect Jira schema type; review manually.';
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
    ?string $proposedDefaultValue,
    ?string $proposedTrackerIds,
    ?string $proposedRoleIds,
    ?string $proposedProjectIds,
    ?string $notes,
    ?int $redmineParentCustomFieldId = null
): string {
    $payload = [
        'redmine_custom_field_id' => $redmineCustomFieldId,
        'migration_status' => $migrationStatus,
        'proposed_redmine_name' => $proposedName,
        'proposed_field_format' => $proposedFieldFormat,
        'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
        'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
        'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
        'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
        'proposed_possible_values' => normalizeJsonForHash($proposedPossibleValues),
        'proposed_default_value' => $proposedDefaultValue,
        'proposed_tracker_ids' => normalizeJsonForHash($proposedTrackerIds),
        'proposed_role_ids' => normalizeJsonForHash($proposedRoleIds),
        'proposed_project_ids' => normalizeJsonForHash($proposedProjectIds),
        'notes' => $notes,
        'redmine_parent_custom_field_id' => $redmineParentCustomFieldId,
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

    try {
        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        return $trimmed;
    }
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
    $sql = 'SELECT id, name, project_ids, tracker_ids FROM staging_redmine_custom_fields';

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
        $id = normalizeInteger($row['id'] ?? null);
        if ($id === null) {
            continue;
        }

        $snapshot[$id] = [
            'name' => isset($row['name']) ? (string)$row['name'] : sprintf('Custom field #%d', $id),
            'project_ids' => normalizeIntegerListColumn($row['project_ids'] ?? null),
            'tracker_ids' => normalizeIntegerListColumn($row['tracker_ids'] ?? null),
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
            mapping_id,
            jira_field_id,
            jira_field_name,
            redmine_custom_field_id,
            redmine_parent_custom_field_id,
            proposed_redmine_name,
            proposed_field_format,
            proposed_is_required,
            proposed_is_filter,
            proposed_is_for_all,
            proposed_is_multiple,
            proposed_possible_values,
            proposed_default_value,
            proposed_tracker_ids,
            proposed_role_ids,
            proposed_project_ids,
            migration_status,
            notes
        FROM migration_mapping_custom_fields
        WHERE
            redmine_custom_field_id IS NOT NULL
            AND migration_status IN ('READY_FOR_UPDATE', 'CREATION_SUCCESS')
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
        $parentId = normalizeInteger($row['redmine_parent_custom_field_id'] ?? null);

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

        if ($parentId !== null) {
            if (!isset($snapshot[$parentId])) {
                $warnings[] = sprintf(
                    'Missing Redmine snapshot entry for parent custom field #%d; re-run the redmine phase.',
                    $parentId
                );
                continue;
            }

            $parentProjects = mergeIntegerLists($snapshot[$parentId]['project_ids'], $mergedProjects);
            $parentTrackers = mergeIntegerLists($snapshot[$parentId]['tracker_ids'], $mergedTrackers);
            $parentMissingProjects = array_diff($parentProjects, $snapshot[$parentId]['project_ids']);
            $parentMissingTrackers = array_diff($parentTrackers, $snapshot[$parentId]['tracker_ids']);
        }

        if ($missingProjects === [] && $missingTrackers === [] && $parentMissingProjects === [] && $parentMissingTrackers === []) {
            continue;
        }

        $plan[] = [
            'mapping_id' => (int)$row['mapping_id'],
            'jira_field_id' => (string)$row['jira_field_id'],
            'jira_field_name' => $row['jira_field_name'] ?? null,
            'redmine_custom_field_id' => $redmineId,
            'redmine_parent_custom_field_id' => $parentId,
            'proposed_redmine_name' => $row['proposed_redmine_name'] ?? null,
            'proposed_field_format' => $row['proposed_field_format'] ?? null,
            'proposed_is_required' => normalizeBooleanFlag($row['proposed_is_required'] ?? null),
            'proposed_is_filter' => normalizeBooleanFlag($row['proposed_is_filter'] ?? null),
            'proposed_is_for_all' => normalizeBooleanFlag($row['proposed_is_for_all'] ?? null),
            'proposed_is_multiple' => normalizeBooleanFlag($row['proposed_is_multiple'] ?? null),
            'proposed_possible_values' => $row['proposed_possible_values'] ?? null,
            'proposed_default_value' => $row['proposed_default_value'] ?? null,
            'proposed_role_ids' => $row['proposed_role_ids'] ?? null,
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

        if (isset($item['redmine_parent_custom_field_id']) && $item['redmine_parent_custom_field_id'] !== null) {
            $parentProjects = $item['parent_project_ids'] === [] ? '[none]' : json_encode($item['parent_project_ids']);
            $parentTrackers = $item['parent_tracker_ids'] === [] ? '[none]' : json_encode($item['parent_tracker_ids']);
            printf(
                '    Parent custom field #%d: projects %s; trackers %s%s',
                $item['redmine_parent_custom_field_id'],
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
            $proposedFormat = $field['proposed_field_format'] ?? null;
            $proposedIsRequired = normalizeBooleanFlag($field['proposed_is_required'] ?? null) ?? false;
            $proposedIsFilter = normalizeBooleanFlag($field['proposed_is_filter'] ?? null) ?? true;
            $proposedIsForAll = normalizeBooleanFlag($field['proposed_is_for_all'] ?? null) ?? true;
            $proposedIsMultiple = normalizeBooleanFlag($field['proposed_is_multiple'] ?? null) ?? false;
            $proposedPossibleValues = decodeJsonColumn($field['proposed_possible_values'] ?? null);
            $proposedDefaultValue = $field['proposed_default_value'] ?? null;
            $proposedTrackerIds = decodeJsonColumn($field['proposed_tracker_ids'] ?? null);
            $proposedRoleIds = decodeJsonColumn($field['proposed_role_ids'] ?? null);
            $proposedProjectIds = decodeJsonColumn($field['proposed_project_ids'] ?? null);
            $notes = $field['notes'] ?? null;
            $jiraAllowedValuesPreview = decodeJsonColumn($field['jira_allowed_values'] ?? null);
            $jiraAllowedValuesPreview = is_array($jiraAllowedValuesPreview) ? $jiraAllowedValuesPreview : [];
            $rawPreviewParentId = $field['redmine_parent_custom_field_id'] ?? null;
            $existingPreviewParentId = null;
            if ($rawPreviewParentId !== null && trim((string)$rawPreviewParentId) !== '') {
                $existingPreviewParentId = (int)$rawPreviewParentId;
            }

            $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
            $effectiveFormat = $proposedFormat ?? 'string';
            $isDependingPreview = strtolower($effectiveFormat) === 'depending_list';
            $dependingPreviewDescriptor = $isDependingPreview ? parseCascadingAllowedValues($jiraAllowedValuesPreview) : null;

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
                redmine_parent_custom_field_id = :redmine_parent_custom_field_id,
                migration_status = :migration_status,
                notes = :notes,
                proposed_redmine_name = :proposed_redmine_name,
                proposed_field_format = :proposed_field_format,
                proposed_is_required = :proposed_is_required,
                proposed_is_filter = :proposed_is_filter,
                proposed_is_for_all = :proposed_is_for_all,
                proposed_is_multiple = :proposed_is_multiple,
                proposed_possible_values = :proposed_possible_values,
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

        foreach ($pendingFields as $field) {
            $mappingId = (int)$field['mapping_id'];
            $jiraId = (string)$field['jira_field_id'];
            $jiraName = $field['jira_field_name'] ?? null;
            $proposedName = $field['proposed_redmine_name'] ?? null;
            $proposedFormat = $field['proposed_field_format'] ?? null;
            $proposedIsRequired = normalizeBooleanFlag($field['proposed_is_required'] ?? null) ?? false;
            $proposedIsFilter = normalizeBooleanFlag($field['proposed_is_filter'] ?? null) ?? true;
            $proposedIsForAll = normalizeBooleanFlag($field['proposed_is_for_all'] ?? null) ?? true;
            $proposedIsMultiple = normalizeBooleanFlag($field['proposed_is_multiple'] ?? null) ?? false;
            $proposedPossibleValues = decodeJsonColumn($field['proposed_possible_values'] ?? null);
            $proposedDefaultValue = $field['proposed_default_value'] ?? null;
            $proposedTrackerIds = decodeJsonColumn($field['proposed_tracker_ids'] ?? null);
            $proposedRoleIds = decodeJsonColumn($field['proposed_role_ids'] ?? null);
            $proposedProjectIds = decodeJsonColumn($field['proposed_project_ids'] ?? null);
            $notes = $field['notes'] ?? null;
            $jiraAllowedValues = decodeJsonColumn($field['jira_allowed_values'] ?? null);
            $jiraAllowedValues = is_array($jiraAllowedValues) ? $jiraAllowedValues : [];

            $rawParentId = $field['redmine_parent_custom_field_id'] ?? null;
            $redmineParentId = null;
            if ($rawParentId !== null && trim((string)$rawParentId) !== '') {
                $redmineParentId = (int)$rawParentId;
            }

            $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
            $effectiveFormat = $proposedFormat ?? 'string';
            $isDependingField = strtolower($effectiveFormat) === 'depending_list';

            if ($isDependingField) {
                $descriptor = parseCascadingAllowedValues($jiraAllowedValues);
                if ($descriptor === null || $descriptor['parents'] === [] || $descriptor['child_values'] === []) {
                    $errorMessage = 'Unable to derive cascading dependencies from Jira contexts. Review the staging data.';
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
                        $proposedDefaultValue,
                        encodeJsonColumn($proposedTrackerIds),
                        encodeJsonColumn($proposedRoleIds),
                        encodeJsonColumn($proposedProjectIds),
                        $errorMessage,
                        $redmineParentId
                    );

                    $updateStatement->execute([
                        'redmine_custom_field_id' => null,
                        'redmine_parent_custom_field_id' => $redmineParentId,
                        'migration_status' => 'CREATION_FAILED',
                        'notes' => $errorMessage,
                        'proposed_redmine_name' => $effectiveName,
                        'proposed_field_format' => $effectiveFormat,
                        'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                        'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                        'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                        'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                        'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
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
                    continue;
                }

                if ($proposedPossibleValues === null) {
                    $proposedPossibleValues = $descriptor['child_values'];
                }

                if (!$dependingApiChecked) {
                    try {
                        verifyDependingCustomFieldsApi($redmineClient);
                        $dependingApiChecked = true;
                    } catch (Throwable $exception) {
                        $errorMessage = sprintf(
                            'redmine_depending_custom_fields API unavailable: %s',
                            summarizeExtendedApiError($exception)
                        );

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
                            $proposedDefaultValue,
                            encodeJsonColumn($proposedTrackerIds),
                            encodeJsonColumn($proposedRoleIds),
                            encodeJsonColumn($proposedProjectIds),
                            $errorMessage,
                            $redmineParentId
                        );

                        $updateStatement->execute([
                            'redmine_custom_field_id' => null,
                            'redmine_parent_custom_field_id' => $redmineParentId,
                            'migration_status' => 'CREATION_FAILED',
                            'notes' => $errorMessage,
                            'proposed_redmine_name' => $effectiveName,
                            'proposed_field_format' => $effectiveFormat,
                            'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                            'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                            'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                            'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                            'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
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
                        continue;
                    }
                }

                $parentNameBase = normalizeString($effectiveName, 255) ?? $effectiveName;
                $parentName = normalizeString(sprintf('%s (Parent)', $parentNameBase), 255) ?? sprintf('%s (Parent)', $parentNameBase);

                if ($redmineParentId === null) {
                    $parentPayload = [
                        'custom_field' => [
                            'name' => $parentName,
                            'field_format' => 'list',
                            'customized_type' => 'issue',
                            'is_required' => $proposedIsRequired,
                            'is_filter' => $proposedIsFilter,
                            'is_for_all' => $proposedIsForAll,
                            'multiple' => false,
                            'possible_values' => $descriptor['parents'],
                        ],
                    ];

                    if ($proposedTrackerIds !== null) {
                        $parentPayload['custom_field']['tracker_ids'] = $proposedTrackerIds;
                    }
                    if ($proposedRoleIds !== null) {
                        $parentPayload['custom_field']['role_ids'] = $proposedRoleIds;
                    }
                    if ($proposedProjectIds !== null) {
                        $parentPayload['custom_field']['project_ids'] = $proposedProjectIds;
                    }

                    try {
                        $parentResponse = $redmineClient->post($endpoint, ['json' => $parentPayload]);
                        $parentDecoded = decodeJsonResponse($parentResponse);
                        $redmineParentId = extractCreatedCustomFieldId($parentDecoded);
                        printf(
                            "    [parent-created] Created Redmine list custom field #%d for cascading parent values.%s",
                            $redmineParentId,
                            PHP_EOL
                        );
                    } catch (Throwable $exception) {
                        $errorMessage = sprintf(
                            'Failed to create cascading parent field: %s',
                            summarizeExtendedApiError($exception)
                        );

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
                            $proposedDefaultValue,
                            encodeJsonColumn($proposedTrackerIds),
                            encodeJsonColumn($proposedRoleIds),
                            encodeJsonColumn($proposedProjectIds),
                            $errorMessage,
                            $redmineParentId
                        );

                        $updateStatement->execute([
                            'redmine_custom_field_id' => null,
                            'redmine_parent_custom_field_id' => $redmineParentId,
                            'migration_status' => 'CREATION_FAILED',
                            'notes' => $errorMessage,
                            'proposed_redmine_name' => $effectiveName,
                            'proposed_field_format' => $effectiveFormat,
                            'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                            'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                            'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                            'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                            'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
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
                        continue;
                    }
                }

                $dependingPayload = [
                    'custom_field' => [
                        'name' => $effectiveName,
                        'type' => 'IssueCustomField',
                        'field_format' => 'depending_list',
                        'is_required' => $proposedIsRequired,
                        'is_filter' => $proposedIsFilter,
                        'is_for_all' => $proposedIsForAll,
                        'multiple' => $proposedIsMultiple,
                        'visible' => true,
                        'parent_custom_field_id' => $redmineParentId,
                        'possible_values' => $proposedPossibleValues ?? $descriptor['child_values'],
                        'value_dependencies' => $descriptor['dependencies'],
                    ],
                ];

                if ($proposedProjectIds !== null) {
                    $dependingPayload['custom_field']['project_ids'] = $proposedProjectIds;
                }
                if ($proposedTrackerIds !== null) {
                    $dependingPayload['custom_field']['tracker_ids'] = $proposedTrackerIds;
                }
                if ($proposedRoleIds !== null) {
                    $dependingPayload['custom_field']['role_ids'] = $proposedRoleIds;
                }

                try {
                    $response = $redmineClient->post('/depending_custom_fields.json', ['json' => $dependingPayload]);
                    $decoded = decodeJsonResponse($response);
                    $newFieldId = extractCreatedCustomFieldId($decoded);

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
                        $proposedDefaultValue,
                        encodeJsonColumn($proposedTrackerIds),
                        encodeJsonColumn($proposedRoleIds),
                        encodeJsonColumn($proposedProjectIds),
                        null,
                        $redmineParentId
                    );

                    $updateStatement->execute([
                        'redmine_custom_field_id' => $newFieldId,
                        'redmine_parent_custom_field_id' => $redmineParentId,
                        'migration_status' => 'CREATION_SUCCESS',
                        'notes' => null,
                        'proposed_redmine_name' => $effectiveName,
                        'proposed_field_format' => $effectiveFormat,
                        'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                        'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                        'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                        'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                        'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
                        'proposed_default_value' => $proposedDefaultValue,
                        'proposed_tracker_ids' => encodeJsonColumn($proposedTrackerIds),
                        'proposed_role_ids' => encodeJsonColumn($proposedRoleIds),
                        'proposed_project_ids' => encodeJsonColumn($proposedProjectIds),
                        'automation_hash' => $automationHash,
                        'mapping_id' => $mappingId,
                    ]);

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
                        $proposedDefaultValue,
                        encodeJsonColumn($proposedTrackerIds),
                        encodeJsonColumn($proposedRoleIds),
                        encodeJsonColumn($proposedProjectIds),
                        $errorMessage,
                        $redmineParentId
                    );

                    $updateStatement->execute([
                        'redmine_custom_field_id' => null,
                        'redmine_parent_custom_field_id' => $redmineParentId,
                        'migration_status' => 'CREATION_FAILED',
                        'notes' => $errorMessage,
                        'proposed_redmine_name' => $effectiveName,
                        'proposed_field_format' => $effectiveFormat,
                        'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                        'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                        'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                        'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                        'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
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

                continue;
            }

            // Standard custom field creation via extended API
            $payload = [
                'custom_field' => [
                    'name' => $effectiveName,
                    'field_format' => $effectiveFormat,
                    'customized_type' => 'issue',
                    'is_required' => $proposedIsRequired,
                    'is_filter' => $proposedIsFilter,
                    'is_for_all' => $proposedIsForAll,
                    'multiple' => $proposedIsMultiple,
                ],
            ];

            if ($proposedPossibleValues !== null) {
                $payload['custom_field']['possible_values'] = $proposedPossibleValues;
            }
            if ($proposedDefaultValue !== null) {
                $payload['custom_field']['default_value'] = $proposedDefaultValue;
            }
            if ($proposedTrackerIds !== null) {
                $payload['custom_field']['tracker_ids'] = $proposedTrackerIds;
            }
            if ($proposedRoleIds !== null) {
                $payload['custom_field']['role_ids'] = $proposedRoleIds;
            }
            if ($proposedProjectIds !== null) {
                $payload['custom_field']['project_ids'] = $proposedProjectIds;
            }

            try {
                $response = $redmineClient->post($endpoint, ['json' => $payload]);
                $decoded = decodeJsonResponse($response);
                $newFieldId = extractCreatedCustomFieldId($decoded);

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
                    $proposedDefaultValue,
                    encodeJsonColumn($proposedTrackerIds),
                    encodeJsonColumn($proposedRoleIds),
                    encodeJsonColumn($proposedProjectIds),
                    null,
                    null
                );

                $updateStatement->execute([
                    'redmine_custom_field_id' => $newFieldId,
                    'redmine_parent_custom_field_id' => null,
                    'migration_status' => 'CREATION_SUCCESS',
                    'notes' => null,
                    'proposed_redmine_name' => $effectiveName,
                    'proposed_field_format' => $effectiveFormat,
                    'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                    'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                    'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                    'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                    'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
                    'proposed_default_value' => $proposedDefaultValue,
                    'proposed_tracker_ids' => encodeJsonColumn($proposedTrackerIds),
                    'proposed_role_ids' => encodeJsonColumn($proposedRoleIds),
                    'proposed_project_ids' => encodeJsonColumn($proposedProjectIds),
                    'automation_hash' => $automationHash,
                    'mapping_id' => $mappingId,
                ]);

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
                    $proposedDefaultValue,
                    encodeJsonColumn($proposedTrackerIds),
                    encodeJsonColumn($proposedRoleIds),
                    encodeJsonColumn($proposedProjectIds),
                    $errorMessage,
                    null
                );

                $updateStatement->execute([
                    'redmine_custom_field_id' => null,
                    'redmine_parent_custom_field_id' => null,
                    'migration_status' => 'CREATION_FAILED',
                    'notes' => $errorMessage,
                    'proposed_redmine_name' => $effectiveName,
                    'proposed_field_format' => $effectiveFormat,
                    'proposed_is_required' => normalizeBooleanDatabaseValue($proposedIsRequired),
                    'proposed_is_filter' => normalizeBooleanDatabaseValue($proposedIsFilter),
                    'proposed_is_for_all' => normalizeBooleanDatabaseValue($proposedIsForAll),
                    'proposed_is_multiple' => normalizeBooleanDatabaseValue($proposedIsMultiple),
                    'proposed_possible_values' => encodeJsonColumn($proposedPossibleValues),
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
        $proposedFormat = $field['proposed_field_format'] ?? null;
        $proposedIsRequired = normalizeBooleanFlag($field['proposed_is_required'] ?? null) ?? false;
        $proposedIsFilter = normalizeBooleanFlag($field['proposed_is_filter'] ?? null) ?? true;
        $proposedIsForAll = normalizeBooleanFlag($field['proposed_is_for_all'] ?? null) ?? true;
        $proposedIsMultiple = normalizeBooleanFlag($field['proposed_is_multiple'] ?? null) ?? false;
        $proposedPossibleValues = decodeJsonColumn($field['proposed_possible_values'] ?? null);
        $proposedDefaultValue = $field['proposed_default_value'] ?? null;
        $proposedTrackerIds = decodeJsonColumn($field['proposed_tracker_ids'] ?? null);
        $proposedRoleIds = decodeJsonColumn($field['proposed_role_ids'] ?? null);
        $proposedProjectIds = decodeJsonColumn($field['proposed_project_ids'] ?? null);
        $notes = $field['notes'] ?? null;
        $previewAllowedValues = decodeJsonColumn($field['jira_allowed_values'] ?? null);
        $previewAllowedValues = is_array($previewAllowedValues) ? $previewAllowedValues : [];
        $rawPreviewParentId = $field['redmine_parent_custom_field_id'] ?? null;
        $existingPreviewParentId = null;
        if ($rawPreviewParentId !== null && trim((string)$rawPreviewParentId) !== '') {
            $existingPreviewParentId = (int)$rawPreviewParentId;
        }

        $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
        $effectiveFormat = $proposedFormat ?? 'string';
        $isDependingPreview = strtolower($effectiveFormat) === 'depending_list';
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

            $automationHash = computeCustomFieldAutomationStateHash(
                null,
                'CREATION_SUCCESS',
                $field['proposed_redmine_name'] ?? ($jiraName ?? $jiraId),
                $field['proposed_field_format'] ?? 'string',
                normalizeBooleanFlag($field['proposed_is_required'] ?? null) ?? false,
                normalizeBooleanFlag($field['proposed_is_filter'] ?? null) ?? true,
                normalizeBooleanFlag($field['proposed_is_for_all'] ?? null) ?? true,
                normalizeBooleanFlag($field['proposed_is_multiple'] ?? null) ?? false,
                $field['proposed_possible_values'] ?? null,
                $field['proposed_default_value'] ?? null,
                $field['proposed_tracker_ids'] ?? null,
                $field['proposed_role_ids'] ?? null,
                $field['proposed_project_ids'] ?? null,
                null,
                isset($field['redmine_parent_custom_field_id']) && trim((string)$field['redmine_parent_custom_field_id']) !== ''
                    ? (int)$field['redmine_parent_custom_field_id']
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
            proposed_is_required,
            proposed_is_filter,
            proposed_is_for_all,
            proposed_is_multiple,
            proposed_possible_values,
            proposed_default_value,
            proposed_tracker_ids,
            proposed_role_ids,
            proposed_project_ids,
            notes,
            jira_allowed_values,
            redmine_parent_custom_field_id
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
        $parentId = isset($item['redmine_parent_custom_field_id']) && $item['redmine_parent_custom_field_id'] !== null
            ? (int)$item['redmine_parent_custom_field_id']
            : null;
        $projects = $item['target_project_ids'] ?? [];
        $trackers = $item['target_tracker_ids'] ?? [];
        $currentStatus = $item['current_status'] ?? 'MATCH_FOUND';
        $nextStatus = $currentStatus === 'READY_FOR_UPDATE' ? 'MATCH_FOUND' : $currentStatus;
        $format = $item['proposed_field_format'] ?? null;

        $payload = ['custom_field' => []];
        if ($projects !== []) {
            $payload['custom_field']['project_ids'] = $projects;
        }
        if ($trackers !== []) {
            $payload['custom_field']['tracker_ids'] = $trackers;
        }

        $path = buildExtendedApiPath($extendedPrefix, sprintf('custom_fields/%d.json', $redmineId));
        $isDependingField = strtolower((string)$format) === 'depending_list';

        if ($isDependingField) {
            verifyDependingCustomFieldsApi($client);
            $path = sprintf('/depending_custom_fields/%d.json', $redmineId);
        }

        if ($parentId !== null) {
            $parentPayload = ['custom_field' => []];
            if ($projects !== []) {
                $parentPayload['custom_field']['project_ids'] = $projects;
            }
            if ($trackers !== []) {
                $parentPayload['custom_field']['tracker_ids'] = $trackers;
            }

            printf(
                "  [plan] Updating parent custom field #%d projects %s, trackers %s%s",
                $parentId,
                $projects === [] ? '[none]' : json_encode($projects),
                $trackers === [] ? '[none]' : json_encode($trackers),
                PHP_EOL
            );

            if (!$isDryRun) {
                try {
                    $parentPath = buildExtendedApiPath($extendedPrefix, sprintf('custom_fields/%d.json', $parentId));
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
                        $item['proposed_default_value'] ?? null,
                        encodeJsonColumn($trackers),
                        $item['proposed_role_ids'] ?? null,
                        encodeJsonColumn($projects),
                        $errorMessage,
                        $parentId
                    );

                    $updateStatement->execute([
                        'migration_status' => 'CREATION_FAILED',
                        'notes' => $errorMessage,
                        'proposed_tracker_ids' => encodeJsonColumn($trackers),
                        'proposed_project_ids' => encodeJsonColumn($projects),
                        'automation_hash' => $automationHash,
                        'mapping_id' => $mappingId,
                    ]);

                    printf("  [failed] Parent update for custom field #%d: %s%s", $parentId, $errorMessage, PHP_EOL);
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
                $item['proposed_default_value'] ?? null,
                encodeJsonColumn($trackers),
                $item['proposed_role_ids'] ?? null,
                encodeJsonColumn($projects),
                null,
                $parentId
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
                $item['proposed_default_value'] ?? null,
                encodeJsonColumn($trackers),
                $item['proposed_role_ids'] ?? null,
                encodeJsonColumn($projects),
                $errorMessage,
                $parentId
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
        throw new RuntimeException('Failed to decode JSON response: ' . $exception->getMessage(), 0, $exception);
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

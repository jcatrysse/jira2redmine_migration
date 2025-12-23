<?php
/** @noinspection DuplicatedCode */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const EXPORT_WORKFLOWS_SCRIPT_VERSION = '0.0.1';
const AVAILABLE_PHASES = [
    'jira' => 'Export Jira workflow/configuration metadata into staging tables.',
];

const JIRA_RATE_LIMIT_MAX_RETRIES = 5;
const JIRA_RATE_LIMIT_BASE_DELAY_MS = 1000;

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
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string} $cliOptions
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

    if (!in_array('jira', $phasesToRun, true)) {
        printf("[%s] Skipping Jira export phase (disabled via CLI option).%s", formatCurrentTimestamp(), PHP_EOL);
        return;
    }

    $pdo = createDatabaseConnection(extractArrayConfig($config, 'database'));
    $jiraClient = createJiraClient(extractArrayConfig($config, 'jira'));

    exportWorkflows($jiraClient, $pdo);
    exportWorkflowSchemes($jiraClient, $pdo);
    exportProjects($jiraClient, $pdo);
    exportIssueTypes($jiraClient, $pdo);
    exportIssueTypeSchemes($jiraClient, $pdo);
    exportIssueTypeSchemeProjects($jiraClient, $pdo);
    exportProjectRoles($jiraClient, $pdo);
    exportFields($jiraClient, $pdo);
    exportScreens($jiraClient, $pdo);
    exportScreenSchemes($jiraClient, $pdo);
    exportIssueTypeScreenSchemes($jiraClient, $pdo);
    exportFieldConfigurations($jiraClient, $pdo);
    exportFieldConfigurationSchemes($jiraClient, $pdo);
    exportAutomationRules($jiraClient, $pdo);
}

function printUsage(): void
{
    printf("Jira Workflow Export (step 16) — version %s%s", EXPORT_WORKFLOWS_SCRIPT_VERSION, PHP_EOL);
    printf("Usage: php 16_export_workflows.php [options]\n\n");
    printf("Options:\n");
    printf("  --phases=LIST        Comma separated list of phases to run (default: jira).\n");
    printf("  --skip=LIST          Comma separated list of phases to skip.\n");
    printf("  --version            Display version information.\n");
    printf("  --help               Display this help message.\n");
}

function printVersion(): void
{
    printf("%s%s", EXPORT_WORKFLOWS_SCRIPT_VERSION, PHP_EOL);
}

function exportWorkflows(Client $client, PDO $pdo): void
{
    $items = fetchPagedItems($client, '/rest/api/3/workflow/search', 'values');
    $legacyPayload = jiraGetWithRetry($client, '/rest/api/3/workflow', [], 'workflows', 'workflow');
    $legacyDecoded = decodeJsonResponse($legacyPayload);
    $legacyItems = extractWorkflowItems($legacyDecoded);

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_workflows (
            workflow_name,
            source,
            raw_payload
        ) VALUES (
            :workflow_name,
            :source,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare workflow insert.');
    }

    $count = 0;
    foreach ($items as $workflow) {
        $name = extractWorkflowName($workflow) ?? 'unknown';
        $statement->execute([
            'workflow_name' => $name,
            'source' => 'search',
            'raw_payload' => encodeJson($workflow),
        ]);
        $count++;
    }

    foreach ($legacyItems as $workflow) {
        $name = extractWorkflowName($workflow) ?? 'unknown';
        $statement->execute([
            'workflow_name' => $name,
            'source' => 'legacy',
            'raw_payload' => encodeJson($workflow),
        ]);
        $count++;
    }

    printf("[%s] Workflows exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportWorkflowSchemes(Client $client, PDO $pdo): void
{
    $items = fetchPagedItems($client, '/rest/api/3/workflowscheme', 'values');

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_workflow_schemes (
            scheme_id,
            scheme_name,
            source,
            raw_payload
        ) VALUES (
            :scheme_id,
            :scheme_name,
            :source,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            scheme_name = VALUES(scheme_name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare workflow scheme insert.');
    }

    $count = 0;
    foreach ($items as $scheme) {
        $schemeId = (string)($scheme['id'] ?? '');
        if ($schemeId === '') {
            continue;
        }
        $schemeName = isset($scheme['name']) ? (string)$scheme['name'] : null;

        $statement->execute([
            'scheme_id' => $schemeId,
            'scheme_name' => $schemeName,
            'source' => 'list',
            'raw_payload' => encodeJson($scheme),
        ]);
        $count++;

        $detailResponse = jiraGetWithRetry($client, sprintf('/rest/api/3/workflowscheme/%s', $schemeId), [], $schemeId, 'workflow-scheme');
        $detailPayload = decodeJsonResponse($detailResponse);
        $statement->execute([
            'scheme_id' => $schemeId,
            'scheme_name' => $schemeName,
            'source' => 'detail',
            'raw_payload' => encodeJson($detailPayload),
        ]);
        $count++;
    }

    printf("[%s] Workflow schemes exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportProjects(Client $client, PDO $pdo): void
{
    $items = fetchPagedItems($client, '/rest/api/3/project/search', 'values');

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_projects_export (
            project_id,
            project_key,
            project_name,
            raw_payload
        ) VALUES (
            :project_id,
            :project_key,
            :project_name,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            project_key = VALUES(project_key),
            project_name = VALUES(project_name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare project insert.');
    }

    $count = 0;
    foreach ($items as $project) {
        $projectId = (string)($project['id'] ?? '');
        $projectKey = (string)($project['key'] ?? '');
        if ($projectId === '' || $projectKey === '') {
            continue;
        }
        $statement->execute([
            'project_id' => $projectId,
            'project_key' => $projectKey,
            'project_name' => isset($project['name']) ? (string)$project['name'] : null,
            'raw_payload' => encodeJson($project),
        ]);
        $count++;
    }

    printf("[%s] Projects exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportIssueTypes(Client $client, PDO $pdo): void
{
    $response = jiraGetWithRetry($client, '/rest/api/3/issuetype', [], 'issuetypes', 'issue-types');
    $payload = decodeJsonResponse($response);
    $items = is_array($payload) ? $payload : [];

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_issue_types_export (
            issue_type_id,
            name,
            raw_payload
        ) VALUES (
            :issue_type_id,
            :name,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare issue type insert.');
    }

    $count = 0;
    foreach ($items as $issueType) {
        if (!is_array($issueType)) {
            continue;
        }
        $issueTypeId = (string)($issueType['id'] ?? '');
        if ($issueTypeId === '') {
            continue;
        }
        $statement->execute([
            'issue_type_id' => $issueTypeId,
            'name' => isset($issueType['name']) ? (string)$issueType['name'] : null,
            'raw_payload' => encodeJson($issueType),
        ]);
        $count++;
    }

    printf("[%s] Issue types exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportIssueTypeSchemes(Client $client, PDO $pdo): void
{
    $items = fetchPagedItems($client, '/rest/api/3/issuetypescheme', 'values');

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_issue_type_schemes (
            scheme_id,
            scheme_name,
            source,
            raw_payload
        ) VALUES (
            :scheme_id,
            :scheme_name,
            :source,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            scheme_name = VALUES(scheme_name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare issue type scheme insert.');
    }

    $count = 0;
    foreach ($items as $scheme) {
        $schemeId = (string)($scheme['id'] ?? '');
        if ($schemeId === '') {
            continue;
        }
        $schemeName = isset($scheme['name']) ? (string)$scheme['name'] : null;
        $statement->execute([
            'scheme_id' => $schemeId,
            'scheme_name' => $schemeName,
            'source' => 'list',
            'raw_payload' => encodeJson($scheme),
        ]);
        $count++;

        $detailResponse = jiraGetWithRetry($client, sprintf('/rest/api/3/issuetypescheme/%s', $schemeId), [], $schemeId, 'issue-type-scheme');
        $detailPayload = decodeJsonResponse($detailResponse);
        $statement->execute([
            'scheme_id' => $schemeId,
            'scheme_name' => $schemeName,
            'source' => 'detail',
            'raw_payload' => encodeJson($detailPayload),
        ]);
        $count++;
    }

    printf("[%s] Issue type schemes exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportIssueTypeSchemeProjects(Client $client, PDO $pdo): void
{
    $items = fetchPagedItems($client, '/rest/api/3/issuetypescheme/project', 'values');

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_issue_type_scheme_projects (
            project_id,
            scheme_id,
            raw_payload
        ) VALUES (
            :project_id,
            :scheme_id,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare issue type scheme project insert.');
    }

    $count = 0;
    foreach ($items as $mapping) {
        $projectId = (string)($mapping['projectId'] ?? '');
        $schemeId = (string)($mapping['issueTypeSchemeId'] ?? '');
        if ($projectId === '' || $schemeId === '') {
            continue;
        }
        $statement->execute([
            'project_id' => $projectId,
            'scheme_id' => $schemeId,
            'raw_payload' => encodeJson($mapping),
        ]);
        $count++;
    }

    printf("[%s] Issue type scheme projects exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportProjectRoles(Client $client, PDO $pdo): void
{
    $projectRows = $pdo->query('SELECT project_id, project_key, project_name FROM staging_jira_projects_export ORDER BY project_id');
    if ($projectRows === false) {
        throw new RuntimeException('Failed to load projects for role export.');
    }

    $roleLinkInsert = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_project_role_links (
            project_id,
            project_key,
            project_name,
            role_id,
            role_name,
            raw_payload
        ) VALUES (
            :project_id,
            :project_key,
            :project_name,
            :role_id,
            :role_name,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            role_name = VALUES(role_name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    $roleInsert = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_roles (
            role_id,
            role_name,
            raw_payload
        ) VALUES (
            :role_id,
            :role_name,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            role_name = VALUES(role_name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($roleLinkInsert === false || $roleInsert === false) {
        throw new RuntimeException('Failed to prepare project role inserts.');
    }

    $uniqueRoleIds = [];
    $count = 0;
    while ($project = $projectRows->fetch(PDO::FETCH_ASSOC)) {
        $projectId = (string)($project['project_id'] ?? '');
        if ($projectId === '') {
            continue;
        }
        $projectKey = isset($project['project_key']) ? (string)$project['project_key'] : null;
        $projectName = isset($project['project_name']) ? (string)$project['project_name'] : null;

        $response = jiraGetWithRetry($client, sprintf('/rest/api/3/project/%s/role', $projectId), [], $projectId, 'project-roles');
        $payload = decodeJsonResponse($response);

        if (!is_array($payload)) {
            continue;
        }

        foreach ($payload as $roleName => $roleUrl) {
            if (!is_string($roleUrl)) {
                continue;
            }
            $roleId = extractRoleIdFromUrl($roleUrl);
            if ($roleId === null) {
                continue;
            }

            $roleLinkInsert->execute([
                'project_id' => $projectId,
                'project_key' => $projectKey,
                'project_name' => $projectName,
                'role_id' => $roleId,
                'role_name' => is_string($roleName) ? $roleName : null,
                'raw_payload' => encodeJson(['url' => $roleUrl, 'name' => $roleName]),
            ]);
            $uniqueRoleIds[$roleId] = true;
            $count++;
        }
    }
    $projectRows->closeCursor();

    foreach (array_keys($uniqueRoleIds) as $roleId) {
        $response = jiraGetWithRetry($client, sprintf('/rest/api/3/role/%s', $roleId), [], $roleId, 'role-detail');
        $payload = decodeJsonResponse($response);
        $roleInsert->execute([
            'role_id' => $roleId,
            'role_name' => isset($payload['name']) ? (string)$payload['name'] : null,
            'raw_payload' => encodeJson($payload),
        ]);
        $count++;
    }

    printf("[%s] Project roles exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportFields(Client $client, PDO $pdo): void
{
    $response = jiraGetWithRetry($client, '/rest/api/3/field', [], 'fields', 'fields');
    $payload = decodeJsonResponse($response);
    $items = is_array($payload) ? $payload : [];

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_fields_export (
            field_id,
            name,
            raw_payload
        ) VALUES (
            :field_id,
            :name,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare field insert.');
    }

    $count = 0;
    foreach ($items as $field) {
        if (!is_array($field)) {
            continue;
        }
        $fieldId = (string)($field['id'] ?? '');
        if ($fieldId === '') {
            continue;
        }
        $statement->execute([
            'field_id' => $fieldId,
            'name' => isset($field['name']) ? (string)$field['name'] : null,
            'raw_payload' => encodeJson($field),
        ]);
        $count++;
    }

    printf("[%s] Fields exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportScreens(Client $client, PDO $pdo): void
{
    $items = fetchPagedItems($client, '/rest/api/3/screens', 'values');

    $screenInsert = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_screens (
            screen_id,
            name,
            raw_payload
        ) VALUES (
            :screen_id,
            :name,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    $tabInsert = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_screen_tabs (
            screen_id,
            tab_id,
            tab_name,
            raw_payload
        ) VALUES (
            :screen_id,
            :tab_id,
            :tab_name,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            tab_name = VALUES(tab_name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    $fieldInsert = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_screen_tab_fields (
            screen_id,
            tab_id,
            field_id,
            raw_payload
        ) VALUES (
            :screen_id,
            :tab_id,
            :field_id,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($screenInsert === false || $tabInsert === false || $fieldInsert === false) {
        throw new RuntimeException('Failed to prepare screen inserts.');
    }

    $count = 0;
    foreach ($items as $screen) {
        $screenId = (string)($screen['id'] ?? '');
        if ($screenId === '') {
            continue;
        }
        $screenInsert->execute([
            'screen_id' => $screenId,
            'name' => isset($screen['name']) ? (string)$screen['name'] : null,
            'raw_payload' => encodeJson($screen),
        ]);
        $count++;

        $tabsResponse = jiraGetWithRetry($client, sprintf('/rest/api/3/screens/%s/tabs', $screenId), [], $screenId, 'screen-tabs');
        $tabsPayload = decodeJsonResponse($tabsResponse);
        $tabs = is_array($tabsPayload) ? $tabsPayload : [];

        foreach ($tabs as $tab) {
            if (!is_array($tab)) {
                continue;
            }
            $tabId = (string)($tab['id'] ?? '');
            if ($tabId === '') {
                continue;
            }
            $tabInsert->execute([
                'screen_id' => $screenId,
                'tab_id' => $tabId,
                'tab_name' => isset($tab['name']) ? (string)$tab['name'] : null,
                'raw_payload' => encodeJson($tab),
            ]);
            $count++;

            $fieldsResponse = jiraGetWithRetry(
                $client,
                sprintf('/rest/api/3/screens/%s/tabs/%s/fields', $screenId, $tabId),
                [],
                $screenId,
                'screen-tab-fields'
            );
            $fieldsPayload = decodeJsonResponse($fieldsResponse);
            $fields = is_array($fieldsPayload) ? $fieldsPayload : [];
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $fieldId = (string)($field['id'] ?? '');
                if ($fieldId === '') {
                    continue;
                }
                $fieldInsert->execute([
                    'screen_id' => $screenId,
                    'tab_id' => $tabId,
                    'field_id' => $fieldId,
                    'raw_payload' => encodeJson($field),
                ]);
                $count++;
            }
        }
    }

    printf("[%s] Screens exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportScreenSchemes(Client $client, PDO $pdo): void
{
    $items = fetchPagedItems($client, '/rest/api/3/screenscheme', 'values');

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_screen_schemes (
            scheme_id,
            scheme_name,
            raw_payload
        ) VALUES (
            :scheme_id,
            :scheme_name,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            scheme_name = VALUES(scheme_name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare screen scheme insert.');
    }

    $count = 0;
    foreach ($items as $scheme) {
        $schemeId = (string)($scheme['id'] ?? '');
        if ($schemeId === '') {
            continue;
        }
        $statement->execute([
            'scheme_id' => $schemeId,
            'scheme_name' => isset($scheme['name']) ? (string)$scheme['name'] : null,
            'raw_payload' => encodeJson($scheme),
        ]);
        $count++;
    }

    printf("[%s] Screen schemes exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportIssueTypeScreenSchemes(Client $client, PDO $pdo): void
{
    $items = fetchPagedItems($client, '/rest/api/3/issuetypescreenscheme', 'values');

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_issue_type_screen_schemes (
            scheme_id,
            scheme_name,
            raw_payload
        ) VALUES (
            :scheme_id,
            :scheme_name,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            scheme_name = VALUES(scheme_name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare issue type screen scheme insert.');
    }

    $count = 0;
    foreach ($items as $scheme) {
        $schemeId = (string)($scheme['id'] ?? '');
        if ($schemeId === '') {
            continue;
        }
        $statement->execute([
            'scheme_id' => $schemeId,
            'scheme_name' => isset($scheme['name']) ? (string)$scheme['name'] : null,
            'raw_payload' => encodeJson($scheme),
        ]);
        $count++;
    }

    printf("[%s] Issue type screen schemes exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportFieldConfigurations(Client $client, PDO $pdo): void
{
    $items = fetchPagedItems($client, '/rest/api/3/fieldconfiguration', 'values');

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_field_configurations (
            configuration_id,
            name,
            raw_payload
        ) VALUES (
            :configuration_id,
            :name,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare field configuration insert.');
    }

    $count = 0;
    foreach ($items as $config) {
        $configId = (string)($config['id'] ?? '');
        if ($configId === '') {
            continue;
        }
        $statement->execute([
            'configuration_id' => $configId,
            'name' => isset($config['name']) ? (string)$config['name'] : null,
            'raw_payload' => encodeJson($config),
        ]);
        $count++;
    }

    printf("[%s] Field configurations exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportFieldConfigurationSchemes(Client $client, PDO $pdo): void
{
    $items = fetchPagedItems($client, '/rest/api/3/fieldconfigurationscheme', 'values');

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_field_configuration_schemes (
            scheme_id,
            name,
            raw_payload
        ) VALUES (
            :scheme_id,
            :name,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare field configuration scheme insert.');
    }

    $count = 0;
    foreach ($items as $scheme) {
        $schemeId = (string)($scheme['id'] ?? '');
        if ($schemeId === '') {
            continue;
        }
        $statement->execute([
            'scheme_id' => $schemeId,
            'name' => isset($scheme['name']) ? (string)$scheme['name'] : null,
            'raw_payload' => encodeJson($scheme),
        ]);
        $count++;
    }

    printf("[%s] Field configuration schemes exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

function exportAutomationRules(Client $client, PDO $pdo): void
{
    $items = fetchPagedItems($client, '/rest/api/3/automation/rules', 'values');

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_automation_rules (
            rule_id,
            name,
            project_id,
            raw_payload
        ) VALUES (
            :rule_id,
            :name,
            :project_id,
            :raw_payload
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            project_id = VALUES(project_id),
            raw_payload = VALUES(raw_payload),
            extracted_at = CURRENT_TIMESTAMP
    SQL);

    if ($statement === false) {
        throw new RuntimeException('Failed to prepare automation rule insert.');
    }

    $count = 0;
    foreach ($items as $rule) {
        $ruleId = (string)($rule['id'] ?? '');
        if ($ruleId === '') {
            continue;
        }
        $statement->execute([
            'rule_id' => $ruleId,
            'name' => isset($rule['name']) ? (string)$rule['name'] : null,
            'project_id' => isset($rule['projectId']) ? (string)$rule['projectId'] : null,
            'raw_payload' => encodeJson($rule),
        ]);
        $count++;
    }

    printf("[%s] Automation rules exported: %d%s", formatCurrentTimestamp(), $count, PHP_EOL);
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchPagedItems(Client $client, string $path, string $valuesKey): array
{
    $startAt = 0;
    $maxResults = 100;
    $items = [];

    do {
        $response = jiraGetWithRetry($client, $path, [
            'query' => [
                'startAt' => $startAt,
                'maxResults' => $maxResults,
            ],
        ], $path, 'paged-export');

        $payload = decodeJsonResponse($response);
        $pageItems = [];
        if (isset($payload[$valuesKey]) && is_array($payload[$valuesKey])) {
            $pageItems = $payload[$valuesKey];
        } elseif (is_array($payload)) {
            $pageItems = $payload;
        }

        foreach ($pageItems as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        $total = isset($payload['total']) ? (int)$payload['total'] : count($pageItems);
        $startAt += $maxResults;
    } while ($startAt < $total);

    return $items;
}

/**
 * @return array<int, array<string, mixed>>
 */
function extractWorkflowItems(array $payload): array
{
    if (isset($payload['values']) && is_array($payload['values'])) {
        return array_values(array_filter($payload['values'], 'is_array'));
    }
    if (isset($payload['workflows']) && is_array($payload['workflows'])) {
        return array_values(array_filter($payload['workflows'], 'is_array'));
    }

    return [];
}

function extractWorkflowName(array $workflow): ?string
{
    foreach (['name', 'workflowName', 'id'] as $key) {
        if (isset($workflow[$key]) && is_string($workflow[$key])) {
            return $workflow[$key];
        }
    }

    return null;
}

function extractRoleIdFromUrl(string $url): ?string
{
    $trimmed = rtrim($url, '/');
    $parts = explode('/', $trimmed);
    $id = end($parts);
    if ($id === false || $id === '') {
        return null;
    }

    return $id;
}

/**
 * @throws BadResponseException
 * @throws GuzzleException
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
                "  [warn] Jira rate limit (429) for %s (%s). Retrying in %.1fs (attempt %d/%d).%s",
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

function encodeJson(mixed $value): string
{
    try {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode JSON payload: ' . $exception->getMessage(), 0, $exception);
    }
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
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string} $cliOptions
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
 * @return array{0: array{help: bool, version: bool, phases: ?string, skip: ?string}, 1: list<string>}
 */
function parseCommandLineOptions(array $argv): array
{
    $options = [
        'help' => false,
        'version' => false,
        'phases' => null,
        'skip' => null,
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
function formatCurrentTimestamp(string $format = 'Y-m-d H:i:s'): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format($format);
}

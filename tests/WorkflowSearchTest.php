<?php

declare(strict_types=1);

use Jira2Redmine\Jira\AutomationRules;
use Jira2Redmine\Jira\WorkflowSearch;
use PHPUnit\Framework\TestCase;

final class WorkflowSearchTest extends TestCase
{
    public function testNameComesFromTheIdObjectOfTheSearchEndpoint(): void
    {
        $workflow = [
            'id' => ['name' => 'Support workflow', 'entityId' => 'a1b2c3d4-0000-4000-8000-000000000001'],
            'description' => '',
        ];

        self::assertSame('Support workflow', WorkflowSearch::name($workflow));
    }

    public function testTwoWorkflowsKeepTwoNames(): void
    {
        $names = array_map([WorkflowSearch::class, 'name'], [
            ['id' => ['name' => 'Workflow A', 'entityId' => 'e1']],
            ['id' => ['name' => 'Workflow B', 'entityId' => 'e2']],
        ]);

        self::assertSame(['Workflow A', 'Workflow B'], $names);
    }

    public function testPlainNamesStillWork(): void
    {
        self::assertSame('Legacy', WorkflowSearch::name(['name' => 'Legacy']));
        self::assertSame('Other', WorkflowSearch::name(['workflowName' => 'Other']));
        self::assertNull(WorkflowSearch::name(['description' => 'no name']));
    }

    public function testSearchAsksForTransitionsAndStatuses(): void
    {
        $expand = explode(',', WorkflowSearch::EXPAND);
        foreach (['transitions', 'transitions.rules', 'statuses', 'schemes', 'projects'] as $part) {
            self::assertContains($part, $expand);
        }
    }

    public function testSearchIsTheOnlyWorkflowEndpoint(): void
    {
        self::assertSame('/rest/api/3/workflow/search', WorkflowSearch::PATH);
        $script = (string)file_get_contents(__DIR__ . '/../16_export_workflows.php');
        self::assertFalse(
            str_contains($script, "'/rest/api/3/workflow'"),
            '16 still calls the legacy GET /rest/api/3/workflow'
        );
    }

    public function testNoCallToTheAutomationPathThatJiraDoesNotHave(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../16_export_workflows.php');

        self::assertFalse(
            str_contains($script, "'/rest/api/3/automation/rules'"),
            '16 still calls /rest/api/3/automation/rules (404 classic, 401 scoped)'
        );
    }

    public function testTheExportSaysWhyAutomationRulesAreNotExported(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../16_export_workflows.php');

        self::assertTrue(
            str_contains($script, 'AutomationRules::SKIPPED'),
            '16 skips the automation rules without a word in its log'
        );
        self::assertStringContainsString('not exported', AutomationRules::SKIPPED);
    }
}

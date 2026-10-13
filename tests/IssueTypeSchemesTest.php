<?php

declare(strict_types=1);

use Jira2Redmine\Jira\IssueTypeSchemes;
use PHPUnit\Framework\TestCase;

final class IssueTypeSchemesTest extends TestCase
{
    public function testMappingRowsAreGroupedPerScheme(): void
    {
        $rows = [
            ['issueTypeSchemeId' => '10000', 'issueTypeId' => '10001'],
            ['issueTypeSchemeId' => '10000', 'issueTypeId' => '10002'],
            ['issueTypeSchemeId' => '10100', 'issueTypeId' => '10001'],
            ['issueTypeSchemeId' => '', 'issueTypeId' => '10003'],
            'not a row',
        ];

        self::assertSame(
            ['10000' => ['10001', '10002'], '10100' => ['10001']],
            IssueTypeSchemes::issueTypesPerScheme($rows)
        );
    }

    public function testProjectAssignmentsReadTheSchemeObjectAndProjectIds(): void
    {
        $values = [
            ['issueTypeScheme' => ['id' => '10200', 'name' => 'Support scheme'], 'projectIds' => ['10011', '10012']],
        ];

        self::assertSame(
            [
                ['project_id' => '10011', 'scheme_id' => '10200'],
                ['project_id' => '10012', 'scheme_id' => '10200'],
            ],
            IssueTypeSchemes::projectAssignments($values, '10011')
        );
    }

    public function testProjectAssignmentsAcceptTheFlatShape(): void
    {
        self::assertSame(
            [['project_id' => '10011', 'scheme_id' => '10200']],
            IssueTypeSchemes::projectAssignments([['issueTypeSchemeId' => '10200']], '10011')
        );
        self::assertSame([], IssueTypeSchemes::projectAssignments([['projectIds' => ['1']]], '1'));
    }

    public function testTheExportReadsProjectAssignmentsThroughTheParser(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../16_export_workflows.php');

        self::assertTrue(
            str_contains($script, 'IssueTypeSchemes::projectAssignments('),
            '16 parses issuetypescheme/project itself and misses the issueTypeScheme object'
        );
    }

    public function testTheExportDoesNotAskForASchemeById(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../16_export_workflows.php');

        self::assertFalse(
            str_contains($script, "'/rest/api/3/issuetypescheme/%s'"),
            '16 still calls GET /rest/api/3/issuetypescheme/{id}, which Jira does not have'
        );
    }
}

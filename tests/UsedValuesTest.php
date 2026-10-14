<?php

declare(strict_types=1);

use Jira2Redmine\CustomFields\UsedValues;
use PHPUnit\Framework\TestCase;

final class UsedValuesTest extends TestCase
{
    private const OFFERED = ['mode' => 'flat', 'values' => [
        ['id' => '20001', 'value' => 'Field Department'],
        ['id' => '20002', 'value' => 'Science'],
    ]];

    public function testAValueNoLongerOfferedIsMissing(): void
    {
        $used = ['mode' => 'flat', 'values' => [
            ['id' => '20002', 'value' => 'Science'],
            ['id' => '20003', 'value' => 'Field'],
        ]];

        self::assertSame(['Field'], UsedValues::missing(self::OFFERED, $used));
    }

    public function testCaseAndKnownValuesAreNotMissing(): void
    {
        $used = ['mode' => 'flat', 'values' => [['id' => null, 'value' => 'field department ']]];

        self::assertSame([], UsedValues::missing(self::OFFERED, $used));
    }

    public function testOnlyFlatListsAreCompared(): void
    {
        $cascading = ['mode' => 'cascading', 'parents' => [['id' => '1', 'value' => 'A']]];

        self::assertSame([], UsedValues::missing($cascading, ['mode' => 'flat', 'values' => [['value' => 'X']]]));
    }

    public function testTheFieldBackfillAddsThem(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php');

        self::assertTrue(str_contains($script, 'UsedValues::missing('), '08 keeps only the values Jira offers today');
        $usagePhase = substr($script, (int)strpos($script, 'function runCustomFieldUsagePhase('), 1200);
        self::assertTrue(
            str_contains($usagePhase, 'backfillProjectIssueTypeFieldsFromIssues('),
            'the backfill runs only in the jira phase, before 10 staged the issues'
        );
        $ten = (string)file_get_contents(__DIR__ . '/../10_migrate_issues.php');
        self::assertTrue(
            str_contains($ten, "JSON_EXTRACT(r.raw_payload, '\$.custom_field.enumerations')"),
            '10 reads the options written at field creation only'
        );
    }

    public function testTheReportHasOneLinePerFieldAndValue(): void
    {
        $kept = ['mode' => 'flat', 'values' => [...self::OFFERED['values'], ['id' => '20003', 'value' => 'Field']]];
        $row = ['field_id' => 'customfield_1', 'field_name' => 'Department', 'format' => 'enumeration',
            'offered' => self::OFFERED, 'kept' => $kept];
        // the same field on another project or issue type, the value in other case
        $again = ['kept' => ['mode' => 'flat', 'values' => [['value' => 'field']]]] + $row;
        $other = ['field_id' => 'customfield_2', 'field_name' => 'Team', 'format' => 'list',
            'offered' => self::OFFERED, 'kept' => self::OFFERED];

        $lines = UsedValues::report([$row, $again, $other]);

        self::assertSame(
            [['field_id' => 'customfield_1', 'field_name' => 'Department', 'format' => 'enumeration', 'value' => 'Field']],
            $lines
        );
    }

    public function testRetiringDependsOnTheRedmineFormat(): void
    {
        self::assertStringContainsString('inactive', UsedValues::retireAdvice('enumeration'));
        self::assertStringContainsString('possible values', UsedValues::retireAdvice('list'));
        self::assertStringContainsString('by hand', UsedValues::retireAdvice(null));
        $script = (string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php');
        self::assertTrue(
            str_contains($script, 'printValuesJiraNoLongerOffers(collectValuesJiraNoLongerOffers($pdo));'),
            '08 adds values Jira no longer offers without listing them for the run report'
        );
    }
}

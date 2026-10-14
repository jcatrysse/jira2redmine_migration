<?php

declare(strict_types=1);

use Jira2Redmine\CustomFields\FieldScope;
use PHPUnit\Framework\TestCase;

final class FieldScopeTest extends TestCase
{
    public function testALaterBatchAddsItsProjectsAndTrackers(): void
    {
        // created by the first batch for projects 3 and 8; the WIKI batch's issues (Jira project 10005) hold it too
        $lookup = ['10008' => 3, '10009' => 8, '10005' => 15];

        self::assertSame([3, 8, 15], FieldScope::desired([8, 3], ['10008', '10005'], $lookup));
        // a Jira project without a Redmine project adds nothing
        self::assertSame([3], FieldScope::desired([3], ['99999'], $lookup));
    }

    public function testTheUpdatePlanComparesWithRedmine(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php');
        $plan = substr($script, (int)strpos($script, 'function collectCustomFieldUpdatePlan('), 6000);

        self::assertTrue(str_contains($plan, 'FieldScope::desired('), 'the plan compares the stored proposal with itself');
        self::assertTrue(str_contains($plan, 'rf.project_ids AS redmine_project_ids'), 'the plan ignores the field in Redmine');
    }
}

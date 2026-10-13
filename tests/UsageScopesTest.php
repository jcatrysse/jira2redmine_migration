<?php

declare(strict_types=1);

use Jira2Redmine\CustomFields\UsageScopes;
use PHPUnit\Framework\TestCase;

final class UsageScopesTest extends TestCase
{
    public function testScopeGrowsToWhereIssuesHoldValues(): void
    {
        $scopes = new UsageScopes();
        // B24: createmeta lists the field for project 200 only; a project 100 issue holds a value.
        $scopes->addIssue('100', '7', ['customfield_1' => ['value' => 'SBP'], 'customfield_2' => null, 'summary' => 'x']);
        $scopes->addIssue('300', '8', ['customfield_1' => [], 'customfield_2' => '']);

        self::assertSame(['100', '200'], $scopes->projectsFor('customfield_1', ['200']));
        self::assertSame(['7', '9'], $scopes->issueTypesFor('customfield_1', ['9']));
        self::assertSame(['100', '200'], $scopes->projectsFor('cascading_parent:customfield_1', ['200']));
        self::assertSame(['200'], $scopes->projectsFor('customfield_2', ['200']));
    }
}

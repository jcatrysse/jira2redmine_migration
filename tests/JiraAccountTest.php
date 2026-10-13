<?php

declare(strict_types=1);

use Jira2Redmine\Users\JiraAccount;
use PHPUnit\Framework\TestCase;

final class JiraAccountTest extends TestCase
{
    public function testOnlyAppAccountsAreApps(): void
    {
        self::assertTrue(JiraAccount::isApp('app'));
        self::assertTrue(JiraAccount::isApp(' App '));
        self::assertFalse(JiraAccount::isApp('atlassian'));
        self::assertFalse(JiraAccount::isApp('customer'));
        self::assertFalse(JiraAccount::isApp(null));
    }
}

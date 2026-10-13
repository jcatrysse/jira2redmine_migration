<?php

declare(strict_types=1);

use Jira2Redmine\Roles\RoleMapping;
use PHPUnit\Framework\TestCase;

final class RoleMappingTest extends TestCase
{
    public function testConfiguredNameIsUsedCaseInsensitively(): void
    {
        $mapping = new RoleMapping(['Administrators' => 'Manager']);

        self::assertSame('Manager', $mapping->redmineRoleName('administrators'));
        self::assertFalse($mapping->isIgnored('Administrators'));
    }

    public function testNullIgnoresTheRole(): void
    {
        $mapping = new RoleMapping(['atlassian-addons-project-access' => null]);

        self::assertTrue($mapping->isIgnored('atlassian-addons-project-access'));
        self::assertNull($mapping->redmineRoleName('atlassian-addons-project-access'));
    }

    public function testUnlistedRoleKeepsItsOwnName(): void
    {
        $mapping = new RoleMapping(['Administrators' => 'Manager']);

        self::assertSame('Developer', $mapping->redmineRoleName('Developer'));
        self::assertFalse($mapping->isIgnored('Developer'));
        self::assertNull($mapping->redmineRoleName(null));
    }

    public function testNoConfigChangesNothing(): void
    {
        $mapping = new RoleMapping(null);

        self::assertSame('Administrators', $mapping->redmineRoleName('Administrators'));
        self::assertFalse($mapping->isIgnored('Administrators'));
    }
}

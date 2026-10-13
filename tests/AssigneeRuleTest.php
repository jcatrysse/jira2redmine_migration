<?php

declare(strict_types=1);

use Jira2Redmine\Issues\AssigneeRule;
use PHPUnit\Framework\TestCase;

final class AssigneeRuleTest extends TestCase
{
    public function testMappedOrNoAssigneeNeedsNoNote(): void
    {
        self::assertSame(['assignee' => 5, 'note' => null], AssigneeRule::resolve('acc-1', 5, 9));
        self::assertSame(['assignee' => null, 'note' => null], AssigneeRule::resolve(null, null, 9));
    }

    public function testUnmappedAssigneeFallsBackInsteadOfBlocking(): void
    {
        self::assertSame(9, AssigneeRule::resolve('app-1', null, 9)['assignee']);
        $unassigned = AssigneeRule::resolve('app-1', null, null);
        self::assertNull($unassigned['assignee']);
        self::assertStringContainsString('created unassigned', (string)$unassigned['note']);
    }
}

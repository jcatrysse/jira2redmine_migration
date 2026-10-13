<?php

declare(strict_types=1);

use Jira2Redmine\Roles\AssignmentStatus;
use PHPUnit\Framework\TestCase;

final class AssignmentStatusTest extends TestCase
{
    public function testAPushedMembershipStaysRecordedOnARerun(): void
    {
        self::assertSame(
            'ASSIGNMENT_RECORDED',
            AssignmentStatus::afterTransform('ASSIGNMENT_RECORDED', [13, 191, 3], [13, 191, 3], false)
        );
    }

    public function testAChangedRoleIsAssignedAgain(): void
    {
        self::assertSame(
            'READY_FOR_ASSIGNMENT',
            AssignmentStatus::afterTransform('ASSIGNMENT_RECORDED', [13, 191, 3], [13, 191, 4], false)
        );
    }

    public function testNewRowsAreReadyUnlessRedmineHasThem(): void
    {
        $none = [null, null, null];
        self::assertSame('READY_FOR_ASSIGNMENT', AssignmentStatus::afterTransform('PENDING_ANALYSIS', $none, [13, 191, 3], false));
        self::assertSame('ASSIGNMENT_RECORDED', AssignmentStatus::afterTransform('PENDING_ANALYSIS', $none, [13, 191, 3], true));
    }
}

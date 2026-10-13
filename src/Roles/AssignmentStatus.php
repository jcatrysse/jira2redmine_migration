<?php

declare(strict_types=1);

namespace Jira2Redmine\Roles;

/**
 * Status of a project role assignment after the transform. A membership the push created stays
 * ASSIGNMENT_RECORDED while its project, principal and role are unchanged: the Redmine snapshot 04
 * compares with is the group snapshot of 03, taken before 04 pushed, so a rerun queued every pushed
 * membership again and the second push failed on "already a member".
 */
final class AssignmentStatus
{
    /**
     * @param array{0: ?int, 1: ?int, 2: ?int} $stored project, principal and role ids on the row
     * @param array{0: ?int, 1: ?int, 2: ?int} $resolved the ids the transform resolved now
     */
    public static function afterTransform(string $currentStatus, array $stored, array $resolved, bool $inSnapshot): string
    {
        if (in_array(null, $resolved, true)) {
            return 'READY_FOR_ASSIGNMENT';
        }
        if ($inSnapshot || ($currentStatus === 'ASSIGNMENT_RECORDED' && $stored === $resolved)) {
            return 'ASSIGNMENT_RECORDED';
        }

        return 'READY_FOR_ASSIGNMENT';
    }
}

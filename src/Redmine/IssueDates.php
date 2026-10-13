<?php

declare(strict_types=1);

namespace Jira2Redmine\Redmine;

/**
 * Jira has no start date; the scripts use the creation date. Redmine refuses a due date before the start
 * date (422 "Due date must be greater than start date", B8), which Jira allows (a due date set in the
 * past). Then the start date becomes the due date.
 */
final class IssueDates
{
    /**
     * @param string|null $createdAt Jira created timestamp or date (Y-m-d...)
     * @param string|null $dueDate Y-m-d
     * @return string|null Y-m-d
     */
    public static function startDate(?string $createdAt, ?string $dueDate): ?string
    {
        $start = $createdAt !== null && strlen($createdAt) >= 10 ? substr($createdAt, 0, 10) : null;
        if ($start !== null && $dueDate !== null && $dueDate !== '' && $dueDate < $start) {
            return $dueDate;
        }

        return $start;
    }
}

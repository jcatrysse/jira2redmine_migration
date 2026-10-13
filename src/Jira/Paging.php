<?php

declare(strict_types=1);

namespace Jira2Redmine\Jira;

/**
 * Offset paging over Jira's list endpoints ({startAt, maxResults, total, isLast, values}). Jira may
 * return fewer items than asked: workflow/search gives 50 when asked for 100. Advancing by the
 * requested page size then skipped items 51 to 100 (16 exported 50 of 64 workflows, B27), so the
 * next page starts after the items actually received.
 */
final class Paging
{
    /**
     * The startAt of the next page, or null when this page was the last one.
     *
     * @param array<mixed> $payload the decoded response
     */
    public static function nextStartAt(array $payload, int $startAt, int $received): ?int
    {
        if ($received === 0 || ($payload['isLast'] ?? false) === true) {
            return null;
        }

        $hasTotal = isset($payload['total']) && is_numeric($payload['total']);
        if (!$hasTotal && !array_key_exists('isLast', $payload)) {
            // a plain list: the endpoint does not page
            return null;
        }

        $pageStart = isset($payload['startAt']) && is_numeric($payload['startAt']) ? (int)$payload['startAt'] : $startAt;
        $next = $pageStart + $received;

        return $hasTotal && $next >= (int)$payload['total'] ? null : $next;
    }
}

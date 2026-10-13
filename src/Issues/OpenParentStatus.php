<?php

declare(strict_types=1);

namespace Jira2Redmine\Issues;

use RuntimeException;

/**
 * Jira allows an open subtask under a closed parent; Redmine refuses the link ("An open issue cannot be
 * attached to a closed parent task"). Decided by Jan (2026-10-13): link the subtask and give the parent
 * the open status "Resolved" instead of its closed Jira status. The status must exist and be open;
 * otherwise the run stops on it.
 */
final class OpenParentStatus
{
    /** Marker in the parent's mapping notes; the verify tool expects the open status for these parents. */
    public const MARKER = '[open-parent]';

    /**
     * @param list<array{id: int|string, name: string, is_closed: bool|int|string|null}> $statuses Redmine statuses
     */
    public static function resolve(array $statuses, string $name): int
    {
        foreach ($statuses as $status) {
            if (strcasecmp(trim((string)$status['name']), trim($name)) !== 0) {
                continue;
            }
            if (!empty($status['is_closed'])) {
                throw new RuntimeException(sprintf(
                    'Redmine status "%s" is a closed status; an open parent needs an open one (subtask_open_parent_status_name).',
                    $name
                ));
            }

            return (int)$status['id'];
        }

        throw new RuntimeException(sprintf('Redmine status "%s" does not exist; create it or set subtask_open_parent_status_name.', $name));
    }

    public static function note(string $statusName, ?string $jiraStatusName): string
    {
        return sprintf(
            '%s Parent status set to "%s" (open) because a subtask is open; Jira status "%s" kept in jira_status_id.',
            self::MARKER,
            $statusName,
            $jiraStatusName ?? '?'
        );
    }
}

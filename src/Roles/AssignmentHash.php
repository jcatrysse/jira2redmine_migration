<?php

declare(strict_types=1);

namespace Jira2Redmine\Roles;

/**
 * Automation hash of a migration_mapping_project_role_groups row: the state 04 last wrote. Jan reviews
 * the proposed memberships in that table before the push (B31); a row he changed no longer matches its
 * hash, and 04 leaves it as he left it on every rerun.
 */
final class AssignmentHash
{
    public static function compute(
        ?int $projectId,
        ?int $principalId,
        ?int $roleId,
        ?int $proposedRoleId,
        ?string $proposedRoleName,
        string $status,
        ?string $notes
    ): string {
        return hash('sha256', implode('|', [
            $projectId ?? 'null',
            $principalId ?? 'null',
            $roleId ?? 'null',
            $proposedRoleId ?? 'null',
            $proposedRoleName ?? 'null',
            $status,
            $notes ?? 'null',
        ]));
    }

    /**
     * @param array<string, mixed> $row a mapping row as read from the table
     */
    public static function isHandEdited(array $row): bool
    {
        // a value that is not a sha256 counts as no hash, as 04 always read it
        $stored = strtolower(trim((string)($row['automation_hash'] ?? '')));
        $int = static fn (mixed $value): ?int => $value === null || $value === '' ? null : (int)$value;

        return preg_match('/^[0-9a-f]{64}$/', $stored) === 1 && $stored !== self::compute(
            $int($row['redmine_project_id'] ?? null),
            $int($row['redmine_group_id'] ?? null),
            $int($row['redmine_role_id'] ?? null),
            $int($row['proposed_redmine_role_id'] ?? null),
            isset($row['proposed_redmine_role_name']) ? (string)$row['proposed_redmine_role_name'] : null,
            (string)($row['migration_status'] ?? ''),
            isset($row['notes']) ? (string)$row['notes'] : null
        );
    }
}

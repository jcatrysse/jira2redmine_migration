<?php

declare(strict_types=1);

namespace Jira2Redmine\Roles;

/**
 * B50 (decided by Jan 2026-10-14): a Jira issue's watcher keeps watching after losing access to the
 * project; Redmine accepts only a watcher who can see the issue and refused them (38 in GEOS, SIS and OAC).
 * Jan wants them migrated, so the history shows who followed the issue: 04 proposes each such watcher
 * as a direct member, like an assignee without membership (B46), in the same review table. Needs the
 * watchers staged (14 --phases=jira) before 04.
 */
final class WatcherMemberships
{
    /** Pseudo Jira role of these rows; migration.roles.watcher_only_role names its Redmine role (default Developer). */
    public const ROLE_ID = -5;
    public const ROLE_NAME = 'Watcher of issues (no membership)';

    /** Note on these rows, READY: without them Redmine refuses the watchers; Jan reviews them with the rest. */
    public const NOTE = 'Watcher only, not in the project\'s permission groups: Redmine accepts watchers with access only.';

    /**
     * migration.roles.mappings with the assignee-only and the watcher-only role added.
     *
     * @param array<string, mixed> $rolesConfig migration.roles
     * @return array<mixed>
     */
    public static function mappings(array $rolesConfig): array
    {
        $mappings = AssigneeMemberships::mappings($rolesConfig);
        $role = $rolesConfig['watcher_only_role'] ?? 'Developer';
        $mappings[self::ROLE_NAME] = is_string($role) && trim($role) !== '' ? trim($role) : 'Developer';

        return $mappings;
    }

    /**
     * The watchers no membership covers. A watcher who is an assignee without membership in the same
     * project is covered by that row: pass the assignee rows among the actors.
     *
     * @param list<array{project_id: string, project_key: ?string, project_name: ?string, account_id: string,
     *     display_name: ?string}> $watchers
     * @param list<array<string, mixed>> $actors role actors, permission grants and assignee-only rows
     * @param array<string, list<string>> $groupMembers Jira group id => account ids
     * @param list<string> $noAccessRoles role names whose members get no membership (ignored roles)
     * @return list<array<string, string|null>>
     */
    public static function uncovered(array $watchers, array $actors, array $groupMembers, array $noAccessRoles): array
    {
        return AssigneeMemberships::uncovered($watchers, $actors, $groupMembers, $noAccessRoles, [self::ROLE_ID, self::ROLE_NAME]);
    }
}

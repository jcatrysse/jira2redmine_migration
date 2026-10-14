<?php

declare(strict_types=1);

namespace Jira2Redmine\Roles;

/**
 * B46 (decided overnight 2026-10-14, option a, for Jan to confirm): Jira keeps an issue's assignee after
 * the person lost the Assignable user permission (left the group, left the company); Redmine assigns only
 * to a member of the project with an assignable role and refused those issues ("Assignee is invalid":
 * 47 in the WIKI batch, 10 in GEOS, SIS and OAC). 04 proposes each such assignee as a direct member, in
 * the same review table, so the assignee is kept. Needs the issues staged (10 --phases=jira) before 04.
 */
final class AssigneeMemberships
{
    /** Pseudo Jira role of these rows; migration.roles.assignee_only_role names its Redmine role (default Developer). */
    public const ROLE_ID = -4;
    public const ROLE_NAME = 'Assignee of issues (no membership)';

    /** Note on these rows, READY: without them Redmine refuses the issues; Jan reviews them with the rest. */
    public const NOTE = 'Assignee only, not in the project\'s permission groups: Redmine assigns issues to members only.';

    /**
     * migration.roles.mappings with the assignee-only role added.
     *
     * @param array<string, mixed> $rolesConfig migration.roles
     * @return array<mixed>
     */
    public static function mappings(array $rolesConfig): array
    {
        $mappings = is_array($rolesConfig['mappings'] ?? null) ? $rolesConfig['mappings'] : [];
        $role = $rolesConfig['assignee_only_role'] ?? 'Developer';
        $mappings[self::ROLE_NAME] = is_string($role) && trim($role) !== '' ? trim($role) : 'Developer';

        return $mappings;
    }

    /**
     * @param list<array{project_id: string, project_key: ?string, project_name: ?string, account_id: string,
     *     display_name: ?string}> $assignees
     * @param list<array<string, mixed>> $actors role actors and permission grants (project_id, role_name, actor_id, actor_type)
     * @param array<string, list<string>> $groupMembers Jira group id => account ids
     * @param list<string> $noAccessRoles role names whose members get no membership (ignored roles)
     * @param array{0: int, 1: string} $pseudoRole id and name of the rows' pseudo role (WatcherMemberships uses its own)
     * @return list<array<string, string|null>> actor rows (atlassian-user-role-actor of the pseudo role) for the uncovered assignees
     */
    public static function uncovered(
        array $assignees,
        array $actors,
        array $groupMembers,
        array $noAccessRoles,
        array $pseudoRole = [self::ROLE_ID, self::ROLE_NAME]
    ): array {
        $covered = [];
        foreach ($actors as $actor) {
            if (in_array((string)($actor['role_name'] ?? ''), $noAccessRoles, true)) {
                continue;
            }
            $project = (string)($actor['project_id'] ?? '');
            $id = (string)($actor['actor_id'] ?? '');
            if (($actor['actor_type'] ?? '') === 'atlassian-user-role-actor') {
                $covered[$project][$id] = true;
            } elseif (($actor['actor_type'] ?? '') === 'atlassian-group-role-actor') {
                // the built-in Non member group gives rights, not assignability: it covers no one
                foreach ($groupMembers[$id] ?? [] as $member) {
                    $covered[$project][$member] = true;
                }
            }
        }

        $rows = [];
        $seen = [];
        foreach ($assignees as $assignee) {
            $project = $assignee['project_id'];
            $account = $assignee['account_id'];
            if ($account === '' || isset($covered[$project][$account]) || isset($seen[$project . '|' . $account])) {
                continue;
            }
            $seen[$project . '|' . $account] = true;
            $rows[] = [
                'project_id' => $project,
                'project_key' => $assignee['project_key'],
                'project_name' => $assignee['project_name'],
                'role_id' => (string)$pseudoRole[0],
                'role_name' => $pseudoRole[1],
                'actor_id' => $account,
                'actor_display' => $assignee['display_name'],
                'actor_type' => 'atlassian-user-role-actor',
            ];
        }

        return $rows;
    }
}

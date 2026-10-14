<?php

declare(strict_types=1);

namespace Jira2Redmine\Workflows;

/**
 * Who gets a transition Jira limits to groups (decision 13): the roles each group holds in the project
 * (04's memberships). A group that is not a member gives a review note instead. The proposal and the
 * comparison with Jira both decide it here, so they cannot drift apart.
 *
 * Jan, decision 14 (2026-10-14): per project and group that is not a member, migration.workflows.group_roles
 * says what happens (a group not listed still gives a review row):
 * - G: 04 makes the group a member with its own role (WorkflowGroupRoles); from then on the group holds that
 *   role and gets the transitions like any member group;
 * - R: the transitions go to the roles the group's users hold in the project (through 04's memberships);
 * - ignore: not carried; the review row becomes IGNORED.
 */
final class GroupConditions
{
    public const OWN_ROLE = 'G';
    public const USERS_ROLES = 'R';
    public const IGNORE = 'ignore';

    /**
     * migration.workflows.group_roles: project key => Jira group name => G, R or ignore.
     *
     * @return array<string, array<string, string>> project key => lower-case Jira group name => mode
     * @throws \InvalidArgumentException on a mode that is not G, R or ignore
     */
    public static function modes(mixed $configured): array
    {
        $modes = [];
        foreach (is_array($configured) ? $configured : [] as $project => $groups) {
            foreach (is_array($groups) ? $groups : [] as $group => $mode) {
                $raw = trim((string)$mode);
                $mode = strtolower($raw) === self::IGNORE ? self::IGNORE : strtoupper($raw);
                if (!in_array($mode, [self::OWN_ROLE, self::USERS_ROLES, self::IGNORE], true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'migration.workflows.group_roles.%s.%s: "%s" is not G, R or ignore',
                        $project,
                        $group,
                        $raw
                    ));
                }
                $modes[trim((string)$project)][mb_strtolower(trim((string)$group), 'UTF-8')] = $mode;
            }
        }

        return $modes;
    }

    /**
     * The roles a group's users hold in each project through 04's memberships, for the groups set to R: a user
     * membership gives its user the role, a group membership every member of that group.
     *
     * @param list<array{project: string, principal_type: string, principal_id: string, role: int}> $memberships
     * @param array<string, list<string>> $groupMembers Jira group id => account ids
     * @param array<string, string> $groupIds lower-case Jira group name => group id
     * @param array<string, array<string, string>> $modes project key => lower-case Jira group name => G, R or ignore
     * @return array<string, array<string, list<int>>> project key => lower-case Jira group name => role ids
     */
    public static function usersRoles(array $memberships, array $groupMembers, array $groupIds, array $modes): array
    {
        $accountRoles = [];
        foreach ($memberships as $membership) {
            $accounts = $membership['principal_type'] === 'user'
                ? [$membership['principal_id']]
                : ($groupMembers[$membership['principal_id']] ?? []);
            foreach ($accounts as $account) {
                $accountRoles[$membership['project']][$account][$membership['role']] = true;
            }
        }
        $result = [];
        foreach ($modes as $project => $groups) {
            foreach ($groups as $group => $mode) {
                if ($mode !== self::USERS_ROLES) {
                    continue;
                }
                $roles = [];
                foreach ($groupMembers[$groupIds[$group] ?? ''] ?? [] as $account) {
                    $roles += $accountRoles[$project][$account] ?? [];
                }
                $roles = array_keys($roles);
                sort($roles);
                $result[$project][$group] = $roles;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $groups the transition's Jira groups
     * @param array<string, array<string, list<int>>> $groupRoles project key => lower-case Jira group name => role ids
     * @param array<string, array<string, string>> $modes project key => lower-case Jira group name => G, R or ignore
     * @param array<string, array<string, list<int>>> $usersRoles project key => lower-case Jira group name => the role
     *     ids its users hold in the project
     * @return array{roles: list<int>, review: list<string>, not_members: list<string>, ignored: list<string>}
     *     roles: sorted, each once; review: the review note of each group that is not a member and has no setting
     *     that resolves it; not_members: those groups; ignored: the note of each group Jan ignores
     */
    public static function resolve(string $project, array $groups, array $groupRoles, array $modes = [], array $usersRoles = []): array
    {
        $roles = [];
        $review = [];
        $notMembers = [];
        $ignored = [];
        foreach ($groups as $group) {
            $key = mb_strtolower($group, 'UTF-8');
            $held = $groupRoles[$project][$key] ?? [];
            $mode = $modes[$project][$key] ?? null;
            if ($held === [] && $mode === self::USERS_ROLES) {
                $held = $usersRoles[$project][$key] ?? [];
            }
            if ($held === [] && $mode === self::IGNORE) {
                $ignored[] = sprintf(
                    'Jira limits it to group "%s", which is not a member of %s: ignored (Jan, decision 14)',
                    $group,
                    $project
                );
            } elseif ($held === []) {
                $notMembers[] = $group;
                $review[] = match ($mode) {
                    self::OWN_ROLE => sprintf(
                        'Jira limits it to group "%s", which is not a member of %s: its own role (Jan, decision 14) has no '
                        . 'membership yet, run 04 and its push',
                        $group,
                        $project
                    ),
                    self::USERS_ROLES => sprintf(
                        'Jira limits it to group "%s", which is not a member of %s: no user of the group is a member '
                        . '(Jan, decision 14: R); set review_role_id and PROPOSED, or IGNORED',
                        $group,
                        $project
                    ),
                    default => sprintf(
                        'Jira limits it to group "%s", which is not a member of %s: set review_role_id and PROPOSED, or IGNORED',
                        $group,
                        $project
                    ),
                };
            }
            $roles = array_merge($roles, $held);
        }
        $roles = array_values(array_unique($roles));
        sort($roles);

        return ['roles' => $roles, 'review' => $review, 'not_members' => $notMembers, 'ignored' => $ignored];
    }
}

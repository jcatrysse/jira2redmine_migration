<?php

declare(strict_types=1);

namespace Jira2Redmine\Roles;

use Jira2Redmine\Workflows\GroupConditions;

/**
 * Jan, decision 14 (2026-10-14), option G: a Jira group that is not a member of a project, while Jira limits
 * transitions there to it, becomes a member with its own role (migration.workflows.group_role_name, default
 * "<group> (workflow)"). 04 proposes the membership as a group actor of a pseudo Jira role named after that
 * role, so the role is found by its own name; 04's push creates the role through the Extended API when Redmine
 * lacks it. The role holds "Edit issues" only: Redmine applies a role's workflow only with "Edit issues" or
 * "Add issues" (Role#consider_workflow?); without "View issues" and not assignable it gives nothing but the
 * transitions to a user who sees the project's issues through another role. The workflow proposal then gives
 * the group's transitions to that role, as to any member group.
 */
final class WorkflowGroupRoles
{
    /** Pseudo Jira role of these rows. */
    public const ROLE_ID = -6;

    /** Note on these rows in the review table. */
    public const NOTE = 'Own role of the group for the workflow transitions Jira limits to it (Jan, decision 14).';

    public const DEFAULT_NAME = '%s (workflow)';

    /** @var list<string> */
    public const DEFAULT_PERMISSIONS = ['edit_issues'];

    public static function roleName(mixed $pattern, string $group): string
    {
        $pattern = is_string($pattern) && substr_count($pattern, '%s') === 1 && substr_count($pattern, '%') === 1
            ? $pattern
            : self::DEFAULT_NAME;

        return sprintf($pattern, $group);
    }

    /**
     * @return list<string> migration.workflows.group_role_permissions, or edit_issues
     */
    public static function permissions(mixed $configured): array
    {
        $permissions = [];
        foreach (is_array($configured) ? $configured : [] as $permission) {
            $permission = trim((string)$permission);
            if ($permission !== '' && !in_array($permission, $permissions, true)) {
                $permissions[] = $permission;
            }
        }

        return $permissions === [] ? self::DEFAULT_PERMISSIONS : $permissions;
    }

    /**
     * A group actor for every project and group set to G.
     *
     * @param array<string, mixed> $workflowsConfig migration.workflows
     * @param array<string, array{id: string, name: ?string}> $projects project key => the staged Jira project
     * @param array<string, array{id: string, name: string}> $groups lower-case Jira group name => the staged Jira group
     * @return array{actors: list<array{project_id: string, project_key: string, project_name: ?string, role_id: string,
     *     role_name: string, actor_id: string, actor_display: string, actor_type: string}>, unknown: list<string>}
     */
    public static function actors(array $workflowsConfig, array $projects, array $groups): array
    {
        $actors = [];
        $unknown = [];
        foreach (GroupConditions::modes($workflowsConfig['group_roles'] ?? null) as $projectKey => $modes) {
            foreach ($modes as $groupKey => $mode) {
                if ($mode !== GroupConditions::OWN_ROLE) {
                    continue;
                }
                $project = $projects[$projectKey] ?? null;
                $group = $groups[$groupKey] ?? null;
                if ($project === null || $group === null) {
                    $why = $project === null ? 'project not staged (01)' : 'no such Jira group (03)';
                    $unknown[] = sprintf('%s / %s: %s', $projectKey, $groupKey, $why);
                    continue;
                }
                $actors[] = [
                    'project_id' => $project['id'],
                    'project_key' => $projectKey,
                    'project_name' => $project['name'],
                    'role_id' => (string)self::ROLE_ID,
                    'role_name' => self::roleName($workflowsConfig['group_role_name'] ?? null, $group['name']),
                    'actor_id' => $group['id'],
                    'actor_display' => $group['name'],
                    'actor_type' => 'atlassian-group-role-actor',
                ];
            }
        }

        return ['actors' => $actors, 'unknown' => $unknown];
    }

    /**
     * The Extended API body that creates the role (POST /extended_api/roles.json).
     *
     * @param list<string> $permissions
     * @return array{role: array{name: string, assignable: bool, issues_visibility: string, users_visibility: string,
     *     time_entries_visibility: string, permissions: list<string>}}
     */
    public static function rolePayload(string $name, array $permissions): array
    {
        return ['role' => [
            'name' => $name,
            'assignable' => false,
            'issues_visibility' => 'default',
            'users_visibility' => 'members_of_visible_projects',
            'time_entries_visibility' => 'own',
            'permissions' => $permissions,
        ]];
    }
}

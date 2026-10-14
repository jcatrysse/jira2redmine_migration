<?php

declare(strict_types=1);

namespace Jira2Redmine\Roles;

/**
 * Project access as Jira grants it: the permission scheme of each project gives "Browse projects",
 * "Assignable user" and "Administer projects" mostly to groups (at GEOxyz 22, 23 and 14 times), while the
 * project roles hold only the administrators. Decided by Jan (B31, 2026-10-13, option a): 04 proposes
 * a group with Browse projects as group member with Developer, one with Administer projects as Manager,
 * in the mapping table, where he reviews the proposal before the push. Assignable user is proposed as
 * Developer too (decided overnight, for Jan to confirm): Redmine only accepts a member as assignee.
 */
final class PermissionGrants
{
    /** Pseudo Jira role ids and names for the mapping rows; migration.roles.mappings maps the names. */
    public const PSEUDO_ROLES = [
        'BROWSE_PROJECTS' => [-1, 'Browse projects (permission scheme)'],
        'ADMINISTER_PROJECTS' => [-2, 'Administer projects (permission scheme)'],
        'ASSIGNABLE_USER' => [-3, 'Assignable user (permission scheme)'],
    ];

    /**
     * @param array<mixed> $scheme GET /rest/api/3/project/{id}/permissionscheme?expand=permissions,group
     * @return array{grants: list<array{permission: string, group_id: string, group_name: ?string}>, other_holders: array<string, int>}
     */
    public static function fromScheme(array $scheme): array
    {
        $grants = [];
        $seen = [];
        $other = [];
        foreach ($scheme['permissions'] ?? [] as $permission) {
            if (!is_array($permission) || !isset(self::PSEUDO_ROLES[(string)($permission['permission'] ?? '')])) {
                continue;
            }
            $name = (string)$permission['permission'];
            $holder = is_array($permission['holder'] ?? null) ? $permission['holder'] : [];
            $type = (string)($holder['type'] ?? '');
            if ($type !== 'group') {
                // project roles come through the role actors; application roles and others are counted
                if ($type !== 'projectRole') {
                    $other[$name . ' ' . $type] = ($other[$name . ' ' . $type] ?? 0) + 1;
                }
                continue;
            }
            $group = is_array($holder['group'] ?? null) ? $holder['group'] : [];
            $groupId = trim((string)($group['groupId'] ?? $holder['value'] ?? ''));
            if ($groupId === '' || isset($seen[$name . '|' . $groupId])) {
                continue;
            }
            $seen[$name . '|' . $groupId] = true;
            $grants[] = [
                'permission' => $name,
                'group_id' => $groupId,
                'group_name' => isset($group['name'])
                    ? (string)$group['name']
                    : (isset($holder['parameter']) ? (string)$holder['parameter'] : null),
            ];
        }

        return ['grants' => $grants, 'other_holders' => $other];
    }
}

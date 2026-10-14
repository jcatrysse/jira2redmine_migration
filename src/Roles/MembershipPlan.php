<?php

declare(strict_types=1);

namespace Jira2Redmine\Roles;

/**
 * What the push of 04 does for one proposed membership. A principal can get several roles on a project
 * (Developer from Browse projects, Manager from Administer projects) and can already be a member: a
 * second POST of the membership answered 422 "has already been taken". The role is added to the
 * existing membership instead.
 */
final class MembershipPlan
{
    /**
     * @param array{id: int, role_ids: list<int>}|null $existing the principal's membership, its direct roles
     * @return array{action: string, role_ids: list<int>} action: create, update or none
     */
    public static function decide(?array $existing, int $roleId): array
    {
        if ($existing === null) {
            return ['action' => 'create', 'role_ids' => [$roleId]];
        }
        if (in_array($roleId, $existing['role_ids'], true)) {
            return ['action' => 'none', 'role_ids' => $existing['role_ids']];
        }
        $roles = $existing['role_ids'];
        $roles[] = $roleId;
        sort($roles);

        return ['action' => 'update', 'role_ids' => $roles];
    }

    /**
     * The memberships of one page of GET /projects/:id/memberships.json, per principal (user or group).
     * Roles inherited from a group membership are left out: a PUT keeps them by itself, and a user who
     * is a member only through a group still has a membership, so a POST would answer 422.
     *
     * @param array<mixed> $page
     * @return array<int, array{id: int, role_ids: list<int>}>
     */
    public static function existingFromApi(array $page): array
    {
        $existing = [];
        foreach ($page['memberships'] ?? [] as $membership) {
            if (!is_array($membership) || !isset($membership['id'])) {
                continue;
            }
            // a built-in group answers as group_non_member or group_anonymous (7.0)
            $principal = null;
            foreach (['user', 'group', 'group_non_member', 'group_anonymous'] as $kind) {
                if (is_array($membership[$kind] ?? null)) {
                    $principal = $membership[$kind];
                    break;
                }
            }
            if (!is_array($principal) || !isset($principal['id'])) {
                continue;
            }
            $roles = [];
            foreach ($membership['roles'] ?? [] as $role) {
                if (is_array($role) && isset($role['id']) && ($role['inherited'] ?? false) !== true) {
                    $roles[(int)$role['id']] = (int)$role['id'];
                }
            }
            sort($roles);
            $existing[(int)$principal['id']] = ['id' => (int)$membership['id'], 'role_ids' => $roles];
        }

        return $existing;
    }
}

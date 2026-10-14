<?php

declare(strict_types=1);

namespace Jira2Redmine\Workflows;

/**
 * Who gets a transition Jira limits to groups (decision 13): the roles each group holds in the project
 * (04's memberships). A group that is not a member gives a review note instead. The proposal and the
 * comparison with Jira both decide it here, so they cannot drift apart.
 */
final class GroupConditions
{
    /**
     * @param list<string> $groups the transition's Jira groups
     * @param array<string, array<string, list<int>>> $groupRoles project key => lower-case Jira group name => role ids
     * @return array{roles: list<int>, review: list<string>, not_members: list<string>}
     *     roles: sorted, each once; review: the review note of each group that is not a member; not_members: those groups
     */
    public static function resolve(string $project, array $groups, array $groupRoles): array
    {
        $roles = [];
        $review = [];
        $notMembers = [];
        foreach ($groups as $group) {
            $held = $groupRoles[$project][mb_strtolower($group, 'UTF-8')] ?? [];
            if ($held === []) {
                $notMembers[] = $group;
                $review[] = sprintf(
                    'Jira limits it to group "%s", which is not a member of %s: set review_role_id and PROPOSED, or IGNORED',
                    $group,
                    $project
                );
            }
            $roles = array_merge($roles, $held);
        }
        $roles = array_values(array_unique($roles));
        sort($roles);

        return ['roles' => $roles, 'review' => $review, 'not_members' => $notMembers];
    }
}

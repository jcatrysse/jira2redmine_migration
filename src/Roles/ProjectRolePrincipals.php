<?php

declare(strict_types=1);

namespace Jira2Redmine\Roles;

/**
 * Who gets a Redmine membership from a Jira project role actor: a group actor its group, a user actor
 * that user. 04 used to give a user's role to every group the user is in; one project admin in
 * "all staff" (254 people) made the whole group admin of 8 projects (B31).
 */
final class ProjectRolePrincipals
{
    public const GROUP = 'group';
    public const USER = 'user';

    private const ACTOR_TYPES = [
        'atlassian-group-role-actor' => self::GROUP,
        'atlassian-user-role-actor' => self::USER,
    ];

    /**
     * @param list<array<string, mixed>> $actors rows of staging_jira_project_role_actors
     * @param array<string, string> $groupNames Jira group id => name
     * @return list<array{project_id: string, project_key: ?string, project_name: ?string, role_id: string,
     *     role_name: ?string, principal_type: string, principal_id: string, principal_name: ?string}>
     */
    public static function fromActors(array $actors, array $groupNames): array
    {
        $result = [];
        $seen = [];
        foreach ($actors as $actor) {
            $type = self::ACTOR_TYPES[(string)($actor['actor_type'] ?? '')] ?? null;
            $principalId = trim((string)($actor['actor_id'] ?? ''));
            $projectId = trim((string)($actor['project_id'] ?? ''));
            $roleId = trim((string)($actor['role_id'] ?? ''));
            if ($type === null || $principalId === '' || $projectId === '' || $roleId === '') {
                continue;
            }
            $key = implode('|', [$projectId, $roleId, $type, $principalId]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $display = isset($actor['actor_display']) ? (string)$actor['actor_display'] : null;
            $result[] = [
                'project_id' => $projectId,
                'project_key' => isset($actor['project_key']) ? (string)$actor['project_key'] : null,
                'project_name' => isset($actor['project_name']) ? (string)$actor['project_name'] : null,
                'role_id' => $roleId,
                'role_name' => isset($actor['role_name']) ? (string)$actor['role_name'] : null,
                'principal_type' => $type,
                'principal_id' => $principalId,
                'principal_name' => $type === self::GROUP ? ($groupNames[$principalId] ?? $display) : $display,
            ];
        }

        return $result;
    }
}

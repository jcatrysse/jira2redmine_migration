<?php

declare(strict_types=1);

namespace Jira2Redmine\Roles;

/**
 * migration.roles.mappings: Jira project role name => Redmine role name, or null to ignore the role.
 * Jira Cloud's own role names never match Redmine's (Administrators vs Manager), so without it every
 * role stayed MANUAL and no membership was pushed; atlassian-addons-project-access only holds app
 * accounts. A role not listed is matched on its own name, as before.
 */
final class RoleMapping
{
    /** @var array<string, ?string> lower-case Jira role name => Redmine role name or null */
    private array $mappings = [];

    /**
     * @param mixed $configured
     */
    public function __construct(mixed $configured)
    {
        if (!is_array($configured)) {
            return;
        }
        foreach ($configured as $jiraName => $redmineName) {
            $key = strtolower(trim((string)$jiraName));
            if ($key === '') {
                continue;
            }
            $target = $redmineName === null ? null : trim((string)$redmineName);
            $this->mappings[$key] = $target === '' ? null : $target;
        }
    }

    public function isIgnored(?string $jiraRoleName): bool
    {
        $key = strtolower(trim((string)$jiraRoleName));

        return $key !== '' && array_key_exists($key, $this->mappings) && $this->mappings[$key] === null;
    }

    /**
     * The Redmine role name to look for: the configured target, else the Jira name itself.
     */
    public function redmineRoleName(?string $jiraRoleName): ?string
    {
        if ($jiraRoleName === null || trim($jiraRoleName) === '') {
            return null;
        }
        $key = strtolower(trim($jiraRoleName));
        if (array_key_exists($key, $this->mappings)) {
            return $this->mappings[$key];
        }

        return $jiraRoleName;
    }
}

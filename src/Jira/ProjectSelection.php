<?php

declare(strict_types=1);

namespace Jira2Redmine\Jira;

/**
 * migration.issues.projects: the Jira project keys whose issues 10 extracts (empty: every project).
 * Before, the only way to limit a run was a JQL filter, and 10 still marked every project as
 * extracted, so adding a project later needed SQL on issues_extracted_at (B4).
 */
final class ProjectSelection
{
    /** @var array<string, true> */
    private array $keys = [];

    /**
     * @param mixed $configured list of keys or a comma separated string
     */
    public function __construct(mixed $configured)
    {
        $values = is_array($configured) ? $configured : explode(',', (string)$configured);
        foreach ($values as $value) {
            $key = strtoupper(trim((string)$value));
            if ($key !== '') {
                $this->keys[$key] = true;
            }
        }
    }

    public function includes(string $projectKey): bool
    {
        return $this->keys === [] || isset($this->keys[strtoupper($projectKey)]);
    }
}

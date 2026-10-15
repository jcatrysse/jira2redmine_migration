<?php

declare(strict_types=1);

namespace Jira2Redmine\Issues;

/**
 * A Jira issue's labels as Redmine tags (redmine_tags): trimmed, empty ones left out, each once. 13 pushes them
 * after the issues exist; 10 sends them when it creates the issue, so the tags leave no journal.
 */
final class IssueLabels
{
    /**
     * @param mixed $labels the decoded "labels" column of staging_jira_issues
     * @return list<string>
     */
    public static function tags(mixed $labels): array
    {
        if (!is_array($labels)) {
            return [];
        }
        $tags = array_values(array_filter(array_map('trim', $labels), static fn ($label) => $label !== ''));

        return array_values(array_unique($tags));
    }
}

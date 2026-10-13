<?php

declare(strict_types=1);

namespace Jira2Redmine\CustomFields;

/**
 * Jira's create metadata says on which projects and issue types a field is on the screen today; issues
 * can hold values of a field that is no longer there (B24: "Survey Hardware" has values in GEOS but
 * createmeta does not list GEOS). Redmine silently drops a value of a field that is not enabled for the
 * issue's project and tracker, so the scope is widened to where the staged issues have values.
 */
final class UsageScopes
{
    /** @var array<string, array<string, true>> */
    private array $projects = [];

    /** @var array<string, array<string, true>> */
    private array $issueTypes = [];

    /**
     * @param array<string, mixed> $fields the "fields" object of a staged Jira issue
     */
    public function addIssue(string $projectId, string $issueTypeId, array $fields): void
    {
        foreach ($fields as $fieldId => $value) {
            if (!str_starts_with((string)$fieldId, 'customfield_') || $value === null || $value === '' || $value === []) {
                continue;
            }
            $this->projects[(string)$fieldId][$projectId] = true;
            $this->issueTypes[(string)$fieldId][$issueTypeId] = true;
        }
    }

    /**
     * @param list<string> $configured project ids from Jira's create metadata
     * @return list<string> those plus the projects whose issues hold a value; "cascading_parent:" rows share
     *                      their field's scope
     */
    public function projectsFor(string $fieldId, array $configured): array
    {
        return self::merge($configured, $this->projects[self::baseField($fieldId)] ?? []);
    }

    /**
     * @param list<string> $configured
     * @return list<string>
     */
    public function issueTypesFor(string $fieldId, array $configured): array
    {
        return self::merge($configured, $this->issueTypes[self::baseField($fieldId)] ?? []);
    }

    private static function baseField(string $fieldId): string
    {
        return str_starts_with($fieldId, 'cascading_parent:') ? substr($fieldId, strlen('cascading_parent:')) : $fieldId;
    }

    /**
     * @param list<string> $configured
     * @param array<string, true> $used
     * @return list<string>
     */
    private static function merge(array $configured, array $used): array
    {
        $all = array_values(array_unique(array_merge($configured, array_map('strval', array_keys($used)))));
        sort($all);

        return $all;
    }
}

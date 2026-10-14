<?php

declare(strict_types=1);

namespace Jira2Redmine\Jira;

/**
 * What Jan decided not to migrate, as a configured outcome (IGNORED with a note) instead of rows left MANUAL that
 * every run and report keeps flagging:
 * - migration.projects.not_migrated: Jira project keys 01 does not create (decision 22: TEST);
 * - migration.custom_fields.not_migrated: Jira field types 08 does not create (decision 20: the service desk fields
 *   "Organizations" and "Request participants"; their values stay in Jira only).
 */
final class NotMigrated
{
    public const PROJECT_NOTE = 'Not migrated (migration.projects.not_migrated, Jan, decision 22).';
    public const FIELD_NOTE = 'Not migrated (migration.custom_fields.not_migrated, Jan, decision 20): its values stay in Jira only.';

    public static function project(mixed $configured, ?string $projectKey): bool
    {
        $key = strtoupper(trim((string)$projectKey));

        return $key !== '' && in_array($key, self::values($configured, true), true);
    }

    /**
     * @param ?string $schemaCustom the field's Jira type, e.g. com.atlassian.servicedesk:sd-request-participants
     */
    public static function fieldType(mixed $configured, ?string $schemaCustom): bool
    {
        $type = trim((string)$schemaCustom);

        return $type !== '' && in_array($type, self::values($configured, false), true);
    }

    /**
     * @return list<string>
     */
    private static function values(mixed $configured, bool $upper): array
    {
        $values = [];
        foreach (is_array($configured) ? $configured : explode(',', (string)$configured) as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $values[] = $upper ? strtoupper($value) : $value;
            }
        }

        return $values;
    }
}

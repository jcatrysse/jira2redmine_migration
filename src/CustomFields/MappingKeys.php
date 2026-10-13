<?php

declare(strict_types=1);

namespace Jira2Redmine\CustomFields;

/**
 * Keys of migration_mapping_custom_fields. A cascading Jira field maps to two Redmine fields: the row
 * of the Jira field (the child) and a synthetic row "cascading_parent:<field id>" (the parent). The purge
 * of rows whose Jira field is gone compared the synthetic key with the Jira fields and deleted every
 * parent row on each transform; after the fields existed nothing recreated them, so 10 lost the parent
 * field of three cascading fields and issues created after a rerun of 08 lost their parent values (B43).
 */
final class MappingKeys
{
    public const CASCADING_PARENT_PREFIX = 'cascading_parent:';

    public static function sourceFieldId(string $jiraFieldId): string
    {
        return str_starts_with($jiraFieldId, self::CASCADING_PARENT_PREFIX)
            ? substr($jiraFieldId, strlen(self::CASCADING_PARENT_PREFIX))
            : $jiraFieldId;
    }

    /**
     * @param array<int|string, string> $mappingKeys mapping id => jira_field_id
     * @param array<string, true> $jiraFieldIds the custom fields Jira still has
     * @return list<int|string> mapping ids whose Jira field is gone
     */
    public static function orphans(array $mappingKeys, array $jiraFieldIds): array
    {
        $orphans = [];
        foreach ($mappingKeys as $mappingId => $jiraFieldId) {
            if (!isset($jiraFieldIds[self::sourceFieldId((string)$jiraFieldId)])) {
                $orphans[] = $mappingId;
            }
        }

        return $orphans;
    }
}

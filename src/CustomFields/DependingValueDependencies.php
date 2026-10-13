<?php

declare(strict_types=1);

namespace Jira2Redmine\CustomFields;

/**
 * redmine_depending_custom_fields checks a depending_enumeration value against value_dependencies keyed
 * by the parent's enumeration id, with the child's enumeration ids as values. 08 created the child field
 * with labels (the ids do not exist before the field), so every issue with a cascading value got 422
 * "is not included in the list" (B7). After creating the field its labels are translated to ids.
 */
final class DependingValueDependencies
{
    /**
     * @param array<int|string, mixed> $dependencies parent label (or id) => list of child labels
     * @param array<int, array<string, mixed>> $parentEnumerations [['id' => 46, 'name' => 'QPS'], ...]
     * @param array<int, array<string, mixed>> $childEnumerations
     * @return array{dependencies: array<string, list<string>>, unresolved: list<string>}
     */
    public static function labelsToIds(array $dependencies, array $parentEnumerations, array $childEnumerations): array
    {
        $parentIds = self::labelToId($parentEnumerations);
        $knownParentIds = array_flip(array_map('strval', array_values($parentIds)));
        $childIds = self::labelToId($childEnumerations);
        $result = [];
        $unresolved = [];

        foreach ($dependencies as $parentKey => $children) {
            $parentKey = (string)$parentKey;
            $parentId = $parentIds[$parentKey] ?? (isset($knownParentIds[$parentKey]) ? $parentKey : null);
            if ($parentId === null) {
                $unresolved[] = 'parent ' . $parentKey;
                continue;
            }
            $ids = [];
            foreach (is_array($children) ? $children : [] as $child) {
                $label = is_array($child) ? (string)($child['value'] ?? $child['name'] ?? '') : (string)$child;
                if (isset($childIds[$label])) {
                    $ids[] = (string)$childIds[$label];
                } elseif ($label !== '') {
                    $unresolved[] = 'child ' . $label;
                }
            }
            $result[(string)$parentId] = array_values(array_unique($ids));
        }

        return ['dependencies' => $result, 'unresolved' => $unresolved];
    }

    /**
     * @param array<int, array<string, mixed>> $enumerations
     * @return array<string, int|string>
     */
    private static function labelToId(array $enumerations): array
    {
        $map = [];
        foreach ($enumerations as $enumeration) {
            $label = trim((string)($enumeration['name'] ?? $enumeration['value'] ?? ''));
            if ($label !== '' && isset($enumeration['id'])) {
                $map[$label] = $enumeration['id'];
            }
        }

        return $map;
    }
}

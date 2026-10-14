<?php

declare(strict_types=1);

namespace Jira2Redmine\Workflows;

/**
 * The transitions of one Jira workflow (GET workflow/search, expand transitions.rules), read for a
 * Redmine workflow proposal. Redmine knows per tracker and role which status may follow which, plus
 * "only when author" and "only when assignee"; the rest of Jira's rules is listed as not mappable.
 */
final class JiraTransitions
{
    /** Post functions every Jira transition has; Redmine does the same by itself (journal, history, index). */
    private const STANDARD_POST_FUNCTIONS = [
        'UpdateIssueStatusFunction', 'CreateCommentFunction', 'GenerateChangeHistoryFunction', 'IssueReindexFunction',
        'FireIssueEventFunction', 'IssueCreateFunction',
    ];

    /**
     * @param array<mixed> $workflow
     * @return list<array{name: string, from: list<string>, to: string, groups: list<string>, permissions: list<string>,
     *     assignee_only: bool, not_mapped: list<string>}>
     *     from: Jira status ids, "0" for the creation of an issue; groups: empty when anyone may
     */
    public static function fromWorkflow(array $workflow): array
    {
        $statuses = [];
        foreach ($workflow['statuses'] ?? [] as $status) {
            if (is_array($status) && isset($status['id'])) {
                $statuses[] = (string)$status['id'];
            }
        }

        $result = [];
        foreach ($workflow['transitions'] ?? [] as $transition) {
            if (!is_array($transition) || !isset($transition['to'])) {
                continue;
            }
            $to = (string)$transition['to'];
            $type = (string)($transition['type'] ?? 'directed');
            $from = match ($type) {
                'initial' => ['0'],
                'global' => array_values(array_diff($statuses, [$to])),
                default => array_values(array_map('strval', is_array($transition['from'] ?? null) ? $transition['from'] : [])),
            };

            $rules = is_array($transition['rules'] ?? null) ? $transition['rules'] : [];
            $read = ['groups' => [], 'permissions' => [], 'assignee_only' => false, 'not_mapped' => []];
            self::readConditions($rules['conditionsTree'] ?? null, $read);
            foreach ($rules['validators'] ?? [] as $validator) {
                $kind = (string)($validator['type'] ?? '');
                if ($kind === 'PermissionValidator') {
                    $read['permissions'][] = (string)($validator['configuration']['permissionKey'] ?? '');
                } elseif ($kind !== '') {
                    $read['not_mapped'][] = 'validator ' . $kind;
                }
            }
            foreach ($rules['postFunctions'] ?? [] as $function) {
                $kind = (string)($function['type'] ?? '');
                $field = (string)($function['configuration']['fieldId'] ?? '');
                if ($kind === '' || in_array($kind, self::STANDARD_POST_FUNCTIONS, true) || $field === 'resolution') {
                    // Redmine has no resolution field: Jira's resolution clean-up has nothing to do
                    continue;
                }
                $read['not_mapped'][] = 'post function ' . $kind . ($field !== '' ? ' (' . $field . ')' : '');
            }
            if (is_array($transition['screen'] ?? null) && isset($transition['screen']['name'])) {
                $read['not_mapped'][] = 'transition screen "' . $transition['screen']['name'] . '"';
            }

            $result[] = [
                'name' => (string)($transition['name'] ?? ''),
                'from' => $from,
                'to' => $to,
                'groups' => array_values(array_unique($read['groups'])),
                'permissions' => array_values(array_unique(array_filter($read['permissions']))),
                'assignee_only' => $read['assignee_only'],
                'not_mapped' => array_values(array_unique($read['not_mapped'])),
            ];
        }

        return $result;
    }

    /**
     * @param array{groups: list<string>, permissions: list<string>, assignee_only: bool, not_mapped: list<string>} $read
     */
    private static function readConditions(mixed $node, array &$read): void
    {
        if (!is_array($node)) {
            return;
        }
        if (($node['nodeType'] ?? '') === 'compound') {
            if (strtoupper((string)($node['operator'] ?? '')) === 'AND' && self::groupConditions($node) > 1) {
                $read['not_mapped'][] = 'condition: user in all of several groups (proposed as in any of them)';
            }
            foreach ($node['conditions'] ?? [] as $child) {
                self::readConditions($child, $read);
            }

            return;
        }
        $configuration = is_array($node['configuration'] ?? null) ? $node['configuration'] : [];
        switch ((string)($node['type'] ?? '')) {
            case 'UserInGroupCondition':
                $read['groups'][] = (string)($configuration['group'] ?? '');
                break;
            case 'UserInAnyGroupCondition':
                foreach ($configuration['groups'] ?? [] as $group) {
                    $read['groups'][] = (string)$group;
                }
                break;
            case 'PermissionCondition':
                $read['permissions'][] = (string)($configuration['permissionKey'] ?? '');
                break;
            case 'AllowOnlyAssignee':
                $read['assignee_only'] = true;
                break;
            case 'OnlyBambooNotificationsCondition':
                // only Bamboo (a build server) may run it: not a person's transition
                $read['not_mapped'][] = 'condition: only Bamboo notifications';
                break;
            default:
                $read['not_mapped'][] = 'condition ' . (string)($node['type'] ?? '?');
        }
    }

    /** @param array<mixed> $node */
    private static function groupConditions(array $node): int
    {
        $count = 0;
        foreach ($node['conditions'] ?? [] as $child) {
            if (is_array($child) && in_array($child['type'] ?? '', ['UserInGroupCondition', 'UserInAnyGroupCondition'], true)) {
                $count++;
            }
        }

        return $count;
    }
}

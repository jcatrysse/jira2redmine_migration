<?php

declare(strict_types=1);

namespace Jira2Redmine\Workflows;

/**
 * A Redmine workflow proposal from Jira's workflows. Jira: a workflow per project and issue type, its
 * transitions limited by group, permission or "only the assignee". Redmine: per tracker and role, which
 * status may follow which, optionally only for the author or the assignee; one workflow per tracker for
 * every project. Jan's decision 13 (2026-10-14):
 * - trackers used by several Jira workflows get the union, and the report lists them;
 * - a transition for a group goes to the roles that group holds in the project (04's memberships); a group
 *   that is not a member gives a review row with role 0, Jan decides per case;
 * - a permission condition goes only to the roles that hold the permission in the project (the holders of
 *   the project's permission scheme, mapped by 04); without that data, to every role of the project, noted;
 * - "only the assignee" becomes Redmine's assignee flag;
 * - a transition screen: each field on it that Jira requires becomes a Redmine field permission (required,
 *   per tracker, role and target status); a field required in some field configurations only is noted;
 * - the post function "assign to the current user": a redmine_custom_workflows script per tracker and status;
 * - the rest (other validators, post functions, field conditions) is listed as not mappable.
 */
final class WorkflowProposal
{
    /** Role id of a review row for a transition limited to a group that is not a member of the project. */
    public const NO_ROLE = 0;

    /**
     * @param list<array{project: string, workflow: string, issue_type: string, transitions: list<array<string, mixed>>}> $sources
     * @param array<int|string, int> $trackerOf Jira issue type id => Redmine tracker id
     * @param array<int|string, int> $statusOf Jira status id => Redmine status id
     * @param array<string, list<int>> $projectRoles project key => Redmine role ids of its members
     * @param array<string, array<string, list<int>>> $groupRoles project key => lower-case Jira group name => role ids
     * @param array<string, mixed> $context optional data of decision 13, each key absent when not staged:
     *     permission_roles: project key => Jira permission => role ids of its holders;
     *     screen_fields: Jira screen id => Jira field ids; field_rules: Jira field id => the field configurations
     *     that require it, out of how many; field_targets: Jira field id => Redmine field (attribute or custom field id)
     * @return array{rows: array<string, array{tracker: int, role: int, old: int, new: int, assignee_only: bool,
     *     workflows: list<string>, projects: list<string>, transitions: list<string>, notes: list<string>}>,
     *     field_permissions: array<string, array{tracker: int, role: int, status: int, field: string, rule: string,
     *     jira_field: string, workflows: list<string>, projects: list<string>, notes: list<string>}>,
     *     scripts: array<string, array{tracker: int, status: int, old: list<int>, workflows: list<string>, projects: list<string>,
     *     transitions: list<string>}>, problems: list<string>, project_rows: array<string, array{project: string, tracker: int,
     *     role: int, old: int, new: int, assignee_only: bool, workflows: list<string>, transitions: list<string>, notes: list<string>}>}
     *     rows: the union per tracker (the global fallback); project_rows: each project's own Jira workflow, for
     *     redmine_project_workflows (Jan, 2026-10-14), keyed "project|tracker|role|old|new|assignee"
     */
    public static function build(
        array $sources,
        array $trackerOf,
        array $statusOf,
        array $projectRoles,
        array $groupRoles,
        array $context = []
    ): array {
        $rows = [];
        $projectRows = [];
        $fieldPermissions = [];
        $scripts = [];
        $problems = [];
        $trackerWorkflows = [];
        foreach ($sources as $source) {
            $tracker = $trackerOf[$source['issue_type']] ?? null;
            if ($tracker === null) {
                $problems[] = sprintf(
                    '%s: issue type %s has no Redmine tracker; workflow "%s" not proposed',
                    $source['project'],
                    $source['issue_type'],
                    $source['workflow']
                );
                continue;
            }
            $trackerWorkflows[$tracker][$source['workflow']] = true;
            foreach ($source['transitions'] as $transition) {
                $new = $statusOf[(string)$transition['to']] ?? null;
                if ($new === null) {
                    $problems[] = sprintf(
                        'workflow "%s": status %s has no Redmine status; transition "%s" not proposed',
                        $source['workflow'],
                        $transition['to'],
                        $transition['name']
                    );
                    continue;
                }
                $olds = self::oldStatuses($transition, $new, $statusOf, $source['workflow'], $problems);
                if ($olds === []) {
                    continue;
                }
                $project = $source['project'];
                [$roles, $groupNotes] = self::roles($project, $transition, $projectRoles, $groupRoles, $problems, $source['workflow']);
                [$roles, $permissionNotes] = self::permitted($project, $transition, $roles, $context, $problems, $source['workflow']);
                $screen = is_array($transition['screen'] ?? null)
                    ? ['id' => (string)($transition['screen']['id'] ?? ''), 'name' => (string)($transition['screen']['name'] ?? '')]
                    : null;
                $screenNotes = $screen !== null
                    ? self::screenFields($screen, $tracker, $roles, $new, $source, $context, $fieldPermissions, $problems)
                    : [];
                $notes = array_values(array_map('strval', array_merge($transition['not_mapped'], $permissionNotes, $screenNotes)));
                $targets = array_map(static fn (int $role): array => [$role, $notes], $roles);
                if ($groupNotes !== []) {
                    $targets[] = [self::NO_ROLE, array_merge($notes, $groupNotes)];
                }
                $assigneeOnly = (bool)($transition['assignee_only'] ?? false);
                foreach ($olds as $old) {
                    foreach ($targets as [$role, $rowNotes]) {
                        $key = implode('|', [$tracker, $role, $old, $new, $assigneeOnly ? 1 : 0]);
                        $rows[$key] ??= ['tracker' => $tracker, 'role' => $role, 'old' => $old, 'new' => $new,
                            'assignee_only' => $assigneeOnly, 'workflows' => [], 'projects' => [], 'transitions' => [], 'notes' => []];
                        $rows[$key]['workflows'] = self::add($rows[$key]['workflows'], [$source['workflow']]);
                        $rows[$key]['projects'] = self::add($rows[$key]['projects'], [$source['project']]);
                        $rows[$key]['transitions'] = self::add($rows[$key]['transitions'], [(string)$transition['name']]);
                        $rows[$key]['notes'] = self::add($rows[$key]['notes'], $rowNotes);
                        $own = $source['project'] . '|' . $key;
                        $projectRows[$own] ??= ['project' => $source['project'], 'tracker' => $tracker, 'role' => $role, 'old' => $old,
                            'new' => $new, 'assignee_only' => $assigneeOnly, 'workflows' => [], 'transitions' => [], 'notes' => []];
                        $projectRows[$own]['workflows'] = self::add($projectRows[$own]['workflows'], [$source['workflow']]);
                        $projectRows[$own]['transitions'] = self::add($projectRows[$own]['transitions'], [(string)$transition['name']]);
                        $projectRows[$own]['notes'] = self::add($projectRows[$own]['notes'], $rowNotes);
                    }
                }
                if (($transition['assign_to_current_user'] ?? false) === true) {
                    $key = $tracker . '|' . $new;
                    $scripts[$key] ??= ['tracker' => $tracker, 'status' => $new, 'old' => [], 'workflows' => [], 'projects' => [],
                        'transitions' => []];
                    $old = array_values(array_unique(array_merge($scripts[$key]['old'], $olds)));
                    sort($old);
                    $scripts[$key]['old'] = $old;
                    $scripts[$key]['workflows'] = self::add($scripts[$key]['workflows'], [$source['workflow']]);
                    $scripts[$key]['projects'] = self::add($scripts[$key]['projects'], [$source['project']]);
                    $scripts[$key]['transitions'] = self::add($scripts[$key]['transitions'], [(string)$transition['name']]);
                }
            }
        }
        foreach ($trackerWorkflows as $tracker => $workflows) {
            if (count($workflows) > 1) {
                $names = array_keys($workflows);
                sort($names);
                $problems[] = sprintf(
                    'tracker #%d: %d Jira workflows (%s); Redmine has one workflow per tracker, the proposal is their union',
                    $tracker,
                    count($names),
                    implode(', ', $names)
                );
            }
        }
        ksort($rows);
        ksort($projectRows);
        ksort($fieldPermissions);
        ksort($scripts);
        $problems = array_values(array_unique($problems));

        return ['rows' => $rows, 'field_permissions' => $fieldPermissions, 'scripts' => $scripts, 'problems' => $problems,
            'project_rows' => $projectRows];
    }

    /**
     * @param array<string, mixed> $transition
     * @param array<int|string, int> $statusOf
     * @param list<string> $problems
     * @return list<int> Redmine statuses the transition starts from, 0 for the creation of an issue
     */
    private static function oldStatuses(array $transition, int $new, array $statusOf, string $workflow, array &$problems): array
    {
        $olds = [];
        foreach ($transition['from'] as $from) {
            $old = $from === '0' ? 0 : ($statusOf[$from] ?? null);
            if ($old === null) {
                $problems[] = sprintf(
                    'workflow "%s": status %s has no Redmine status; transition "%s" from it not proposed',
                    $workflow,
                    $from,
                    $transition['name']
                );
                continue;
            }
            if ($old !== $new) {
                $olds[] = $old;
            }
        }

        return $olds;
    }

    /**
     * @param array<string, mixed> $transition
     * @param array<string, list<int>> $projectRoles
     * @param array<string, array<string, list<int>>> $groupRoles
     * @param list<string> $problems
     * @return array{0: list<int>, 1: list<string>} the roles, and the review notes of groups that are not members
     */
    private static function roles(
        string $project,
        array $transition,
        array $projectRoles,
        array $groupRoles,
        array &$problems,
        string $workflow
    ): array {
        if ($transition['groups'] === []) {
            return [$projectRoles[$project] ?? [], []];
        }
        $roles = [];
        $notes = [];
        foreach ($transition['groups'] as $group) {
            $held = $groupRoles[$project][mb_strtolower($group, 'UTF-8')] ?? [];
            if ($held === []) {
                $problems[] = sprintf(
                    '%s, workflow "%s": group "%s" is not a member of the project; review row with role 0',
                    $project,
                    $workflow,
                    $group
                );
                $notes[] = sprintf(
                    'Jira limits it to group "%s", which is not a member of %s: set review_role_id and PROPOSED, or IGNORED',
                    $group,
                    $project
                );
            }
            $roles = array_merge($roles, $held);
        }
        $roles = array_values(array_unique($roles));
        sort($roles);

        return [$roles, $notes];
    }

    /**
     * Rule 4: a transition with a permission condition goes only to the roles that hold every such permission.
     *
     * @param array<string, mixed> $transition
     * @param list<int> $roles
     * @param array<string, mixed> $context
     * @param list<string> $problems
     * @return array{0: list<int>, 1: list<string>} the roles, and notes when the holders are unknown
     */
    private static function permitted(
        string $project,
        array $transition,
        array $roles,
        array $context,
        array &$problems,
        string $workflow
    ): array {
        if ($transition['permissions'] === []) {
            return [$roles, []];
        }
        if (!isset($context['permission_roles']) || !is_array($context['permission_roles'])) {
            return [$roles, array_values(array_map(
                static fn (mixed $permission): string => 'Jira permission ' . $permission . ' (given to every role of the project)',
                $transition['permissions']
            ))];
        }
        foreach ($transition['permissions'] as $permission) {
            $holders = $context['permission_roles'][$project][$permission] ?? [];
            $roles = array_values(array_intersect($roles, is_array($holders) ? $holders : []));
            if ($roles === []) {
                $problems[] = sprintf(
                    '%s, workflow "%s": no role of the project holds %s; transition "%s" goes to no role',
                    $project,
                    $workflow,
                    $permission,
                    $transition['name']
                );
                break;
            }
        }

        return [$roles, []];
    }

    /**
     * Rule 3: the fields of the transition screen that Jira requires, as Redmine field permissions.
     *
     * @param array{id: string, name: string} $screen
     * @param list<int> $roles
     * @param array{project: string, workflow: string, issue_type: string, transitions: list<array<string, mixed>>} $source
     * @param array<string, mixed> $context
     * @param array<string, array{tracker: int, role: int, status: int, field: string, rule: string, jira_field: string,
     *     workflows: list<string>, projects: list<string>, notes: list<string>}> $fieldPermissions
     * @param list<string> $problems
     * @return list<string> a note for the transition rows when the screen's required fields are unknown
     */
    private static function screenFields(
        array $screen,
        int $tracker,
        array $roles,
        int $status,
        array $source,
        array $context,
        array &$fieldPermissions,
        array &$problems
    ): array {
        $fields = $context['screen_fields'][$screen['id']] ?? null;
        if (!is_array($fields) || !isset($context['field_rules'])) {
            return [sprintf(
                'transition screen "%s": its required fields are unknown (screens or field configurations not exported by 16)',
                $screen['name']
            )];
        }
        foreach ($fields as $field) {
            $field = (string)$field;
            $rule = $context['field_rules'][$field] ?? null;
            if (!is_array($rule) || !is_array($rule['required_in'] ?? null) || $rule['required_in'] === []) {
                continue;
            }
            $target = $context['field_targets'][$field] ?? null;
            if (!is_string($target) || $target === '') {
                $problems[] = sprintf('screen "%s": field %s is required in Jira and has no Redmine field', $screen['name'], $field);
                continue;
            }
            $notes = count($rule['required_in']) < (int)($rule['configurations'] ?? 0)
                ? [sprintf('required in Jira field configurations %s only', implode(', ', array_map('strval', $rule['required_in'])))]
                : [];
            foreach ($roles as $role) {
                $key = implode('|', [$tracker, $role, $status, $target]);
                $fieldPermissions[$key] ??= ['tracker' => $tracker, 'role' => $role, 'status' => $status, 'field' => $target,
                    'rule' => 'required', 'jira_field' => $field, 'workflows' => [], 'projects' => [], 'notes' => []];
                $fieldPermissions[$key]['workflows'] = self::add($fieldPermissions[$key]['workflows'], [$source['workflow']]);
                $fieldPermissions[$key]['projects'] = self::add($fieldPermissions[$key]['projects'], [$source['project']]);
                $fieldPermissions[$key]['notes'] = self::add($fieldPermissions[$key]['notes'], $notes);
            }
        }

        return [];
    }

    /**
     * @param list<string> $list
     * @param list<string> $more
     * @return list<string>
     */
    private static function add(array $list, array $more): array
    {
        $merged = array_values(array_unique(array_merge($list, $more)));
        sort($merged);

        return $merged;
    }
}

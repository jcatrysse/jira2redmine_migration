<?php

declare(strict_types=1);

namespace Jira2Redmine\Jira;

/**
 * 16 exports the Jira workflows through GET /rest/api/3/workflow/search only. The legacy
 * GET /rest/api/3/workflow is gone from the Jira Cloud spec and answers 401 "scope does not match"
 * to a read-scoped token, which stopped the whole export (B25). Without expand the search returns
 * only id, description and dates, and its id is an object {name, entityId}: the name was read as
 * null and every workflow was stored under "unknown", one row left (B26).
 */
final class WorkflowSearch
{
    public const PATH = '/rest/api/3/workflow/search';

    /** Everything needed to rebuild a workflow in Redmine: transitions with rules, statuses, usage. */
    public const EXPAND = 'transitions,transitions.rules,transitions.properties,statuses,'
        . 'statuses.properties,default,schemes,projects,hasDraftWorkflow';

    /**
     * @param array<mixed> $workflow
     */
    public static function name(array $workflow): ?string
    {
        $id = $workflow['id'] ?? null;
        if (is_array($id) && isset($id['name']) && is_string($id['name']) && $id['name'] !== '') {
            return $id['name'];
        }

        foreach (['name', 'workflowName'] as $key) {
            if (isset($workflow[$key]) && is_string($workflow[$key]) && $workflow[$key] !== '') {
                return $workflow[$key];
            }
        }

        return is_string($id) && $id !== '' ? $id : null;
    }
}

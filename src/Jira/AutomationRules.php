<?php

declare(strict_types=1);

namespace Jira2Redmine\Jira;

/**
 * Jira automation rules are not in the Jira platform REST API: /rest/api/3/automation/rules answers 404
 * to a classic token and 401 "scope does not match" to a read-scoped one. They have their own API and
 * scope. Decided overnight (2026-10-13/14), for Jan to confirm: no export through that API; 16 says so
 * in its log, and the rules are exported by hand from the Jira UI as a checklist kept outside the repo.
 */
final class AutomationRules
{
    public const SKIPPED = 'Automation rules: not exported (not in the Jira platform REST API). '
        . 'Export them by hand from Jira\'s automation settings (Export rules, JSON) and keep the file '
        . 'outside the repository as a checklist; see the runbook.';
}

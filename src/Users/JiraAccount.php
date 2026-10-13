<?php

declare(strict_types=1);

namespace Jira2Redmine\Users;

/**
 * Jira account types (accountType in the user API): "atlassian" is a person, "app" an automation or
 * integration (Jira Automation, Slack, ...), "customer" a service desk customer. App accounts have no
 * e-mail address and need no Redmine account (B3): their issues and comments use the default author.
 */
final class JiraAccount
{
    public static function isApp(?string $accountType): bool
    {
        return $accountType !== null && strtolower(trim($accountType)) === 'app';
    }
}

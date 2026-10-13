<?php

declare(strict_types=1);

namespace Jira2Redmine\Jira;

use RuntimeException;

/**
 * Jira answers 200 with an empty list when it does not recognise the caller (a scoped token on the
 * site URL instead of the gateway, a wrong e-mail): 01 then reported "0 project records" and exited 0
 * (F5). An empty project list is never right for a migration, so it stops the run with the likely causes.
 */
final class ExtractionGuard
{
    public static function requireProjects(int $count): void
    {
        if ($count > 0) {
            return;
        }

        throw new RuntimeException(
            'Jira returned no projects. Check jira.cloud_id (a scoped API token works only through the '
            . 'gateway), jira.username and jira.api_token, and that the account can browse projects.'
        );
    }
}

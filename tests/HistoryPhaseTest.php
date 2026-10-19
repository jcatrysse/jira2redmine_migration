<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Decision 41 (Jan, 2026-10-19): the history step is a phase of 11, after its push, part of the one run (11's default
 * phases), and a rerun skips an issue whose history is in Redmine.
 */
final class HistoryPhaseTest extends TestCase
{
    private function eleven(): string
    {
        return (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');
    }

    public function testHistoryIsADefaultPhaseAfterThePush(): void
    {
        $eleven = $this->eleven();
        $push = strpos($eleven, "    'push' => 'Create Redmine journals");
        $history = strpos($eleven, "    'history' => 'Write the Jira history of status, assignee and priority");
        self::assertIsInt($push);
        self::assertIsInt($history);
        self::assertGreaterThan($push, $history);
        self::assertGreaterThan(
            strpos($eleven, 'runJournalPushPhase($pdo, $config, $confirmPush'),
            strpos($eleven, 'runHistoryPhase($pdo, $config, $useExtendedApi);')
        );
    }

    public function testThePhaseBuildsEachIssueThroughTheChainAndChecksItsStatus(): void
    {
        $eleven = $this->eleven();
        self::assertStringContainsString('$history = HistoryChain::build($entries, $lookups);', $eleven);
        self::assertStringContainsString("\$check = \$history->statusCheck(\$issue['status_id'], \$issue['jira_status_id']);", $eleven);
        self::assertStringContainsString("'request' => HistoryRequest::body(\$history, \$file),", $eleven);
        // Jira data goes to the attachment directory, never into the repository
        self::assertStringContainsString("\$tmp = \$config['paths']['tmp'] ?? null;", $eleven);
    }

    public function testElevensRulesMapTheValuesAndTheAuthors(): void
    {
        $eleven = $this->eleven();
        self::assertStringContainsString("\$mapped = \"migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')\";", $eleven);
        self::assertStringContainsString('FROM migration_mapping_statuses WHERE $mapped AND redmine_status_id IS NOT NULL', $eleven);
        self::assertStringContainsString('FROM migration_mapping_priorities WHERE $mapped AND redmine_priority_id IS NOT NULL', $eleven);
        self::assertStringContainsString(
            'return new HistoryLookups($statuses, $priorities, $users, $defaultAuthorId ?? fetchApiUserId($redmineClient));',
            $eleven
        );
        self::assertStringContainsString('buildHistoryLookups($pdo, $redmineClient, extractDefaultJournalAuthorId($config));', $eleven);
    }

    public function testARerunSkipsAnIssueWhoseHistoryIsInRedmine(): void
    {
        self::assertStringContainsString(
            'AND (j.history_journal_id IS NOT NULL OR j.history_attachment_id IS NOT NULL)',
            $this->eleven()
        );
        $schema = (string)file_get_contents(__DIR__ . '/../database/schema.sql');
        self::assertStringContainsString('`history_journal_id` INT NULL', $schema);
        self::assertStringContainsString('`history_attachment_id` INT NULL', $schema);
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Decision 41 (Jan, 2026-10-19): the history is a phase of 11 after its push, part of the one run (11's default
 * phases). With the push it goes in between the comments, per issue in Jira's time order (option C: Redmine's "Last
 * updated by" is the journal with the highest id), and a rerun sends only what is missing.
 */
final class HistoryPhaseTest extends TestCase
{
    private function eleven(): string
    {
        return (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');
    }

    public function testHistoryIsADefaultPhaseAfterThePushAndGoesWithIt(): void
    {
        $eleven = $this->eleven();
        $push = strpos($eleven, "    'push' => 'Create Redmine journals");
        $history = strpos($eleven, "    'history' => 'Write the Jira history of status, assignee and priority");
        self::assertIsInt($push);
        self::assertIsInt($history);
        self::assertGreaterThan($push, $history);
        self::assertStringContainsString(
            'runJournalPushPhase($pdo, $config, $confirmPush, $isDryRun, $useExtendedApi, $extendedApiPrefix, $withJournals, '
            . '$withHistory);',
            $eleven
        );
        self::assertStringContainsString("\$history = prepareHistory(\$pdo, DefaultAuthor::required(\$config, 'journals'));", $eleven);
    }

    public function testEachIssueIsBuiltThroughTheChainAndItsStatusChecked(): void
    {
        $eleven = $this->eleven();
        self::assertStringContainsString('$history = HistoryChain::build($entries, $lookups);', $eleven);
        self::assertStringContainsString("\$check = \$history->statusCheck(\$issue['status_id'], \$issue['jira_status_id']);", $eleven);
        // the dry run's requests go to the attachment directory, never into the repository
        self::assertStringContainsString("\$tmp = \$config['paths']['tmp'] ?? null;", $eleven);
    }

    public function testElevensRulesMapTheValuesAndTheAuthors(): void
    {
        $eleven = $this->eleven();
        self::assertStringContainsString("\$mapped = \"migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')\";", $eleven);
        self::assertStringContainsString('FROM migration_mapping_statuses WHERE $mapped AND redmine_status_id IS NOT NULL', $eleven);
        self::assertStringContainsString('FROM migration_mapping_priorities WHERE $mapped AND redmine_priority_id IS NOT NULL', $eleven);
        // decision 42: authors without an account get the configured default author, no longer the API user
        self::assertStringContainsString('return new HistoryLookups($statuses, $priorities, $users, $defaultAuthorId);', $eleven);
    }

    public function testWhatIsPushedIsKeptPerJiraEntryAndARerunSendsOnlyWhatIsMissing(): void
    {
        $eleven = $this->eleven();
        self::assertStringContainsString(
            "static fn (array \$journal): bool => !isset(\$done['journals'][\$journal['history_id']])",
            $eleven
        );
        self::assertStringContainsString("\$needsFile = \$history->needsFile() && !isset(\$done['files'][\$jiraIssueId]);", $eleven);
        self::assertStringContainsString(
            "storeHistoryResult(\$pdo, (string)\$event['journal']['history_id'], \$jiraIssueId, 'history_journal_id', "
            . "\$answer['journal_ids'][\$index]);",
            sourceWithoutLayout($eleven)
        );
        self::assertStringContainsString(
            "storeHistoryResult(\$pdo, \$stamp['history_id'], \$jiraIssueId, 'history_attachment_id', \$answer['attachment_id']);",
            sourceWithoutLayout($eleven)
        );
        $schema = (string)file_get_contents(__DIR__ . '/../database/schema.sql');
        self::assertStringContainsString('`history_journal_id` INT NULL', $schema);
        self::assertStringContainsString('`history_attachment_id` INT NULL', $schema);
    }

    public function testAMissingEndpointStopsTheHistoryOnceAndLeavesTheCommentsAlone(): void
    {
        $eleven = $this->eleven();
        self::assertStringContainsString(
            "if (\$exception->getResponse()->getStatusCode() === 404) {\n            \$history['disabled'] = true;",
            $eleven
        );
        self::assertStringContainsString("if (\$history['disabled']) {\n        return;\n    }", $eleven);
    }
}

<?php

declare(strict_types=1);

use Jira2Redmine\Redmine\ExtendedApi;
use PHPUnit\Framework\TestCase;

/**
 * Jan, decision 37: the writes after an issue exists leave no trace. Through the Extended API they send skip_journal
 * (redmine_extended_api 8e7096c or later) next to notify=false: 10's second pass, 12's relations, 13's tags and the
 * relink (TextUpdate, tested in IssueTagsTest and RelinkTest). 11's comments carry notes and go without it: the
 * plugin refuses an issue update with notes and the flag.
 */
final class SkipJournalTest extends TestCase
{
    public function testTheQueryCarriesTheFlagNextToNotify(): void
    {
        self::assertSame(['notify' => 'false', 'send_notification' => 0, 'skip_journal' => 1], ExtendedApi::NO_TRACE);
        self::assertSame(ExtendedApi::NO_TRACE, \Jira2Redmine\Redmine\TextUpdate::SILENT);
    }

    public function testTheSecondPassOf10SendsIt(): void
    {
        $ten = (string)file_get_contents(__DIR__ . '/../10_migrate_issues.php');
        // the final update (description, parent: "Subtask added" on the parent) and the open-parent retry
        self::assertSame(2, substr_count($ten, "\$options['query'] = ExtendedApi::NO_TRACE;"));
        self::assertStringNotContainsString("['notify' => 'false', 'send_notification' => 0]", $ten);
        // the create sets no parent, so "Subtask added" comes only from the final update; it keeps notify=false only
        $start = strpos($ten, "'project_id' => \$projectId,");
        self::assertIsInt($start);
        $end = strpos($ten, "'tag_list' => issueCreationTags(\$candidate),", $start);
        self::assertIsInt($end);
        self::assertStringNotContainsString('parent', substr($ten, $start, $end - $start));
        self::assertStringContainsString("'query' => ['notify' => 'false'],", $ten);
    }

    public function testTheRelationsOf12SendIt(): void
    {
        $twelve = (string)file_get_contents(__DIR__ . '/../12_migrate_issue_relations.php');
        $flag = "        \$payload['notify'] = false;\n"
            . "        // the Extended API stores no journal on either issue for it (decision 37)\n"
            . "        \$payload['skip_journal'] = true;\n";
        self::assertStringContainsString($flag, $twelve);
        self::assertStringContainsString('buildRelationPayload($toId, $relationType, $useExtendedApi)', $twelve);
        // 12 creates relations only; it deletes none
        self::assertStringNotContainsString("'DELETE'", $twelve);
        self::assertStringNotContainsString('->delete(', $twelve);
    }

    public function testTheCommentsOf11GoWithoutIt(): void
    {
        $eleven = (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');
        self::assertStringNotContainsString('NO_TRACE', $eleven);
        self::assertStringNotContainsString('skip_journal', $eleven);
    }
}

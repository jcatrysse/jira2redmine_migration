<?php

declare(strict_types=1);

use Jira2Redmine\Text\Relink;
use PHPUnit\Framework\TestCase;

final class RelinkTest extends TestCase
{
    private const PUSHED = "See [the plan](https://site.atlassian.net/browse/ABC-12).\nThanks";

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../lib/description_conversion.php';
    }

    public function testALinkThatResolvesNowIsRewritten(): void
    {
        $rewrite = static fn (string $text): string => replaceIssueLinksInText($text, ['ABC-12' => 812]);
        // Redmine gives the text back with \r\n
        $decision = Relink::decide(str_replace("\n", "\r\n", self::PUSHED), Relink::hash(self::PUSHED), $rewrite);

        self::assertSame(['action' => 'relink', 'text' => "See the plan (#812).\nThanks"], $decision);
    }

    public function testATextEditedInRedmineIsLeftAlone(): void
    {
        $rewrite = static fn (string $text): string => replaceIssueLinksInText($text, ['ABC-12' => 812]);

        $pushed = Relink::hash(self::PUSHED);
        self::assertSame('edited', Relink::decide(self::PUSHED . ' (updated)', $pushed, $rewrite)['action']);
        self::assertSame('unknown', Relink::decide(self::PUSHED, null, $rewrite)['action']);
        self::assertSame('nothing', Relink::decide(self::PUSHED, $pushed, static fn (string $t): string => $t)['action']);
    }

    public function testTheCheckCountsEveryTextThatStillLinksAMigratedIssue(): void
    {
        // Jan, B52: after the relink the count of Jira links to migrated issues must be 0; an edited text the relink
        // left alone still counts, a text whose link points outside the migration does not
        $rewrite = static fn (string $text): string => replaceIssueLinksInText($text, ['ABC-12' => 812]);

        self::assertTrue(Relink::linkLeft(str_replace("\n", "\r\n", self::PUSHED), $rewrite));
        self::assertTrue(Relink::linkLeft(self::PUSHED . ' (updated)', $rewrite));
        self::assertFalse(Relink::linkLeft("See the plan (#812).\nThanks", $rewrite));
        self::assertFalse(Relink::linkLeft('See [elsewhere](https://site.atlassian.net/browse/XYZ-1).', $rewrite));
        self::assertFalse(Relink::linkLeft(null, $rewrite));
    }

    public function testThePushesStoreWhatTheySent(): void
    {
        $ten = (string)file_get_contents(__DIR__ . '/../10_migrate_issues.php');
        $eleven = (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');

        self::assertGreaterThanOrEqual(4, substr_count($ten, 'recordPushedDescription($pdo, '), '10 does not store what it pushed');
        $call = 'markJournalPushSuccess($pdo, $mappingId, $journalId, $note)';
        self::assertGreaterThanOrEqual(1, substr_count($eleven, $call), '11 does not store what it pushed');
    }
}

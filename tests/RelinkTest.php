<?php

declare(strict_types=1);

use Jira2Redmine\Redmine\TextUpdate;
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

    public function testATextWithoutAJiraLinkIsNotTouched(): void
    {
        // B76: the relink rewrote with replaceIssueLinksInText, whose spacing cleanup made "#203," into "#203 ,"; 10's
        // own rewrite of a description does not do that, so a text 10 had already relinked was reported as one to
        // relink, and the check counted it as still holding a Jira link (SIS-112 in a single run of GEOS, SIS, OAC)
        $text = "Linked to #203, if a heading is off.\nSee also #204.";
        self::assertNotSame($text, replaceIssueLinksInText($text, ['ABC-12' => 812]));

        $tool = (string)file_get_contents(__DIR__ . '/../tools/relink_issue_links.php');
        self::assertStringContainsString('$rewrite = static fn (string $text): string => replaceIssueLinksOnly($text, $issueMap);', $tool);
        $rewrite = static fn (string $text): string => replaceIssueLinksOnly($text, ['ABC-12' => 812]);
        self::assertSame(['action' => 'nothing', 'text' => $text], Relink::decide($text, Relink::hash($text), $rewrite));
        self::assertFalse(Relink::linkLeft($text, $rewrite));
        // a real link is still rewritten, the rest of the text as it is
        $pushed = "Linked to #203, see [the plan](https://site.atlassian.net/browse/ABC-12).";
        self::assertSame(
            ['action' => 'relink', 'text' => 'Linked to #203, see the plan (#812).'],
            Relink::decide($pushed, Relink::hash($pushed), $rewrite)
        );
    }

    public function testTheRewritesGoThroughTheExtendedApiWithoutMail(): void
    {
        // Jan (2026-10-15): the relink's description and comment updates leave no mail and no webhook
        $history = new \ArrayObject();
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(204), new \GuzzleHttp\Psr7\Response(204),
            new \GuzzleHttp\Psr7\Response(204), new \GuzzleHttp\Psr7\Response(204),
            new \GuzzleHttp\Psr7\Response(204), new \GuzzleHttp\Psr7\Response(204),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        self::assertInstanceOf(\ArrayObject::class, $history);
        $client = new \GuzzleHttp\Client(['base_uri' => 'http://redmine.test/sub/', 'handler' => $stack]);

        TextUpdate::issue($client, 7, ['description' => 'x (#8)'], 'extended_api');
        TextUpdate::journalNotes($client, 9, 'y (#8)', 'extended_api');
        TextUpdate::issue($client, 7, ['description' => 'x (#8)'], null);
        TextUpdate::journalNotes($client, 9, 'y (#8)', null);
        // the issue keeps its updated time through the Extended API (Jan, 2026-10-15); the plain route cannot
        TextUpdate::issue($client, 7, ['description' => 'x (#8)'], 'extended_api', true, '2024-03-04T05:06:07Z');
        TextUpdate::issue($client, 7, ['description' => 'x (#8)'], null, true, '2024-03-04T05:06:07Z');

        $calls = [];
        foreach ($history as $entry) {
            $request = is_array($entry) ? $entry['request'] : null;
            self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
            $calls[] = $request->getMethod() . ' ' . $request->getUri()->getPath() . '?' . $request->getUri()->getQuery()
                . ' ' . $request->getBody();
        }
        self::assertSame([
            'PUT /sub/extended_api/issues/7.json?notify=false&send_notification=0&skip_journal=1 {"issue":{"description":"x (#8)"}}',
            'PUT /sub/extended_api/journals/9.json?notify=false&send_notification=0&skip_journal=1 {"journal":{"notes":"y (#8)"}}',
            // without the Extended API the plain routes, as before
            'PUT /issues/7.json? {"issue":{"description":"x (#8)"}}',
            'PUT /journals/9.json? {"journal":{"notes":"y (#8)"}}',
            'PUT /sub/extended_api/issues/7.json?notify=false&send_notification=0&skip_journal=1 '
                . '{"issue":{"description":"x (#8)","updated_on":"2024-03-04T05:06:07Z"}}',
            'PUT /issues/7.json? {"issue":{"description":"x (#8)"}}',
        ], $calls);

        $tool = (string)file_get_contents(__DIR__ . '/../tools/relink_issue_links.php');
        self::assertStringNotContainsString('$client->put(', $tool);
        $description = "TextUpdate::issue(\$client, (int)\$row['redmine_issue_id'], ['description' => \$decision['text']], "
            . "\$extendedApiPrefix, true, \$issue['updated_on'] ?? null)";
        self::assertStringContainsString($description, $tool);
        $notes = "TextUpdate::journalNotes(\$client, (int)\$row['redmine_journal_id'], \$decision['text'], \$extendedApiPrefix)";
        self::assertStringContainsString($notes, $tool);
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

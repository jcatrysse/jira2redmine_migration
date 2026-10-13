<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B44: the spacing clean-up joined digits after a reference, so a mention followed by a number pointed
 * to another user ("user#332 4 lines" became "user#3324 lines", 8 journals of the SURV run), and
 * "#12 3 times" would become "#123"; "user 5" in plain text became a mention of user 5.
 * Inputs are anonymised reproductions.
 */
final class ReferenceSpacingTest extends TestCase
{
    public function testAMentionFollowedByANumberKeepsItsId(): void
    {
        $html = '<p>With <a href="https://example.atlassian.net/secure/ViewProfile.jspa?accountId=712020:aaaa-bbbb"'
            . ' class="user-hover" data-account-id="712020:aaaa-bbbb">Survey Boat 22</a> 4 lines should be sufficient.</p>';
        $lookup = ['712020:aaaa-bbbb' => ['redmine_user_id' => 332, 'redmine_login' => 'boat22@example.com']];

        $out = replaceJiraUserLinksWithRedmineIds((string)convertJiraHtmlToMarkdown($html, []), $lookup);

        self::assertStringNotContainsString('3324', $out);
        self::assertMatchesRegularExpression('/(@boat22@example\.com|user#332) 4 lines/', $out);
    }

    public function testIssueReferencesAndNumbersStaySeparate(): void
    {
        self::assertSame('see #12 3 times', normalizeRedmineReferenceSpacing('see #12 3 times'));
        self::assertSame('user#7 2 lines', normalizeRedmineReferenceSpacing('user#7 2 lines'));
    }

    public function testPlainUserAndANumberIsNotAMention(): void
    {
        self::assertSame('the user 5 minutes later', normalizeRedmineReferenceSpacing('the user 5 minutes later'));
        self::assertSame('the user 5 minutes later', transform_text_in_transform_phase('the user 5 minutes later', []));
    }

    public function testEncodedUserReferencesAreStillRepaired(): void
    {
        self::assertSame('ask user#12 now', normalizeRedmineReferenceSpacing('ask user # 12 now'));
        self::assertSame('ask user#12 now', normalizeRedmineReferenceSpacing('ask user%20%2312 now'));
    }
}

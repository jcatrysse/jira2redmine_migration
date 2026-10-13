<?php

declare(strict_types=1);

use Jira2Redmine\Redmine\IssueFinalUpdate;
use PHPUnit\Framework\TestCase;

final class IssueFinalUpdateTest extends TestCase
{
    public function testNothingToSendWhenDescriptionAndParentAreAlreadyThere(): void
    {
        $current = ['description' => "Line one\r\nLine two", 'parent' => ['id' => 12]];
        self::assertSame([], IssueFinalUpdate::changes("Line one\nLine two", 12, $current));
        self::assertSame([], IssueFinalUpdate::changes(null, null, $current));
    }

    public function testSendsTheRewrittenDescriptionOnly(): void
    {
        $current = ['description' => 'See [ABC-1](https://x.atlassian.net/browse/ABC-1)'];
        self::assertSame(['description' => 'See #41'], IssueFinalUpdate::changes('See #41', null, $current));
    }

    public function testSendsAMissingOrDifferentParent(): void
    {
        self::assertSame(['parent_issue_id' => 7], IssueFinalUpdate::changes('x', 7, ['description' => 'x']));
        self::assertSame(['parent_issue_id' => 7], IssueFinalUpdate::changes('x', 7, ['description' => 'x', 'parent' => ['id' => 3]]));
    }
}

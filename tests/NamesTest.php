<?php

declare(strict_types=1);

use Jira2Redmine\Redmine\Names;
use PHPUnit\Framework\TestCase;

final class NamesTest extends TestCase
{
    public function testTrackerNamesFitRedminesLimit(): void
    {
        self::assertSame('Task', Names::tracker(' Task '));
        $long = 'Automated Feedback from Issue tickets'; // 37 characters, as in B1
        self::assertSame('Automated Feedback from Issue', Names::tracker($long));
        self::assertLessThanOrEqual(30, mb_strlen(Names::tracker($long)));
        self::assertSame(30, mb_strlen(Names::tracker(str_repeat('é', 40))));
    }
}

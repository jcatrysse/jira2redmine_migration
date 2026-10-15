<?php

declare(strict_types=1);

use Jira2Redmine\Issues\IssueLabels;
use PHPUnit\Framework\TestCase;

final class IssueLabelsTest extends TestCase
{
    public function testLabelsBecomeTagsTrimmedOnceWithoutEmptyOnes(): void
    {
        self::assertSame(['MBES', 'EM712'], IssueLabels::tags([' MBES', 'EM712', '', 'MBES ', '  ']));
        self::assertSame([], IssueLabels::tags(null));
        self::assertSame([], IssueLabels::tags('MBES'));
    }
}

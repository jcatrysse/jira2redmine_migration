<?php

declare(strict_types=1);

use Jira2Redmine\Cli\Fallbacks;
use PHPUnit\Framework\TestCase;

/**
 * Decision 43: a PCRE error keeps the text as it was, an unusable value skips the field, each with a counted "[warn]"
 * line that names where.
 */
final class FallbacksTest extends TestCase
{
    public function testAResultPassesThroughWithoutAWarning(): void
    {
        $count = Fallbacks::count();
        ob_start();
        $text = Fallbacks::keepText('new', 'old', 'GEOS-1, description');
        self::assertSame('', ob_get_clean());
        self::assertSame('new', $text);
        self::assertSame($count, Fallbacks::count());
    }

    public function testAPcreErrorKeepsTheTextAndSaysWhere(): void
    {
        $count = Fallbacks::count();
        ob_start();
        $text = Fallbacks::keepText(@preg_replace('/\s+/u', ' ', "a\xff b"), "a\xff b", 'GEOS-1, description');
        self::assertSame(
            "  [warn] GEOS-1, description: PCRE failed (Malformed UTF-8 characters, possibly incorrectly encoded), the"
                . " text is kept as it was (decision 43).\n",
            ob_get_clean()
        );
        self::assertSame("a\xff b", $text);
        self::assertSame($count + 1, Fallbacks::count());
    }

    public function testASkippedFieldIsCountedInTheSummary(): void
    {
        $count = Fallbacks::count();
        ob_start();
        Fallbacks::skipField('Jira field customfield_1, value dependencies', 'the parent enumerations cannot be decoded');
        self::assertSame(
            "  [warn] Jira field customfield_1, value dependencies: the parent enumerations cannot be decoded, the field"
                . " is skipped (decision 43).\n",
            ob_get_clean()
        );
        self::assertSame($count + 1, Fallbacks::count());
        self::assertSame(sprintf('Fallbacks (decision 43, each a [warn] line above): %d.', $count + 1), Fallbacks::summary());
    }
}

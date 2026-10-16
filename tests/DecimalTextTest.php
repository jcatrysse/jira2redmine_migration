<?php

declare(strict_types=1);

use Jira2Redmine\CustomFields\DecimalText;
use PHPUnit\Framework\TestCase;

/**
 * B79: 10 wrote a float field's value with rtrim(rtrim($text, '0'), '.'), so a whole number lost its zeros.
 */
final class DecimalTextTest extends TestCase
{
    public function testWholeNumbersKeepTheirZeros(): void
    {
        self::assertSame('0', DecimalText::of(0.0));
        self::assertSame('0', DecimalText::of(-0.0));
        self::assertSame('10', DecimalText::of(10.0));
        self::assertSame('100', DecimalText::of(100.0));
        self::assertSame('-20', DecimalText::of(-20.0));
    }

    public function testFractionsHaveNoTrailingZeros(): void
    {
        self::assertSame('1.5', DecimalText::of(1.50));
        self::assertSame('2.25', DecimalText::of(2.25));
        self::assertSame('0.1', DecimalText::of(0.1));
    }

    public function testThe10UsesIt(): void
    {
        $ten = (string)file_get_contents(__DIR__ . '/../10_migrate_issues.php');
        self::assertStringNotContainsString("rtrim(rtrim((string)\$floatValue, '0'), '.')", $ten);
        self::assertStringContainsString('return $floatValue === null ? null : DecimalText::of($floatValue);', $ten);
    }
}

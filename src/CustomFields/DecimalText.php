<?php

declare(strict_types=1);

namespace Jira2Redmine\CustomFields;

/**
 * A Jira number as the text of a Redmine float field. PHP writes a float without trailing zeros already ("10", "1.5");
 * 10 also stripped zeros from that text, so "10" became "1" and "0" became empty (19 of the 126 revision numbers of
 * DOC, B79).
 */
final class DecimalText
{
    public static function of(float $value): string
    {
        // -0.0 is written "-0"
        return $value == 0.0 ? '0' : (string)$value;
    }
}

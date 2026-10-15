<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Decision 26: the remaining conversion losses of the bench, each class with an anonymised reproduction.
 */
final class ConversionLossesTest extends TestCase
{
    public function testEmphasisSplitBySuperscriptStaysOneRun(): void
    {
        // GEOS-53: "16<sup>th</sup>" inside italics came out as "*16**th**", Redmine showed the asterisks
        $html = '<p><em>Figure 1: nav (a), 15</em><sup><em>th</em></sup> <em>yellow, good match</em></p>'
            . '<p><em>Figure 2: Trimble; 16</em><sup><em>th</em></sup><em>. Good matching</em></p>';

        self::assertSame(
            "*Figure 1: nav (a), 15th* *yellow, good match*\n\n*Figure 2: Trimble; 16th. Good matching*",
            convertJiraHtmlToMarkdown($html, [])
        );
    }

    public function testNestedAndSplitEmphasisKeepsItsDelimitersApart(): void
    {
        // GEOS-103: Jira's "<em><b>x</em></b>" and "(</em><b><em>-70</em></b><em>)" became "(****-70****)"; bold
        // between "(" and ")" inside italics would close the italics in CommonMark: the italics stay, that bold goes
        $html = '<p><em>Line: L1</em>#03 range (<em><b>_185.7,197.0</em></b><em>) does not fit within (</em>'
            . '<b><em>-70.0,10.0</em></b><em>).</em></p><p><b>bold </b>next and <b>From:</b> x</p>';

        self::assertSame(
            "*Line: L1*\\#03 range (***\\_185.7,197.0**) does not fit within (-70.0,10.0).*\n\n**bold** next and **From:** x",
            convertJiraHtmlToMarkdown($html, [])
        );
    }
}

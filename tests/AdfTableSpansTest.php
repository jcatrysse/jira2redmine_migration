<?php

declare(strict_types=1);

use Karvaka\AdfToGfm\Converter as AdfConverter;
use PHPUnit\Framework\TestCase;

/**
 * A Jira table with merged cells (rowspan, colspan) came out of the ADF path as a header of 2 cells over a
 * delimiter of 6: no table in Redmine, the rows shown as text with "|---" (GEOS-10, anonymised).
 */
final class AdfTableSpansTest extends TestCase
{
    /**
     * @param list<list<array{0: string, 1?: int, 2?: int, 3?: bool}>> $rows text, colspan, rowspan, header
     */
    private static function table(array $rows): string
    {
        $cell = static fn (array $c): array => [
            'type' => ($c[3] ?? false) ? 'tableHeader' : 'tableCell',
            'attrs' => array_filter(['colspan' => $c[1] ?? null, 'rowspan' => $c[2] ?? null]),
            'content' => [['type' => 'paragraph', 'content' => $c[0] === '' ? [] : [['type' => 'text', 'text' => $c[0]]]]],
        ];
        $doc = ['type' => 'doc', 'version' => 1, 'content' => [[
            'type' => 'table',
            'content' => array_map(static fn (array $r): array => ['type' => 'tableRow', 'content' => array_map($cell, $r)], $rows),
        ]]];

        return convertDescriptionToMarkdown((string)json_encode($doc), new AdfConverter());
    }

    public function testMergedCellsKeepEveryRowAsWideAsTheTable(): void
    {
        $markdown = self::table([
            [['Sensor', 1, 2, true], ['Sizes', 3, 1, true]],
            [['Input'], ['Interim'], ['Final']],
            [['SSS'], ['N'], ['Y'], ['Y']],
        ]);

        self::assertSame(
            "| Sensor | Sizes |  |  |\n| --- | --- | --- | --- |\n|  | Input | Interim | Final |\n| SSS | N | Y | Y |",
            $markdown
        );
    }

    public function testATableWithoutSpansIsUnchanged(): void
    {
        self::assertSame(
            "| A | B |\n| --- | --- |\n| 1 | 2 |",
            self::table([[['A', 1, 1, true], ['B', 1, 1, true]], [['1'], ['2']]])
        );
    }

    public function testARowspanInTheBodyLeavesAnEmptyCellBelow(): void
    {
        self::assertSame(
            "| A | B |\n| --- | --- |\n| x | 1 |\n|  | 2 |",
            self::table([[['A', 1, 1, true], ['B', 1, 1, true]], [['x', 1, 2], ['1']], [['2']]])
        );
    }
}

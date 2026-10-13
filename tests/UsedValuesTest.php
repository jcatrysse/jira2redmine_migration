<?php

declare(strict_types=1);

use Jira2Redmine\CustomFields\UsedValues;
use PHPUnit\Framework\TestCase;

final class UsedValuesTest extends TestCase
{
    private const OFFERED = ['mode' => 'flat', 'values' => [
        ['id' => '10576', 'value' => 'Survey Department'],
        ['id' => '10692', 'value' => 'GeoSciences'],
    ]];

    public function testAValueNoLongerOfferedIsMissing(): void
    {
        $used = ['mode' => 'flat', 'values' => [
            ['id' => '10692', 'value' => 'GeoSciences'],
            ['id' => '10748', 'value' => 'Survey'],
        ]];

        self::assertSame(['Survey'], UsedValues::missing(self::OFFERED, $used));
    }

    public function testCaseAndKnownValuesAreNotMissing(): void
    {
        $used = ['mode' => 'flat', 'values' => [['id' => null, 'value' => 'survey department ']]];

        self::assertSame([], UsedValues::missing(self::OFFERED, $used));
    }

    public function testOnlyFlatListsAreCompared(): void
    {
        $cascading = ['mode' => 'cascading', 'parents' => [['id' => '1', 'value' => 'A']]];

        self::assertSame([], UsedValues::missing($cascading, ['mode' => 'flat', 'values' => [['value' => 'X']]]));
    }

    public function testTheFieldBackfillAddsThem(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php');

        self::assertTrue(str_contains($script, 'UsedValues::missing('), '08 keeps only the values Jira offers today');
    }
}

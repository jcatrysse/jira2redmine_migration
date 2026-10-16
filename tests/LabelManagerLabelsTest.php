<?php

declare(strict_types=1);

use Jira2Redmine\CustomFields\LabelManagerLabels;
use PHPUnit\Framework\TestCase;

/**
 * B77: the label app ("label-manager-for-jira") stores some selections of several labels as one comma-joined entry.
 * 08 made it one value of the Redmine field (or none above 60 characters) and 10 held the issue back.
 */
final class LabelManagerLabelsTest extends TestCase
{
    public function testAJoinedEntryIsSeveralLabels(): void
    {
        self::assertSame(['Vessel_IV', 'Vessel_VII'], LabelManagerLabels::split('Vessel_IV, Vessel_VII'));
        self::assertSame(['Vessel_IV'], LabelManagerLabels::split(' Vessel_IV '));
        self::assertSame([], LabelManagerLabels::split(' , '));
        self::assertSame([], LabelManagerLabels::split(null));
        self::assertSame([], LabelManagerLabels::split(['nested']));
        self::assertSame(
            ['Vessel_VII', 'Vessel_IV', 'Vessel_V', 'Vessel_III'],
            LabelManagerLabels::all(['Vessel_VII, Vessel_IV, Vessel_V', '', 'Vessel_III'])
        );
    }

    public function testThe08And10UseItForEveryLabelList(): void
    {
        $eight = (string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php');
        self::assertStringContainsString('foreach (LabelManagerLabels::split($entry) as $normalized) {', $eight);
        self::assertStringContainsString("foreach (LabelManagerLabels::all(\$item['labels']) as \$labelText) {", $eight);
        self::assertStringContainsString("foreach (LabelManagerLabels::all(\$decoded['labels']) as \$labelText) {", $eight);
        self::assertStringNotContainsString("foreach (\$decoded['labels'] as \$label) {", $eight);
        self::assertStringNotContainsString("foreach (\$item['labels'] as \$label) {", $eight);

        $ten = (string)file_get_contents(__DIR__ . '/../10_migrate_issues.php');
        self::assertStringContainsString('foreach (LabelManagerLabels::split($entry) as $label) {', $ten);
        self::assertStringContainsString("LabelManagerLabels::all(\$value['labels']),", $ten);
    }
}

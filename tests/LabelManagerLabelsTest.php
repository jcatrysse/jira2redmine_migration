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

    public function testAnIssueCountsForALabelAppFieldOnlyWithALabel(): void
    {
        // B91: Jira gives every issue in a project with the field an object, also without a label
        self::assertTrue(LabelManagerLabels::isApp('ari:cloud:ecosystem::extension/639553fa/37f393d5/static/label-manager-for-jira'));
        self::assertFalse(LabelManagerLabels::isApp('com.atlassian.jira.plugin.system.customfieldtypes:labels'));
        self::assertFalse(LabelManagerLabels::isApp(null));

        self::assertTrue(LabelManagerLabels::hasLabels(['labels' => ['FOCUS_III'], 'colors' => ['light-grey']]));
        self::assertFalse(LabelManagerLabels::hasLabels(['labels' => [], 'colors' => [], 'labelSource' => 'global']));
        self::assertFalse(LabelManagerLabels::hasLabels(['labels' => [' , '], 'colors' => ['']]));
        self::assertFalse(LabelManagerLabels::hasLabels(null));
        self::assertFalse(LabelManagerLabels::hasLabels('FOCUS_III'));

        $eight = (string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php');
        self::assertStringContainsString('LabelManagerLabels::isApp(', $eight);
        self::assertStringContainsString('LabelManagerLabels::hasLabels(json_decode($value, true))', $eight);
    }
}

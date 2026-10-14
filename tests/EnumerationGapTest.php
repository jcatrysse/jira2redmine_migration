<?php

declare(strict_types=1);

use Jira2Redmine\CustomFields\EnumerationGap;
use PHPUnit\Framework\TestCase;

final class EnumerationGapTest extends TestCase
{
    public function testAValueOfALaterBatchIsMissingFromTheField(): void
    {
        $field = [['id' => 255, 'name' => 'Field Department', 'active' => true], ['id' => 256, 'name' => 'Science', 'active' => false]];

        self::assertSame(['Field'], EnumerationGap::missing(['science ', 'Field', 'Field Department', 'field', ''], $field));
        self::assertSame([], EnumerationGap::missing(['Science'], $field));
        self::assertSame(['A'], EnumerationGap::missing(['A'], []));
    }

    public function testThePushAddsThemToExistingFields(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php');

        $call = 'addValuesToExistingEnumerationFields($pdo, $redmineClient, $extendedApiPrefix, $confirmPush && !$isDryRun);';
        self::assertTrue(
            str_contains($script, $call),
            '08 updates an existing field\'s projects and trackers but not its values'
        );
        self::assertTrue(str_contains($script, "JSON_SET(raw_payload, '\$.custom_field.enumerations'"), '10 would read a stale snapshot');
        // the staging database is MariaDB at GEOxyz: it has no CAST(... AS JSON), the snapshot update failed (B47 proof run)
        self::assertFalse(str_contains($script, 'AS JSON)'), '08 casts to JSON, which MariaDB refuses');
    }
}

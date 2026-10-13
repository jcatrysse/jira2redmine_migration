<?php

declare(strict_types=1);

use Jira2Redmine\CustomFields\MappingKeys;
use PHPUnit\Framework\TestCase;

final class MappingKeysTest extends TestCase
{
    public function testACascadingParentRowLivesAsLongAsItsField(): void
    {
        $mappings = [
            35 => 'customfield_10037',
            111 => 'cascading_parent:customfield_10037',
            90 => 'customfield_99999',
            112 => 'cascading_parent:customfield_99999',
        ];

        self::assertSame([90, 112], MappingKeys::orphans($mappings, ['customfield_10037' => true]));
    }

    public function testSourceFieldId(): void
    {
        self::assertSame('customfield_10037', MappingKeys::sourceFieldId('cascading_parent:customfield_10037'));
        self::assertSame('customfield_10037', MappingKeys::sourceFieldId('customfield_10037'));
    }

    public function testThePurgeUsesIt(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php');

        self::assertTrue(str_contains($script, 'MappingKeys::orphans('), '08 purges every cascading parent row');
    }
}

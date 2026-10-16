<?php

declare(strict_types=1);

use Jira2Redmine\Jira\NotMigrated;
use PHPUnit\Framework\TestCase;

final class NotMigratedTest extends TestCase
{
    public function testAProjectLeftOutIsFoundByItsKey(): void
    {
        self::assertTrue(NotMigrated::project(['TEST'], 'TEST'));
        self::assertTrue(NotMigrated::project(['test '], 'Test'));
        self::assertTrue(NotMigrated::project('ABC, TEST', 'TEST'));
        self::assertFalse(NotMigrated::project(['TEST'], 'TESTER'));
        self::assertFalse(NotMigrated::project([], 'TEST'));
        self::assertFalse(NotMigrated::project(['TEST'], null));
    }

    public function testAFieldTypeLeftOutIsFoundExactly(): void
    {
        $types = ['com.atlassian.servicedesk:sd-request-participants'];

        self::assertTrue(NotMigrated::fieldType($types, 'com.atlassian.servicedesk:sd-request-participants'));
        self::assertFalse(NotMigrated::fieldType($types, 'com.atlassian.servicedesk:sd-customer-organizations'));
        self::assertFalse(NotMigrated::fieldType($types, null));
        self::assertFalse(NotMigrated::fieldType([], 'com.atlassian.servicedesk:sd-request-participants'));
    }

    public function testJansDecisionsAreTheConfiguredOutcome(): void
    {
        // decision 20: the service desk fields are not migrated (IGNORED, not MANUAL); decision 22: TEST is not migrated;
        // decision 40: Jira's AI agent sessions are not migrated either (B78: an issue with a session was held back)
        $config = require __DIR__ . '/../config/config.default.php';

        self::assertSame(['TEST'], $config['migration']['projects']['not_migrated']);
        self::assertSame([
            'com.atlassian.servicedesk:sd-customer-organizations',
            'com.atlassian.servicedesk:sd-request-participants',
            'com.atlassian.jira.plugin.system.customfieldtypes:agent-sessions',
        ], $config['migration']['custom_fields']['not_migrated']);
    }

    public function testTheScriptsApplyIt(): void
    {
        $projects = (string)file_get_contents(__DIR__ . '/../01_migrate_projects.php');
        $fields = (string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php');

        $call = "runProjectTransformationPhase(\$pdo, \$config['migration']['projects']['not_migrated']";
        self::assertStringContainsString($call, $projects);
        self::assertStringContainsString('NotMigrated::project($notMigrated, $projectKey)', $projects);
        $call = "runCustomFieldTransformationPhase(\$pdo, \$config['migration']['custom_fields']['not_migrated']";
        self::assertStringContainsString($call, $fields);
        self::assertStringContainsString('NotMigrated::fieldType($notMigrated, $jiraSchemaCustom)', $fields);
    }
}

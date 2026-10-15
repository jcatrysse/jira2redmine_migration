<?php

declare(strict_types=1);

use Jira2Redmine\Cli\ConfigValue;
use PHPUnit\Framework\TestCase;

/**
 * Jan, decision 33: the roles the "workflows" step opens come from the configuration, not the names "Manager" and
 * "Developer" in the script (on an existing Redmine with other role names it opened nothing, B12).
 */
final class OpenWorkflowRolesTest extends TestCase
{
    public function testTheRolesComeFromTheConfigurationWithTodaysDefault(): void
    {
        $config = require __DIR__ . '/../config/config.default.php';

        self::assertSame(['Manager', 'Developer'], ConfigValue::at($config, 'migration.workflows.open_workflow_roles'));
        self::assertSame('project', ConfigValue::at($config, 'migration.workflows.mode'));
        $this->expectException(InvalidArgumentException::class);
        ConfigValue::at($config, 'migration.workflows.nothing');
    }

    public function testTheStepReadsThemAndStopsOnARoleItCannotUse(): void
    {
        $step = (string)file_get_contents(__DIR__ . '/../tools/testenv/manual_steps.rb');
        self::assertStringNotContainsString('%w[Manager Developer]', $step);
        self::assertStringContainsString("JSON.parse(ENV['J2R_WORKFLOW_ROLES'].presence || '[\"Manager\", \"Developer\"]')", $step);
        $missing = 'abort "Roles named in migration.workflows.open_workflow_roles that this Redmine does not have:';
        self::assertStringContainsString($missing, $step);
        self::assertStringContainsString('roles.reject(&:consider_workflow?)', $step);
        self::assertStringContainsString('temporary_role.call if names.include?(TEMPORARY_ROLE)', $step);

        $run = (string)file_get_contents(__DIR__ . '/../tools/testenv/run_all.sh');
        self::assertStringContainsString('php tools/config_value.php migration.workflows.open_workflow_roles', $run);
        self::assertStringContainsString('J2R_WORKFLOW_ROLES="$roles"', $run);
    }
}

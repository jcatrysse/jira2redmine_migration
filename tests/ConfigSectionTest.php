<?php

declare(strict_types=1);

use Jira2Redmine\Cli\ConfigValue;
use PHPUnit\Framework\TestCase;

/**
 * The configuration sections of the scripts (plan M1: the 16 copies of extractArrayConfig). A missing section or one
 * that is no array stops the script; 01 to 03, 08 and 12 keep the wording their copy had.
 */
final class ConfigSectionTest extends TestCase
{
    public function testASectionIsReturnedAsItIs(): void
    {
        $config = ['jira' => ['base_url' => 'https://example.atlassian.net'], 'database' => []];
        self::assertSame(['base_url' => 'https://example.atlassian.net'], ConfigValue::section($config, 'jira'));
        self::assertSame([], ConfigValue::section($config, 'database'));
    }

    public function testAMissingSectionStopsTheScript(): void
    {
        foreach ([[], ['redmine' => null], ['redmine' => 'http://redmine']] as $config) {
            try {
                ConfigValue::section($config, 'redmine');
                self::fail('a section was made up');
            } catch (RuntimeException $exception) {
                self::assertSame('Missing configuration section: redmine', $exception->getMessage());
            }
        }
        $this->expectExceptionMessage('Missing redmine configuration block.');
        ConfigValue::section([], 'redmine', 'Missing %s configuration block.');
    }

    public function testEveryScriptReadsItsSectionsHereInItsOwnWording(): void
    {
        $own = [
            '01' => 'Missing or invalid configuration section: %s',
            '02' => 'Missing or invalid configuration section: %s',
            '03' => 'Missing or invalid configuration section: %s',
            '08' => 'Missing configuration section "%s".',
            '12' => 'Missing %s configuration block.',
        ];
        foreach (glob(__DIR__ . '/../[0-9][0-9]_*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            $wording = $own[substr(basename($file), 0, 2)] ?? null;
            $call = $wording === null
                ? "ConfigValue::section(\$config, 'database')"
                : "ConfigValue::section(\$config, 'database', '$wording')";
            self::assertStringContainsString($call, $source, basename($file));
            self::assertStringNotContainsString('function extractArrayConfig(', $source, basename($file));
        }
    }
}

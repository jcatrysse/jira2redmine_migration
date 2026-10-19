<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The scripts open the staging database and the Jira and Redmine clients through the factories (plan M1: the copies
 * of createDatabaseConnection, createJiraClient and createRedmineClient went). Each keeps the settings its copy had:
 * 01 to 07 and 10 give their clients 30 seconds, the others no timeout; the PDO attributes differ as before (the plan,
 * M1 rest: switching them risks TypeErrors under strict_types).
 */
final class ScriptFactoriesTest extends TestCase
{
    private const ASSOC = '[PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]';
    private const NATIVE = '[PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]';

    public function testEveryScriptKeepsItsDatabaseAttributesAndClientTimeouts(): void
    {
        $timeout = ['01', '02', '03', '04', '05', '06', '07', '10'];
        $attributes = ['01' => self::NATIVE, '02' => self::NATIVE, '03' => self::NATIVE, '04' => null, '05' => null, '06' => null,
            '07' => null, '10' => null];
        foreach (glob(__DIR__ . '/../[0-9][0-9]_*.php') ?: [] as $file) {
            $number = substr(basename($file), 0, 2);
            $source = sourceWithoutLayout((string)file_get_contents($file));
            foreach (['createDatabaseConnection', 'createJiraClient', 'createRedmineClient'] as $copy) {
                self::assertStringNotContainsString('function ' . $copy . '(', $source, basename($file));
            }

            $attribute = array_key_exists($number, $attributes) ? $attributes[$number] : self::ASSOC;
            preg_match_all('/ConnectionFactory::connect\((.*?)\);/', $source, $connects);
            self::assertCount(1, $connects[1], basename($file));
            self::assertStringEndsWith($attribute === null ? 'Config' : ', ' . $attribute, $connects[1][0], basename($file));

            preg_match_all('/ClientFactory::(?:jira|redmine)\((.*?)\);/', $source, $clients);
            self::assertNotEmpty($clients[1], basename($file));
            foreach ($clients[1] as $arguments) {
                $hasTimeout = str_ends_with($arguments, ', 30.0');
                self::assertSame(in_array($number, $timeout, true), $hasTimeout, basename($file) . ': ' . $arguments);
            }
        }
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RequirementsTest extends TestCase
{
    private const ALL = ['Core', 'PDO', 'pdo_mysql', 'mbstring', 'ctype', 'json', 'dom', 'libxml', 'curl', 'openssl'];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../lib/requirements.php';
    }

    public function testPhp82WithTheExtensionsPasses(): void
    {
        // Jan, decision 23: production runs on PHP 8.2 or newer
        self::assertSame([], j2rRequirementProblems('8.2.0', self::ALL));
        self::assertSame([], j2rRequirementProblems('8.4.1', self::ALL));
    }

    public function testAnOlderPhpStopsWithItsVersion(): void
    {
        self::assertSame(['PHP 8.2.0 or newer is required; this is PHP 8.1.34.'], j2rRequirementProblems('8.1.34', self::ALL));
    }

    public function testAMissingExtensionIsNamed(): void
    {
        $loaded = array_values(array_diff(self::ALL, ['pdo_mysql', 'curl']));

        $problems = j2rRequirementProblems('8.3.6', $loaded);

        self::assertCount(1, $problems);
        self::assertStringStartsWith('Missing PHP extension(s): pdo_mysql, curl ', $problems[0]);
    }

    public function testBootstrapChecksBeforeTheAutoloader(): void
    {
        $bootstrap = (string)file_get_contents(__DIR__ . '/../bootstrap.php');

        $check = strpos($bootstrap, 'j2rRequirementProblems(PHP_VERSION, get_loaded_extensions())');
        $autoload = strpos($bootstrap, "require_once __DIR__ . '/vendor/autoload.php';");
        self::assertNotFalse($check);
        self::assertNotFalse($autoload);
        self::assertLessThan($autoload, $check);
        self::assertStringContainsString('exit(1);', $bootstrap);
    }

    public function testThisPlatformPasses(): void
    {
        self::assertSame([], j2rRequirementProblems(PHP_VERSION, get_loaded_extensions()));
    }
}

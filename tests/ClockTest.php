<?php

declare(strict_types=1);

use Jira2Redmine\Time\Clock;
use PHPUnit\Framework\TestCase;

/**
 * The scripts' current time (plan M1: the copies of formatCurrentTimestamp and formatCurrentUtcTimestamp): UTC in the
 * two formats the scripts print and store, also when PHP runs in another timezone.
 */
final class ClockTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testTheLogTimeOf01To08IsAtomInUtc(): void
    {
        $before = time();
        $atom = Clock::atom();
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $atom);
        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $atom);
        self::assertNotFalse($parsed);
        self::assertGreaterThanOrEqual($before, $parsed->getTimestamp());
        self::assertLessThanOrEqual(time(), $parsed->getTimestamp());
    }

    public function testTheTimeOf09To16AndTheStagingTablesIsUtcWithoutAnOffset(): void
    {
        $before = time();
        $dateTime = Clock::dateTime();
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $dateTime);
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateTime, new DateTimeZone('UTC'));
        self::assertNotFalse($parsed);
        self::assertGreaterThanOrEqual($before, $parsed->getTimestamp());
        self::assertLessThanOrEqual(time(), $parsed->getTimestamp());
    }

    public function testNoScriptKeepsACopy(): void
    {
        foreach (glob(__DIR__ . '/../[0-9][0-9]_*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            self::assertStringNotContainsString('function formatCurrent', $source, basename($file));
            $number = substr(basename($file), 0, 2);
            self::assertSame($number <= '08', str_contains($source, 'Clock::atom()'), basename($file));
        }
    }
}

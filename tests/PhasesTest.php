<?php

declare(strict_types=1);

use Jira2Redmine\Cli\Phases;
use PHPUnit\Framework\TestCase;

final class PhasesTest extends TestCase
{
    private const AVAILABLE = ['jira', 'pull', 'transform', 'push'];

    public function testPhasesRunInTheScriptsOrder(): void
    {
        self::assertSame(self::AVAILABLE, Phases::select(self::AVAILABLE, null, null));
        // 02 and 03 looped over the phases in the order given: push could run before jira
        self::assertSame(['jira', 'push'], Phases::select(self::AVAILABLE, 'push, jira', null));
        self::assertSame(['transform', 'push'], Phases::select(self::AVAILABLE, 'TRANSFORM,push,push', null));
        self::assertSame(['jira', 'pull', 'push'], Phases::select(self::AVAILABLE, null, 'transform'));
    }

    public function testAnUnknownPhaseStopsTheScript(): void
    {
        // 09, 11 and 13 to 16 ignored it and, with nothing left, ran every phase: jira, pull, transform and push
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown phase "pul" in --phases. Valid phases: jira, pull, transform, push');

        Phases::select(self::AVAILABLE, 'pul', null);
    }

    public function testAnUnknownSkipStopsTheScriptToo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown phase "puhs" in --skip.');

        Phases::select(self::AVAILABLE, null, 'puhs');
    }

    public function testAnEmptyOptionStopsTheScript(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The --phases option was given without a phase.');

        Phases::select(self::AVAILABLE, ' , ', null);
    }

    public function testNothingLeftStopsTheScript(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No phases left to run after --phases and --skip.');

        Phases::select(self::AVAILABLE, 'push', 'push');
    }

    public function testEveryScriptUsesIt(): void
    {
        foreach (glob(__DIR__ . '/../[0-9][0-9]_*.php') ?: [] as $script) {
            $source = (string)file_get_contents($script);
            self::assertStringContainsString(
                '$phasesToRun = Phases::fromOptions(AVAILABLE_PHASES, $cliOptions);',
                $source,
                basename($script)
            );
        }
    }

    public function testTheParsedCommandLineChoosesThePhases(): void
    {
        $available = ['jira' => 'read', 'transform' => 'match', 'push' => 'write'];
        self::assertSame(['jira', 'transform', 'push'], Phases::fromOptions($available, ['phases' => null, 'skip' => null]));
        self::assertSame(['transform'], Phases::fromOptions($available, ['phases' => 'push,transform', 'skip' => 'push']));

        $this->expectExceptionMessage('Unknown phase "pul" in --phases. Valid phases: jira, transform, push');
        Phases::fromOptions($available, ['phases' => 'pul', 'skip' => null]);
    }
}

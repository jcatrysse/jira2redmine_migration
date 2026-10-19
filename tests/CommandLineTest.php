<?php

declare(strict_types=1);

use Jira2Redmine\Cli\CommandLine;
use Jira2Redmine\Cli\Phases;
use Jira2Redmine\Cli\Script;
use Jira2Redmine\Cli\ScriptCommandLines;
use PHPUnit\Framework\TestCase;

/**
 * The shared option parsing and start of the 16 scripts (plan M1): each rule of CommandLine on its own, and the runner
 * every script starts with.
 */
final class CommandLineTest extends TestCase
{
    /**
     * @param list<string> $arguments
     * @return array<string, bool|int|string|null>
     */
    private static function parse(CommandLine $commandLine, array $arguments): array
    {
        return $commandLine->parse(array_merge(['script.php'], $arguments));
    }

    /**
     * @param list<string> $arguments
     */
    private static function refusal(string $script, array $arguments): string
    {
        try {
            ScriptCommandLines::for($script)->parse(array_merge([$script], $arguments));
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return 'accepted';
    }

    /** @return list<string> */
    private static function scripts(): array
    {
        return array_map('basename', glob(__DIR__ . '/../[0-9][0-9]_*.php') ?: []);
    }

    public function testTheValidCommandLine(): void
    {
        $plain = new CommandLine(['confirm-push' => CommandLine::FLAG, 'download-limit' => CommandLine::POSITIVE_INT]);
        $options = self::parse($plain, ['--phases=jira,push', '--skip=jira', '--confirm-push', '--download-limit=12']);

        self::assertSame([
            'help' => false,
            'version' => false,
            'phases' => 'jira,push',
            'skip' => 'jira',
            'confirm_push' => true,
            'download_limit' => 12,
        ], $options);
    }

    public function testEveryScriptTakesTheShortHelpAndVersion(): void
    {
        // decision 43: 09 and 11 to 16 took -h and -V for positional arguments
        foreach (self::scripts() as $script) {
            $options = ScriptCommandLines::for($script)->parse([$script, '-h']);
            self::assertTrue($options['help'], $script);
            $options = ScriptCommandLines::for($script)->parse([$script, '-V']);
            self::assertTrue($options['version'], $script);
        }
    }

    public function testAnUnknownOptionStopsEveryScriptEvenWithHelp(): void
    {
        // decision 43: 09 to 11 and 13 to 16 took an unknown option for a positional argument and showed the help
        $unknown = ['--bogus', '--bogus=1', '-x', '-', '--', '--HELP', '--confirm-push=1', '--confirm-pull'];
        foreach (self::scripts() as $script) {
            foreach ($unknown as $option) {
                if ($option === '--confirm-pull' && str_starts_with($script, '09')) {
                    continue;
                }
                self::assertSame('Unknown option: ' . $option, self::refusal($script, ['--help', $option]), $script);
                self::assertSame('Unknown option: ' . $option, self::refusal($script, [$option, '--help']), $script);
            }
            self::assertSame('Unknown option: --', self::refusal($script, ['--', '--help']), $script);
        }
    }

    public function testAPositionalOrEmptyArgumentStopsEveryScriptEvenWithHelp(): void
    {
        // decision 43: every script but 04 showed the help; 10 and 12 left an empty argument out
        foreach (self::scripts() as $script) {
            self::assertSame('Unexpected positional argument: "a"', self::refusal($script, ['a', '--help']), $script);
            self::assertSame('Unexpected positional argument: ""', self::refusal($script, ['']), $script);
            self::assertSame('Unexpected positional argument: ""', self::refusal($script, ['', '--help']), $script);
            self::assertSame(
                'Unexpected positional argument: "extra"',
                self::refusal($script, ['--phases=jira', 'extra']),
                $script
            );
        }
    }

    public function testAnOptionWithoutItsValueStopsEveryScript(): void
    {
        // decision 43: 01 to 03 also took --phases LIST and --skip LIST
        foreach (self::scripts() as $script) {
            self::assertSame(
                'The --phases option requires a value: --phases=LIST',
                self::refusal($script, ['--phases', 'jira']),
                $script
            );
            self::assertSame('The --skip option requires a value: --skip=LIST', self::refusal($script, ['--skip']), $script);
        }
        self::assertSame(
            'The --download-limit option requires a value: --download-limit=N',
            self::refusal('09_migrate_attachments.php', ['--download-limit', '5'])
        );
    }

    public function testAnEmptyPhaseListStopsEveryScript(): void
    {
        // decision 43: 10 counted an empty --phases= or --skip= (or "0") as not given and ran every phase
        $phases = ['jira' => 'read', 'push' => 'write'];
        foreach (self::scripts() as $script) {
            $refused = [
                ['--phases=', 'The --phases option was given without a phase.'],
                ['--skip=', 'The --skip option was given without a phase.'],
                ['--phases=0', 'Unknown phase "0" in --phases.'],
            ];
            foreach ($refused as [$argument, $message]) {
                $options = ScriptCommandLines::for($script)->parse([$script, $argument]);
                try {
                    Phases::fromOptions($phases, $options);
                    self::fail($script . ' ran with ' . $argument);
                } catch (RuntimeException $exception) {
                    self::assertStringStartsWith($message, $exception->getMessage(), $script);
                }
            }
        }
    }

    public function testALimitIsAPositiveWholeNumber(): void
    {
        $rule = new CommandLine(['download-limit' => CommandLine::POSITIVE_INT]);
        self::assertNull(self::parse($rule, [])['download_limit']);
        self::assertSame(7, self::parse($rule, ['--download-limit= 7 '])['download_limit']);
        $refused = [
            '--download-limit=x' => 'Invalid --download-limit value: x',
            '--download-limit=0' => '--download-limit expects a positive integer.',
        ];
        foreach ($refused as $argument => $message) {
            try {
                self::parse($rule, [$argument]);
                self::fail($argument . ' was accepted');
            } catch (RuntimeException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testEveryScriptHasACommandLine(): void
    {
        foreach (glob(__DIR__ . '/../[0-9][0-9]_*.php') ?: [] as $file) {
            ScriptCommandLines::for(basename($file));
            self::assertStringContainsString(
                implode("\n", [
                    "require_once __DIR__ . '/lib/cli.php';",
                    '',
                    'exit(Script::run(',
                    '    $_SERVER[\'argv\'] ?? ($GLOBALS[\'argv\'] ?? null),',
                    '    ScriptCommandLines::for(basename(__FILE__)),',
                ]),
                (string)file_get_contents($file)
            );
        }
        $this->expectExceptionMessage('No command line for 17_unknown.php.');
        ScriptCommandLines::for('17_unknown.php');
    }

    public function testEachScriptTellsTheAnalysisTheOptionsItsCommandLineGives(): void
    {
        // the start passes the options to main() with an @var of their shape; it must name what the parser returns
        foreach (glob(__DIR__ . '/../[0-9][0-9]_*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            self::assertSame(1, preg_match('/@var array\{(.*?)\} \$cliOptions/s', $source, $match), basename($file));
            preg_match_all('/(\w+): /', $match[1], $keys);
            $options = ScriptCommandLines::for(basename($file))->parse([basename($file)]);
            self::assertSame(array_keys($options), $keys[1], basename($file));
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string, list<array<string, mixed>>}
     */
    private static function start(array $arguments): array
    {
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($errors);
        $ran = [];
        ob_start();
        $code = Script::run(
            array_merge(['script.php'], $arguments),
            new CommandLine(['dry-run' => CommandLine::FLAG]),
            static function (): void {
                echo 'usage';
            },
            static function (): void {
                echo 'version';
            },
            static function (array $options) use (&$ran): void {
                $ran[] = $options;
                if ($options['dry_run'] === true) {
                    throw new LogicException('stopped');
                }
            },
            $errors
        );
        $output = (string)ob_get_clean();
        rewind($errors);

        return [$code, $output, (string)stream_get_contents($errors), $ran];
    }

    public function testTheStartAnswersHelpAndVersionWithoutRunningTheScript(): void
    {
        self::assertSame([0, 'usage', '', []], self::start(['--help', '--version']));
        self::assertSame([0, 'usage', '', []], self::start(['-h']));
        self::assertSame([0, 'version', '', []], self::start(['--version']));
        self::assertSame([0, 'version', '', []], self::start(['-V']));
    }

    public function testTheStartRefusesWhatTheCommandLineDoesNotKnowAndReportsErrorsOnOneLine(): void
    {
        self::assertSame([1, '', "[ERROR] Unexpected positional argument: \"a\"\n", []], self::start(['a', 'b']));
        self::assertSame([1, '', "[ERROR] Unexpected positional argument: \"stray\"\n", []], self::start(['--help', 'stray']));
        self::assertSame([1, '', "[ERROR] Unknown option: --bogus\n", []], self::start(['--version', '--bogus']));
        [$code, $output, $errors, $ran] = self::start(['--dry-run']);
        self::assertSame([1, '', "[ERROR] stopped\n"], [$code, $output, $errors]);
        self::assertCount(1, $ran);
    }

    public function testTheStartRunsTheScriptWithItsOptions(): void
    {
        [$code, , $errors, $ran] = self::start(['--phases=push']);
        self::assertSame([0, ''], [$code, $errors]);
        self::assertSame([['help' => false, 'version' => false, 'phases' => 'push', 'skip' => null, 'dry_run' => false]], $ran);
    }

    public function testHelpAndVersionNeedNeitherTheAutoloaderNorTheConfiguration(): void
    {
        // lib/cli.php loads the start without Composer: bootstrap.php checks this PHP first (decision 23)
        $process = proc_open(
            [PHP_BINARY, '-r', 'require "lib/cli.php"; echo implode(",", array_map("class_exists", '
                . '["Jira2Redmine\\Cli\\Script", "Jira2Redmine\\Cli\\ScriptCommandLines", "GuzzleHttp\\Client"]));'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__)
        );
        self::assertIsResource($process);
        self::assertSame('1,1,', stream_get_contents($pipes[1]));
        proc_close($process);
    }

    public function testTheStartNeedsTheCommandLine(): void
    {
        $this->expectExceptionMessage('Unable to access the command-line arguments.');
        $nothing = static function (): void {
        };
        Script::run(null, new CommandLine([]), $nothing, $nothing, $nothing);
    }
}

<?php

declare(strict_types=1);

use Jira2Redmine\Cli\CommandLine;
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
     * @return array{0: array<string, bool|int|string|null>, 1: list<string>}
     */
    private static function parse(CommandLine $commandLine, array $arguments): array
    {
        return $commandLine->parse(array_merge(['script.php'], $arguments));
    }

    public function testTheValidCommandLineIsTheSameForEveryRule(): void
    {
        $plain = new CommandLine(['confirm-push' => CommandLine::FLAG, 'download-limit' => CommandLine::POSITIVE_INT]);
        [$options, $positional] = self::parse($plain, ['--phases=jira,push', '--skip=jira', '--confirm-push', '--download-limit=12']);

        self::assertSame([
            'help' => false,
            'version' => false,
            'phases' => 'jira,push',
            'skip' => 'jira',
            'confirm_push' => true,
            'download_limit' => 12,
        ], $options);
        self::assertSame([], $positional);
    }

    public function testShortOptionsOnlyWhereTheScriptHadThem(): void
    {
        self::assertSame(['-h', '-V'], self::parse(new CommandLine([]), ['-h', '-V'])[1]);
        [$options] = self::parse(new CommandLine([], shortOptions: true), ['-h', '-V']);
        self::assertTrue($options['help']);
        self::assertTrue($options['version']);
    }

    public function testSeparateValuesAndTrimming(): void
    {
        $rule = new CommandLine([], separateValues: true, trimValues: true);
        self::assertSame(' --dry-run', self::parse(new CommandLine([]), ['--phases= --dry-run'])[0]['phases']);
        self::assertSame('--dry-run', self::parse($rule, ['--phases', ' --dry-run '])[0]['phases']);
        self::assertSame('push', self::parse($rule, ['--skip= push '])[0]['skip']);
        self::assertSame(['--phases'], self::parse(new CommandLine([]), ['--phases'])[1]);

        $this->expectExceptionMessage('The --skip option requires a value.');
        self::parse($rule, ['--skip']);
    }

    public function testAFalsyValueCountsAsNotGivenWhereTheScriptSaidSo(): void
    {
        $rule = new CommandLine([], falsyValueIsNull: true);
        self::assertNull(self::parse($rule, ['--phases='])[0]['phases']);
        self::assertNull(self::parse($rule, ['--phases=0'])[0]['phases']);
        self::assertSame('', self::parse(new CommandLine([]), ['--phases='])[0]['phases']);
    }

    public function testAnUnknownOptionIsAnErrorOrAPositionalArgument(): void
    {
        self::assertSame(['--bogus', '-x', ''], self::parse(new CommandLine([]), ['--bogus', '-x', ''])[1]);
        $rule = new CommandLine([], unknownOption: 'Unknown option: %s');
        self::assertSame([''], self::parse($rule, [''])[1]);

        $this->expectExceptionMessage('Unknown option: -x');
        self::parse($rule, ['-x']);
    }

    public function testOnlyDoubleDashesAreOptionsWhenThePrefixSaysSo(): void
    {
        $rule = new CommandLine([], unknownOption: 'Unknown option: %s', optionPrefix: '--', positionalError: 'Unknown short option: %s');
        try {
            self::parse($rule, ['--bogus']);
            self::fail('--bogus was accepted');
        } catch (RuntimeException $exception) {
            self::assertSame('Unknown option: --bogus', $exception->getMessage());
        }

        $this->expectExceptionMessage('Unknown short option: -x');
        self::parse($rule, ['-x']);
    }

    public function testDoubleDashEndsTheOptionsOrIsAnArgument(): void
    {
        self::assertSame(['--help', 'a'], self::parse(new CommandLine([], doubleDash: true), ['--', '--help', 'a'])[1]);
        [$options, $positional] = self::parse(new CommandLine([]), ['--', '--help']);
        self::assertSame(['--'], $positional);
        self::assertTrue($options['help']);
    }

    public function testEmptyArgumentsAreLeftOutWhereTheScriptDidThat(): void
    {
        self::assertSame([], self::parse(new CommandLine([], skipEmpty: true), ['', ''])[1]);
        self::assertSame(['', ''], self::parse(new CommandLine([]), ['', ''])[1]);
    }

    public function testALimitIsAPositiveWholeNumber(): void
    {
        $rule = new CommandLine(['download-limit' => CommandLine::POSITIVE_INT]);
        self::assertNull(self::parse($rule, [])[0]['download_limit']);
        self::assertSame(7, self::parse($rule, ['--download-limit= 7 '])[0]['download_limit']);
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
            [$options] = ScriptCommandLines::for(basename($file))->parse([basename($file)]);
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
        self::assertSame([0, 'usage', '', []], self::start(['--help', '--version', 'stray']));
        self::assertSame([0, 'version', '', []], self::start(['--version', 'stray']));
    }

    public function testTheStartRefusesPositionalArgumentsAndReportsErrorsOnOneLine(): void
    {
        self::assertSame([1, '', "[ERROR] Unexpected positional arguments: a, b\n", []], self::start(['a', 'b']));
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

<?php

declare(strict_types=1);

use Jira2Redmine\Cli\ScriptCommandLines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 (plan M1) moved the 16 copies of the option parsing to src/Cli without changing what any script accepts or
 * refuses. tests/fixtures/command_lines.json holds what each copy returned or threw for the command lines of
 * commandLineInputs(), made from the copies themselves at 2245962 (tools/testenv/legacy_command_lines.php).
 *
 * Decision 43 then replaced the 16 rules by one (CommandLineTest). The changes it made are listed below, by command
 * line and script; on every other command line a script does what its copy did: it runs with the same options, shows
 * its help or version, or stops (the error may now be worded by the one rule).
 */
final class CommandLineCompatibilityTest extends TestCase
{
    private const ALL = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16'];
    private const WITHOUT_04 = ['01', '02', '03', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16'];
    private const POSITIONAL_COPIES = ['09', '10', '11', '13', '14', '15', '16'];
    private const WITHOUT_SHORT_OPTIONS = ['09', '11', '12', '13', '14', '15', '16'];

    /** @var array<string, list<string>> command line => the scripts whose outcome decision 43 changed */
    private const DECISION_43 = [
        // the value is no longer trimmed; Phases reads " jira , push " as "jira , push" (PhasesTest)
        '["--phases= jira , push "]' => ['01', '02', '03'],
        '["--skip= push"]' => ['01', '02', '03'],
        // --phases LIST and --skip LIST: now an error, as in the other scripts
        '["--phases","jira"]' => ['01', '02', '03'],
        '["--phases","--dry-run"]' => ['01', '02', '03'],
        '["--skip","push"]' => ['01', '02', '03'],
        // "--" ended the options in 01 to 07; now an unknown option
        '["--"]' => ['01', '02', '03', '04', '05', '06', '07'],
        // an empty argument was left out in 10 and 12; now an error
        '[""]' => ['10', '12'],
        // 10 counted an empty --phases= or --skip= (or "0") as not given; now Phases refuses it
        '["--phases="]' => ['10'],
        '["--phases=0"]' => ['10'],
        '["--skip="]' => ['10'],
        // a positional argument or an unknown option next to --help showed the help; now an error
        '["--","--help"]' => self::POSITIONAL_COPIES,
        '["a","--help"]' => self::WITHOUT_04,
        '["","--help"]' => self::WITHOUT_04,
        '["--help","--bogus"]' => self::POSITIONAL_COPIES,
        '["--bogus","--help"]' => self::POSITIONAL_COPIES,
        // -h and -V in every script
        '["-h"]' => self::WITHOUT_SHORT_OPTIONS,
        '["-V"]' => self::WITHOUT_SHORT_OPTIONS,
        '["-h","-V"]' => self::WITHOUT_SHORT_OPTIONS,
    ];

    /**
     * @return array<string, array{string, list<string>, array<string, mixed>}>
     */
    public static function recorded(): array
    {
        $recorded = json_decode((string)file_get_contents(__DIR__ . '/fixtures/command_lines.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($recorded as $script => $rows) {
            foreach ($rows as $row) {
                $cases[sprintf('%s %s', substr($script, 0, 2), json_encode($row['argv']))] = [$script, $row['argv'], $row];
            }
        }

        return $cases;
    }

    public function testEveryScriptAndEveryCommandLineWasRecorded(): void
    {
        $recorded = json_decode((string)file_get_contents(__DIR__ . '/fixtures/command_lines.json'), true, 512, JSON_THROW_ON_ERROR);
        $scripts = array_map('basename', glob(__DIR__ . '/../[0-9][0-9]_*.php') ?: []);
        self::assertSame($scripts, array_keys($recorded));
        foreach ($recorded as $rows) {
            self::assertSame(commandLineInputs(), array_column($rows, 'argv'));
        }
        foreach (array_keys(self::DECISION_43) as $argv) {
            self::assertContains(json_decode($argv, true), commandLineInputs());
        }
        self::assertSame(self::ALL, array_map(static fn (string $script): string => substr($script, 0, 2), $scripts));
    }

    /**
     * @param list<string> $argv
     * @param array<string, mixed> $expected
     */
    #[DataProvider('recorded')]
    public function testTheScriptDoesWhatItsOwnCopyDidApartFromDecision43(string $script, array $argv, array $expected): void
    {
        try {
            $actual = ['options' => ScriptCommandLines::for($script)->parse(array_merge([$script], $argv)), 'positional' => []];
        } catch (Throwable $exception) {
            $actual = ['error' => get_class($exception) . ': ' . $exception->getMessage()];
        }

        $changed = in_array(substr($script, 0, 2), self::DECISION_43[json_encode($argv)] ?? [], true);
        if ($changed) {
            self::assertNotSame(self::outcome($expected), self::outcome($actual));
        } else {
            self::assertSame(self::outcome($expected), self::outcome($actual));
        }
    }

    /**
     * What the start does with the parsed command line (Script::run): stop, show the help or the version, or run the
     * script with these options.
     *
     * @param array<string, mixed> $parsed
     */
    private static function outcome(array $parsed): string
    {
        if (isset($parsed['error']) || !is_array($parsed['options'] ?? null)) {
            return 'stop';
        }
        if ($parsed['options']['help'] === true) {
            return 'help';
        }
        if ($parsed['options']['version'] === true) {
            return 'version';
        }

        return $parsed['positional'] === [] ? 'run ' . json_encode($parsed['options']) : 'stop';
    }
}

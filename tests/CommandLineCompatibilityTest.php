<?php

declare(strict_types=1);

use Jira2Redmine\Cli\ScriptCommandLines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 (plan M1) moved the 16 copies of the option parsing to src/Cli without changing what any script accepts or
 * refuses. tests/fixtures/command_lines.json holds what each copy returned or threw for the command lines of
 * commandLineInputs(), made from the copies themselves at 2245962 (tools/testenv/legacy_command_lines.php).
 */
final class CommandLineCompatibilityTest extends TestCase
{
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
    }

    /**
     * @param list<string> $argv
     * @param array<string, mixed> $expected
     */
    #[DataProvider('recorded')]
    public function testTheScriptAcceptsAndRefusesWhatItsOwnCopyDid(string $script, array $argv, array $expected): void
    {
        try {
            [$options, $positional] = ScriptCommandLines::for($script)->parse(array_merge([$script], $argv));
            $actual = ['argv' => $argv, 'options' => $options, 'positional' => $positional];
        } catch (Throwable $exception) {
            $actual = ['argv' => $argv, 'error' => get_class($exception) . ': ' . $exception->getMessage()];
        }

        self::assertSame($expected, $actual);
    }
}

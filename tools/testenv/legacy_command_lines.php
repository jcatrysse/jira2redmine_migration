<?php
// The option parsing of the 16 scripts as it was before phase 6 moved it to src/Cli (plan M1), as test data:
//   php tools/testenv/legacy_command_lines.php 2245962 > tests/fixtures/command_lines.json
// Reads each script's own parseCommandLineOptions() (and 09's parsePositiveIntOption()) at that commit, runs it on
// the command lines of tests/CommandLineCompatibilityTest.php and prints what it returned or threw. The test then holds
// the shared parser to exactly that.
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../tests/CommandLineInputs.php';

$commit = $argv[1] ?? '';
if (!preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
    fwrite(STDERR, "Usage: php tools/testenv/legacy_command_lines.php COMMIT\n");
    exit(1);
}
$root = dirname(__DIR__, 2);

/** The source of a top-level function, by its name. */
function legacyFunction(string $code, string $name): string
{
    $tokens = token_get_all($code);
    $depth = 0;
    foreach ($tokens as $i => $token) {
        if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif ($token === '}') {
            $depth--;
        } elseif ($depth === 0 && is_array($token) && $token[0] === T_FUNCTION && ($tokens[$i + 2][1] ?? '') === $name) {
            $source = '';
            $level = 0;
            $open = false;
            for ($j = $i, $n = count($tokens); $j < $n; $j++) {
                $part = $tokens[$j];
                $source .= is_array($part) ? $part[1] : $part;
                if ($part === '{' || (is_array($part) && in_array($part[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $level++;
                    $open = true;
                } elseif ($part === '}' && --$level === 0 && $open) {
                    return $source;
                }
            }
        }
    }
    throw new RuntimeException("no function $name");
}

$result = [];
$files = explode("\n", trim((string)shell_exec(sprintf('git -C %s ls-tree --name-only %s', escapeshellarg($root), escapeshellarg($commit)))));
foreach (array_filter($files, static fn (string $file): bool => (bool)preg_match('/^\d\d_.*\.php$/', $file)) as $script) {
    $code = (string)shell_exec(sprintf('git -C %s show %s', escapeshellarg($root), escapeshellarg($commit . ':' . $script)));
    $prefix = 'legacy' . substr($script, 0, 2);
    $source = str_replace('function parseCommandLineOptions(', "function {$prefix}Parse(", legacyFunction($code, 'parseCommandLineOptions'));
    if (str_contains($source, 'parsePositiveIntOption(')) {
        $source = str_replace('parsePositiveIntOption(', "{$prefix}PositiveInt(", $source)
            . "\n" . str_replace('function parsePositiveIntOption(', "function {$prefix}PositiveInt(", legacyFunction($code, 'parsePositiveIntOption'));
    }
    eval($source);
    foreach (commandLineInputs() as $arguments) {
        try {
            [$options, $positional] = ($prefix . 'Parse')(array_merge([$script], $arguments));
            $outcome = ['options' => $options, 'positional' => $positional];
        } catch (Throwable $exception) {
            $outcome = ['error' => get_class($exception) . ': ' . $exception->getMessage()];
        }
        $result[$script][] = ['argv' => $arguments] + $outcome;
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

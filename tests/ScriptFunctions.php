<?php

declare(strict_types=1);

/**
 * Loads functions of a script into the test run without running the script: their source with the script's "use"
 * lines and strict types, in a namespace per script (the scripts declare helpers of the same name). A function a loaded one calls must
 * be loaded with it, or be global (lib/, PHP). Returns the namespace: "\\Script11\\buildJournalAttachmentBlock".
 *
 * @param list<string> $functions
 */
function loadScriptFunctions(string $script, array $functions): string
{
    $namespace = 'Script' . substr($script, 0, 2);
    $missing = array_values(array_filter(
        $functions,
        static fn (string $function): bool => !function_exists($namespace . '\\' . $function)
    ));
    if ($missing === []) {
        return $namespace;
    }

    $source = (string)file_get_contents(__DIR__ . '/../' . $script);
    preg_match_all('/^use [^;(]+;$/m', $source, $uses);
    $code = "declare(strict_types=1);\nnamespace " . $namespace . ";\n" . implode("\n", $uses[0]) . "\n";
    foreach ($missing as $function) {
        // a script's functions start and end at the first column (PSR-12)
        if (preg_match('/^function ' . preg_quote($function, '/') . '\(.*?^}$/ms', $source, $match) !== 1) {
            throw new RuntimeException(sprintf('No function %s in %s.', $function, $script));
        }
        $code .= $match[0] . "\n";
    }
    eval($code);

    return $namespace;
}

/**
 * One function of a script, loaded with the functions of the script it calls ($with).
 *
 * @param list<string> $with
 */
function scriptFunction(string $script, string $function, array $with = []): callable
{
    $callable = loadScriptFunctions($script, array_merge([$function], $with)) . '\\' . $function;
    if (!is_callable($callable)) {
        throw new RuntimeException(sprintf('%s did not load.', $callable));
    }

    return $callable;
}

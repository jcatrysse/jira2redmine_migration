<?php

declare(strict_types=1);

namespace Jira2Redmine\Cli;

use RuntimeException;
use Throwable;

/**
 * The start of every script (plan M1, the same lines in all 16): read the command line, answer --help and --version,
 * refuse positional arguments, run the script and turn any error into one "[ERROR]" line and exit code 1.
 */
final class Script
{
    /**
     * @param mixed $argv $_SERVER['argv']
     * @param callable(): void $printUsage
     * @param callable(): void $printVersion
     * @param callable(array<string, bool|int|string|null>): void $main gets the options; it loads the configuration
     * @param resource|null $errors where the error line goes, STDERR by default
     * @return int the exit code
     */
    public static function run(
        mixed $argv,
        CommandLine $commandLine,
        callable $printUsage,
        callable $printVersion,
        callable $main,
        $errors = null
    ): int {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('This script is intended to be run from the command line.');
        }
        if (!is_array($argv)) {
            throw new RuntimeException('Unable to access the command-line arguments.');
        }

        try {
            [$options, $positional] = $commandLine->parse($argv);
            if (!empty($options['help'])) {
                $printUsage();

                return 0;
            }
            if (!empty($options['version'])) {
                $printVersion();

                return 0;
            }
            if ($positional !== []) {
                throw new RuntimeException(sprintf('Unexpected positional arguments: %s', implode(', ', $positional)));
            }

            $main($options);
        } catch (Throwable $exception) {
            fwrite($errors ?? STDERR, sprintf('[ERROR] %s%s', $exception->getMessage(), PHP_EOL));

            return 1;
        }

        return 0;
    }
}

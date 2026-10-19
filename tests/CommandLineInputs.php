<?php

declare(strict_types=1);

/**
 * The command lines the option parsing of the 16 scripts is held to (tests/CommandLineCompatibilityTest.php): every
 * option, both value spellings, values with spaces or empty, unknown options, "--", positional and empty arguments.
 *
 * @return list<list<string>>
 */
function commandLineInputs(): array
{
    return [
        [],
        ['--help'],
        ['-h'],
        ['--version'],
        ['-V'],
        ['-h', '-V'],
        ['--phases=jira'],
        ['--phases= jira , push '],
        ['--phases='],
        ['--phases=0'],
        ['--phases', 'jira'],
        ['--phases', '--dry-run'],
        ['--phases'],
        ['--skip=push'],
        ['--skip= push'],
        ['--skip='],
        ['--skip', 'push'],
        ['--skip'],
        ['--confirm-push'],
        ['--dry-run'],
        ['--use-extended-api'],
        ['--confirm-pull'],
        ['--confirm-pull', '--confirm-push', '--use-extended-api', '--dry-run'],
        ['--download-limit=5'],
        ['--download-limit= 7 '],
        ['--download-limit=0'],
        ['--download-limit=x'],
        ['--download-limit='],
        ['--upload-limit=3'],
        ['--upload-limit=-1'],
        ['--phases=push', '--confirm-push', '--dry-run'],
        ['--bogus'],
        ['--bogus=1'],
        ['-x'],
        ['-'],
        ['--'],
        ['--', '--help'],
        ['--', 'a', 'b'],
        ['a'],
        ['a', '--help'],
        ['--help', '--bogus'],
        ['--bogus', '--help'],
        [''],
        ['', '--help'],
        ['--phases=jira', 'extra'],
        ['--HELP'],
        ['--confirm-push=1'],
    ];
}

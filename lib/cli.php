<?php

/**
 * The shared start of the 16 scripts (src/Cli), loaded without Composer's autoloader: --help, --version and an invalid
 * command line are answered before bootstrap.php checks this PHP (decision 23) and loads the autoloader.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Cli/CommandLine.php';
require_once __DIR__ . '/../src/Cli/ScriptCommandLines.php';
require_once __DIR__ . '/../src/Cli/Script.php';

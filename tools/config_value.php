<?php

declare(strict_types=1);

/**
 * Prints one configuration value as JSON, for the steps outside PHP:
 *   php tools/config_value.php migration.workflows.open_workflow_roles
 * Secrets stay out: only values under "migration" are printed.
 */

use Jira2Redmine\Cli\ConfigValue;

$config = require __DIR__ . '/../bootstrap.php';
$path = (string)($argv[1] ?? '');
if (!str_starts_with($path, 'migration.')) {
    fwrite(STDERR, "Usage: php tools/config_value.php migration.<path>\n");
    exit(2);
}
echo json_encode(ConfigValue::at($config, $path), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;

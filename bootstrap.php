<?php

declare(strict_types=1);

// PHP version and extensions first, with a clear message (decision 23, docs/RUNBOOK.md "Prerequisites")
require_once __DIR__ . '/lib/requirements.php';
$requirementProblems = j2rRequirementProblems(PHP_VERSION, get_loaded_extensions());
if ($requirementProblems !== []) {
    fwrite(STDERR, '[ERROR] This PHP cannot run the migration scripts:' . PHP_EOL . '  - '
        . implode(PHP_EOL . '  - ', $requirementProblems) . PHP_EOL . 'See docs/RUNBOOK.md, "Prerequisites".' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/jira_gateway.php';

$defaultConfigPath = __DIR__ . '/config/config.default.php';
$localConfigPath = __DIR__ . '/config/config.local.php';

$defaultConfig = require $defaultConfigPath;
if (!is_array($defaultConfig)) {
    throw new RuntimeException(sprintf('Expected %s to return an array.', $defaultConfigPath));
}

$localConfig = [];
if (is_readable($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (!is_array($localConfig)) {
        throw new RuntimeException(sprintf('Expected %s to return an array.', $localConfigPath));
    }
}

return array_replace_recursive($defaultConfig, $localConfig);

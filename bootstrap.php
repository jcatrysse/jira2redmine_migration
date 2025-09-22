<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

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

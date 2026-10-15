<?php

declare(strict_types=1);

namespace Jira2Redmine\Cli;

/**
 * One value of the configuration by its dotted path, for the steps outside PHP (the rails runner steps read
 * `migration.workflows.open_workflow_roles` this way, decision 33).
 */
final class ConfigValue
{
    /**
     * @param array<mixed> $config
     */
    public static function at(array $config, string $path): mixed
    {
        $value = $config;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new \InvalidArgumentException(sprintf('No configuration value at %s', $path));
            }
            $value = $value[$key];
        }

        return $value;
    }
}

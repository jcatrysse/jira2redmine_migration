<?php

declare(strict_types=1);

namespace Jira2Redmine\Cli;

/**
 * One value of the configuration by its dotted path, for the steps outside PHP (the rails runner steps read
 * `migration.workflows.open_workflow_roles` this way, decision 33), and one section for the scripts.
 */
final class ConfigValue
{
    public const MISSING_SECTION = 'Missing configuration section: %s';

    /**
     * A section of the configuration (jira, redmine, database). The 16 scripts each had a copy (plan M1); 01 to 03, 08
     * and 12 word the error their own way and pass their wording.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function section(array $config, string $key, string $missing = self::MISSING_SECTION): array
    {
        if (!isset($config[$key]) || !is_array($config[$key])) {
            throw new \RuntimeException(sprintf($missing, $key));
        }

        return $config[$key];
    }

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

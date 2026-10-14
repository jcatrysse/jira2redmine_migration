<?php

/**
 * Jan, decision 23 (2026-10-14): the scripts run on an existing server with PHP 8.2 or newer. bootstrap.php checks
 * this first, before Composer's autoloader and the code that needs the extensions, and stops with a clear message
 * instead of an error halfway through a run. Kept in plain syntax, so an older PHP can still read it.
 */

const J2R_MIN_PHP_VERSION = '8.2.0';

/** pdo_mysql: the staging database; mbstring, ctype: text; dom, libxml: HTML to Markdown; curl, openssl: HTTPS. */
const J2R_REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'mbstring', 'ctype', 'json', 'dom', 'libxml', 'curl', 'openssl'];

/**
 * @param list<string> $loaded the names of the loaded extensions
 * @return list<string> what stops the scripts on this platform, empty when nothing does
 */
function j2rRequirementProblems(string $phpVersion, array $loaded): array
{
    $problems = [];
    if (version_compare($phpVersion, J2R_MIN_PHP_VERSION, '<')) {
        $problems[] = sprintf('PHP %s or newer is required; this is PHP %s.', J2R_MIN_PHP_VERSION, $phpVersion);
    }
    $missing = array_values(array_diff(J2R_REQUIRED_EXTENSIONS, array_map('strtolower', $loaded)));
    if ($missing !== []) {
        $problems[] = sprintf(
            'Missing PHP extension(s): %s (Debian/Ubuntu packages php8.x-mysql, -mbstring, -xml, -curl).',
            implode(', ', $missing)
        );
    }

    return $problems;
}

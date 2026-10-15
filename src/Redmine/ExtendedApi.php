<?php

declare(strict_types=1);

namespace Jira2Redmine\Redmine;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * The redmine_extended_api helpers the scripts 04 to 12 each carried as their own copy (plan M1): whether to use it,
 * its prefix and paths, the availability check and the error summary. Moved here unchanged.
 */
final class ExtendedApi
{
    /**
     * The query of a write the migration makes after an issue exists, without notes: 10's second pass (description,
     * parent, an open parent), 12's relations, 13's tags and the relink (descriptions and comments). notify=false: no
     * mail and no Redmine 7 webhook. skip_journal (redmine_extended_api 8e7096c or later; Jan, decision 37): no journal,
     * no "Subtask added" on a parent, every issue the request saves keeps its updated time unless the request sends
     * its own, and an edited comment keeps its times (not marked "Edited"). Never with notes: the plugin refuses an
     * issue update with notes and the flag (422), so 11's comments go without it.
     */
    public const NO_TRACE = ['notify' => 'false', 'send_notification' => 0, 'skip_journal' => 1];

    /**
     * @param array<string, mixed> $redmineConfig
     */
    public static function enabled(array $redmineConfig, bool $cliFlag): bool
    {
        if ($cliFlag) {
            return true;
        }

        $extendedConfig = isset($redmineConfig['extended_api']) && is_array($redmineConfig['extended_api'])
            ? $redmineConfig['extended_api']
            : [];

        return (bool)($extendedConfig['enabled'] ?? false);
    }

    /**
     * @param array<string, mixed> $redmineConfig
     */
    public static function prefix(array $redmineConfig): string
    {
        $extendedConfig = isset($redmineConfig['extended_api']) && is_array($redmineConfig['extended_api'])
            ? $redmineConfig['extended_api']
            : [];

        $prefix = isset($extendedConfig['prefix']) ? trim((string)$extendedConfig['prefix']) : '/extended_api';

        if ($prefix === '') {
            $prefix = '/extended_api';
        }

        return trim($prefix, '/');
    }

    /** A path relative to the Redmine base URL, so a Redmine under a sub-path keeps it (F6). */
    public static function path(string $prefix, string $resource): string
    {
        $normalizedPrefix = trim($prefix, '/');
        $normalizedResource = ltrim($resource, '/');

        if ($normalizedPrefix === '') {
            return $normalizedResource;
        }

        return $normalizedPrefix . '/' . $normalizedResource;
    }

    public static function verifyAvailable(Client $client, string $prefix, string $resource): void
    {
        $path = self::path($prefix, $resource);

        try {
            $response = $client->get($path);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            throw new RuntimeException(
                sprintf(
                    'Extended API availability check failed (%s): HTTP %d %s',
                    $path,
                    $response->getStatusCode(),
                    $response->getReasonPhrase()
                ),
                0,
                $exception
            );
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Failed to reach the extended API: ' . $exception->getMessage(), 0, $exception);
        }

        $header = $response->getHeaderLine('X-Redmine-Extended-API');
        if (trim($header) === '') {
            throw new RuntimeException('Extended API response missing X-Redmine-Extended-API header. Verify the plugin installation.');
        }
    }

    public static function summarizeError(Throwable $exception): string
    {
        if ($exception instanceof BadResponseException) {
            $response = $exception->getResponse();
            $message = sprintf('HTTP %d %s', $response->getStatusCode(), $response->getReasonPhrase());
            $details = self::errorDetails($response);
            if ($details !== null) {
                $message .= ': ' . $details;
            }

            return $message;
        }

        return $exception->getMessage();
    }

    public static function errorDetails(ResponseInterface $response): ?string
    {
        $body = trim((string)$response->getBody());
        if ($body === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $body;
        }

        if (is_array($decoded)) {
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                return implode('; ', array_map('strval', $decoded['errors']));
            }

            if (isset($decoded['error']) && is_string($decoded['error'])) {
                return $decoded['error'];
            }
        }

        return $body;
    }
}

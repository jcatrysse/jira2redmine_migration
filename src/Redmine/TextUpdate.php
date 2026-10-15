<?php

declare(strict_types=1);

namespace Jira2Redmine\Redmine;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Updates of an issue or a journal after the import: 13's tags on Redmine 7.0 and tools/relink_issue_links.php.
 * Jan (2026-10-15): with the Extended API they go through it with notify=false, like the import itself, so no mail
 * and no Redmine 7 webhook leaves from them (its proxy serves the core routes under its prefix). Without it, the
 * plain core route, as before. With the Extended API an issue keeps its updated time when the caller passes it
 * (Jan, 2026-10-15: post-processing leaves no visible trace); the journal Redmine writes for the change stays.
 */
final class TextUpdate
{
    public const SILENT = ['notify' => 'false', 'send_notification' => 0];

    /**
     * @param array<string, mixed> $attributes the issue attributes to update
     * @param bool $absolute the plain route with a leading slash (the relink tool's paths) instead of a relative one
     * @param string|null $keepUpdatedOn the issue's updated time as Redmine has it, to keep (Extended API only)
     * @throws GuzzleException
     */
    public static function issue(
        ClientInterface $client,
        int $issueId,
        array $attributes,
        ?string $extendedApiPrefix,
        bool $absolute = true,
        ?string $keepUpdatedOn = null
    ): void {
        if ($extendedApiPrefix !== null && $keepUpdatedOn !== null && $keepUpdatedOn !== '') {
            $attributes['updated_on'] = $keepUpdatedOn;
        }
        self::put($client, sprintf('issues/%d.json', $issueId), ['issue' => $attributes], $extendedApiPrefix, $absolute);
    }

    /**
     * @throws GuzzleException
     */
    public static function journalNotes(ClientInterface $client, int $journalId, string $notes, ?string $extendedApiPrefix): void
    {
        self::put($client, sprintf('journals/%d.json', $journalId), ['journal' => ['notes' => $notes]], $extendedApiPrefix, true);
    }

    /**
     * @param array<string, mixed> $body
     * @throws GuzzleException
     */
    private static function put(ClientInterface $client, string $resource, array $body, ?string $extendedApiPrefix, bool $absolute): void
    {
        if ($extendedApiPrefix === null) {
            $client->request('PUT', ($absolute ? '/' : '') . $resource, ['json' => $body]);

            return;
        }
        $client->request('PUT', ExtendedApi::path($extendedApiPrefix, $resource), ['json' => $body, 'query' => self::SILENT]);
    }
}

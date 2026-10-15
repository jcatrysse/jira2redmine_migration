<?php

declare(strict_types=1);

namespace Jira2Redmine\Redmine;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Adds tags to a Redmine issue on 5.1 and 7.0 alike (B19). Redmine 5.1 with redmine_tags (GEOxyz) has
 * POST /issues/:id/tags.json, which adds tags without a journal; the redmine70-migration branch of
 * redmine_tags dropped that route, and tags go through the issue update ("add_tag_list"), which records
 * a journal detail. The first 404 of the route switches to the issue update for the rest of the run.
 * With the Extended API (Jan, 2026-10-15) the issue update goes through it with notify=false, like 10 and 11:
 * a plain issue update mails the watchers and fires Redmine 7's webhooks.
 */
final class IssueTags
{
    public const ENDPOINT_TAGS = 'tags';
    public const ENDPOINT_ISSUE = 'issue';

    /**
     * @param list<string> $tags
     * @param string|null $endpoint the endpoint that worked before, null on the first call
     * @param string|null $extendedApiPrefix the Extended API's prefix when it is enabled, for the issue update
     * @return string the endpoint that worked, to pass to the next call
     * @throws GuzzleException
     */
    public static function push(
        ClientInterface $client,
        int $issueId,
        array $tags,
        ?string $endpoint,
        ?string $extendedApiPrefix = null
    ): string {
        if ($endpoint !== self::ENDPOINT_ISSUE) {
            try {
                $client->request('POST', sprintf('issues/%d/tags.json', $issueId), ['json' => ['tags' => $tags]]);

                return self::ENDPOINT_TAGS;
            } catch (BadResponseException $exception) {
                if ($endpoint === self::ENDPOINT_TAGS || $exception->getResponse()->getStatusCode() !== 404) {
                    throw $exception;
                }
                // The route or the issue is missing; the issue update 404s only for a missing issue.
            }
        }

        $keepUpdatedOn = null;
        if ($extendedApiPrefix !== null) {
            // the issue keeps its updated time (Jan, 2026-10-15)
            $current = json_decode((string)$client->request('GET', sprintf('issues/%d.json', $issueId))->getBody(), true);
            $updatedOn = is_array($current) ? ($current['issue']['updated_on'] ?? null) : null;
            $keepUpdatedOn = is_string($updatedOn) ? $updatedOn : null;
        }
        TextUpdate::issue($client, $issueId, ['add_tag_list' => implode(',', $tags)], $extendedApiPrefix, false, $keepUpdatedOn);

        return self::ENDPOINT_ISSUE;
    }
}

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
 */
final class IssueTags
{
    public const ENDPOINT_TAGS = 'tags';
    public const ENDPOINT_ISSUE = 'issue';

    /**
     * @param list<string> $tags
     * @param string|null $endpoint the endpoint that worked before, null on the first call
     * @return string the endpoint that worked, to pass to the next call
     * @throws GuzzleException
     */
    public static function push(ClientInterface $client, int $issueId, array $tags, ?string $endpoint): string
    {
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

        $client->request('PUT', sprintf('issues/%d.json', $issueId), [
            'json' => ['issue' => ['add_tag_list' => implode(',', $tags)]],
        ]);

        return self::ENDPOINT_ISSUE;
    }
}

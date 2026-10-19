<?php

declare(strict_types=1);

namespace Jira2Redmine\History;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Jira2Redmine\Redmine\ExtendedApi;
use JsonException;
use RuntimeException;

/**
 * The two requests of the history step (decision 41): jira-history.json goes up through the Extended API's upload,
 * which takes its author and time, and one POST per issue writes the journals and attaches the file. The answer must
 * name one journal id per journal sent, in order, or the issue is not marked pushed.
 *
 * @phpstan-import-type Body from HistoryRequest
 */
final class HistoryPush
{
    /**
     * @param array{author_id: int, created_on: string} $stamp
     * @throws GuzzleException
     * @throws JsonException
     */
    public static function upload(ClientInterface $client, string $prefix, string $content, array $stamp): string
    {
        $query = http_build_query([
            'filename' => HistoryFile::FILENAME,
            'attachment[author_id]' => $stamp['author_id'],
            'attachment[created_on]' => $stamp['created_on'],
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $client->request('POST', ExtendedApi::path($prefix, 'uploads.json') . '?' . $query, [
            'headers' => ['Content-Type' => 'application/octet-stream'],
            'body' => $content,
        ]);
        $answer = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $token = is_array($answer) ? ($answer['upload']['token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('The upload of jira-history.json returned no token.');
        }

        return $token;
    }

    /**
     * @param Body $body
     * @return array{journal_ids: list<int>, attachment_id: ?int}
     * @throws GuzzleException
     * @throws JsonException
     */
    public static function send(ClientInterface $client, string $prefix, int $redmineIssueId, array $body): array
    {
        $response = $client->request('POST', ExtendedApi::path($prefix, HistoryRequest::path($redmineIssueId)), ['json' => $body]);
        $answer = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $ids = is_array($answer) ? HistoryRequest::journalIds($answer) : null;
        if ($ids === null || count($ids) !== count($body['journals'])) {
            throw new RuntimeException(sprintf(
                'The history endpoint answered %d without one journal id per journal sent (%d).',
                $response->getStatusCode(),
                count($body['journals'])
            ));
        }

        return ['journal_ids' => $ids, 'attachment_id' => is_array($answer) ? HistoryRequest::attachmentId($answer) : null];
    }
}

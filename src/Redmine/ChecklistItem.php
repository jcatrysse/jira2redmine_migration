<?php

declare(strict_types=1);

namespace Jira2Redmine\Redmine;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * One checklist item of 15 (redmine_checklists). Its subject has a maximum length that differs per version: 512 on the
 * 5.1 plugin, 1,000 on the 7.0 branch. A longer Jira item was refused with 422, and 15 then stopped the issue's
 * checklist there, so the items after it were lost too (DOC-292 on 5.1, one item of 520 characters, B80). Refused as
 * too long, the item is posted again cut to the maximum Redmine names in its error, whatever its language.
 */
final class ChecklistItem
{
    private const ELLIPSIS = '…';

    /**
     * @return bool true when the subject had to be shortened
     * @throws GuzzleException
     */
    public static function post(ClientInterface $client, int $issueId, string $subject, bool $isDone): bool
    {
        $path = sprintf('/issues/%d/checklists.json', $issueId);
        try {
            $client->request('POST', $path, ['json' => self::body($subject, $isDone)]);

            return false;
        } catch (BadResponseException $exception) {
            $maximum = $exception->getResponse()->getStatusCode() === 422
                ? self::maximumFrom((string)$exception->getResponse()->getBody(), mb_strlen($subject))
                : null;
            if ($maximum === null) {
                throw $exception;
            }
        }
        $client->request('POST', $path, ['json' => self::body(self::shortened($subject, $maximum), $isDone)]);

        return true;
    }

    /**
     * The maximum a "too long" error names: the first number in it below the subject's length ("Subject is too long
     * (maximum is 512 characters)", or the same in the API user's language); null when there is none.
     */
    public static function maximumFrom(string $errorBody, int $length): ?int
    {
        preg_match_all('/\d+/', $errorBody, $matches);
        foreach ($matches[0] as $number) {
            if ((int)$number > 0 && (int)$number < $length) {
                return (int)$number;
            }
        }

        return null;
    }

    public static function shortened(string $subject, int $maximum): string
    {
        return rtrim(mb_substr($subject, 0, max(0, $maximum - 1))) . self::ELLIPSIS;
    }

    /**
     * @return array{checklist: array{subject: string, is_done: bool}}
     */
    private static function body(string $subject, bool $isDone): array
    {
        return ['checklist' => ['subject' => $subject, 'is_done' => $isDone]];
    }
}

<?php

declare(strict_types=1);

namespace Jira2Redmine\Attachments;

/**
 * A failed download is usually transient (a connection reset while several files stream): on 7.0, 2 of
 * 576 failed during a run next to a second run, the transform requeued them without a reason and 10
 * then held their issue back (B33). The pull now tries the failed files once more before it ends.
 */
final class PullRetry
{
    public const PASSES = 2;

    /**
     * @param array{queued: int, downloaded: int, failed: int} $summary of the pass just done
     */
    public static function anotherPass(array $summary, ?int $downloadLimit, int $passesDone): bool
    {
        return $summary['failed'] > 0 && $downloadLimit === null && $passesDone < self::PASSES;
    }
}

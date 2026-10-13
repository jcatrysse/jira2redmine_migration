<?php

declare(strict_types=1);

namespace Jira2Redmine\Attachments;

/**
 * Test option attachments.test_sample (Jan, 2026-10-13, for the SURV volume run; off by default): every
 * attachment up to 512 KB, plus per file extension the largest file up to 20 MB. The rest keeps its
 * staging row but is not downloaded or uploaded, marked "skipped by sample"; the verify tool expects
 * those files to be absent. A fixed rule, so a rerun selects the same files.
 */
final class AttachmentSample
{
    public const SMALL_MAX_BYTES = 512 * 1024;
    public const LARGEST_PER_EXTENSION_MAX_BYTES = 20 * 1024 * 1024;
    public const NOTE = 'skipped by sample';

    /**
     * @param iterable<array{id: string, filename: string, size: int}> $attachments
     * @return array<string, true> the selected Jira attachment ids
     */
    public static function select(iterable $attachments): array
    {
        $selected = [];
        /** @var array<string, array{id: string, size: int}> $largest */
        $largest = [];
        foreach ($attachments as $attachment) {
            $id = (string)$attachment['id'];
            $size = (int)$attachment['size'];
            if ($size <= self::SMALL_MAX_BYTES) {
                $selected[$id] = true;
            }
            if ($size > self::LARGEST_PER_EXTENSION_MAX_BYTES) {
                continue;
            }
            $extension = self::extension((string)$attachment['filename']);
            $current = $largest[$extension] ?? null;
            if (
                $current === null
                || $size > $current['size']
                || ($size === $current['size'] && strnatcmp($id, $current['id']) < 0)
            ) {
                $largest[$extension] = ['id' => $id, 'size' => $size];
            }
        }
        foreach ($largest as $candidate) {
            $selected[$candidate['id']] = true;
        }

        return $selected;
    }

    /**
     * Lower-case extension without the dot; '' for a name without one.
     */
    public static function extension(string $filename): string
    {
        $base = basename(str_replace('\\', '/', trim($filename)));
        $dot = strrpos($base, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($base) - 1) {
            return '';
        }

        return mb_strtolower(substr($base, $dot + 1), 'UTF-8');
    }
}

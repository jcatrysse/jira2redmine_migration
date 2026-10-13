<?php

declare(strict_types=1);

namespace Jira2Redmine\Attachments;

/**
 * Redmine file names of migrated attachments (B14, decided by Jan 2026-10-13).
 *
 * Redmine resolves an inline image by file name and takes the latest attachment with that name, so two
 * attachments called "image.png" on one issue made an inline reference show the wrong file (measured on
 * 7.0). Rule: the original name, and "{jiraId}__name" only for an attachment whose name already exists
 * on the same issue (issue or journals; the lowest Jira id keeps the plain name). The same input always
 * gives the same names, so a rerun keeps them.
 *
 * Inside the pipeline the scripts mark attachments with the token "{jiraId}__{sanitised name}"; rewrite()
 * replaces those tokens in a converted text by the final names, as the last step.
 */
final class AttachmentNames
{
    /**
     * Redmine's Attachment#sanitize_filename (5.1 and 7.0): drop a path, replace / ? % * : | " ' < > and
     * line breaks by "_".
     */
    public static function redmineSanitize(string $filename): string
    {
        $name = preg_replace('/\A.*[\\\\\/]/s', '', $filename) ?? $filename;

        return preg_replace('/[\/?%*:|"\'<>\n\r]+/', '_', $name) ?? $name;
    }

    /**
     * The token the scripts use inside the pipeline (09/10/11 sanitizeAttachmentFileName).
     */
    public static function token(string $jiraId, string $filename): string
    {
        $source = $filename !== '' ? $filename : 'attachment-' . $jiraId;
        $sanitized = trim((string)preg_replace('/[^A-Za-z0-9._-]/u', '_', $source), '_');

        return $jiraId . '__' . ($sanitized !== '' ? $sanitized : 'attachment');
    }

    /**
     * @param array<int|string, string> $attachments Jira attachment id => original file name, of one issue
     * @return array<int|string, string> Jira attachment id => Redmine file name
     */
    public static function assign(array $attachments): array
    {
        $ids = array_map('strval', array_keys($attachments));
        usort($ids, static fn (string $a, string $b): int => strnatcmp($a, $b));
        $taken = [];
        $names = [];
        foreach ($ids as $id) {
            $name = self::redmineSanitize(trim((string)$attachments[$id]));
            if ($name === '') {
                $name = 'attachment-' . $id;
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($taken[$key])) {
                $name = $id . '__' . $name;
                $key = mb_strtolower($name, 'UTF-8');
            }
            $taken[$key] = true;
            $names[$id] = $name;
        }

        return $names;
    }

    /**
     * Replaces the pipeline tokens of these attachments by their Redmine names: link targets are URL
     * encoded, attachment: links quoted when the name has a space.
     *
     * @param array<int|string, string> $attachments Jira attachment id => original file name, of one issue
     */
    public static function rewrite(string $text, array $attachments): string
    {
        if ($text === '' || $attachments === []) {
            return $text;
        }
        $final = self::assign($attachments);
        // Longest token first, so "12__a.png" does not eat into "12__a.png.zip".
        $tokens = [];
        foreach ($final as $id => $name) {
            $tokens[self::token((string)$id, (string)$attachments[$id])] = $name;
        }
        uksort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($tokens as $token => $name) {
            $quoted = preg_quote($token, '/');
            // Markdown link or image target: ](token) or ](token "title")
            $text = preg_replace_callback(
                '/\]\(\s*' . $quoted . '(?=[\s)])/',
                static fn (): string => '](' . rawurlencode($name),
                $text
            ) ?? $text;
            // attachment:token
            $text = preg_replace_callback(
                '/attachment:' . $quoted . '(?![A-Za-z0-9_-])(?!\.[A-Za-z0-9])/',
                static fn (): string => 'attachment:'
                    . (preg_match('/[\s"]/', $name) === 1 ? '"' . str_replace('"', '_', $name) . '"' : $name),
                $text
            ) ?? $text;
            // src="token" / href="token" in HTML left in the text
            $text = preg_replace_callback(
                '/(src|href)="' . $quoted . '"/',
                static fn (array $m): string => $m[1] . '="' . rawurlencode($name) . '"',
                $text
            ) ?? $text;
        }

        return $text;
    }
}

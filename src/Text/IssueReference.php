<?php

declare(strict_types=1);

namespace Jira2Redmine\Text;

/**
 * A link to a migrated Jira issue becomes Redmine's issue reference "#123". A link with its own text
 * ("[Howto record raw data](.../browse/ABC-12)", common in the WIKI project) kept only "#123" and lost
 * the text; it now reads "Howto record raw data (#123)". A link whose text is the key, the URL or an
 * icon stays "#123": Redmine shows the subject on hover.
 */
final class IssueReference
{
    /** The Jira issue key a link points to: /browse/KEY or a board link with selectedIssue=KEY. */
    public static function keyOfUrl(string $url): ?string
    {
        if (preg_match('~/browse/([A-Z][A-Z0-9_]*-[0-9]+)~i', $url, $m) === 1) {
            return strtoupper($m[1]);
        }
        if (preg_match('/[?&]selectedIssue=([A-Z][A-Z0-9_]*-[0-9]+)/i', $url, $m) === 1) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /**
     * forLink() in running text: $after is the letters right after the link. Redmine links "#123" only when no
     * letter follows ("(#461)which", "comment f (#1015)rom" stayed text, WIKI-42, WIKI-594). A link that ends
     * inside a word ("[comment f](...)rom") takes the rest of the word into its text; otherwise a space goes
     * between the reference and the next word.
     */
    public static function inText(string $label, string $after, string $jiraKey, int $redmineId): string
    {
        if ($after === '') {
            return self::forLink($label, $jiraKey, $redmineId);
        }
        if (preg_match('/\s$/u', $label) !== 1) {
            $text = self::forLink($label . $after, $jiraKey, $redmineId);

            return $text === '#' . $redmineId ? $text . ' ' . $after : $text;
        }

        return self::forLink($label, $jiraKey, $redmineId) . ' ' . $after;
    }

    public static function forLink(string $label, string $jiraKey, int $redmineId): string
    {
        $reference = '#' . $redmineId;
        // issue type icons inside the link text are left out, as everywhere else
        $text = trim(preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $label) ?? $label);
        $plain = trim(str_replace(['\\', '*', '_', '`'], '', $text));
        if ($plain === '' || strcasecmp($plain, $jiraKey) === 0 || preg_match('~^(?:https?://|www\.)~i', $plain) === 1) {
            return $reference;
        }

        return $text . ' (' . $reference . ')';
    }
}

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

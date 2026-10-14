<?php

declare(strict_types=1);

namespace Jira2Redmine\Text;

/**
 * B52 (decided overnight 2026-10-14, for Jan to confirm): a link to an issue that a later batch migrates
 * stays a Jira link. After the last batch an opt-in step rewrites such links in the descriptions and
 * comments already in Redmine, only where Redmine's text is still what the migration pushed (the hash
 * 10 and 11 store at the push): a text someone edited in Redmine is left alone and listed.
 */
final class Relink
{
    /** Hash of a text as Redmine keeps it: Redmine stores line ends as \r\n; surrounding blanks do not count. */
    public static function hash(?string $text): string
    {
        return hash('sha256', trim(str_replace(["\r\n", "\r"], "\n", (string)$text)));
    }

    /**
     * @param callable(string): string $rewrite the link rewrite with the current issue map
     * @return array{action: string, text: string} action: relink, nothing (no link to rewrite), edited
     *     (changed in Redmine since the push), unknown (no hash stored: pushed before this step existed)
     */
    public static function decide(?string $current, ?string $pushedHash, callable $rewrite): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string)$current);
        if ($pushedHash === null || $pushedHash === '') {
            return ['action' => 'unknown', 'text' => $text];
        }
        if (!hash_equals($pushedHash, self::hash($text))) {
            return ['action' => 'edited', 'text' => $text];
        }
        $rewritten = $rewrite($text);

        return ['action' => $rewritten === $text ? 'nothing' : 'relink', 'text' => $rewritten];
    }

    /**
     * The check after the relink (Jan, B52: batches plus relink): does this text, as Redmine has it now, still hold
     * a Jira link to a migrated issue? Whatever its hash says, so an edited text with such a link is counted too.
     *
     * @param callable(string): string $rewrite the link rewrite with the current issue map
     */
    public static function linkLeft(?string $current, callable $rewrite): bool
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string)$current);

        return $rewrite($text) !== $text;
    }
}

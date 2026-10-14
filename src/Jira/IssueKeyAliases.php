<?php

declare(strict_types=1);

namespace Jira2Redmine\Jira;

/**
 * B54 (decided overnight 2026-10-14, for Jan to confirm): Jira keeps old issue keys working. A renamed
 * project keeps the numbers (DATA-12 is GEOS-12, GEOS was once DATA); an issue moved to another project
 * gets a new number (ABC-7 became XYZ-31). Texts keep the old keys in their links, and the rewrite only
 * knew the current keys, so such links stayed Jira links. 10 and 11 ask Jira once per unknown key for its
 * current key (GET issue/{key}?fields=key) and keep it; the link map then holds the old key too.
 */
final class IssueKeyAliases
{
    /** @return list<string> upper-case issue keys of the Jira issue links in a text */
    public static function keys(string $text): array
    {
        preg_match_all('~/browse/([A-Za-z][A-Za-z0-9_]*-\d+)~', $text, $matches);

        return array_values(array_unique(array_map('strtoupper', $matches[1])));
    }

    /**
     * @param array<string, int> $issueMap upper-case Jira key => Redmine issue id
     * @param array<string, string> $aliases old key => current key, as Jira resolved them
     * @return array<string, int> the map with each old key next to its current key's issue
     */
    public static function extend(array $issueMap, array $aliases): array
    {
        foreach ($aliases as $old => $current) {
            $old = strtoupper($old);
            $current = strtoupper($current);
            if ($old !== $current && isset($issueMap[$current]) && !isset($issueMap[$old])) {
                $issueMap[$old] = $issueMap[$current];
            }
        }

        return $issueMap;
    }
}

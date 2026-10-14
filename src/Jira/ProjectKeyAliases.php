<?php

declare(strict_types=1);

namespace Jira2Redmine\Jira;

/**
 * B54: a project whose key changed keeps its issue numbers (DATA-12 became GEOS-12) and Jira still
 * resolves the old key, so texts keep links like ".../browse/DATA-12". The link rewrite only knew the
 * current keys, so such links stayed Jira links (3 GEOS comments). The issue map now also holds each
 * migrated issue under its project's former keys.
 */
final class ProjectKeyAliases
{
    /** @return list<string> upper-case project keys of the Jira issue links in a text */
    public static function prefixes(string $text): array
    {
        preg_match_all('~/browse/([A-Za-z][A-Za-z0-9_]*)-\d+~', $text, $matches);

        return array_values(array_unique(array_map('strtoupper', $matches[1])));
    }

    /**
     * @param array<string, int> $issueMap upper-case Jira key ("GEOS-12") => Redmine issue id
     * @param array<string, string> $aliases former project key => current key ("DATA" => "GEOS")
     * @return array<string, int> the map with "DATA-12" next to "GEOS-12"
     */
    public static function extend(array $issueMap, array $aliases): array
    {
        if ($aliases === []) {
            return $issueMap;
        }
        $byProject = [];
        foreach ($aliases as $old => $current) {
            $byProject[strtoupper($current)][] = strtoupper($old);
        }
        $extended = $issueMap;
        foreach ($issueMap as $key => $id) {
            $dash = strrpos($key, '-');
            if ($dash === false) {
                continue;
            }
            foreach ($byProject[substr($key, 0, $dash)] ?? [] as $old) {
                $extended[$old . substr($key, $dash)] ??= $id;
            }
        }

        return $extended;
    }
}

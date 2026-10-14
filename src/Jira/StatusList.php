<?php

declare(strict_types=1);

namespace Jira2Redmine\Jira;

/**
 * 05 read the Jira statuses from GET /rest/api/3/status only. That list leaves out statuses Jira's status
 * search shows (25 of 64 on 2026-10-14, among them 9 of FPS's workflow): they had no Redmine status, and
 * the workflow proposal dropped every transition to or from them (B57). 05 now also reads the paged
 * GET /rest/api/3/statuses/search and adds what the list lacks, in the list's shape, when a staged project
 * uses it (GET /rest/api/3/statuses/{id}/projectUsages): the other 16 of the 25 belong to no project, and
 * 05 would create every staged status in Redmine.
 */
final class StatusList
{
    public const LIST_PATH = '/rest/api/3/status';
    public const SEARCH_PATH = '/rest/api/3/statuses/search';
    public const SEARCH_PAGE_SIZE = 200;
    public const PROJECT_USAGES_PATH = '/rest/api/3/statuses/%s/projectUsages';

    /** The search names a category (TODO, IN_PROGRESS, DONE); the list gives its key (new, indeterminate, done). */
    private const CATEGORY_KEYS = ['TODO' => 'new', 'IN_PROGRESS' => 'indeterminate', 'DONE' => 'done'];

    /**
     * The values of every page of the status search.
     *
     * @param callable(int): array<mixed> $page the decoded answer for a startAt
     * @return list<mixed>
     */
    public static function searchAll(callable $page): array
    {
        $values = [];
        $startAt = 0;
        do {
            $payload = $page($startAt);
            $received = isset($payload['values']) && is_array($payload['values']) ? array_values($payload['values']) : [];
            $values = array_merge($values, $received);
            $startAt = Paging::nextStartAt($payload, $startAt, count($received));
        } while ($startAt !== null);

        return $values;
    }

    /**
     * The ids of the projects that use a status, over every page of its project usages.
     *
     * @param callable(?string): array<mixed> $page the decoded answer for a nextPageToken (null: the first page)
     * @return list<string>
     */
    public static function projectIds(callable $page): array
    {
        $ids = [];
        $token = null;
        do {
            $payload = $page($token);
            $projects = isset($payload['projects']) && is_array($payload['projects']) ? $payload['projects'] : [];
            $values = isset($projects['values']) && is_array($projects['values']) ? $projects['values'] : [];
            foreach ($values as $project) {
                if (is_array($project) && isset($project['id']) && is_scalar($project['id'])) {
                    $ids[(string)$project['id']] = (string)$project['id'];
                }
            }
            $next = $projects['nextPageToken'] ?? null;
            $token = is_string($next) && $next !== '' && $next !== $token ? $next : null;
        } while ($token !== null);

        return array_values($ids);
    }

    /**
     * The statuses of the search the list lacks and one of the given projects uses, in the list's shape.
     *
     * @param array<mixed> $listed the answer of GET /rest/api/3/status
     * @param array<mixed> $searched the values of every page of GET /rest/api/3/statuses/search
     * @param callable(string): list<string> $projectsOf the ids of the projects that use a status
     * @param list<string> $projectIds the staged projects
     * @return array{used: list<array<mixed>>, unused: int}
     */
    public static function missingUsed(array $listed, array $searched, callable $projectsOf, array $projectIds): array
    {
        $listedIds = array_map(fn (array $status): string => (string)$status['id'], self::merge($listed, []));
        $projects = array_fill_keys($projectIds, true);
        $used = [];
        $unused = 0;
        foreach (self::merge($listed, $searched) as $status) {
            $id = (string)$status['id'];
            if (in_array($id, $listedIds, true)) {
                continue;
            }
            if ($projects === [] || array_intersect_key(array_fill_keys($projectsOf($id), true), $projects) === []) {
                $unused++;
                continue;
            }
            $used[] = $status;
        }

        return ['used' => $used, 'unused' => $unused];
    }

    /**
     * The statuses of the list, then those only the search has; each id once.
     *
     * @param array<mixed> $listed the answer of GET /rest/api/3/status
     * @param array<mixed> $searched the values of every page of GET /rest/api/3/statuses/search
     * @return list<array<mixed>>
     */
    public static function merge(array $listed, array $searched): array
    {
        $statuses = [];
        $seen = [];
        foreach ([[$listed, false], [$searched, true]] as [$source, $fromSearch]) {
            foreach ($source as $status) {
                if (!is_array($status) || !isset($status['id']) || !is_scalar($status['id'])) {
                    continue;
                }
                $id = (string)$status['id'];
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $statuses[] = $fromSearch ? self::fromSearch($status) : $status;
            }
        }

        return $statuses;
    }

    /**
     * A status of the search in the list's shape: its category as {key, name}, the key as the list gives it.
     *
     * @param array<mixed> $status
     * @return array<mixed>
     */
    public static function fromSearch(array $status): array
    {
        $category = $status['statusCategory'] ?? null;
        if (is_string($category) && $category !== '') {
            $status['statusCategory'] = [
                'key' => self::CATEGORY_KEYS[strtoupper($category)] ?? strtolower($category),
                'name' => $category,
            ];
        }

        return $status;
    }
}

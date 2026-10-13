<?php

declare(strict_types=1);

/**
 * Pure comparison helpers for tools/verify.php (Jira against Redmine after a run).
 * No I/O here, so every rule is unit tested in tests/VerifyTest.php.
 */

/**
 * Plain text of Jira's rendered HTML (renderedFields.description, renderedBody of a comment).
 */
function verifyHtmlToText(?string $html): string
{
    if ($html === null || trim($html) === '') {
        return '';
    }

    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    // A mention is a chip in Jira: "<a class="user-hover">Jane Doe</a>thanks" reads as two words.
    $html = preg_replace('#(<a\b[^>]*\buser-hover\b[^>]*>.*?</a>)#is', ' $1 ', $html) ?? $html;
    // Block elements and line breaks separate words.
    $html = preg_replace('#<(br|/p|/div|/li|/tr|/td|/th|/h[1-6]|/pre|/blockquote)\b[^>]*>#i', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return verifyDropJiraBrowseUrls($text);
}

/**
 * Links to Jira issues (".../browse/ABC-12...") are rewritten to Redmine issue links (#123) by design;
 * they are left out of the word comparison on both sides.
 */
function verifyDropJiraBrowseUrls(string $text): string
{
    return preg_replace('#https?://[^\s"<>()\[\]]+/browse/[A-Z][A-Z0-9_]*-\d+[^\s"<>()\[\]]*#', ' ', $text) ?? $text;
}

/**
 * Plain text of Redmine Markdown (CommonMark/GFM as the scripts write it), close enough to compare words.
 * Redmine renders user#123 and @login as the user's name; $userName (id or login => name, null if unknown)
 * does the same here.
 *
 * @param null|callable(string): ?string $userName
 */
function verifyMarkdownToText(?string $markdown, ?callable $userName = null): string
{
    if ($markdown === null || trim($markdown) === '') {
        return '';
    }

    // Code is text: keep "<depth>" in a code block from being stripped as a tag.
    $text = preg_replace_callback(
        '/(```|~~~)[^\n]*\n(.*?)\1|`([^`\n]+)`/s',
        static fn (array $m): string => ' ' . htmlspecialchars((string)($m[2] ?? $m[3] ?? ''), ENT_QUOTES, 'UTF-8') . ' ',
        $markdown,
        -1,
        $count,
        PREG_UNMATCHED_AS_NULL
    ) ?? $markdown;
    if ($userName !== null) {
        $text = preg_replace_callback(
            '/\buser#(\d+)|(?<![\w@])@([A-Za-z0-9_.@-]*[A-Za-z0-9_])/u',
            static function (array $m) use ($userName): string {
                $name = $userName((string)($m[1] ?? $m[2]));

                return $name ?? (string)$m[0];
            },
            $text,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL
        ) ?? $text;
    }
    // Images keep their alt text, links keep their label.
    $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', ' $1 ', $text) ?? $text;
    $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', ' $1 ', $text) ?? $text;
    // Autolinks are text, not tags.
    $text = preg_replace('/<((?:https?|ftp|mailto):[^>\s]+|[^\s<>@"\/]+@[^\s<>@"]+)>/iu', ' $1 ', $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return verifyDropJiraBrowseUrls($text);
}

/**
 * Words of a plain text: lower case runs of letters and digits.
 *
 * @return list<string>
 */
function verifyWords(string $text): array
{
    if (preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), $matches) === false) {
        return [];
    }

    return $matches[0];
}

/**
 * Share of the expected words found in the actual words, counting duplicates (multiset).
 * Extra actual words do not lower the score: Redmine may carry added text such as a link back to Jira.
 *
 * @param list<string> $expected
 * @param list<string> $actual
 * @return array{coverage: float, missing: list<string>}
 */
function verifyWordCoverage(array $expected, array $actual): array
{
    if ($expected === []) {
        return ['coverage' => 1.0, 'missing' => []];
    }

    $available = array_count_values($actual);
    $missing = [];
    $found = 0;
    foreach ($expected as $word) {
        if (($available[$word] ?? 0) > 0) {
            $available[$word]--;
            $found++;
            continue;
        }
        $missing[] = $word;
    }

    return ['coverage' => $found / count($expected), 'missing' => $missing];
}

/**
 * Number of links and images in Jira's rendered HTML. Links to attachments and anchors are not counted
 * as links: attachments are checked on their own.
 *
 * @return array{links: int, images: int}
 */
function verifyHtmlLinkCounts(?string $html): array
{
    if ($html === null || $html === '') {
        return ['links' => 0, 'images' => 0];
    }

    $links = 0;
    if (preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/i', $html, $matches) !== false) {
        foreach ($matches[1] as $href) {
            if ($href === '' || $href[0] === '#' || preg_match('#/(secure/attachment|rest/api/\d+/attachment)/#', $href) === 1) {
                continue;
            }
            $links++;
        }
    }

    // Jira's link icons and emoticons are not images of the text.
    $images = 0;
    if (preg_match_all('/<img\b[^>]*>/i', $html, $tags) !== false) {
        foreach ($tags[0] as $tag) {
            if (preg_match('#rendericon|emoticon|/images/icons/#i', $tag) !== 1) {
                $images++;
            }
        }
    }

    return ['links' => $links, 'images' => $images];
}

/**
 * Number of links and images in Redmine Markdown (inline, autolinks and HTML).
 *
 * @return array{links: int, images: int}
 */
function verifyMarkdownLinkCounts(?string $markdown): array
{
    if ($markdown === null || $markdown === '') {
        return ['links' => 0, 'images' => 0];
    }

    $images = preg_match_all('/!\[[^\]]*\]\([^)]*\)|<img\b/i', $markdown) ?: 0;
    $withoutImages = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $markdown) ?? $markdown;
    // Inline links, URL and e-mail autolinks, HTML anchors.
    $linkPattern = '/\[[^\]]*\]\([^)]*\)|<(?:https?|mailto):[^>]+>|<[^\s<>@"\/]+@[^\s<>@"]+>|<a\b[^>]*href=/i';
    $links = preg_match_all($linkPattern, $withoutImages) ?: 0;
    // Redmine renders these as links too: user#1, @login (mentions) and #123 (issues).
    $links += preg_match_all('/\buser#\d+|(?<![\w@\/.])@[A-Za-z0-9_.@-]*[A-Za-z0-9_]|(?<![\w&#\/])#\d+\b/', $withoutImages) ?: 0;
    $withoutLinks = preg_replace('/\[[^\]]*\]\([^)]*\)|<[^>]+>/', '', $withoutImages) ?? $withoutImages;
    $links += preg_match_all('#(?<![(\w])https?://\S+#', $withoutLinks) ?: 0;

    return ['links' => $links, 'images' => $images];
}

/**
 * Attachment name as the scripts upload it, reduced for comparison: without the "{jiraId}__" prefix that
 * keeps inline references unique, and with every character outside [A-Za-z0-9._-] as "_".
 */
function verifyNormaliseFilename(string $filename): string
{
    $filename = preg_replace('/^\d+__/', '', $filename) ?? $filename;
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? $filename;

    return trim($filename, '_');
}

/**
 * Compare two lists of [name, size] pairs as multisets.
 *
 * @param list<array{0: string, 1: int}> $expected
 * @param list<array{0: string, 1: int}> $actual
 * @return array{missing: list<string>, unexpected: list<string>}
 */
function verifyCompareFiles(array $expected, array $actual): array
{
    $key = static fn (array $file): string => $file[0] . ' (' . $file[1] . ' B)';
    $available = array_count_values(array_map($key, $actual));
    $missing = [];
    foreach ($expected as $file) {
        $k = $key($file);
        if (($available[$k] ?? 0) > 0) {
            $available[$k]--;
            continue;
        }
        $missing[] = $k;
    }
    $unexpected = [];
    foreach ($available as $k => $count) {
        for ($i = 0; $i < $count; $i++) {
            $unexpected[] = (string)$k;
        }
    }

    return ['missing' => $missing, 'unexpected' => $unexpected];
}

/**
 * Deterministic sample: every n-th id of the sorted list so the same run gives the same sample.
 *
 * @param list<int|string> $ids
 * @return list<int|string>
 */
function verifySample(array $ids, int $size): array
{
    sort($ids, SORT_NATURAL);
    $count = count($ids);
    if ($size <= 0 || $count === 0) {
        return [];
    }
    if ($size >= $count) {
        return $ids;
    }

    $sample = [];
    $step = $count / $size;
    for ($i = 0; $i < $size; $i++) {
        $sample[] = $ids[(int)floor($i * $step)];
    }

    return $sample;
}

/**
 * Parents whose Redmine status is expected to differ from their Jira status: an open subtask under a
 * closed parent gives the parent the open status "Resolved" (Jan, 2026-10-13). Found from the mapping
 * itself, a parent whose proposed status is not its mapped Jira status, because the push clears the
 * "[open-parent]" note once the issue exists (the two OAC parents failed the counts after a full run).
 *
 * @param iterable<array{jira_issue_id: string|int, jira_status_id: string|int, proposed_status_id: string|int|null,
 *     notes: ?string, is_parent: string|int|bool}> $rows migration_mapping_issues rows
 * @param array<int|string, string> $statusMap Jira status id => Redmine status id
 * @return array<string, string> Jira issue id => expected Redmine status id
 */
function verifyOpenParents(iterable $rows, array $statusMap): array
{
    $result = [];
    foreach ($rows as $row) {
        $proposed = $row['proposed_status_id'];
        if ($proposed === null || $proposed === '') {
            continue;
        }
        $marked = str_contains((string)($row['notes'] ?? ''), '[open-parent]');
        $mapped = $statusMap[(string)$row['jira_status_id']] ?? null;
        $overridden = (bool)(int)$row['is_parent'] && $mapped !== null && $mapped !== (string)$proposed;
        if ($marked || $overridden) {
            $result[(string)$row['jira_issue_id']] = (string)$proposed;
        }
    }

    return $result;
}

/**
 * Journals that exist twice on one issue: same author, same time, same text. A rerun of 11 used to push
 * every comment again (B35); the comment check still passed because each Jira comment was found.
 *
 * @param list<array<string, mixed>> $journals Redmine journals of one issue (REST include=journals)
 * @return list<string> one entry per extra copy: "journal #id duplicates #id"
 */
function verifyDuplicateJournals(array $journals): array
{
    $seen = [];
    $duplicates = [];
    foreach ($journals as $journal) {
        $notes = trim((string)($journal['notes'] ?? ''));
        $details = json_encode($journal['details'] ?? []);
        if ($notes === '' && ($journal['details'] ?? []) === []) {
            continue;
        }
        $key = implode('|', [(string)($journal['user']['id'] ?? ''), (string)($journal['created_on'] ?? ''), $notes, $details]);
        if (isset($seen[$key])) {
            $duplicates[] = sprintf('journal #%s duplicates #%s', (string)($journal['id'] ?? '?'), $seen[$key]);
            continue;
        }
        $seen[$key] = (string)($journal['id'] ?? '?');
    }

    return $duplicates;
}

/**
 * The Redmine assignee to expect: the mapped user, or for a Jira assignee without a Redmine account what
 * the B9 rule gives (the default assignee, else none). verify expected "unmapped" and failed such issues.
 *
 * @param array<int|string, string> $userMap Jira account id => Redmine user id
 */
function verifyExpectedAssignee(?string $jiraAccountId, array $userMap, ?int $defaultAssigneeId): string
{
    $mapped = $jiraAccountId !== null && isset($userMap[$jiraAccountId]) ? (int)$userMap[$jiraAccountId] : null;
    $resolved = \Jira2Redmine\Issues\AssigneeRule::resolve($jiraAccountId, $mapped, $defaultAssigneeId)['assignee'];

    return $resolved === null ? '' : (string)$resolved;
}

/**
 * Whether a Jira field value holds anything. A rich text field can hold an ADF document without text
 * (one empty paragraph): Redmine rightly stores nothing, verify counted it as a missing value.
 */
function verifyJiraValueIsEmpty(mixed $value): bool
{
    if ($value === null || $value === '' || $value === []) {
        return true;
    }
    if (is_array($value) && ($value['type'] ?? null) === 'doc' && isset($value['content'])) {
        $text = '';
        array_walk_recursive($value, static function (mixed $item, int|string $key) use (&$text): void {
            if ($key === 'text' && is_string($item)) {
                $text .= $item;
            }
        });

        return trim($text) === '';
    }

    return false;
}

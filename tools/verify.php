<?php

declare(strict_types=1);

/**
 * Verify a migration run: compare Jira (live, read-only) with Redmine for the given projects.
 *
 *   php tools/verify.php --projects=GEOS,SIS,OAC [--sample=25] [--checksum-max-bytes=5242880] [--report=FILE]
 *
 * Checks
 *   counts       per project: issues per (tracker, status), Jira types and statuses mapped through staging
 *   presence     every sampled Jira issue has a Redmine issue
 *   fields       subject, tracker, status, priority, author, assignee, parent, created date
 *   description  words of Jira's rendered description found in the Redmine Markdown (>= 98%), links, images
 *   comments     every Jira comment found in a Redmine journal note (>= 95% of its words)
 *   attachments  name and size as a multiset; sha256 of both downloads up to --checksum-max-bytes;
 *                files "skipped by sample" (attachments.test_sample) are expected absent
 *   watchers     Jira watchers (mapped users) are Redmine watchers
 *   relations    Jira links between migrated issues exist as Redmine relations (either direction)
 *   custom       a mapped custom field with a Jira value has a Redmine value
 *
 * The staging database is used only to map ids (projects, issues, users, trackers, statuses, priorities,
 * custom fields); the values compared come from the two live systems. Exit code 0 = everything passed.
 * The report holds issue keys and short details from Jira: keep it out of the repository.
 */

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

require_once __DIR__ . '/../lib/verify.php';
$config = require __DIR__ . '/../bootstrap.php';

$options = getopt('', ['projects:', 'sample::', 'checksum-max-bytes::', 'report::', 'help']);
if (isset($options['help']) || !isset($options['projects'])) {
    fwrite(STDERR, "Usage: php tools/verify.php --projects=KEY[,KEY] [--sample=25] [--checksum-max-bytes=5242880] [--report=FILE]\n");
    exit(2);
}

$projectKeys = array_values(array_filter(array_map('trim', explode(',', (string)$options['projects']))));
$sampleSize = (int)($options['sample'] ?? 25);
$checksumMax = (int)($options['checksum-max-bytes'] ?? 5 * 1024 * 1024);
$reportPath = (string)($options['report'] ?? (rtrim((string)$config['paths']['tmp'], '/') . '/verify-' . date('Ymd-His') . '.md'));

$jira = new Client([
    'base_uri' => rtrim((string)$config['jira']['base_url'], '/'),
    'handler' => jiraHandlerStack($config['jira']),
    'auth' => [(string)$config['jira']['username'], (string)$config['jira']['api_token']],
    'headers' => ['Accept' => 'application/json'],
    'timeout' => 120,
]);
$redmineBase = rtrim((string)$config['redmine']['base_url'], '/');
$redmine = new Client([
    'base_uri' => $redmineBase . '/',
    'headers' => ['Accept' => 'application/json', 'X-Redmine-API-Key' => (string)$config['redmine']['api_key']],
    'timeout' => 120,
]);
$pdo = new PDO((string)$config['database']['dsn'], (string)$config['database']['username'], (string)$config['database']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/** @return array<string, string> */
function verifyMap(PDO $pdo, string $sql): array
{
    $map = [];
    foreach ($pdo->query($sql) as $row) {
        $row = array_values($row);
        if ($row[1] !== null) {
            $map[(string)$row[0]] = (string)$row[1];
        }
    }

    return $map;
}

$maps = [
    'project' => verifyMap($pdo, 'SELECT jira_project_id, redmine_project_id FROM migration_mapping_projects'),
    'issue' => verifyMap($pdo, 'SELECT jira_issue_id, redmine_issue_id FROM migration_mapping_issues'),
    'user' => verifyMap($pdo, 'SELECT jira_account_id, redmine_user_id FROM migration_mapping_users'),
    'tracker' => verifyMap($pdo, 'SELECT jira_issue_type_id, redmine_tracker_id FROM migration_mapping_trackers'),
    'status' => verifyMap($pdo, 'SELECT jira_status_id, redmine_status_id FROM migration_mapping_statuses'),
    'priority' => verifyMap($pdo, 'SELECT jira_priority_id, redmine_priority_id FROM migration_mapping_priorities'),
];
$customFields = verifyMap($pdo, "SELECT jira_field_id, redmine_custom_field_id FROM migration_mapping_custom_fields
    WHERE migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')");
$defaultAuthor = (string)($config['migration']['issues']['default_redmine_author_id'] ?? '');
// Parents of an open subtask get an open status instead of their closed Jira status (Jan, 2026-10-13).
$openParents = verifyOpenParents($pdo->query(
    'SELECT m.jira_issue_id, m.jira_status_id, m.proposed_status_id, m.notes,
        EXISTS (SELECT 1 FROM staging_jira_issues c WHERE c.parent_issue_id = m.jira_issue_id) AS is_parent
     FROM migration_mapping_issues m'
), $maps['status']);
// Attachments left out by the test option attachments.test_sample are expected to be absent.
$skippedBySample = verifyMap($pdo, sprintf(
    "SELECT jira_attachment_id, notes FROM migration_mapping_attachments WHERE notes = '%s'",
    \Jira2Redmine\Attachments\AttachmentSample::NOTE
));

/** @return array<string, mixed> */
function verifyGetJson(Client $client, string $path, array $query = []): array
{
    $attempt = 0;
    while (true) {
        try {
            $response = $client->get($path, ['query' => $query]);

            return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $exception) {
            $code = method_exists($exception, 'getResponse') && $exception->getResponse() !== null
                ? $exception->getResponse()->getStatusCode() : 0;
            if (($code === 429 || $code >= 500) && $attempt < 5) {
                sleep(2 ** $attempt++);
                continue;
            }
            throw $exception;
        }
    }
}

function verifyDownloadHash(Client $client, string $url): string
{
    $context = hash_init('sha256');
    $body = $client->get($url, ['stream' => true, 'headers' => ['Accept' => '*/*']])->getBody();
    while (!$body->eof()) {
        hash_update($context, $body->read(65536));
    }

    return hash_final($context);
}

// Redmine users, to render user#id and @login as Redmine does.
// Per status (active, registered, locked): "status=" means all on 5.1 but is refused on 7.0.
$userNames = [];
foreach ([1, 2, 3] as $status) {
    $offset = 0;
    do {
        $page = verifyGetJson($redmine, 'users.json', ['status' => $status, 'limit' => 100, 'offset' => $offset]);
        foreach ($page['users'] ?? [] as $user) {
            $name = trim($user['firstname'] . ' ' . $user['lastname']);
            $userNames[(string)$user['id']] = $name;
            $userNames[(string)$user['login']] = $name;
        }
        $offset += 100;
    } while ($offset < (int)($page['total_count'] ?? 0));
}
$userName = static fn (string $ref): ?string => $userNames[$ref] ?? null;

$results = [];
$failures = [];
$record = static function (string $check, bool $passed, string $subject, string $detail = '') use (&$results, &$failures): void {
    $results[$check] ??= ['pass' => 0, 'fail' => 0];
    $results[$check][$passed ? 'pass' : 'fail']++;
    if (!$passed) {
        $failures[] = [$check, $subject, mb_substr($detail, 0, 240)];
    }
};

foreach ($projectKeys as $projectKey) {
    $project = verifyGetJson($jira, '/rest/api/3/project/' . rawurlencode($projectKey));
    $key = (string)$project['key'];
    $redmineProjectId = $maps['project'][(string)$project['id']] ?? null;
    fwrite(STDOUT, sprintf("[%s] %s: counting issues...\n", date('H:i:s'), $key));

    // Jira issues of the project: ids plus type and status.
    $jiraIssues = [];
    $token = null;
    do {
        $query = ['jql' => sprintf('project = "%s" ORDER BY id ASC', $key), 'fields' => 'issuetype,status', 'maxResults' => 100];
        if ($token !== null) {
            $query['nextPageToken'] = $token;
        }
        $page = verifyGetJson($jira, '/rest/api/3/search/jql', $query);
        foreach ($page['issues'] ?? [] as $issue) {
            $jiraIssues[(string)$issue['id']] = [(string)$issue['fields']['issuetype']['id'], (string)$issue['fields']['status']['id']];
        }
        $token = $page['nextPageToken'] ?? null;
    } while ($token !== null && ($page['isLast'] ?? true) === false);

    $expectedCounts = [];
    foreach ($jiraIssues as $jiraIssueId => [$typeId, $statusId]) {
        $redmineStatus = $openParents[(string)$jiraIssueId] ?? ($maps['status'][$statusId] ?? 'unmapped status ' . $statusId);
        $k = ($maps['tracker'][$typeId] ?? 'unmapped type ' . $typeId) . '/' . $redmineStatus;
        $expectedCounts[$k] = ($expectedCounts[$k] ?? 0) + 1;
    }
    $actualCounts = [];
    if ($redmineProjectId !== null) {
        $offset = 0;
        do {
            $page = verifyGetJson($redmine, 'issues.json', [
                'project_id' => $redmineProjectId, 'subproject_id' => '!*', 'status_id' => '*', 'limit' => 100, 'offset' => $offset,
            ]);
            foreach ($page['issues'] ?? [] as $issue) {
                $k = $issue['tracker']['id'] . '/' . $issue['status']['id'];
                $actualCounts[$k] = ($actualCounts[$k] ?? 0) + 1;
            }
            $offset += 100;
        } while ($offset < (int)($page['total_count'] ?? 0));
    }
    $jiraTotal = count($jiraIssues);
    $redmineTotal = array_sum($actualCounts);
    $record('counts: total', $jiraTotal === $redmineTotal, $key, sprintf('Jira %d, Redmine %d', $jiraTotal, $redmineTotal));
    foreach (array_unique(array_merge(array_keys($expectedCounts), array_keys($actualCounts))) as $k) {
        $e = $expectedCounts[$k] ?? 0;
        $a = $actualCounts[$k] ?? 0;
        $record('counts: tracker/status', $e === $a, $key . ' ' . $k, sprintf('Jira %d, Redmine %d', $e, $a));
    }

    foreach (verifySample(array_keys($jiraIssues), $sampleSize) as $jiraId) {
        $jiraId = (string)$jiraId;
        $issue = verifyGetJson($jira, '/rest/api/3/issue/' . $jiraId, ['expand' => 'renderedFields', 'fields' => '*all']);
        $issueKey = (string)$issue['key'];
        $fields = $issue['fields'];
        $redmineId = $maps['issue'][$jiraId] ?? null;
        $record('presence', $redmineId !== null, $issueKey, 'no Redmine issue in migration_mapping_issues');
        if ($redmineId === null) {
            continue;
        }
        try {
            $rm = verifyGetJson($redmine, sprintf('issues/%d.json', (int)$redmineId), ['include' => 'journals,attachments,watchers,relations'])['issue'];
        } catch (GuzzleException $exception) {
            $record('presence', false, $issueKey, 'Redmine issue #' . $redmineId . ': ' . $exception->getMessage());
            continue;
        }

        // Fields.
        $record('fields: subject', trim((string)$rm['subject']) === trim((string)$fields['summary']), $issueKey, 'subject differs');
        $record('fields: tracker', (string)$rm['tracker']['id'] === ($maps['tracker'][(string)$fields['issuetype']['id']] ?? ''), $issueKey,
            sprintf('Redmine %s, expected %s', $rm['tracker']['name'], $fields['issuetype']['name']));
        $expectedStatus = $openParents[$jiraId] ?? ($maps['status'][(string)$fields['status']['id']] ?? '');
        $record('fields: status', (string)$rm['status']['id'] === $expectedStatus, $issueKey,
            sprintf('Redmine %s, expected %s%s', $rm['status']['name'], $fields['status']['name'], isset($openParents[$jiraId]) ? ' (open parent: status #' . $expectedStatus . ')' : ''));
        if (isset($fields['priority']['id'])) {
            $expectedPriority = $maps['priority'][(string)$fields['priority']['id']] ?? '';
            $record('fields: priority', (string)$rm['priority']['id'] === $expectedPriority, $issueKey,
                sprintf('Redmine %s, expected %s (%s)', $rm['priority']['id'], $expectedPriority, $fields['priority']['name']));
        }
        $expectedAuthor = $maps['user'][(string)($fields['reporter']['accountId'] ?? '')] ?? $defaultAuthor;
        $record('fields: author', (string)$rm['author']['id'] === $expectedAuthor, $issueKey,
            sprintf('Redmine %s, expected %s', $rm['author']['id'], $expectedAuthor));
        $expectedAssignee = isset($fields['assignee']['accountId']) ? ($maps['user'][(string)$fields['assignee']['accountId']] ?? 'unmapped') : '';
        $record('fields: assignee', (string)($rm['assigned_to']['id'] ?? '') === $expectedAssignee, $issueKey,
            sprintf('Redmine %s, expected %s', $rm['assigned_to']['id'] ?? '-', $expectedAssignee ?: '-'));
        $expectedParent = isset($fields['parent']['id']) ? ($maps['issue'][(string)$fields['parent']['id']] ?? 'unmapped') : '';
        // A parent in a project outside this run cannot be linked.
        if ($expectedParent === 'unmapped' && !in_array(strtok((string)($fields['parent']['key'] ?? ''), '-'), $projectKeys, true)) {
            $expectedParent = '';
        }
        $record('fields: parent', (string)($rm['parent']['id'] ?? '') === $expectedParent, $issueKey,
            sprintf('Redmine %s, expected %s', $rm['parent']['id'] ?? '-', $expectedParent ?: '-'));
        $jiraCreated = (new DateTimeImmutable((string)$fields['created']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
        $rmCreated = (new DateTimeImmutable((string)$rm['created_on']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
        $record('fields: created', $jiraCreated === $rmCreated, $issueKey, sprintf('Redmine %s, Jira %s', $rmCreated, $jiraCreated));

        // Description.
        $html = (string)($issue['renderedFields']['description'] ?? '');
        $coverage = verifyWordCoverage(verifyWords(verifyHtmlToText($html)), verifyWords(verifyMarkdownToText($rm['description'] ?? '', $userName)));
        $record('description: words', $coverage['coverage'] >= 0.98, $issueKey,
            sprintf('%.1f%% of words; missing: %s', 100 * $coverage['coverage'], implode(' ', array_slice($coverage['missing'], 0, 12))));
        $jiraLinks = verifyHtmlLinkCounts($html);
        $rmLinks = verifyMarkdownLinkCounts($rm['description'] ?? '');
        $record('description: links', $rmLinks['links'] >= $jiraLinks['links'], $issueKey, sprintf('Jira %d, Redmine %d', $jiraLinks['links'], $rmLinks['links']));
        $record('description: images', $rmLinks['images'] >= $jiraLinks['images'], $issueKey, sprintf('Jira %d, Redmine %d', $jiraLinks['images'], $rmLinks['images']));

        // Comments against journal notes.
        $notes = [];
        foreach ($rm['journals'] ?? [] as $journal) {
            if (trim((string)($journal['notes'] ?? '')) !== '') {
                $notes[] = verifyWords(verifyMarkdownToText((string)$journal['notes'], $userName));
            }
        }
        $startAt = 0;
        do {
            $page = verifyGetJson($jira, sprintf('/rest/api/3/issue/%s/comment', $jiraId), ['expand' => 'renderedBody', 'startAt' => $startAt, 'maxResults' => 100]);
            foreach ($page['comments'] ?? [] as $comment) {
                $words = verifyWords(verifyHtmlToText((string)($comment['renderedBody'] ?? '')));
                $best = ['coverage' => 0.0, 'missing' => $words];
                foreach ($notes as $noteWords) {
                    $match = verifyWordCoverage($words, $noteWords);
                    if ($match['coverage'] > $best['coverage']) {
                        $best = $match;
                    }
                }
                $record('comments', $best['coverage'] >= 0.95, $issueKey . ' comment ' . $comment['id'],
                    sprintf('best journal match %.1f%%; missing: %s', 100 * $best['coverage'], implode(' ', array_slice($best['missing'], 0, 12))));
            }
            $startAt += count($page['comments'] ?? []);
        } while ($startAt < (int)($page['total'] ?? 0) && ($page['comments'] ?? []) !== []);

        // Attachments.
        $expectedFiles = [];
        foreach ($fields['attachment'] ?? [] as $attachment) {
            if (isset($skippedBySample[(string)$attachment['id']])) {
                $record('attachments: skipped by sample (expected absent)', true, $issueKey);
                continue;
            }
            $expectedFiles[] = [(string)$attachment['filename'], (int)$attachment['size']];
        }
        $actualFiles = [];
        foreach ($rm['attachments'] ?? [] as $attachment) {
            $actualFiles[] = [(string)$attachment['filename'], (int)$attachment['filesize']];
        }
        $normalise = static fn (array $file): array => [verifyNormaliseFilename($file[0]), $file[1]];
        $diff = verifyCompareFiles(array_map($normalise, $expectedFiles), array_map($normalise, $actualFiles));
        $record('attachments: name and size', $diff['missing'] === [] && $diff['unexpected'] === [], $issueKey,
            'missing: ' . implode(', ', $diff['missing']) . '; unexpected: ' . implode(', ', $diff['unexpected']));
        // Redmine file names: the original, "{jiraId}__" only for a name the issue has twice (B14).
        $originals = [];
        $sizes = [];
        foreach ($fields['attachment'] ?? [] as $attachment) {
            $originals[(string)$attachment['id']] = (string)$attachment['filename'];
            $sizes[(string)$attachment['id']] = (int)$attachment['size'];
        }
        $expectedNames = [];
        foreach (\Jira2Redmine\Attachments\AttachmentNames::assign($originals) as $id => $name) {
            if (!isset($skippedBySample[(string)$id])) {
                $expectedNames[] = [$name, $sizes[(string)$id]];
            }
        }
        $exact = verifyCompareFiles($expectedNames, $actualFiles);
        $record('attachments: file name rule', $exact['missing'] === [], $issueKey,
            count($exact['missing']) . ' of ' . count($expectedNames) . ' under another name, e.g. expected '
            . ($exact['missing'][0] ?? '-') . ', found ' . ($exact['unexpected'][0] ?? '-'));
        foreach ($fields['attachment'] ?? [] as $attachment) {
            if ((int)$attachment['size'] > $checksumMax || isset($skippedBySample[(string)$attachment['id']])) {
                continue;
            }
            foreach ($rm['attachments'] ?? [] as $rmAttachment) {
                if (verifyNormaliseFilename((string)$rmAttachment['filename']) === verifyNormaliseFilename((string)$attachment['filename'])
                    && (int)$rmAttachment['filesize'] === (int)$attachment['size']) {
                    $same = verifyDownloadHash($jira, (string)$attachment['content']) === verifyDownloadHash($redmine, (string)$rmAttachment['content_url']);
                    $record('attachments: sha256', $same, $issueKey . ' ' . $attachment['filename']);
                    break;
                }
            }
        }

        // Watchers.
        $watchers = verifyGetJson($jira, sprintf('/rest/api/3/issue/%s/watchers', $jiraId));
        $expectedWatchers = [];
        foreach ($watchers['watchers'] ?? [] as $watcher) {
            if (isset($maps['user'][(string)$watcher['accountId']])) {
                $expectedWatchers[] = $maps['user'][(string)$watcher['accountId']];
            }
        }
        $actualWatchers = array_map(static fn (array $w): string => (string)$w['id'], $rm['watchers'] ?? []);
        $missingWatchers = array_diff($expectedWatchers, $actualWatchers);
        $record('watchers', $missingWatchers === [], $issueKey, 'missing Redmine users: ' . implode(', ', $missingWatchers));

        // Relations between migrated issues.
        $related = [];
        foreach ($rm['relations'] ?? [] as $relation) {
            $related[] = (string)((string)$relation['issue_id'] === (string)$redmineId ? $relation['issue_to_id'] : $relation['issue_id']);
        }
        foreach ($fields['issuelinks'] ?? [] as $link) {
            $other = $link['outwardIssue']['id'] ?? $link['inwardIssue']['id'] ?? null;
            $otherRedmine = $other !== null ? ($maps['issue'][(string)$other] ?? null) : null;
            if ($otherRedmine === null) {
                continue;
            }
            $record('relations', in_array($otherRedmine, $related, true), $issueKey . ' link ' . $link['id'], 'to Redmine #' . $otherRedmine);
        }

        // Custom fields.
        $rmValues = [];
        foreach ($rm['custom_fields'] ?? [] as $customField) {
            $rmValues[(string)$customField['id']] = $customField['value'] ?? null;
        }
        foreach ($customFields as $jiraFieldId => $redmineFieldId) {
            // Cascading selects map to two Redmine fields: "cascading_parent:<id>" holds the parent value,
            // "<id>" the child, which Jira may leave empty.
            $isParentPart = str_starts_with((string)$jiraFieldId, 'cascading_parent:');
            $sourceFieldId = $isParentPart ? substr((string)$jiraFieldId, strlen('cascading_parent:')) : (string)$jiraFieldId;
            $value = $fields[$sourceFieldId] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (!$isParentPart && isset($customFields['cascading_parent:' . $sourceFieldId]) && is_array($value) && !isset($value['child'])) {
                continue;
            }
            $rmValue = $rmValues[$redmineFieldId] ?? null;
            $record('custom fields', $rmValue !== null && $rmValue !== '' && $rmValue !== [], $issueKey . ' ' . $jiraFieldId, 'Redmine field ' . $redmineFieldId . ' empty');
        }
    }
}

// Report.
$lines = ['# Verify report', '', sprintf('Projects: %s; sample %d per project; Redmine %s; %s', implode(', ', $projectKeys), $sampleSize, $redmineBase, date('c')), ''];
$lines[] = '| check | pass | fail |';
$lines[] = '|---|---:|---:|';
$allPassed = true;
ksort($results);
foreach ($results as $check => $counts) {
    $lines[] = sprintf('| %s | %d | %d |', $check, $counts['pass'], $counts['fail']);
    $allPassed = $allPassed && $counts['fail'] === 0;
}
$lines[] = '';
$lines[] = $allPassed ? '**PASS**' : sprintf('**FAIL** (%d failures)', count($failures));
if ($failures !== []) {
    $lines[] = '';
    $lines[] = '| check | subject | detail |';
    $lines[] = '|---|---|---|';
    foreach ($failures as [$check, $subject, $detail]) {
        $lines[] = sprintf('| %s | %s | %s |', $check, $subject, str_replace(['|', "\n"], ['\|', ' '], $detail));
    }
}
@mkdir(dirname($reportPath), 0775, true);
file_put_contents($reportPath, implode("\n", $lines) . "\n");
fwrite(STDOUT, implode("\n", array_slice($lines, 0, 6 + count($results) + 2)) . "\nReport: " . $reportPath . "\n");
exit($allPassed ? 0 : 1);

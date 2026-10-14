<?php

declare(strict_types=1);

/**
 * B52, opt-in, after the last batch: links to issues that a later batch migrated, rewritten in the
 * descriptions and comments already in Redmine.
 *
 *   php tools/relink_issue_links.php                 preview: what would change
 *   php tools/relink_issue_links.php --confirm-push  rewrite
 *
 * Only texts that hold a Jira issue link and are still what the migration pushed (the hash 10 and 11
 * stored) are rewritten; a text edited in Redmine since, or pushed before this hash existed, is left
 * alone and counted. The rewrite is the one 10 uses ("#123", or "text (#123)").
 * The last line counts the texts that still hold a Jira link to a migrated issue after this run, edited ones
 * included (each listed as [left]): after --confirm-push a preview must say 0 (Jan, B52: batches plus relink).
 */

use Jira2Redmine\Http\ClientFactory;
use Jira2Redmine\Text\Relink;

$config = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/description_conversion.php';

$confirm = in_array('--confirm-push', $argv, true);
$pdo = new PDO((string)$config['database']['dsn'], (string)$config['database']['username'], (string)$config['database']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$client = ClientFactory::redmine($config['redmine']);
$issueMap = loadIssueLinkMap($pdo);
$rewrite = static fn (string $text): string => replaceIssueLinksInText($text, $issueMap);
$counts = ['relink' => 0, 'nothing' => 0, 'edited' => 0, 'unknown' => 0, 'failed' => 0];
$left = 0;
$get = static function (string $path, array $query = []) use ($client): array {
    $decoded = json_decode((string)$client->get($path, ['query' => $query])->getBody(), true);

    return is_array($decoded) ? $decoded : [];
};

// Descriptions whose Jira text links an issue.
$issues = $pdo->query("SELECT m.mapping_id, m.jira_issue_key, m.redmine_issue_id, m.pushed_text_hash
    FROM migration_mapping_issues m JOIN staging_jira_issues i ON i.id = m.jira_issue_id
    WHERE m.redmine_issue_id IS NOT NULL AND i.description_html LIKE '%/browse/%'");
$storeIssue = $pdo->prepare('UPDATE migration_mapping_issues SET pushed_text_hash = :h WHERE mapping_id = :m');
foreach ($issues as $row) {
    $decision = null;
    try {
        $current = $get(sprintf('/issues/%d.json', $row['redmine_issue_id']))['issue']['description'] ?? '';
        $decision = Relink::decide((string)$current, $row['pushed_text_hash'], $rewrite);
        $counts[$decision['action']]++;
        if ($decision['action'] === 'edited') {
            printf("  [edited] %s (#%d): changed in Redmine since the migration; left alone.%s", $row['jira_issue_key'], $row['redmine_issue_id'], PHP_EOL);
        }
        if (Relink::linkLeft((string)$current, $rewrite) && !($confirm && $decision['action'] === 'relink')) {
            $left++;
            if ($decision['action'] !== 'relink') {
                printf("  [left] %s (#%d): description still links a migrated issue by its Jira link.%s", $row['jira_issue_key'], $row['redmine_issue_id'], PHP_EOL);
            }
        }
        if ($decision['action'] !== 'relink') {
            continue;
        }
        printf("  [%s] %s (#%d): description%s", $confirm ? 'relinked' : 'would relink', $row['jira_issue_key'], $row['redmine_issue_id'], PHP_EOL);
        if ($confirm) {
            $client->put(sprintf('/issues/%d.json', $row['redmine_issue_id']), ['json' => ['issue' => ['description' => $decision['text']]]]);
            $storeIssue->execute(['h' => Relink::hash($decision['text']), 'm' => $row['mapping_id']]);
        }
    } catch (Throwable $exception) {
        $counts['failed']++;
        // a rewrite that failed leaves its link
        $left += $confirm && ($decision['action'] ?? null) === 'relink' ? 1 : 0;
        printf("  [failed] %s: %s%s", $row['jira_issue_key'], $exception->getMessage(), PHP_EOL);
    }
}

// Comments whose Jira text links an issue.
$journals = $pdo->query("SELECT j.mapping_id, i.jira_issue_key, i.redmine_issue_id, j.redmine_journal_id, j.pushed_text_hash
    FROM migration_mapping_journals j
    JOIN staging_jira_comments c ON c.id = j.jira_entity_id AND j.entity_type = 'COMMENT'
    JOIN migration_mapping_issues i ON i.jira_issue_id = c.issue_id
    WHERE j.redmine_journal_id IS NOT NULL AND i.redmine_issue_id IS NOT NULL AND c.body_html LIKE '%/browse/%'
    ORDER BY i.redmine_issue_id");
$storeJournal = $pdo->prepare('UPDATE migration_mapping_journals SET pushed_text_hash = :h WHERE mapping_id = :m');
$notesOf = [];
foreach ($journals as $row) {
    $decision = null;
    try {
        $issueId = (int)$row['redmine_issue_id'];
        if (!isset($notesOf[$issueId])) {
            $notesOf = [$issueId => []];
            foreach ($get(sprintf('/issues/%d.json', $issueId), ['include' => 'journals'])['issue']['journals'] ?? [] as $journal) {
                $notesOf[$issueId][(int)$journal['id']] = (string)($journal['notes'] ?? '');
            }
        }
        $current = $notesOf[$issueId][(int)$row['redmine_journal_id']] ?? null;
        if ($current === null) {
            continue;
        }
        $decision = Relink::decide($current, $row['pushed_text_hash'], $rewrite);
        $counts[$decision['action']]++;
        if ($decision['action'] === 'edited') {
            printf("  [edited] %s, journal #%d: changed in Redmine since the migration; left alone.%s", $row['jira_issue_key'], $row['redmine_journal_id'], PHP_EOL);
        }
        if (Relink::linkLeft($current, $rewrite) && !($confirm && $decision['action'] === 'relink')) {
            $left++;
            if ($decision['action'] !== 'relink') {
                printf("  [left] %s, journal #%d: still links a migrated issue by its Jira link.%s", $row['jira_issue_key'], $row['redmine_journal_id'], PHP_EOL);
            }
        }
        if ($decision['action'] !== 'relink') {
            continue;
        }
        printf("  [%s] %s, journal #%d%s", $confirm ? 'relinked' : 'would relink', $row['jira_issue_key'], $row['redmine_journal_id'], PHP_EOL);
        if ($confirm) {
            $client->put(sprintf('/journals/%d.json', $row['redmine_journal_id']), ['json' => ['journal' => ['notes' => $decision['text']]]]);
            $storeJournal->execute(['h' => Relink::hash($decision['text']), 'm' => $row['mapping_id']]);
        }
    } catch (Throwable $exception) {
        $counts['failed']++;
        $left += $confirm && ($decision['action'] ?? null) === 'relink' ? 1 : 0;
        printf("  [failed] %s, journal #%d: %s%s", $row['jira_issue_key'], $row['redmine_journal_id'], $exception->getMessage(), PHP_EOL);
    }
}

printf(
    "%s: %d relinked, %d without a link to rewrite, %d edited in Redmine (left alone), %d pushed before the hash existed (left alone), %d failed.%s",
    $confirm ? 'Relink' : 'Preview (no --confirm-push)',
    $counts['relink'],
    $counts['nothing'],
    $counts['edited'],
    $counts['unknown'],
    $counts['failed'],
    PHP_EOL
);
printf("Texts that still hold a Jira link to a migrated issue after this run: %d.%s", $left, PHP_EOL);

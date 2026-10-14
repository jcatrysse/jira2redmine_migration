<?php

declare(strict_types=1);

/**
 * Text conversion protocol, step 3: the items for visual_check.mjs, from staging and the issue mapping.
 *   php tools/testenv/visual_items.php OUT.json construct=KEY,KEY ...   (e.g. table=ABC-10,XYZ-39)
 * The output holds Jira's rendered descriptions: keep it outside the repository.
 */

$config = require __DIR__ . '/../../bootstrap.php';
$pdo = new PDO((string)$config['database']['dsn'], (string)$config['database']['username'], (string)$config['database']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$out = $argv[1] ?? '';
if ($out === '' || count($argv) < 3) {
    fwrite(STDERR, "Usage: php tools/testenv/visual_items.php OUT.json construct=KEY,KEY ...\n");
    exit(2);
}
$query = $pdo->prepare('SELECT m.redmine_issue_id, i.description_html FROM migration_mapping_issues m
    JOIN staging_jira_issues i ON i.id = m.jira_issue_id WHERE m.jira_issue_key = :key AND m.redmine_issue_id IS NOT NULL');
$comments = $pdo->prepare("SELECT j.redmine_journal_id, c.body_html FROM migration_mapping_issues m
    JOIN staging_jira_comments c ON c.issue_id = m.jira_issue_id
    JOIN migration_mapping_journals j ON j.jira_entity_id = c.id AND j.entity_type = 'COMMENT'
    WHERE m.jira_issue_key = :key AND j.redmine_journal_id IS NOT NULL ORDER BY c.created_at, c.id");
$items = [];
foreach (array_slice($argv, 2) as $argument) {
    [$construct, $keys] = array_pad(explode('=', $argument, 2), 2, '');
    foreach (array_filter(explode(',', $keys)) as $key) {
        if (isset($items[$key])) {
            continue;
        }
        $query->execute(['key' => $key]);
        $row = $query->fetch();
        if ($row === false) {
            fwrite(STDERR, "not migrated: $key\n");
            continue;
        }
        // many constructs of the set sit in a comment: every migrated comment, next to its journal
        $comments->execute(['key' => $key]);
        $notes = array_map(static fn (array $c): array => ['journal_id' => (int)$c['redmine_journal_id'],
            'jira_html' => (string)$c['body_html']], $comments->fetchAll());
        $items[$key] = ['key' => $key, 'redmine_id' => (int)$row['redmine_issue_id'], 'construct' => $construct,
            'jira_html' => (string)$row['description_html'], 'comments' => $notes];
    }
}
file_put_contents($out, json_encode(array_values($items), JSON_THROW_ON_ERROR));
printf("%d items\n", count($items));

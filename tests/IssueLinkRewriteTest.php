<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B6: the link rewrite of the push phase changed proposed_description of issues that were not created
 * yet (failed or waiting), so their automation hash no longer matched and every later transform kept
 * them as "manual overrides".
 */
final class IssueLinkRewriteTest extends TestCase
{
    public function testOnlyCreatedIssuesAreRewritten(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE migration_mapping_issues (mapping_id INTEGER PRIMARY KEY, jira_issue_key TEXT,
            redmine_issue_id INTEGER NULL, proposed_description TEXT, last_updated_at TEXT)');
        $link = 'See [ABC-1](https://x.atlassian.net/browse/ABC-1)';
        $insert = $pdo->prepare('INSERT INTO migration_mapping_issues VALUES (?, ?, ?, ?, NULL)');
        $insert->execute([1, 'ABC-1', 41, 'target']);
        $insert->execute([2, 'ABC-2', 42, $link]);
        $insert->execute([3, 'ABC-3', null, $link]);

        replaceIssueLinksWithRedmineIds($pdo, null, false, '', false);

        $rows = $pdo->query('SELECT mapping_id, proposed_description FROM migration_mapping_issues ORDER BY mapping_id');
        self::assertNotFalse($rows);
        $descriptions = $rows->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertStringContainsString('#41', (string)$descriptions[2]);
        self::assertSame($link, $descriptions[3]);
    }
}

<?php

declare(strict_types=1);

use Jira2Redmine\Users\DefaultAuthor;
use PHPUnit\Framework\TestCase;

/**
 * Decision 42 (Jan, 2026-10-19): Jira content whose author has no Redmine account gets the user the configuration
 * names, Anonymous by default; never the API user (the migration account) without being asked.
 */
final class DefaultAuthorTest extends TestCase
{
    public function testEachSectionReadsItsOwnKey(): void
    {
        $config = [
            'migration' => ['issues' => ['default_redmine_author_id' => 4], 'journals' => ['default_redmine_author_id' => '4']],
            'attachments' => ['default_redmine_author_id' => 7],
        ];
        self::assertSame(4, DefaultAuthor::configured($config, 'issues'));
        self::assertSame(4, DefaultAuthor::configured($config, 'journals'));
        self::assertSame(7, DefaultAuthor::configured($config, 'attachments'));
        // an unset environment variable reads as 0 in the production configuration
        self::assertNull(DefaultAuthor::configured(['migration' => ['issues' => ['default_redmine_author_id' => 0]]], 'issues'));
        self::assertNull(DefaultAuthor::configured([], 'journals'));
    }

    public function testWithoutAnIdTheScriptStopsAndSaysHowToFindAnonymous(): void
    {
        try {
            DefaultAuthor::required(['migration' => ['journals' => ['default_redmine_author_id' => null]]], 'journals');
            self::fail('no exception');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('migration.journals.default_redmine_author_id', $exception->getMessage());
            self::assertStringContainsString("rails runner 'puts User.anonymous.id'", $exception->getMessage());
        }
        self::assertSame(4, DefaultAuthor::required(['attachments' => ['default_redmine_author_id' => 4]], 'attachments'));
    }

    public function testTheScriptsNeedItWithTheExtendedApiAndNoLongerFallBackToTheApiUser(): void
    {
        $nine = (string)file_get_contents(__DIR__ . '/../09_migrate_attachments.php');
        $ten = (string)file_get_contents(__DIR__ . '/../10_migrate_issues.php');
        $eleven = (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');
        self::assertStringContainsString("? DefaultAuthor::required(\$config, 'attachments')", $nine);
        self::assertStringContainsString("? DefaultAuthor::required(\$config, 'issues')", $ten);
        self::assertStringContainsString("\$useExtendedApi ? DefaultAuthor::required(\$config, 'journals')", $eleven);
        self::assertStringContainsString("\$history = prepareHistory(\$pdo, DefaultAuthor::required(\$config, 'journals'));", $eleven);
        self::assertStringNotContainsString('fetchApiUserId', $eleven);
        self::assertStringNotContainsString('users/current.json', $eleven);
    }
}

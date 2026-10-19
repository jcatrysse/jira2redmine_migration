<?php

declare(strict_types=1);

use Jira2Redmine\Cli\Fallbacks;
use PHPUnit\Framework\TestCase;

/**
 * Decision 43 (Jan): the spots PHPStan found where a PCRE error or an unusable value ended in a TypeError or a PHP
 * warning (the phase 6 baseline of 08, 10 and 11). A PCRE error keeps the text as it was, an unusable value skips the
 * field, each with a "[warn]" line naming where, counted in the script's summary.
 */
final class ScriptFallbacksTest extends TestCase
{
    /**
     * @param callable(): mixed $call
     * @return array{mixed, string, int} the result, the output and how many fallbacks it took
     */
    private static function fallback(callable $call): array
    {
        $count = Fallbacks::count();
        ob_start();
        try {
            $result = $call();
        } finally {
            $output = (string)ob_get_clean();
        }

        return [$result, $output, Fallbacks::count() - $count];
    }

    /**
     * @param callable(): mixed $call
     */
    private static function withBacktrackLimitOfOne(callable $call): mixed
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            return $call();
        } finally {
            ini_set('pcre.jit', (string)$jit);
            ini_set('pcre.backtrack_limit', (string)$limit);
        }
    }

    public function testElevenKeepsACommentWhoseAttachmentLinksPcreCannotRewrite(): void
    {
        $normalize = scriptFunction('11_migrate_journals.php', 'normalizeJournalAttachmentLinks');
        $text = 'See ![shot](attachment:10__shot.png) and https://x.atlassian.net/rest/api/3/attachment/content/10';

        [$result, $output, $fallbacks] = self::fallback(static fn (): mixed => self::withBacktrackLimitOfOne(
            static fn (): mixed => $normalize($text, ['10' => '10__shot.png'], 'GEOS-1, comment 5')
        ));

        self::assertSame($text, $result);
        self::assertSame(
            "  [warn] GEOS-1, comment 5, attachment links: PCRE failed (Backtrack limit exhausted), the text is kept as it"
                . " was (decision 43).\n",
            $output
        );
        self::assertSame(1, $fallbacks);
        // without the error the links are rewritten as before (the outputs of the old chain of six calls)
        self::assertSame(
            'See ![shot](10__shot.png) and 10__shot.png',
            $normalize($text, ['10' => '10__shot.png'], 'GEOS-1, comment 5')
        );
        self::assertSame(
            'a [f]( attachment:10__shot.png) 10__shot.png 10__shot.png [x]( attachment:10__shot.png) attachment:10__shot.png',
            $normalize(
                'a [f](attachment:10__shot.png) 10__shot.png /secure/attachment/10 [x](/rest/api/2/attachment/thumbnail/10?y)'
                    . ' attachment:10__shot.png',
                ['10' => '10__shot.png'],
                'GEOS-1, comment 5'
            )
        );
    }

    public function testElevenNamesAnAttachmentPcreCannotReadAsTheAttachmentTokenDoes(): void
    {
        $block = scriptFunction('11_migrate_journals.php', 'buildJournalAttachmentBlock');
        $attachment = static fn (string $id, string $name): array => [
            'mapping_id' => 1,
            'jira_attachment_id' => $id,
            'filename' => $name,
            'sharepoint_url' => null,
            'redmine_attachment_id' => 3,
            'created_at' => null,
        ];

        [$result, $output, $fallbacks] = self::fallback(static fn (): mixed => $block(
            [$attachment('77', "bad\xff name.png"), $attachment('78', 'good name.png')],
            'GEOS-1, comment 5'
        ));

        // AttachmentNames::token() gives such a file the same token, so rewrite() still finds it
        self::assertSame("77__attachment", \Jira2Redmine\Attachments\AttachmentNames::token('77', "bad\xff name.png"));
        self::assertSame("> attachment:77__attachment\n> attachment:78__good_name.png", $result);
        self::assertSame(
            '  [warn] GEOS-1, comment 5, attachment 77: PCRE failed (Malformed UTF-8 characters, possibly incorrectly'
                . " encoded) on the file name, named \"attachment\" as its token is (decision 43).\n",
            $output
        );
        self::assertSame(1, $fallbacks);
    }

    public function testTenComparesALabelPcreCannotReadAsItIs(): void
    {
        $labelKey = scriptFunction('10_migrate_issues.php', 'normalizeLabelKey');

        [$result, $output, $fallbacks] = self::fallback(
            static fn (): mixed => $labelKey("  Foo\xff  Bar ", 'GEOS-1, Jira field customfield_1')
        );

        self::assertSame(mb_strtolower("Foo\xff  Bar"), $result);
        self::assertSame(
            '  [warn] GEOS-1, Jira field customfield_1: PCRE failed (Malformed UTF-8 characters, possibly incorrectly'
                . " encoded), the text is kept as it was (decision 43).\n",
            $output
        );
        self::assertSame(1, $fallbacks);
        self::assertSame('foo bar', $labelKey("  Foo\xc2\xa0 Bar ", 'GEOS-1, Jira field customfield_1'));
    }

    public function testEightMapsNoParentLabelWhenRedmineGaveNoEnumerationsAndSaysSo(): void
    {
        $labelToId = scriptFunction('08_migrate_custom_fields.php', 'parentEnumerationLabelToId');

        [$result, $output, $fallbacks] = self::fallback(
            static fn (): mixed => $labelToId(null, 'Jira field customfield_2, value dependencies')
        );

        self::assertSame([], $result);
        self::assertSame(
            "  [warn] Jira field customfield_2, value dependencies: Redmine gave the new parent field no enumerations, its"
                . " options cannot be named (decision 43).\n",
            $output
        );
        self::assertSame(1, $fallbacks);
        self::assertSame(
            ['QPS' => 46, 'ROV' => 47],
            $labelToId(
                [['id' => 46, 'name' => ' QPS '], ['id' => '47', 'value' => 'ROV'], ['name' => 'no id'], ['id' => 9, 'name' => '']],
                'Jira field customfield_2, value dependencies'
            )
        );
        // the push builds its labels with it, the warning instead of PHP's "foreach() argument must be of type array"
        $eight = sourceWithoutLayout((string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php'));
        self::assertStringContainsString(
            sourceWithoutLayout(
                '$parentLabelToId = parentEnumerationLabelToId($parentEnumerations, sprintf(\'Jira field %s, value'
                    . ' dependencies\', $jiraId));'
            ),
            $eight
        );
    }

    public function testEightLeavesOutTheValueDependenciesOfAnUpdateWhoseParentEnumerationsCannotBeUsed(): void
    {
        $dependencies = scriptFunction(
            '08_migrate_custom_fields.php',
            'dependingUpdateValueDependencies',
            ['normalizeDependingValueDependencies', 'buildEnumerationLabelToIdMap', 'normalizeInteger']
        );
        $proposed = ['QPS' => ['Survey']];
        $children = [['id' => 5, 'name' => 'Survey']];

        foreach (['not json', null] as $parentEnumerations) {
            [$result, $output, $fallbacks] = self::fallback(static fn (): mixed => $dependencies(
                $proposed,
                $parentEnumerations,
                $children,
                'Jira field customfield_3, value dependencies'
            ));
            self::assertNull($result);
            self::assertSame(
                "  [warn] Jira field customfield_3, value dependencies: the parent field's enumerations cannot be decoded or"
                    . " read, the field is skipped (decision 43).\n",
                $output
            );
            self::assertSame(1, $fallbacks);
        }
        self::assertSame(
            ['46' => [5]],
            $dependencies($proposed, [['id' => 46, 'name' => 'QPS']], $children, 'x')
        );
        $eight = sourceWithoutLayout((string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php'));
        self::assertStringContainsString(
            sourceWithoutLayout('$valueDependencies = dependingUpdateValueDependencies('),
            $eight
        );
        self::assertStringNotContainsString(
            sourceWithoutLayout("\$payload['custom_field']['value_dependencies'] = normalizeDependingValueDependencies("),
            $eight
        );
    }

    public function testTheThreeScriptsReportTheirFallbacksInTheirSummary(): void
    {
        foreach (['08_migrate_custom_fields.php', '10_migrate_issues.php', '11_migrate_journals.php'] as $script) {
            self::assertStringContainsString(
                'Fallbacks::summary()',
                (string)file_get_contents(__DIR__ . '/../' . $script),
                $script
            );
        }
        self::assertFileDoesNotExist(__DIR__ . '/../tools/phpstan-baseline/08_migrate_custom_fields.php.neon');
        self::assertFileDoesNotExist(__DIR__ . '/../tools/phpstan-baseline/10_migrate_issues.php.neon');
        self::assertFileDoesNotExist(__DIR__ . '/../tools/phpstan-baseline/11_migrate_journals.php.neon');
    }
}

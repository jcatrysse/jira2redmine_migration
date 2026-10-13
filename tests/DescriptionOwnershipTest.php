<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B41: 11 rewrote migration_mapping_issues.proposed_description after 10 had pushed it: too late for
 * Redmine (avatars stayed in descriptions), and for issues not created yet it broke 10's automation hash,
 * so those issues stayed "manual overrides" and were never created (17 WIKI issues). 10 now does it.
 */
final class DescriptionOwnershipTest extends TestCase
{
    public function testAvatarImagesAreRemovedAndTheLinkKept(): void
    {
        $text = 'Asked [![avatar](https://example.atlassian.net/rest/api/2/universal_avatar/view/type/user/avatar/1) Jane Doe]'
            . '(https://example.com/people/jane) and ![a](https://example.atlassian.net/rest/api/3/universal_avatar/x) done';

        $out = transform_text_in_transform_phase($text, []);

        self::assertStringNotContainsString('universal_avatar', $out);
        self::assertStringContainsString('[Jane Doe](https://example.com/people/jane)', $out);
    }

    public function testElevenNoLongerRewritesIssueDescriptions(): void
    {
        $eleven = (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');

        self::assertFalse(str_contains($eleven, 'transformDescriptionsForUsersAndCanonicalizeIssues'));
        self::assertDoesNotMatchRegularExpression('/UPDATE\s+migration_mapping_issues\s+SET\s+proposed_description/i', $eleven);
    }

    public function testTenAppliesItBeforeItsHash(): void
    {
        $ten = (string)file_get_contents(__DIR__ . '/../10_migrate_issues.php');

        self::assertTrue(str_contains($ten, 'transform_text_in_transform_phase($proposedDescription'));
    }
}

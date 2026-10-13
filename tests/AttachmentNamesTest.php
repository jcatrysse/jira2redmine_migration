<?php

declare(strict_types=1);

use Jira2Redmine\Attachments\AttachmentNames;
use PHPUnit\Framework\TestCase;

final class AttachmentNamesTest extends TestCase
{
    public function testOriginalNamesAndAPrefixOnlyForARepeatedName(): void
    {
        // Two "image.png" on one issue (as on GEOS-8): the lowest Jira id keeps the name.
        $names = AttachmentNames::assign(['10002' => 'image.png', '10001' => 'Image.PNG', '10003' => 'Report v2.pdf']);
        self::assertSame(['10001' => 'Image.PNG', '10002' => '10002__image.png', '10003' => 'Report v2.pdf'], $names);
        // Same input, same names (rerun).
        self::assertSame($names, AttachmentNames::assign(['10003' => 'Report v2.pdf', '10001' => 'Image.PNG', '10002' => 'image.png']));
    }

    public function testRedmineSanitisesLikeRedmine(): void
    {
        self::assertSame('a_b_c.png', AttachmentNames::redmineSanitize('a:b?c.png'));
        self::assertSame('file.txt', AttachmentNames::redmineSanitize('C:\\dir\\file.txt'));
        self::assertSame('[4E59]-x (1).png', AttachmentNames::redmineSanitize('[4E59]-x (1).png'));
    }

    public function testRewriteReplacesPipelineTokensWithTheFinalNames(): void
    {
        $attachments = ['10001' => 'image.png', '10002' => 'image.png', '10003' => 'Report v2.pdf'];
        $text = "![](10001__image.png)\n![](10002__image.png \"title\")\nattachment:10003__Report_v2.pdf and attachment:10001__image.png.";
        self::assertSame(
            "![](image.png)\n![](10002__image.png \"title\")\nattachment:\"Report v2.pdf\" and attachment:image.png.",
            AttachmentNames::rewrite($text, $attachments)
        );
    }

    public function testLinkTargetsAreEncodedAndOtherIssuesTokensUntouched(): void
    {
        $text = '[the report](10003__Report_v2.pdf) and ![](99999__other.png)';
        $result = AttachmentNames::rewrite($text, ['10003' => 'Report v2.pdf']);
        self::assertSame('[the report](Report%20v2.pdf) and ![](99999__other.png)', $result);
    }
}

<?php

declare(strict_types=1);

use Jira2Redmine\Text\Safelinks;
use PHPUnit\Framework\TestCase;

final class SafelinksTest extends TestCase
{
    private const WRAPPED = 'https://eur06.safelinks.protection.outlook.com/?url=https%3A%2F%2Fexample.org%2Fdocs%2Fa%2520b%3Fx%3D1%26y%3D2'
        . '&data=05%7C02%7Cuser%40example.eu%7Cabc%7C0&sdata=xyz%3D&reserved=0';

    public function testAMailedLinkKeepsItsTargetOnly(): void
    {
        self::assertSame(
            'See [the report](https://example.org/docs/a%20b?x=1&y=2) and https://example.org/docs/a%20b?x=1&y=2 here.',
            Safelinks::unwrap('See [the report](' . self::WRAPPED . ') and ' . self::WRAPPED . ' here.')
        );
    }

    public function testCharactersThatEndAMarkdownLinkStayEncoded(): void
    {
        $wrapped = 'https://nam02.safelinks.protection.outlook.com/?url=https%3A%2F%2Fexample.org%2Fwiki%2FPage_%28draft%29&data=x';

        self::assertSame('[p](https://example.org/wiki/Page_%28draft%29)', Safelinks::unwrap('[p](' . $wrapped . ')'));
    }

    public function testAWrapperWithoutATargetStays(): void
    {
        $text = 'https://eur06.safelinks.protection.outlook.com/?data=05%7C02 and '
            . 'https://eur06.safelinks.protection.outlook.com/?url=javascript%3Aalert(1)';

        self::assertSame($text, Safelinks::unwrap($text));
        self::assertSame('no links', Safelinks::unwrap('no links'));
    }

    public function testTheConvertersUnwrapMailedLinks(): void
    {
        require_once __DIR__ . '/../lib/description_conversion.php';
        $html = '<p>Mail: <a href="' . htmlspecialchars(self::WRAPPED) . '">the report</a></p>';

        self::assertSame('Mail: [the report](https://example.org/docs/a%20b?x=1&y=2)', convertJiraHtmlToMarkdown($html, []));
    }
}

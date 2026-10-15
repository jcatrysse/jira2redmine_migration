<?php

declare(strict_types=1);

use Karvaka\AdfToGfm\Converter as AdfConverter;
use PHPUnit\Framework\TestCase;

/**
 * The ADF path wrote a code block between single backticks: Redmine showed inline code, its lines run
 * together (the bench: "no code block" on 31 of 33 items of the ADF path).
 */
final class AdfCodeBlockTest extends TestCase
{
    private static function code(string $text, ?string $language = null): string
    {
        $block = ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => $text]]];
        if ($language !== null) {
            $block['attrs'] = ['language' => $language];
        }

        $doc = ['type' => 'doc', 'version' => 1, 'content' => [$block]];

        return convertDescriptionToMarkdown((string)json_encode($doc), new AdfConverter());
    }

    public function testACodeBlockIsFenced(): void
    {
        self::assertSame("```php\necho 1;\necho 2;\n```", self::code("echo 1;\necho 2;", 'php'));
        self::assertSame("```\na*b_c <x> [y](z)\n```", self::code('a*b_c <x> [y](z)'));
    }

    public function testAFenceInsideTheCodeGetsALongerFence(): void
    {
        self::assertSame("````\nsee ```this```\n````", self::code('see ```this```'));
    }
}

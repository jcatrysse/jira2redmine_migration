<?php

declare(strict_types=1);

namespace Jira2Redmine\Text;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * MediaWiki source pasted into a Jira text (WIKI: template documentation, "the table becomes the following").
 * Jira's renderer reads its "|" as table cells and its line breaks away: a broken table in Jira itself, and
 * in Redmine after conversion (WIKI-280, -282, -294, -295). The ADF still holds the source as the user typed
 * it. A paragraph of it that is MediaWiki source without doubt (a table "{| ... |}", or a block tag such as
 * <pre> over several lines) becomes a code block with that exact text, in place of what Jira rendered of it.
 * Anything less certain (a bold '''word''' or a [[link]] in a sentence) stays as Jira shows it.
 */
final class MediaWikiSource
{
    private const TABLE_START = '/^[ \t]*:*\{\|/m';
    private const TABLE_END = '/^[ \t]*\|\}/m';
    private const BLOCK_TAG = '#<(pre|syntaxhighlight|source)\b[^>]*>(?=[^<]*\n)(?:(?!</\1>).)*\n(?:(?!</\1>).)*</\1>#is';

    /**
     * The top-level paragraphs of an ADF document that are MediaWiki source, as text with line breaks.
     *
     * @return list<string>
     */
    public static function paragraphs(?string $adf): array
    {
        if ($adf === null || !str_contains($adf, '"paragraph"')) {
            return [];
        }
        $doc = json_decode($adf, true);
        if (!is_array($doc) || !is_array($doc['content'] ?? null)) {
            return [];
        }
        $sources = [];
        foreach ($doc['content'] as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'paragraph') {
                continue;
            }
            $text = self::text($block);
            if (self::isSource($text)) {
                $sources[] = $text;
            }
        }

        return $sources;
    }

    public static function isSource(string $text): bool
    {
        if (substr_count($text, "\n") < 2) {
            return false;
        }

        return (preg_match(self::TABLE_START, $text) === 1 && preg_match(self::TABLE_END, $text) === 1)
            || preg_match(self::BLOCK_TAG, $text) === 1;
    }

    /**
     * Jira's rendering of each source paragraph, from the block that starts with its first line to the block
     * that holds its last line, becomes <pre> with the source. A paragraph whose rendering is not found leaves
     * the HTML as it is.
     *
     * @param list<string> $sources
     */
    public static function replaceInHtml(string $html, array $sources): string
    {
        if ($sources === []) {
            return $html;
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="utf-8"?><div id="j2r-root">' . $html . '</div>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $loaded ? $doc->getElementById('j2r-root') : null;
        if ($root === null) {
            return $html;
        }
        /** @var list<DOMNode> $nodes */
        $nodes = [];
        foreach ($root->childNodes as $child) {
            if (!($child instanceof DOMText && trim($child->data) === '')) {
                $nodes[] = $child;
            }
        }
        $cursor = 0;
        $replaced = 0;
        foreach ($sources as $source) {
            $lines = array_values(array_filter(
                array_map([self::class, 'letters'], explode("\n", $source)),
                static fn (string $l): bool => $l !== ''
            ));
            if ($lines === []) {
                continue;
            }
            $start = null;
            for ($i = $cursor, $n = count($nodes); $i < $n; $i++) {
                if (str_starts_with(self::letters($nodes[$i]->textContent), $lines[0])) {
                    $start = $i;
                    break;
                }
            }
            if ($start === null) {
                continue;
            }
            $end = null;
            $seen = '';
            for ($j = $start, $n = count($nodes); $j < $n; $j++) {
                $seen .= self::letters($nodes[$j]->textContent);
                if (str_contains($seen, end($lines))) {
                    $end = $j;
                    break;
                }
            }
            if ($end === null) {
                continue;
            }
            $pre = $doc->createElement('pre');
            if (!$pre instanceof DOMElement) {
                continue;
            }
            $pre->appendChild($doc->createTextNode($source));
            $root->insertBefore($pre, $nodes[$start]);
            for ($k = $start; $k <= $end; $k++) {
                $root->removeChild($nodes[$k]);
            }
            array_splice($nodes, $start, $end - $start + 1, [$pre]);
            $cursor = $start + 1;
            $replaced++;
        }
        if ($replaced === 0) {
            return $html;
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $paragraph
     */
    private static function text(array $paragraph): string
    {
        $text = '';
        foreach (is_array($paragraph['content'] ?? null) ? $paragraph['content'] : [] as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === 'text') {
                $text .= (string)($node['text'] ?? '');
            } elseif (($node['type'] ?? null) === 'hardBreak') {
                $text .= "\n";
            }
        }

        return $text;
    }

    private static function letters(string $text): string
    {
        return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', $text);
    }
}

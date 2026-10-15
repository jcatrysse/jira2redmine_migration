<?php

declare(strict_types=1);

namespace Jira2Redmine\Text;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Bold and italic in Jira's HTML as Markdown can write them. Jira nests and splits emphasis freely
 * ("<em>range (</em><b><em>-70</em></b><em>)</em>", "<em><b>x</em></b>"); written one element at a time the
 * delimiters touch ("(****-70****)") and Redmine shows the asterisks (GEOS-53, GEOS-103). Here the text of a
 * run of inline content is regrouped by the emphasis it has: italic outside, bold inside, one element per
 * stretch, no white space just inside a delimiter. Only HTML where emphasis touches emphasis, or holds white
 * space at an edge, is rewritten; everything else is returned as it came.
 */
final class Emphasis
{
    private const TAGS = ['em' => 'i', 'i' => 'i', 'strong' => 'b', 'b' => 'b'];
    private const NEEDS_WORK = '#</(?:em|i|strong|b)>\s*<(?:em|i|strong|b)\b|<(?:em|i|strong|b)\b[^>]*>\s*<(?:em|i|strong|b)\b'
        . '|<(?:em|i|strong|b)\b[^>]*>[ \t]|[ \t]</(?:em|i|strong|b)>#i';
    /** Block elements end a run of inline content; their own content is a run of its own. */
    private const BLOCKS = ['p', 'div', 'li', 'ul', 'ol', 'table', 'tbody', 'thead', 'tr', 'td', 'th', 'blockquote', 'pre',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'dl', 'dt', 'dd'];

    public static function normalize(string $html): string
    {
        if (preg_match(self::NEEDS_WORK, $html) !== 1) {
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
        self::walk($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function walk(DOMElement $element): void
    {
        $sequence = [];
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), self::BLOCKS, true)) {
                self::regroup($element, $sequence, $child);
                $sequence = [];
                self::walk($child);
                continue;
            }
            $sequence[] = $child;
        }
        self::regroup($element, $sequence, null);
    }

    /**
     * @param list<DOMNode> $sequence
     */
    private static function regroup(DOMElement $parent, array $sequence, ?DOMNode $before): void
    {
        $hasEmphasis = false;
        foreach ($sequence as $node) {
            $hasEmphasis = $hasEmphasis || ($node instanceof DOMElement && isset(self::TAGS[strtolower($node->nodeName)]));
        }
        if (!$hasEmphasis) {
            return;
        }
        $atoms = [];
        foreach ($sequence as $node) {
            if (!self::atoms($node, '', $atoms)) {
                return;
            }
        }
        // the atoms leave their old parents first: PHP 8.2 frees the children of an element that is dropped
        foreach ($atoms as [$atom]) {
            $atom->parentNode?->removeChild($atom);
        }
        foreach ($sequence as $node) {
            if ($node->parentNode === $parent) {
                $parent->removeChild($node);
            }
        }
        $doc = $parent->ownerDocument;
        foreach (self::build($doc, $atoms) as $node) {
            $before === null ? $parent->appendChild($node) : $parent->insertBefore($node, $before);
        }
    }

    /**
     * @param list<array{0: DOMNode, 1: string}> $atoms node and its emphasis ('', 'i', 'b', 'bi')
     */
    private static function atoms(DOMNode $node, string $format, array &$atoms): bool
    {
        $tag = $node instanceof DOMElement ? strtolower($node->nodeName) : '';
        if (isset(self::TAGS[$tag])) {
            $format = self::with($format, self::TAGS[$tag]);
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), self::BLOCKS, true)) {
                    return false;
                }
                if (!self::atoms($child, $format, $atoms)) {
                    return false;
                }
            }

            return true;
        }
        $atoms[] = [$node, $format];

        return true;
    }

    private static function with(string $format, string $flag): string
    {
        $flags = str_split($format . $flag);
        sort($flags);

        return implode('', array_unique($flags));
    }

    /**
     * @param list<array{0: DOMNode, 1: string}> $atoms
     * @return list<DOMNode>
     */
    private static function build(?DOMDocument $doc, array $atoms, int $depth = 0): array
    {
        if ($doc === null) {
            return [];
        }
        $out = [];
        $i = 0;
        $count = count($atoms);
        while ($i < $count) {
            $format = $atoms[$i][1];
            if ($format === '' || self::blank($atoms[$i][0])) {
                $out[] = $atoms[$i][0];
                $i++;
                continue;
            }
            $outer = str_contains($format, 'i') ? 'i' : 'b';
            $group = [];
            while ($i < $count && str_contains($atoms[$i][1], $outer)) {
                $group[] = $atoms[$i];
                $i++;
            }
            // white space at the end of the stretch goes after it
            $tail = [];
            while ($group !== [] && self::blank(end($group)[0])) {
                array_unshift($tail, array_pop($group)[0]);
            }
            $inner = array_map(static fn (array $a): array => [$a[0], str_replace($outer, '', $a[1])], $group);
            $wrapper = $doc->createElement($outer === 'i' ? 'em' : 'strong');
            foreach (self::build($doc, $inner, $depth + 1) as $node) {
                $wrapper->appendChild($node);
            }
            // inside other emphasis, a delimiter at the very start or end of it joins the outer one ("***")
            $previous = $out === [] ? ($depth > 0 ? null : '') : (string)end($out)->textContent;
            $next = $tail !== [] ? ' ' : ($i < $count ? (string)$atoms[$i][0]->textContent : ($depth > 0 ? null : ''));
            foreach (self::trimEdges($doc, $wrapper, $previous, $next, $depth > 0) as $node) {
                $out[] = $node;
            }
            foreach ($tail as $node) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * White space just inside a delimiter stops Markdown from seeing it: moved out. A delimiter CommonMark would
     * not read as one is left out, the text stays without that emphasis: an opening one that is not left-flanking,
     * a closing one that is not right-flanking, and, inside other emphasis, one that is both and could close
     * the outer one ("(**-70**)" in italics). $previous and $next are the text around it, null where the
     * delimiter joins the outer one.
     *
     * @return list<DOMNode>
     */
    private static function trimEdges(DOMDocument $doc, DOMElement $wrapper, ?string $previous, ?string $next, bool $nested): array
    {
        $before = '';
        $after = '';
        $first = $wrapper->firstChild;
        if ($first instanceof DOMText && preg_match('/^\s+/u', $first->data, $m) === 1) {
            $before = $m[0];
            $first->data = (string)substr($first->data, strlen($m[0]));
        }
        $last = $wrapper->lastChild;
        if ($last instanceof DOMText && preg_match('/\s+$/u', $last->data, $m) === 1) {
            $after = $m[0];
            $last->data = (string)substr($last->data, 0, -strlen($m[0]));
        }
        $text = $wrapper->textContent;
        if (trim($text) === '' && $wrapper->getElementsByTagName('img')->length === 0) {
            return [$doc->createTextNode($before . $text . $after)];
        }
        $left = $before !== '' ? ' ' : ($previous === null ? null : mb_substr($previous, -1));
        $right = $after !== '' ? ' ' : ($next === null ? null : mb_substr($next, 0, 1));
        $opens = $left === null || (self::leftFlanking($left, mb_substr($text, 0, 1))
            && !($nested && $before === '' && self::rightFlanking($left, mb_substr($text, 0, 1))));
        $closes = $right === null || (self::rightFlanking(mb_substr($text, -1), $right)
            && !($nested && $after === '' && self::leftFlanking(mb_substr($text, -1), $right)));
        $nodes = [$wrapper];
        if (!($opens && $closes)) {
            // out of the wrapper before it goes (PHP 8.2 frees the children of a dropped element)
            $nodes = iterator_to_array($wrapper->childNodes);
            foreach ($nodes as $node) {
                $wrapper->removeChild($node);
            }
        }

        return array_values(array_filter(array_merge(
            [$before !== '' ? $doc->createTextNode($before) : null],
            $nodes,
            [$after !== '' ? $doc->createTextNode($after) : null]
        )));
    }

    private static function leftFlanking(string $before, string $after): bool
    {
        return !self::space($after) && (!self::punctuation($after) || self::space($before) || self::punctuation($before));
    }

    private static function rightFlanking(string $before, string $after): bool
    {
        return !self::space($before) && (!self::punctuation($before) || self::space($after) || self::punctuation($after));
    }

    private static function space(string $char): bool
    {
        return $char === '' || preg_match('/^\s$/u', $char) === 1;
    }

    private static function punctuation(string $char): bool
    {
        return preg_match('/^[\p{P}\p{S}]$/u', $char) === 1;
    }

    private static function blank(DOMNode $node): bool
    {
        return $node instanceof DOMText && trim($node->data) === '';
    }
}

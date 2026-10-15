<?php

declare(strict_types=1);

namespace Jira2Redmine\Text;

/**
 * Inline code that Jira did not render. The ADF marks the text as code; Jira writes it as "{{...}}" and
 * renders that as monospace, unless the code holds braces itself or starts with a space: then Jira shows the
 * braces as text ("{{{{lc: {{{RELEASE|}}} }}}}", WIKI template code in 7 items). Where Jira's HTML shows
 * "{{" + the code + "}}" for exactly the text the ADF marks as code, it becomes <code> again.
 */
final class FailedMonospace
{
    /**
     * @param string|null $adf the same text as ADF
     */
    public static function restore(string $html, ?string $adf): string
    {
        if ($adf === null || !str_contains($html, '{{') || !str_contains($adf, '"code"')) {
            return $html;
        }
        $doc = json_decode($adf, true);
        if (!is_array($doc)) {
            return $html;
        }
        $codes = [];
        self::codes($doc, $codes);
        $codes = array_values(array_unique(array_filter(array_map('trim', $codes), static fn (string $c): bool => $c !== '')));
        usort($codes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($codes as $code) {
            $variants = array_unique([
                htmlspecialchars($code, ENT_NOQUOTES, 'UTF-8'),
                htmlspecialchars($code, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ]);
            foreach ($variants as $escaped) {
                $html = preg_replace_callback(
                    '/\{\{\s*' . preg_quote($escaped, '/') . '\s*\}\}/u',
                    static fn (): string => '<code>' . $escaped . '</code>',
                    $html
                ) ?? $html;
            }
        }

        return $html;
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $codes
     */
    private static function codes(array $node, array &$codes): void
    {
        if (($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null)) {
            foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
                if (is_array($mark) && ($mark['type'] ?? null) === 'code') {
                    $codes[] = $node['text'];
                }
            }
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                self::codes($child, $codes);
            }
        }
    }
}

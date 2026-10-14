<?php

declare(strict_types=1);

namespace Jira2Redmine\Text;

/**
 * Outlook "Safe Links": a mail forwarded to Jira carries every link wrapped as
 * https://<region>.safelinks.protection.outlook.com/?url=<the link>&data=<recipient, tenant, ...>&reserved=0.
 * The wrapper expires, needs the Microsoft redirect and carries the recipient's address; the comment
 * keeps the link itself. A wrapper without a usable url parameter stays as it is.
 */
final class Safelinks
{
    private const WRAPPED = '~https?://[A-Za-z0-9.-]*\.safelinks\.protection\.outlook\.com/?\?[^\s<>"\'()\[\]]+~i';

    public static function unwrap(string $text): string
    {
        if (stripos($text, 'safelinks.protection.outlook.com') === false) {
            return $text;
        }

        return preg_replace_callback(self::WRAPPED, static fn (array $m): string => self::target($m[0]) ?? $m[0], $text) ?? $text;
    }

    private static function target(string $wrapped): ?string
    {
        // the converter escapes Markdown characters in plain text ("\_"); the query has none of its own
        $query = parse_url(str_replace('\\', '', $wrapped), PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }
        parse_str($query, $parameters);
        $url = $parameters['url'] ?? null;
        if (!is_string($url) || preg_match('~^(?:https?://|mailto:)\S~i', $url) !== 1) {
            return null;
        }

        // parse_str decoded the parameter once, which is all it was encoded: keep the link's own escapes
        return strtr($url, [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E', '[' => '%5B', ']' => '%5D']);
    }
}

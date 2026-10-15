<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use League\HTMLToMarkdown\HtmlConverter;
use League\HTMLToMarkdown\Converter\TableConverter;
use Karvaka\AdfToGfm\Converter as AdfConverter;
use Jira2Redmine\Jira\IssueKeyAliases;
use Jira2Redmine\Text\Emphasis;
use Jira2Redmine\Text\FailedMonospace;
use Jira2Redmine\Text\IssueReference;
use Jira2Redmine\Text\MediaWikiSource;
use Jira2Redmine\Text\Safelinks;

/**
 * The label of a Markdown link, on one line: no bracket of its own (escaped ones and images are fine). A lazy "(.*?)" ran
 * from an earlier link's "[" to a later "](": a profile link then swallowed everything from that earlier link
 * on ("Linked to [SURV-977](...), second incident ... [Claire](profile)" became "Linked to user#66", WIKI-724).
 */
const MARKDOWN_LINK_LABEL = '((?:[^\[\]\\\\\n]|\\\\.|!\[[^\]\n]*\]\([^)\n]*\))*)';

/**
 * Markdown autolinks (<https://...>, <mailto:...>, <name@domain>) look like tags to DOMDocument, which
 * then drops the part after "@" and wraps the text in <p> (B15). The DOM passes over Markdown hide them
 * behind placeholders first and put them back after.
 *
 * @param array<string, string> $saved filled with placeholder => autolink
 */
function protectMarkdownAutolinks(string $text, array &$saved): string
{
    return preg_replace_callback(
        '/<(?:[A-Za-z][A-Za-z0-9+.\-]{1,31}:[^\s<>]*|[^\s<>@"\/]+@[^\s<>@"]+)>/',
        static function (array $m) use (&$saved): string {
            $placeholder = 'J2RAUTOLINK' . count($saved) . 'X';
            $saved[$placeholder] = $m[0];

            return $placeholder;
        },
        $text
    ) ?? $text;
}

/**
 * @param array<string, string> $saved
 */
function restoreMarkdownAutolinks(string $text, array $saved): string
{
    return $saved === [] ? $text : strtr($text, $saved);
}

/**
 * True when the text holds one of these HTML tags as a real tag ("<a href", "<img ", "<div>"), not just
 * the same letters at the start of an autolink such as "<alice@example.eu>".
 *
 * @param list<string> $tags
 */
function textHasHtmlTag(string $text, array $tags): bool
{
    return preg_match('/<(?:' . implode('|', array_map('preg_quote', $tags)) . ')(?=[\s>\/])/i', $text) === 1;
}

/**
 * @param string|null $adf the same text as ADF, when there is one: MediaWiki source in it becomes a code block
 */
function convertJiraHtmlToMarkdown(?string $html, array $attachments, ?string $adf = null): ?string
{
    if ($html === null) {
        return null;
    }

    $trimmed = trim($html);
    if ($trimmed === '') {
        return null;
    }

    // Jira geeft soms enkel: <!-- ADF macro (type = 'table') -->
    // Dat is geen HTML inhoud, dus forceer fallback naar ADF.
    $withoutComments = preg_replace('/<!--.*?-->/s', '', $trimmed);
    $withoutComments = is_string($withoutComments) ? trim($withoutComments) : $trimmed;
    if ($withoutComments === '' && stripos($trimmed, 'ADF macro') !== false) {
        return null;
    }

    // MediaWiki source pasted into Jira: Jira rendered it as a broken table; the ADF has the text (decision 26)
    $trimmed = MediaWikiSource::replaceInHtml($trimmed, MediaWikiSource::paragraphs($adf));
    // inline code Jira showed as "{{...}}" because the code holds braces itself
    $trimmed = FailedMonospace::restore($trimmed, $adf);

    // Heading anchors ("<h3><a name="Title"></a>Title</h3>"): Redmine makes its own. Left in, the empty
    // <a> stayed as HTML in the Markdown, and the DOM passes over it wrapped the whole text in <p>, so
    // Redmine showed the Markdown as text ("### ", "**", "&gt;").
    $trimmed = preg_replace('#<a\s+name=("[^"]*"|\'[^\']*\')\s*(?:/>|>\s*</a>)#i', '', $trimmed) ?? $trimmed;

    // A status lozenge (issue macro, status macro) is a coloured box in Jira; the plain word ran into the
    // text ("#170 Closed"). Inline code keeps it a separate label and cannot mix with other Markdown.
    $trimmed = preg_replace_callback(
        '#<span\b[^>]*\bclass="[^"]*\baui-lozenge\b[^"]*"[^>]*>(.*?)</span>#is',
        static function (array $m): string {
            $status = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $status === '' ? '' : '<code>' . htmlspecialchars($status, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</code>';
        },
        $trimmed
    ) ?? $trimmed;

    // Superscript and subscript have no Markdown; their text stays. Emphasis split by them ("<em>16</em><sup>
    // <em>th</em></sup>"), or nested and split by Jira, is regrouped so its delimiters do not touch (GEOS-53).
    $trimmed = preg_replace('#</?(?:sup|sub)\b[^>]*>#i', '', $trimmed) ?? $trimmed;
    $trimmed = Emphasis::normalize($trimmed);

    $trimmed = unnestLinks($trimmed);
    $rewrittenHtml = rewriteJiraAttachmentLinks($trimmed, $attachments);

    static $converter = null;
    if ($converter === null) {
        $converter = new HtmlConverter([
            'strip_tags' => true,
            'hard_break' => true,
            'remove_nodes' => 'script style',
        ]);
        $converter->getEnvironment()->addConverter(new TableConverter());
    }

    // The converter decodes a code block's text and then removes "<pre>" from it, the text "<pre>" a user typed
    // too (WIKI-843: MediaWiki source lost its "<pre>" lines). That text passes under another name.
    $rewrittenHtml = preg_replace('#&lt;(/?)pre\b#i', '&lt;$1j2rliteralpre', $rewrittenHtml) ?? $rewrittenHtml;

    try {
        $markdown = trim($converter->convert($rewrittenHtml));
    } catch (Throwable) {
        $markdown = trim(strip_tags($rewrittenHtml));
    }
    $markdown = preg_replace('#(<|&lt;)(/?)j2rliteralpre#i', '$1$2pre', $markdown) ?? $markdown;

    // extra safety: xml header die toch doorsijpelt
    $markdown = preg_replace('/^\s*<\?xml[^>]*\?>\s*/i', '', $markdown ?? '') ?? $markdown;
    $markdown = str_replace('<?xml encoding="utf-8"?>', '', $markdown);

    // UNESCAPE: verwijder door de Markdown-converter toegevoegde backslashes
    // alleen binnen attachment: tokens (bv. attachment:10876\_\_Report\_... -> attachment:10876__Report_...)
    $markdown = preg_replace_callback(
        '/attachment:\d+\\\\_\\\\_[^)\s]*/i',
        function ($m) {
            return str_replace('\\_', '_', $m[0]);
        },
        $markdown
    );

    // mailed comments: Outlook's safe-link wrapper around every link
    $markdown = Safelinks::unwrap((string)$markdown);
    $markdown = unescapeBareUrls($markdown);

    return $markdown !== '' ? $markdown : null;
}

/**
 * An address left as plain text (Jira showed broken link markup as text, WIKI-777) is escaped by the converter
 * like any text ("document\\_control"). Redmine links a bare address as it stands, backslashes included: a
 * broken link that shows them (both versions, checked). Inside such an address the escapes go; escaped
 * brackets stay, they end the address. Code, link labels and targets are left alone (Markdown reads escapes there).
 */
function unescapeBareUrls(string $markdown): string
{
    if (!str_contains($markdown, '://') || !str_contains($markdown, '\\')) {
        return $markdown;
    }
    $parts = preg_split('/(```.*?```|`[^`\n]*`)/s', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$markdown];
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            continue;
        }
        $parts[$i] = preg_replace_callback(
            '/(?<![(<])(?<!(?<!\\\\)\[)\bhttps?:\/\/(?:[^\s<>()\[\]\\\\]|\\\\[^\s\[\]])+/',
            static fn (array $m): string => (string)preg_replace('/\\\\([\\\\`*_{}()#+\-.!|~])/', '$1', $m[0]),
            $part
        ) ?? $part;
    }

    return implode('', $parts);
}

/**
 * A link inside a link: Jira's renderer writes one for an address with ">" in it (WIKI-520), and the converter
 * made nested Markdown links that Redmine showed as "[...](...)". The inner link gives up its address, its
 * text stays in the outer one.
 */
function unnestLinks(string $html): string
{
    if (preg_match('#<a\b[^>]*>(?:(?!</a>).)*?<a\b#is', $html) !== 1) {
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
    $changed = false;
    foreach (iterator_to_array($root->getElementsByTagName('a')) as $outer) {
        foreach (iterator_to_array($outer->getElementsByTagName('a')) as $inner) {
            $parent = $inner->parentNode;
            if ($parent === null) {
                continue;
            }
            foreach (iterator_to_array($inner->childNodes) as $child) {
                $parent->insertBefore($child, $inner);
            }
            $parent->removeChild($inner);
            $changed = true;
        }
    }
    if (!$changed) {
        return $html;
    }
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }

    return $out;
}

function rewriteJiraAttachmentLinks(string $html, array $attachments): string
{
    if ($attachments === []) {
        return $html;
    }

    // normalize attachments into the same shape used by mapAttachmentUrlToTarget
    $imgExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'tif', 'tiff'];
    $attachmentsMeta = [];
    foreach ($attachments as $aid => $unique) {
        $ext = strtolower(pathinfo($unique, PATHINFO_EXTENSION));
        $isImage = $ext !== '' && in_array($ext, $imgExts, true);
        $attachmentsMeta[(string)$aid] = [
            'unique' => (string)$unique,
            'sharepoint' => null,
            // convenience flag for this function
            'is_image' => $isImage,
        ];
    }

    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $options = 0;
    if (defined('LIBXML_HTML_NOIMPLIED')) $options |= LIBXML_HTML_NOIMPLIED;
    if (defined('LIBXML_HTML_NODEFDTD')) $options |= LIBXML_HTML_NODEFDTD;

    $payload = '<?xml encoding="utf-8"?>' . $html;
    if (!@$doc->loadHTML($payload, $options)) {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $html;
    }

    // 1) Eerst images normaliseren: zet src naar unique/SharePoint en verwijder preview attrs
    foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
        if (!$img instanceof DOMElement) continue;
        $src = (string)$img->getAttribute('src');
        if ($src === '') continue;

        // resolve via mapAttachmentUrlToTarget (dit zal absolute SharePoint URL's ook respecteren)
        $new = mapAttachmentUrlToTarget($src, $attachmentsMeta);
        $img->setAttribute('src', $new);

        // remove noisy attributes so Markdown converter niet met titles/alt's komt
        foreach (['title', 'alt', 'data-attachment-name', 'data-attachment-type', 'data-media-services-id', 'data-media-services-type'] as $attr) {
            if ($img->hasAttribute($attr)) $img->removeAttribute($attr);
        }

        // remove tiny rendericons (they are not our attachments)
        $cls = strtolower((string)$img->getAttribute('class'));
        if (str_contains($cls, 'rendericon') || str_contains($src, '/images/icons/')) {
            $parent = $img->parentNode;
            $parent?->removeChild($img);
        }
    }

    // 1b) Handle embedded media objects (video/audio) that reference attachment URLs.
    /**
     * @throws DOMException
     */
    $replaceMediaNode = function(DOMElement $element, ?string $sourceUrl) use ($doc, $attachmentsMeta): void {
        if ($sourceUrl === null || $sourceUrl === '') {
            return;
        }

        $resolved = mapAttachmentUrlToTarget($sourceUrl, $attachmentsMeta);
        if (preg_match('/^(\d+)__(.+)$/', $resolved, $mResolved)) {
            $aid = $mResolved[1];
            $isImage = $attachmentsMeta[$aid]['is_image'] ?? false;

            if ($isImage) {
                $img = $doc->createElement('img');
                $img->setAttribute('src', $resolved);
                $element->parentNode?->replaceChild($img, $element);
            } else {
                $textNode = $doc->createTextNode('attachment:' . $resolved);
                $element->parentNode?->replaceChild($textNode, $element);
            }
            return;
        }

        if ($resolved !== $sourceUrl) {
            $parts = parse_url($resolved);
            $basename = isset($parts['path']) ? basename($parts['path']) : '';
            $label = $basename !== '' ? $basename : $resolved;

            $link = $doc->createElement('a', $label);
            $link->setAttribute('href', $resolved);
            $element->parentNode?->replaceChild($link, $element);
        }
    };

    foreach (iterator_to_array($doc->getElementsByTagName('object')) as $object) {
        if (!$object instanceof DOMElement) continue;
        $source = null;
        if ($object->hasAttribute('data')) {
            $source = (string)$object->getAttribute('data');
        }
        if ($source === '' || $source === null) {
            foreach (iterator_to_array($object->getElementsByTagName('param')) as $param) {
                if (!$param instanceof DOMElement) continue;
                if (strtolower((string)$param->getAttribute('name')) === 'data') {
                    $source = (string)$param->getAttribute('value');
                    break;
                }
                if ($source === '' && strtolower((string)$param->getAttribute('name')) === 'src') {
                    $source = (string)$param->getAttribute('value');
                }
            }
        }
        if (($source === '' || $source === null) && $object->getElementsByTagName('embed')->length > 0) {
            $embed = $object->getElementsByTagName('embed')->item(0);
            if ($embed instanceof DOMElement) {
                $source = (string)$embed->getAttribute('src');
            }
        }
        $replaceMediaNode($object, $source);
    }

    foreach (iterator_to_array($doc->getElementsByTagName('embed')) as $embed) {
        if (!$embed instanceof DOMElement) continue;
        $source = (string)$embed->getAttribute('src');
        $replaceMediaNode($embed, $source);
    }

    // 2) Process anchors
    $links = iterator_to_array($doc->getElementsByTagName('a'));
    foreach ($links as $a) {
        if (!$a instanceof DOMElement) continue;

        $href = (string)$a->getAttribute('href');
        // quick-skip empty hrefs
        if ($href === '') {
            // still remove small rendericons if present
            foreach (iterator_to_array($a->getElementsByTagName('img')) as $chImg) {
                if (!$chImg instanceof DOMElement) continue;
                $cls = strtolower((string)$chImg->getAttribute('class'));
                $src = (string)$chImg->getAttribute('src');
                if (str_contains($cls, 'rendericon') || str_contains($src, '/images/icons/')) {
                    $parent = $chImg->parentNode;
                    $parent?->removeChild($chImg);
                }
            }
            continue;
        }

        // verwijder preview/title/preview attributen zodat Markdown geen "title" toevoegt
        foreach (['title', 'file-preview-title', 'file-preview-id', 'file-preview-type', 'data-linked-resource-id', 'data-attachment-name', 'data-attachment-type'] as $attr) {
            if ($a->hasAttribute($attr)) $a->removeAttribute($attr);
        }

        // normaliseer target URL naar unieke naam of SharePoint (mapAttachmentUrlToTarget kent de REST patronen)
        $new = mapAttachmentUrlToTarget($href, $attachmentsMeta);

        // Als de anchor een IMG bevat: kijk of het een echte attachment-image is.
        $imgs = $a->getElementsByTagName('img');
        if ($imgs->length > 0) {
            $firstImg = $imgs->item(0);
            if ($firstImg instanceof DOMElement) {
                $imgSrc = (string)$firstImg->getAttribute('src');

                // Als img src naar een unique filename verwijst, detecteer attachment id
                if (preg_match('/^(\d+)__.+$/', $imgSrc, $mImg)) {
                    $aidImg = $mImg[1];
                    $isImgAttachment = $attachmentsMeta[$aidImg]['is_image'] ?? false;
                } else {
                    // fallback: probeer mapAttachmentUrlToTarget op originele src
                    $resolved = mapAttachmentUrlToTarget($imgSrc, $attachmentsMeta);
                    if (preg_match('/^(\d+)__.+$/', $resolved, $mImg2)) {
                        $aidImg = $mImg2[1];
                        $isImgAttachment = $attachmentsMeta[$aidImg]['is_image'] ?? false;
                        // ensure img src is the resolved one
                        $firstImg->setAttribute('src', $resolved);
                    } else {
                        $isImgAttachment = false;
                    }
                }

                if ($isImgAttachment) {
                    // vervang <a><img/></a> door alleen de <img/> (geen klikbare link)
                    $parent = $a->parentNode;
                    if ($parent !== null) {
                        // when we move $firstImg it is removed from $a automatically
                        $parent->replaceChild($firstImg, $a);
                        continue;
                    }
                }
            }
        }

        // Nu: geen embedded image — behandel anchor zelf
        // Als $new is onze unique filename (pattern 'digits__...') => kijk of image of niet
        if (preg_match('/^(\d+)__(.+)$/', $new, $mNew)) {
            $aid = $mNew[1];
            $isImage = $attachmentsMeta[$aid]['is_image'] ?? false;

            if (!$isImage) {
                // Non-image: vervang de hele <a> door plain text "attachment:{unique}"
                $textNode = $doc->createTextNode('attachment:' . $new);
                $parent = $a->parentNode;
                $parent?->replaceChild($textNode, $a);
            } else {
                // image (maar zonder inner <img>): laat anchor bestaan en zet href naar unique
                $a->setAttribute('href', $new);
                $linkText = trim((string)$a->textContent);
                if ($linkText === '') {
                    $a->textContent = $new; // fallback linktekst
                }
            }
        } else {
            // $new is waarschijnlijk een SharePoint absolute URL of andere externe URL
            $a->setAttribute('href', $new);
            $linkText = trim((string)$a->textContent);
            if ($linkText === '') {
                $parts = parse_url($new);
                if (isset($parts['path'])) {
                    $basename = basename($parts['path']);
                    $a->textContent = $basename !== '' ? $basename : $new;
                } else {
                    $a->textContent = $new;
                }
            }
        }

        // --- BEGIN: collapse split digits inside Jira-issue anchors (safe)
        // If the anchor was replaced earlier (parentNode === null), skip.
        if ($a->parentNode === null) {
            continue;
        }

        $linkText = trim((string)$a->textContent);

        // Detect if this anchor is a Jira issue link / macro
        $hrefLower = strtolower($href);
        $newLower = strtolower((string)$new);
        $cls = strtolower((string)$a->getAttribute('class'));

        $isJiraIssueLink = false;
        if (preg_match('#/browse/[A-Z][A-Z0-9]+-[0-9]+#i', $href) || preg_match('#/browse/[A-Z][A-Z0-9]+-[0-9]+#i', (string)$new)) {
            $isJiraIssueLink = true;
        } elseif (strpos($hrefLower, 'selectedissue=') !== false || strpos($newLower, 'selectedissue=') !== false) {
            $isJiraIssueLink = true;
        } elseif ($a->hasAttribute('data-jira-key') || str_contains($cls, 'jira-issue-macro') || str_contains($cls, 'issue-link')) {
            $isJiraIssueLink = true;
        }

        if ($isJiraIssueLink && $linkText !== '') {
            $iter = 0;
            do {
                $iter++;
                $prev = $linkText;

                // 1) Collapse '#digits digits...' -> '#digitsdigits...'
                $linkText = preg_replace_callback(
                    '/#((?:\s*\d)+)/u',
                    function ($m) {
                        return '#' . preg_replace('/\s+/u', '', $m[1]);
                    },
                    $linkText
                );

                // 2) Collapse any adjacent digit groups separated by whitespace '3 78' -> '378'
                $linkText = preg_replace('/(\d)\s+(\d)/u', '$1$2', $linkText);

                // 3) Collapse splits for issue-key style tokens, e.g. 'SURV-3 78' -> 'SURV-378'
                $linkText = preg_replace('/([A-Za-z0-9]+-[0-9]+)\s+([0-9]+)/u', '$1$2', $linkText);

                if ($linkText === $prev) break;
            } while ($iter < 6);

            // assign cleaned text back to anchor
            $a->textContent = $linkText;
        }
        // --- END

    } // end foreach anchors

    $converted = $doc->saveHTML();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $converted !== false ? preg_replace('/^\s*<\?xml[^>]+\?>\s*/i', '', $converted) : $html;
}

/**
 * @throws JsonException
 */
function convertDescriptionToMarkdown(
    string $adfJson,
    AdfConverter $adfToMd
): string {
    $adfJson = trim($adfJson);

    if ($adfJson !== '') {
        $node = $adfToMd->convert($adfJson);
        $md   = trim(Safelinks::unwrap($node->toMarkdown()));
        if ($md !== '') return $md;
    }

    return '';
}

function convertJiraAdfToPlaintext(mixed $descriptionAdf): ?string
{
    if ($descriptionAdf === null) {
        return null;
    }

    if (is_string($descriptionAdf)) {
        $trimmed = trim($descriptionAdf);
        return $trimmed !== '' ? $trimmed : null;
    }

    if (!is_array($descriptionAdf)) {
        return null;
    }

    $fragments = [];

    $stack = [$descriptionAdf];
    while ($stack !== []) {
        $current = array_pop($stack);
        if (is_array($current)) {
            if (isset($current['text']) && is_string($current['text'])) {
                $fragments[] = $current['text'];
            }
            if (isset($current['content']) && is_array($current['content'])) {
                foreach (array_reverse($current['content']) as $child) {
                    $stack[] = $child;
                }
                $fragments[] = PHP_EOL;
            }
        }
    }

    $text = trim(preg_replace('/\n{3,}/', PHP_EOL . PHP_EOL, implode('', $fragments)) ?? '');

    return $text !== '' ? $text : null;
}

/**
 * Replace a single URL that points to a Jira attachment with either 'attachment:uniqueName' or SharePoint URL.
 * Returns replacement string (not the whole markdown/img tag).
 *
 * @param string $url
 * @param array<string, array{unique: string, sharepoint: ?string}> $attachmentsMap keyed by attachment id
 * @return string replacement URL (or original $url if no match)
 */
function mapAttachmentUrlToTarget(string $url, array $attachmentsMap): string
{
    // Support explicit unique tokens "attachment:{id}__name" or bare "1234__name"
    $uniqueCandidate = $url;
    if (str_starts_with($uniqueCandidate, 'attachment:')) {
        $uniqueCandidate = substr($uniqueCandidate, strlen('attachment:'));
    }
    if (preg_match('/^(\d+)__/', $uniqueCandidate, $m)) {
        $id = $m[1];
        if (isset($attachmentsMap[$id])) {
            $meta = $attachmentsMap[$id];
            return !empty($meta['sharepoint']) ? $meta['sharepoint'] : $meta['unique'];
        }
    }

    // Patterns to find numeric attachment ids in common Jira URL shapes
    $patterns = [
        '#/rest/api/\d+/attachment/content/(\d+)#i',
        '#/rest/api/\d+/attachment/thumbnail/(\d+)#i',
        '#/attachment/content/(\d+)#i',
        '#/attachment/(\d+)#i',
        '#/attachments/(\d+)#i',
        '#/secure/attachment/(\d+)#i',
        '#attachment/content/(\d+)#i',
        '#(\d+)(?:[^\d]|$)#' // fallback
    ];

    foreach ($patterns as $pat) {
        if (preg_match($pat, $url, $m)) {
            $id = $m[1] ?? null;
            if ($id === null) continue;
            if (!isset($attachmentsMap[$id])) {
                return $url; // niet een van onze attachments
            }
            $meta = $attachmentsMap[$id];
            return !empty($meta['sharepoint']) ? $meta['sharepoint'] : $meta['unique'];
        }
    }

    return $url;
}

/**
 * Link markup that Jira showed as plain text, typed by a user as Jira wiki markup "[label|url]" or as
 * Markdown "[label](url)", reaches us escaped by the HTML converter ("\\[label|url\\]"). The link rewrites
 * after it ignored the escapes and left half a link: "](" visible in Redmine (B42). It becomes a real
 * link here; parentheses in the address are percent-encoded so the link stays one link.
 */
function restoreLiteralMarkupLinks(string $text): string
{
    if (!str_contains($text, '\\[')) {
        return $text;
    }

    $address = static function (string $url): string {
        $url = (string)preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!|~])/', '$1', $url);

        return strtr(rtrim($url), ['(' => '%28', ')' => '%29', ' ' => '%20']);
    };
    // brackets in the label stay escaped: "[[1]|url]" (a citation) is the link "\[1\]"
    $label = static fn (string $value): string => strtr(
        trim((string)preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!|~])/', '$1', $value)),
        ['[' => '\\[', ']' => '\\]']
    );

    // White space inside the brackets separated the link from the words around it ("to[ glossary|url]."): it
    // stays outside the link, where a word touches it (WIKI-613 read "toglossary").
    $link = static function (string $before, string $rawLabel, string $url, string $after) use ($label, $address): string {
        $lead = $before !== '' && preg_match('/^\s/u', $rawLabel) === 1 ? ' ' : '';
        $trail = $after !== '' && preg_match('/\s$/u', $rawLabel) === 1 ? ' ' : '';

        return $lead . '[' . $label($rawLabel) . '](' . $address($url) . ')' . $trail;
    };

    // \[label\](url\)  (Markdown typed into Jira)
    $text = (string)preg_replace_callback(
        '/(?:(?<=(\S)))?\\\\\[([^\]\n]{1,300}?)\\\\\]\((https?:\/\/(?:[^\s()\\\\]|\\\\.|\([^\s()]*\))+)\\\\?\)(?=(\S?))/u',
        static fn (array $m): string => $link($m[1], $m[2], $m[3], $m[4]),
        $text
    );

    // \[label|url\]  (Jira wiki markup typed into the new editor)
    return (string)preg_replace_callback(
        '/(?:(?<=(\S)))?\\\\\[((?:\\\\[\[\]]|[^\]|\n]){1,300}?\s*)(?:\\\\)?\|\s*(https?:\/\/(?:[^\s\]\\\\]|\\\\[^\]])+)\\\\?\](?=(\S?))/u',
        static fn (array $m): string => $link($m[1], $m[2], $m[3], $m[4]),
        $text
    );
}

/**
 * Jira user avatars (images served by Jira's REST API) inside a link keep the link and its label;
 * standalone ones are dropped. Used for descriptions (10) and comments (11): avatars reached Redmine in
 * 3 issues and 3 journals of the WIKI run.
 */
function removeJiraAvatarImages(string $text): string
{
    // [![image](...rest/api/universal_avatar... ) label](real_url)  => [label](real_url)
    $text = (string)preg_replace_callback(
        '/\[\s*!\[[^]]*]\(\s*https?:\/\/[^)]+\/rest\/api\/[^)]+\)\s*([^]]*)]\((https?:\/\/[^)]+)\)/i',
        static function (array $m): string {
            $visible = trim($m[1]);

            return '[' . ($visible !== '' ? $visible : $m[2]) . '](' . $m[2] . ')';
        },
        $text
    );

    return (string)preg_replace('/!\[[^]]*]\(\s*https?:\/\/[^)]+\/rest\/api\/[^)]+\)/i', '', $text);
}

/**
 * Lower-level worker used in transform phase.
 * - preserves lines "Original Jira issue:" (placeholder/restore)
 * - removes avatar images
 * - replaces profile links to user#<id> when mapping exists via $userMap
 * - canonicalizes issue links to https://host/browse/KEY
 */
function transform_text_in_transform_phase(string $text, array $userMap): string
{
    if (trim($text) === '') return $text;

    // quick cleanup: malformed markdown links pointing to user#<id> -> user#<id>
    // also handle url-encoded spaces (%20) and url-encoded hash (%23)
    $text = preg_replace(
        '/\[[^]]*]\(\s*user(?:%20|\s)*(?:%23|#)?(?:%20|\s)*([0-9]+)[^)]*\)/i',
        'user#$1',
        $text
    ) ?? $text;

    // safety: remove an accidental space between "user" and "#123" ("user #123" -> "user#123"); without
    // the "#" it is text ("the user 5 minutes later", B44)
    $text = preg_replace(
        '/\buser[[:space:]]+#[[:space:]]*([0-9]+)/i',
        'user#$1',
        $text
    ) ?? $text;

    $text = restoreLiteralMarkupLinks($text);

    // 1) Preserve Original Jira issue lines by placeholders
    $orig = [];
    $i = 0;
    $text = preg_replace_callback(
        '/(?m)^[[:space:]>]*Original Jira issue:[^\r\n]*(?:\r?\n)?/',
        function($m) use (&$orig, &$i) {
            $k = "__ORIGJIRA_" . ($i++);
            $orig[$k] = $m[0];
            return $k . PHP_EOL;
        },
        $text
    );

    // 2) Remove avatar images inside links or standalone
    $text = removeJiraAvatarImages($text);

    // helper closures
    $unwrapSafelink = function($url) {
        if (stripos($url, 'safelinks.protection.outlook.com') !== false) {
            $parts = parse_url($url);
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                if (!empty($q['url'])) return rawurldecode($q['url']);
            }
        }
        return $url;
    };
    $stripTrailing = function($u) {
        return rtrim(preg_replace('/[)\]>.,;:\'"]+$/u', '', $u));
    };

    // 3) Replace profile markdown links and raw profile URLs to user#id
    // markdown [Name](https://.../secure/ViewProfile.jspa?accountId=...)
    $text = preg_replace_callback(
        '/\[' . MARKDOWN_LINK_LABEL . ']\(\s*(https?:\/\/[^\s)]+\/secure\/ViewProfile(?:\.jspa)?[^)]*)\)/i',
        function($m) use ($unwrapSafelink, $stripTrailing, $userMap) {
            $label = $m[1];
            $url = $unwrapSafelink($m[2]);
            $url = $stripTrailing($url);
            $acc = null;
            if (preg_match('/accountId=([^&\s]+)/', $url, $mm)) $acc = rawurldecode($mm[1]);
            if ($acc === null && preg_match('~/secure/ViewProfile/([^/]+)~i',$url,$mm2)) $acc = rawurldecode($mm2[1]);
            if ($acc !== null && isset($userMap[$acc])) {
                return 'user#' . $userMap[$acc];
            }
            return $label ?: $url;
        },
        $text
    );
    // raw profile urls
    $text = preg_replace_callback(
        '/https?:\/\/[^\s)\]]+\/secure\/ViewProfile(?:\.jspa)?[^\s)\]]*/i',
        function($m) use ($unwrapSafelink, $stripTrailing, $userMap) {
            $url = $unwrapSafelink($m[0]); $url = $stripTrailing($url);
            $acc = null;
            if (preg_match('/accountId=([^&\s]+)/', $url, $mm)) $acc = rawurldecode($mm[1]);
            if ($acc !== null && isset($userMap[$acc])) return 'user#' . $userMap[$acc];
            return $url;
        },
        $text
    );

    // 4) canonicaliseer ISSUE links to https://host/browse/KEY
    // handle wiki-style [label|url]
    $text = preg_replace_callback(
        '/\[\s*([^|\]]+?)\s*\|\s*(https?:\/\/[^]\s]+)\s*]/i',
        function($m) use ($unwrapSafelink, $stripTrailing) {
            $label = $m[1]; $url = $unwrapSafelink($m[2]); $url = $stripTrailing($url);
            // try to find KEY
            if (preg_match('~/browse/([A-Z][A-Z0-9]+-[0-9]+)~i', $url, $k)) {
                $host = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
                return '[' . $label . '](' . $host . '/browse/' . strtoupper($k[1]) . ')';
            }
            if (preg_match('/[?&]selectedIssue=([A-Z][A-Z0-9]+-[0-9]+)/i', $url, $k2)) {
                $uHost = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
                return '[' . $label . '](' . $uHost . '/browse/' . strtoupper($k2[1]) . ')';
            }
            return '[' . $label . '](' . $url . ')';
        },
        $text
    );

    // markdown links [label](url)
    $text = preg_replace_callback(
        '/\[' . MARKDOWN_LINK_LABEL . ']\(\s*(https?:\/\/[^\s)]+)(?:\s+"[^"]*")?\s*\)/i',
        function($m) use ($unwrapSafelink, $stripTrailing) {
            $label = $m[1]; $url = $unwrapSafelink($m[2]); $url = $stripTrailing($url);
            // selectedIssue or browse:
            if (preg_match('~/browse/([A-Z][A-Z0-9]+-[0-9]+)~i', $url, $k)) {
                $host = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
                return '[' . $label . '](' . $host . '/browse/' . strtoupper($k[1]) . ')';
            }
            if (preg_match('/[?&]selectedIssue=([A-Z][A-Z0-9]+-[0-9]+)/i', $url, $k2)) {
                $host = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
                return '[' . $label . '](' . $host . '/browse/' . strtoupper($k2[1]) . ')';
            }
            // board URLs with selectedIssue
            if (preg_match('/[?&]selectedIssue=([A-Z][A-Z0-9]+-[0-9]+)/i', $url, $k3)) {
                $host = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
                return '[' . $label . '](' . $host . '/browse/' . strtoupper($k3[1]) . ')';
            }
            return '[' . $label . '](' . $url . ')';
        },
        $text
    );

    // 5) restore Original Jira issue lines
    if (!empty($orig)) {
        foreach ($orig as $k => $v) {
            $text = str_replace($k . PHP_EOL, $v, $text);
            $text = str_replace($k, $v, $text);
        }
    }

    return normalizeRedmineReferenceSpacing($text);
}

/**
 * Replace Jira issue links in a text blob with #redmine_id references.
 *
 * @param array<string, int> $issueMap
 */
function replaceIssueLinksInText(string $text, array $issueMap): string
{
    if ($issueMap === []) {
        return $text;
    }

    return normalizeRedmineReferenceSpacing(replaceIssueLinksOnly($text, $issueMap));
}

/**
 * The Jira issue links of a text replaced by #redmine_id references, and nothing else: the rest of the text, its
 * spacing around references included, stays as it is.
 *
 * @param array<string, int> $issueMap
 */
function replaceIssueLinksOnly(string $text, array $issueMap): string
{
    if ($issueMap === []) {
        return $text;
    }

    $replaceUrlToHash = function(string $urlOrLink) use ($issueMap): string {
        if (preg_match('~/browse/([A-Z][A-Z0-9]+-[0-9]+)~i', $urlOrLink, $m)) {
            $key = strtoupper($m[1]);
            if (isset($issueMap[$key])) {
                return '#' . $issueMap[$key];
            }
        }
        if (preg_match('/[?&]selectedIssue=([A-Z][A-Z0-9]+-[0-9]+)/i', $urlOrLink, $m2)) {
            $key = strtoupper($m2[1]);
            if (isset($issueMap[$key])) {
                return '#' . $issueMap[$key];
            }
        }
        return $urlOrLink;
    };

    $preserve = [];
    $i = 0;
    $protected = preg_replace_callback(
        '/(?m)^[[:space:]>]*Original Jira issue:[^\r\n]*(?:\r?\n)?/',
        function($m) use (&$preserve, &$i) {
            $key = "__ORIGJIRA_{$i}__";
            $preserve[$key] = $m[0];
            $i++;
            return $key . PHP_EOL;
        },
        $text
    );

    $replaced = preg_replace_callback(
        '/\[' . MARKDOWN_LINK_LABEL . ']\(\s*(https?:\/\/[^\s)]+)(?:\s+"[^"]*")?\s*\)([\p{L}\p{N}]*)/iu',
        function($m) use ($issueMap) {
            $key = IssueReference::keyOfUrl($m[2]);
            if ($key === null || !isset($issueMap[$key])) {
                return $m[0];
            }
            return IssueReference::inText($m[1], $m[3], $key, $issueMap[$key]);
        },
        $protected
    );

    $replaced = preg_replace_callback(
        '/https?:\/\/[^\s)\]>]+/i',
        function($m) use ($replaceUrlToHash) {
            return $replaceUrlToHash($m[0]);
        },
        $replaced
    );

    if (!empty($preserve)) {
        foreach ($preserve as $key => $value) {
            $replaced = str_replace($key . PHP_EOL, $value, $replaced);
        }
    }

    return $replaced;
}

/**
 * Ensure Redmine reference tokens (attachment:, user#, #123) are separated from surrounding text,
 * but do NOT introduce spaces inside numeric tokens. Final conservative cleanup collapses
 * any accidental spaces inside numeric sequences after '#' or after 'user#'.
 */
function normalizeRedmineReferenceSpacing(string $text): string
{
    if (trim($text) === '') {
        return $text;
    }

    // Normalize obvious user tokens and strip stray '< ' artifacts
    $text = preg_replace('/user(?:%20|\s)*#(?:%20|\s)*(\d+)/iu', 'user#$1', $text) ?? $text;
    $text = preg_replace('/<\s*(user#\d+)/iu', '$1', $text) ?? $text;
    $text = preg_replace('/<\s*(#\d+)/u', '$1', $text) ?? $text;

    // Ensure a separating space before inline attachment tokens when glued to previous non-space
    // Only insert if there is a preceding character (use lookbehind for a non-space)
    $text = preg_replace('/(?<=\S)(attachment:\d+__)/u', ' $1', $text) ?? $text;

    // Insert a space before "user#123" when it's glued to a preceding word token (e.g. "worduser#123" -> "word user#123")
    $text = preg_replace('/(?<=\w)(user#\d+\b)/u', ' $1', $text) ?? $text;

    // Insert a space before "#123" when preceded by a word char, but do NOT split "user#123"
    $text = preg_replace('/(?<=\w)(?<!user)(?=#\d+\b)/iu', ' ', $text) ?? $text;

    // Ensure a space after tokens when immediately followed by letters/underscore
    $text = preg_replace('/(user#\d+)(?=[\p{L}_\[])/u', '$1 ', $text) ?? $text;
    $text = preg_replace('/(#\d+)(?=[\p{L}_])/u', '$1 ', $text) ?? $text;

    // Ensure punctuation after tokens is separated by a space for readability
    $text = preg_replace('/(user#\d+)(?=[.,;:!?])/u', '$1 ', $text) ?? $text;
    $text = preg_replace('/(#\d+)(?=[.,;:!?])/u', '$1 ', $text) ?? $text;

    // No joining of digits after a reference: "user#332 4 lines" became "user#3324" (another user) and
    // "#12 3 times" "#123" (B44).

    // Final normalization of user token encodings (defensive); the "#" is required, "user 5" is text.
    $text = preg_replace('/\buser(?:%20|\s)*(?:%23|#)(?:%20|\s)*([0-9]+)\b/iu', 'user#$1', $text) ?? $text;

    return $text;
}

/**
 * Old issue keys in the staged texts' links (B54): every key that is not a staged issue and not asked
 * before is asked of Jira once (GET issue/{key}?fields=key, read-only); Jira answers with the current
 * key (a renamed project, a moved issue) or not at all (deleted, not visible). Every answer is kept, so a
 * rerun asks only new keys. Returns the number of old keys that resolve to another key.
 */
function refreshIssueKeyAliases(Client $client, PDO $pdo): int
{
    $known = [];
    $staged = $pdo->query('SELECT issue_key FROM staging_jira_issues UNION SELECT old_key FROM staging_jira_issue_key_aliases');
    foreach ($staged !== false ? $staged->fetchAll(PDO::FETCH_COLUMN) : [] as $key) {
        $known[strtoupper((string)$key)] = true;
    }
    $wanted = [];
    $texts = $pdo->query("SELECT description_html FROM staging_jira_issues WHERE description_html LIKE '%/browse/%'
        UNION ALL SELECT body_html FROM staging_jira_comments WHERE body_html LIKE '%/browse/%'");
    foreach ($texts !== false ? $texts->fetchAll(PDO::FETCH_COLUMN) : [] as $html) {
        foreach (IssueKeyAliases::keys((string)$html) as $key) {
            if (!isset($known[$key])) {
                $wanted[$key] = true;
            }
        }
    }
    $store = $pdo->prepare('INSERT INTO staging_jira_issue_key_aliases (old_key, current_key) VALUES (:old, :current)
        ON DUPLICATE KEY UPDATE current_key = VALUES(current_key), extracted_at = CURRENT_TIMESTAMP');
    if ($store === false) {
        throw new RuntimeException('Failed to prepare the issue key alias insert.');
    }
    foreach (array_keys($wanted) as $key) {
        try {
            $issue = json_decode((string)$client->get('/rest/api/3/issue/' . rawurlencode((string)$key), ['query' => ['fields' => 'key']])->getBody(), true);
            $current = strtoupper((string)($issue['key'] ?? ''));
        } catch (Throwable) {
            // deleted or not visible to the token: the link stays a Jira link; asked again on a rerun
            continue;
        }
        if ($current !== '') {
            $store->execute(['old' => $key, 'current' => $current]);
        }
    }
    $count = $pdo->query('SELECT COUNT(*) FROM staging_jira_issue_key_aliases WHERE old_key <> current_key');
    $aliases = $count !== false ? (int)$count->fetchColumn() : 0;
    printf("  Issue keys in links asked of Jira: %d; old keys that are another issue now: %d (B54).%s", count($wanted), $aliases, PHP_EOL);

    return $aliases;
}

/**
 * Jira issue key => Redmine issue id of the migrated issues, also under the old keys Jira resolves to
 * them ("DATA-12" next to "GEOS-12", B54): the map every link rewrite uses (10, 11, the relink step).
 *
 * @return array<string, int>
 */
function loadIssueLinkMap(PDO $pdo): array
{
    $map = [];
    $rows = $pdo->query('SELECT jira_issue_key, redmine_issue_id FROM migration_mapping_issues WHERE redmine_issue_id IS NOT NULL');
    foreach ($rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $map[strtoupper((string)$row['jira_issue_key'])] = (int)$row['redmine_issue_id'];
    }
    $aliases = [];
    try {
        $aliasRows = $pdo->query('SELECT old_key, current_key FROM staging_jira_issue_key_aliases WHERE old_key <> current_key');
        foreach ($aliasRows !== false ? $aliasRows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $aliases[(string)$row['old_key']] = (string)$row['current_key'];
        }
    } catch (PDOException) {
        // a staging database from before B54 has no alias table: current keys only
    }

    return IssueKeyAliases::extend($map, $aliases);
}

/**
 * Vervang Jira issue links door #redmine_id in DB (issues only).
 * Optioneel ook PATCH naar Redmine zodat de issue description op Redmine zelf verandert.
 *
 * @param PDO $pdo
 * @param Client|null $redmineClient  (null = geen Redmine PATCH)
 * @param bool $useExtendedApi
 * @param string $extendedApiPrefix
 * @param bool $updateRedmine  true = PATCH Redmine issues' descriptions
 */
function replaceIssueLinksWithRedmineIds(
    PDO $pdo,
    ?Client $redmineClient = null,
    bool $useExtendedApi = false,
    string $extendedApiPrefix = '',
    bool $updateRedmine = false
): void {
    // preload mapping: JIRA key -> redmine_issue_id (former project keys too, B54)
    $issueMap = loadIssueLinkMap($pdo);
    if ($issueMap === []) {
        // niets te doen
        return;
    }

    // helper: vervang een url of markdown-link indien KEY matched
    $replaceUrlToHash = function(string $urlOrLink) use ($issueMap): string {
        // zoek KEY in /browse/KEY
        if (preg_match('~/browse/([A-Z][A-Z0-9]+-[0-9]+)~i', $urlOrLink, $m)) {
            $key = strtoupper($m[1]);
            if (isset($issueMap[$key])) return '#' . $issueMap[$key];
        }
        // zoek selectedIssue param
        if (preg_match('/[?&]selectedIssue=([A-Z][A-Z0-9]+-[0-9]+)/i', $urlOrLink, $m2)) {
            $key = strtoupper($m2[1]);
            if (isset($issueMap[$key])) return '#' . $issueMap[$key];
        }
        return $urlOrLink;
    };

    // 1) Update migration_mapping_issues.proposed_description, of created issues only: rewriting rows that
    // still wait for creation broke their automation hash, so every retry saw them as manual overrides (B6).
    $sel = $pdo->query("SELECT mapping_id, proposed_description, redmine_issue_id FROM migration_mapping_issues WHERE redmine_issue_id IS NOT NULL");
    $upd = $pdo->prepare("UPDATE migration_mapping_issues SET proposed_description = :desc, last_updated_at = CURRENT_TIMESTAMP WHERE mapping_id = :mid");

    while ($r = $sel->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$r['mapping_id'];
        $desc = (string)$r['proposed_description'];
        $redId = isset($r['redmine_issue_id']) ? (int)$r['redmine_issue_id'] : null;

        // bescherm Original Jira issue regels (eerst)
        $preserve = [];
        $i = 0;
        $desc = preg_replace_callback(
            '/(?m)^[[:space:]>]*Original Jira issue:[^\r\n]*(?:\r?\n)?/',
            function($m) use (&$preserve, &$i) {
                $key = "__ORIGJIRA_{$i}__";
                $preserve[$key] = $m[0];
                $i++;
                return $key . PHP_EOL;
            },
            $desc
        );

        // 1a) vervang markdown links [label](url)
        $new = preg_replace_callback('/\[' . MARKDOWN_LINK_LABEL . ']\(\s*(https?:\/\/[^\s)]+)(?:\s+"[^"]*")?\s*\)([\p{L}\p{N}]*)/iu', function($m) use ($issueMap) {
            $key = IssueReference::keyOfUrl($m[2]);
            if ($key === null || !isset($issueMap[$key])) return $m[0]; // geen wijziging
            return IssueReference::inText($m[1], $m[3], $key, $issueMap[$key]); // #1234, or "label (#1234)"
        }, $desc);

        // 1b) vervang raw urls
        $new = preg_replace_callback('/https?:\/\/[^\s)\]>]+/i', function($m) use ($replaceUrlToHash) {
            return $replaceUrlToHash($m[0]);
        }, $new);

        // restore preserved lines
        if (!empty($preserve)) {
            foreach ($preserve as $k => $v) $new = str_replace($k . PHP_EOL, $v, $new);
        }

        if ($new !== $desc) {
            $upd->execute([':desc' => $new, ':mid' => $mid]);
            // optioneel: PATCH Redmine issue description
            if ($updateRedmine && $redId !== null && $redId > 0 && $redmineClient !== null) {
                // build payload
                $payload = ['issue' => ['description' => $new]];
                $endpoint = $useExtendedApi
                    ? buildExtendedApiPath($extendedApiPrefix, sprintf('issues/%d.json', $redId))
                    : sprintf('issues/%d.json', $redId);
                $options = ['json' => $payload];
                if ($useExtendedApi) $options['query'] = ['notify' => 'false', 'send_notification' => 0];
                try {
                    $redmineClient->request($useExtendedApi ? 'patch' : 'put', $endpoint, $options);
                } catch (Throwable $e) {
                    // log en ga verder; we still persisted locally
                    printf("Warning: failed to patch Redmine #%d description: %s\n", $redId, $e->getMessage());
                }
            }
        }
    }

    // 2) Update migration_mapping_journals.proposed_notes
    // Note: journal proposed_notes are canonicalised elsewhere to avoid double-processing.
}

/**
 * Vervang Atlassian account-links door "user#redmine_user_id" waar mogelijk.
 *
 * @param string $text
 * @param array<string,array{redmine_user_id:int}> $userLookup   // keys = jira_account_id
 * @return string
 */
function replaceJiraUserLinksWithRedmineIds(string $text, array $userLookup): string
{
    return rewriteUserIdsAsMentions(replaceJiraUserLinksWithRedmineIdsOnly($text, $userLookup), $userLookup);
}

/**
 * Decision 4: a mention becomes "@login", Redmine's own mention syntax (rendered as a link, notifies the
 * user, found by mention search), instead of the plain user link "user#id". Users whose login is not
 * known keep "user#id".
 *
 * @param array<string, array<string, mixed>> $userLookup Jira account id => row with redmine_user_id, redmine_login
 */
function rewriteUserIdsAsMentions(string $text, array $userLookup): string
{
    $logins = [];
    foreach ($userLookup as $row) {
        $id = isset($row['redmine_user_id']) ? (int)$row['redmine_user_id'] : 0;
        $login = isset($row['redmine_login']) ? trim((string)$row['redmine_login']) : '';
        if ($id > 0 && $login !== '' && preg_match('/^[A-Za-z0-9_\-@.]+$/', $login) === 1) {
            $logins[$id] = $login;
        }
    }
    if ($logins === []) {
        return $text;
    }

    return preg_replace_callback(
        '/(?<![\w#@\/])user#(\d+)(?!\d)(?: (?=[,;:!?.)]))?(?=([\w\[]?))/',
        static function (array $m) use ($logins): string {
            if (!isset($logins[(int)$m[1]])) {
                return $m[0];
            }
            // Redmine needs a boundary after the login: a space before a letter, none before punctuation; a
            // link right after a mention is a separate word too, as in Jira (WIKI-37: "Claire Pirotseismic logfiles")
            return '@' . $logins[(int)$m[1]] . ($m[2] !== '' ? ' ' : '');
        },
        $text
    ) ?? $text;
}

function replaceJiraUserLinksWithRedmineIdsOnly(string $text, array $userLookup): string
{
    if (trim($text) === '') return $text;

    $text = preg_replace('/user#(\d+)\s+class=/i', 'user#$1', $text) ?? $text;
    $text = preg_replace('/\[[^]]*]\(\s*<?user#(\d+)[^)]*>\s*\)/i', 'user#$1', $text) ?? $text;
    $text = preg_replace('/\[[^]]*]\(\s*<?user(?:%20|\s)*#(?:%20|\s)*(\d+)[^)]*>\s*\)/i', 'user#$1', $text) ?? $text;
    $text = preg_replace('/\[[^]]*]\(\s*<?user(?:%20|\s)*#(?:%20|\s)*(\d+)[^)]*\)/i', 'user#$1', $text) ?? $text;

    $replaceAccountId = function (?string $maybeUrl) use ($userLookup): ?string {
        if ($maybeUrl === null || $maybeUrl === '') return null;

        $decoded = urldecode($maybeUrl);
        if (preg_match('/user#(\d+)/i', $decoded, $m)) {
            return 'user#' . $m[1];
        }

        // zoek accountId param (urlencoded mogelijk)
        if (preg_match('/[?&]accountId=([^&)\s]+)/i', $maybeUrl, $m)) {
            $acc = urldecode($m[1]);
            if ($acc !== '' && isset($userLookup[$acc]) && isset($userLookup[$acc]['redmine_user_id'])) {
                return 'user#' . (int)$userLookup[$acc]['redmine_user_id'];
            }
        }

        // soms zit account id in path? (gevraagd zelden) - fallback: geen vervanging
        return null;
    };

    // 1) Markdown links: [Name](https://...ViewProfile.jspa?accountId=...)
    // (also the relative profile link that the ADF path writes for a mention)
    $text = preg_replace_callback('/\[' . MARKDOWN_LINK_LABEL . ']\(\s*(https?:\/\/[^)\s]+|\/secure\/ViewProfile\.jspa\?[^)\s]+)\s*\)/mi', function ($m) use ($replaceAccountId) {
        $label = $m[1];
        $url = $m[2];
        $repl = $replaceAccountId($url);
        if ($repl !== null) {
            // we vervangen de hele markdown link door user#id
            return $repl;
        }
        return $m[0];
    }, $text);

    // 2) HTML anchors: <a href="...">Label</a> (autolinks hidden from the DOM, see B15)
    $autolinks = [];
    $text = protectMarkdownAutolinks($text, $autolinks);
    if (textHasHtmlTag($text, ['a'])) {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $payload = '<?xml encoding="utf-8"?>' . $text;
        $options = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) $options |= LIBXML_HTML_NOIMPLIED;
        if (defined('LIBXML_HTML_NODEFDTD')) $options |= LIBXML_HTML_NODEFDTD;

        if (@$doc->loadHTML($payload, $options)) {
            $anchors = iterator_to_array($doc->getElementsByTagName('a'));
            foreach ($anchors as $a) {
                if (!$a instanceof DOMElement) continue;

                // Prefer data-account-id attribute if present
                $acc = $a->getAttribute('data-account-id') ?: null;

                // fallback: look for accountId param in href
                $href = $a->getAttribute('href');
                if ($acc === null || $acc === '') {
                    if ($href !== '') {
                        if (preg_match('/[?&]accountId=([^&\s]+)/i', $href, $m)) {
                            $acc = rawurldecode($m[1]);
                        }
                        // sometimes path-based /secure/ViewProfile/<id>
                        if ($acc === null && preg_match('~/secure/ViewProfile/([^/]+)~i', $href, $m2)) {
                            $acc = rawurldecode($m2[1]);
                        }
                    }
                }

                // If we have an account id and mapping, replace the whole anchor node with text "user#ID"
                if ($acc !== null && $acc !== '') {
                    if (isset($userLookup[$acc]) && !empty($userLookup[$acc]['redmine_user_id'])) {
                        $replacement = $doc->createTextNode('user#' . (int)$userLookup[$acc]['redmine_user_id']);
                        $a->parentNode?->replaceChild($replacement, $a);
                        continue;
                    }
                }

                // Otherwise try matching an encoded accountId in other attributes (data-account-id, accountid)
                $accAttr = $a->getAttribute('accountid') ?: $a->getAttribute('data-account-id');
                if ($accAttr !== '') {
                    $accAttr = rawurldecode($accAttr);
                    if (isset($userLookup[$accAttr]) && !empty($userLookup[$accAttr]['redmine_user_id'])) {
                        $replacement = $doc->createTextNode('user#' . (int)$userLookup[$accAttr]['redmine_user_id']);
                        $a->parentNode?->replaceChild($replacement, $a);
                        continue;
                    }
                }
            }

            $out = $doc->saveHTML();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            // strip xml header
            $out = preg_replace('/^\s*<\?xml[^>]+\?>\s*/i', '', $out);
            $text = $out !== false ? $out : $text;
        } else {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }
    $text = restoreMarkdownAutolinks($text, $autolinks);

    // 3) Bare profile URLs (rare): replace direct URLs if possible
    $text = preg_replace_callback(
        '#https?://[^\s)\]]*/secure/ViewProfile\.jspa\?accountId=([^)\s&]+)#i',
        function ($m) use ($replaceAccountId) {
            $url = $m[0];
            $repl = $replaceAccountId($url);
            return $repl !== null ? $repl : $url;
        },
        $text
    );

    return normalizeRedmineReferenceSpacing($text);
}

/**
 * In-memory transform of text: replace browse/selectedIssue links by #redmine_id using provided map.
 *
 * @param string $text
 * @param array<string,int> $jiraToRedmine  keys uppercase Jira keys like 'WIKI-617'
 * @return string
 */
function inlineReplaceJiraIssueKeysWithHashes(string $text, array $jiraToRedmine): string
{
    if (trim($text) === '' || $jiraToRedmine === []) return $text;

    // markdown links [label](url)
    $text = preg_replace_callback('/\[' . MARKDOWN_LINK_LABEL . ']\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)([\p{L}\p{N}]*)/miu', function($m) use ($jiraToRedmine) {
        $key = IssueReference::keyOfUrl($m[2]);
        if ($key === null || !isset($jiraToRedmine[$key])) return $m[0];
        return IssueReference::inText($m[1], $m[3], $key, $jiraToRedmine[$key]);
    }, $text);

    // bare browse URLs and selectedIssue params
    $text = preg_replace_callback('#https?://[^\s)\]]*/browse/([A-Z][A-Z0-9]+-[0-9]+)(\?[^\s)\]]*)?#i', function($m) use ($jiraToRedmine) {
        $key = strtoupper($m[1]);
        return isset($jiraToRedmine[$key]) ? ('#' . $jiraToRedmine[$key]) : $m[0];
    }, $text);

    $text = preg_replace_callback('#[^\s)\]]*[?&]selectedIssue=([A-Z][A-Z0-9]+-[0-9]+)[^\s)\]]*#i', function($m) use ($jiraToRedmine) {
        $key = strtoupper($m[1]);
        return isset($jiraToRedmine[$key]) ? ('#' . $jiraToRedmine[$key]) : $m[0];
    }, $text);

    return normalizeRedmineReferenceSpacing($text);
}

/**
 * Normalize attachment links in a text blob (markdown or HTML).
 * - First does a DOM pass for <img> and <a> tags if HTML present.
 * - Then does a markdown-style pass replacing inline links/images.
 *
 * @param array<int|string, array{unique: string, sharepoint: ?string, is_image?: bool}> $attachmentsMap keyed by attachment id
 */
function normalizeAttachmentLinksInText(string $text, array $attachmentsMap): string
{
    if (trim($text) === '') return $text;

    // make Markdown-escaped underscores visible for our detection
    $text = str_replace('\\_', '_', $text);

    // DOM pass for images/anchors when HTML is present (autolinks hidden from the DOM, see B15)
    $autolinks = [];
    $text = protectMarkdownAutolinks($text, $autolinks);
    if (textHasHtmlTag($text, ['img', 'a', 'div'])) {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $options = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) $options |= LIBXML_HTML_NOIMPLIED;
        if (defined('LIBXML_HTML_NODEFDTD')) $options |= LIBXML_HTML_NODEFDTD;
        $payload = '<?xml encoding="utf-8"?>' . $text;
        if (@$doc->loadHTML($payload, $options)) {
            // process <img>
            foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
                if (!$img instanceof DOMElement) continue;
                $src = (string)$img->getAttribute('src');
                if ($src === '') continue;

                $new = mapAttachmentUrlToTarget($src, $attachmentsMap);
                $img->setAttribute('src', $new);

                // remove noisy attributes
                foreach (['title', 'alt', 'data-attachment-name', 'data-attachment-type', 'data-media-services-id', 'data-media-services-type'] as $attr) {
                    if ($img->hasAttribute($attr)) $img->removeAttribute($attr);
                }

                // remove tiny rendericons
                $cls = strtolower((string)$img->getAttribute('class'));
                if (str_contains($cls, 'rendericon') || str_contains($src, '/images/icons/')) {
                    $parent = $img->parentNode;
                    $parent?->removeChild($img);
                }
            }

            // process <a>
            foreach (iterator_to_array($doc->getElementsByTagName('a')) as $a) {
                if (!$a instanceof DOMElement) continue;
                $href = (string)$a->getAttribute('href');
                if ($href === '') continue;

                // remove preview/title attributes
                foreach (['title','file-preview-title','file-preview-id','file-preview-type','data-linked-resource-id','data-attachment-name','data-attachment-type'] as $attr) {
                    if ($a->hasAttribute($attr)) $a->removeAttribute($attr);
                }

                $new = mapAttachmentUrlToTarget($href, $attachmentsMap);

                // if anchor wraps an <img>, handle embedded images as images (replace <a><img/></a> with <img/>)
                $imgs = $a->getElementsByTagName('img');
                if ($imgs->length > 0) {
                    $firstImg = $imgs->item(0);
                    if ($firstImg instanceof DOMElement) {
                        $imgSrc = (string)$firstImg->getAttribute('src');
                        $resolved = mapAttachmentUrlToTarget($imgSrc, $attachmentsMap);
                        if (preg_match('/^(\d+)__.+$/', $resolved, $m)) {
                            $aidImg = $m[1];
                            $isImgAttachment = $attachmentsMap[$aidImg]['is_image'] ?? true;
                        } else {
                            $isImgAttachment = false;
                        }

                        if ($isImgAttachment) {
                            $parent = $a->parentNode;
                            $parent?->replaceChild($firstImg, $a);
                            continue;
                        }
                    }
                }

                // treat anchor
                if (preg_match('/^(\d+)__(.+)$/', $new, $mNew)) {
                    $aid = $mNew[1];
                    $isImage = $attachmentsMap[$aid]['is_image'] ?? false;
                    if (!$isImage) {
                        $textNode = $doc->createTextNode('attachment:' . $new);
                        $parent = $a->parentNode;
                        $parent?->replaceChild($textNode, $a);
                    } else {
                        $a->setAttribute('href', $new);
                        if (trim((string)$a->textContent) === '') $a->textContent = $new;
                    }
                } else {
                    // external/SharePoint or unchanged
                    $a->setAttribute('href', $new);
                    if (trim((string)$a->textContent) === '') {
                        $parts = parse_url($new);
                        if (isset($parts['path'])) {
                            $basename = basename($parts['path']);
                            $a->textContent = $basename !== '' ? $basename : $new;
                        } else {
                            $a->textContent = $new;
                        }
                    }
                }
            }

            $converted = $doc->saveHTML();
            if ($converted !== false) {
                $converted = preg_replace('/^\s*<\?xml[^>]+\?>\s*/i', '', $converted);
                $text = $converted;
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
    }
    $text = restoreMarkdownAutolinks($text, $autolinks);

    // Markdown-style replacements: ![alt](URL) and [label](URL)
    $text = preg_replace_callback(
        '/(!?\[[^]]*])\(\s*([^\s)]+)(?:\s+"([^"]*)")?\s*\)/m',
        function ($m) use ($attachmentsMap) {
            $label = $m[1];
            $url   = trim($m[2]);
            $isImageLabel = str_starts_with($label, '!');

            // explicit attachment:attachment:token
            if (str_starts_with($url, 'attachment:')) {
                $unique = substr($url, strlen('attachment:'));
                $mapped = mapAttachmentUrlToTarget($unique, $attachmentsMap);
                if (preg_match('#^https?://#i', $mapped)) {
                    return $label . '(' . $mapped . ')';
                }
                if (!$isImageLabel && preg_match('/^\d+__.+$/', $mapped)) {
                    return $label . '(attachment:' . $mapped . ')';
                }
                return $label . '(' . $mapped . ')';
            }

            // leave absolute SharePoint URLs alone
            if (preg_match('#^https?://#i', $url) && str_contains($url, 'sharepoint.com')) {
                return $m[0];
            }

            $new = mapAttachmentUrlToTarget($url, $attachmentsMap);
            if (preg_match('#^https?://#i', $new)) {
                return $label . '(' . $new . ')';
            }
            if (!$isImageLabel && preg_match('/^\d+__.+$/', $new)) {
                $new = 'attachment:' . $new;
            }

            return $label . '(' . $new . ')';
        },
        $text
    );

    // plain "attachment:123__filename" tokens -> resolve to SharePoint if available
    $text = preg_replace_callback(
        '/\battachment:(\d+__[^)\s,;]+)/i',
        function ($m) use ($attachmentsMap) {
            $unique = $m[1];
            $mapped = mapAttachmentUrlToTarget($unique, $attachmentsMap);
            if (preg_match('#^https?://#i', $mapped)) {
                return $mapped;
            }
            return $m[0];
        },
        $text
    );

    // bare unique filenames such as "10388__foo"
    $text = preg_replace_callback(
        '#(?<![/:])\b(\d+__[^)\s,;]+)#',
        function ($m) use ($attachmentsMap) {
            $unique = $m[1];
            $mapped = mapAttachmentUrlToTarget($unique, $attachmentsMap);
            if (preg_match('#^https?://#i', $mapped)) {
                return $mapped;
            }
            return $m[0];
        },
        $text
    );

    // leftover absolute REST API attachment URLs
    $text = preg_replace_callback(
        '#https?://[^\s)\]}]+/rest/api/\d+/attachment/(?:content|thumbnail)/(\d+)[^\s)\]}]*#i',
        function ($m) use ($attachmentsMap) {
            $id = $m[1] ?? null;
            if ($id === null || !isset($attachmentsMap[$id])) return $m[0];
            $meta = $attachmentsMap[$id];
            return !empty($meta['sharepoint']) ? $meta['sharepoint'] : $meta['unique'];
        },
        $text
    );

    // relative REST API attachment URLs
    $text = preg_replace_callback(
        '#/rest/api/\d+/attachment/(?:content|thumbnail)/(\d+)[^\s)\]}]*#i',
        function ($m) use ($attachmentsMap) {
            $id = $m[1] ?? null;
            if ($id === null || !isset($attachmentsMap[$id])) return $m[0];
            $meta = $attachmentsMap[$id];
            return !empty($meta['sharepoint']) ? $meta['sharepoint'] : $meta['unique'];
        },
        $text
    );

    // patterns like "/attachment/1234" or "/secure/attachment/1234"
    $text = preg_replace_callback(
        '#(?:/secure/attachment/|/attachment/|/attachment/content/)(\d+)#i',
        function ($m) use ($attachmentsMap) {
            $id = $m[1] ?? null;
            if ($id === null || !isset($attachmentsMap[$id])) return $m[0];
            $meta = $attachmentsMap[$id];
            return !empty($meta['sharepoint']) ? $meta['sharepoint'] : $meta['unique'];
        },
        $text
    );

    return normalizeRedmineReferenceSpacing($text);
}

/**
 * Jira attachment id => original file name, per Jira issue id, from staging (read once per run). Feeds
 * Jira2Redmine\Attachments\AttachmentNames (B14).
 *
 * @return array<string, array<string, string>>
 */
function attachmentOriginalNamesByIssue(PDO $pdo): array
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $index = [];
    $statement = $pdo->query('SELECT id, issue_id, filename FROM staging_jira_attachments');
    if ($statement !== false) {
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $index[(string)$row['issue_id']][(string)$row['id']] = (string)$row['filename'];
        }
        $statement->closeCursor();
    }

    return $cache[$key] = $index;
}


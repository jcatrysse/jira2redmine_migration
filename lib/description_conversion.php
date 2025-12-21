<?php

declare(strict_types=1);

use League\HTMLToMarkdown\HtmlConverter;
use League\HTMLToMarkdown\Converter\TableConverter;
use Karvaka\AdfToGfm\Converter as AdfConverter;

function convertJiraHtmlToMarkdown(?string $html, array $attachments): ?string
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

    try {
        $markdown = trim($converter->convert($rewrittenHtml));
    } catch (Throwable) {
        $markdown = trim(strip_tags($rewrittenHtml));
    }

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

    return $markdown !== '' ? $markdown : null;
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
    } // end foreach anchors

    $converted = $doc->saveHTML();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $converted !== false ? preg_replace('/^\s*<\?xml[^>]+\?>\s*/i', '', $converted) : $html;
}

function convertDescriptionToMarkdown(
    string $adfJson,
    AdfConverter $adfToMd
): string {
    $adfJson = trim($adfJson);

    if ($adfJson !== '') {
        $node = $adfToMd->convert($adfJson);
        $md   = trim($node->toMarkdown());
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

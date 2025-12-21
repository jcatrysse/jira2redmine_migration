<?php

declare(strict_types=1);

use GuzzleHttp\Client;
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

/**
 * Transformfase: vervang profile links naar user#ID, verwijder avatars,
 * en canonicaliseer issue links naar https://host/browse/KEY (maar vervang niet naar #id).
 *
 * Roept DB alleen per rij update aan (batch-friendly).
 */
function transformDescriptionsForUsersAndCanonicalizeIssues(PDO $pdo): void
{
    // preload user map: jira_account_id => redmine_user_id
    $userMap = [];
    $stmt = $pdo->query("SELECT jira_account_id, redmine_user_id, migration_status FROM migration_mapping_users");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!in_array($r['migration_status'], ['MATCH_FOUND','CREATION_SUCCESS'], true)) continue;
        if (!empty($r['jira_account_id']) && !empty($r['redmine_user_id'])) {
            $userMap[(string)$r['jira_account_id']] = (int)$r['redmine_user_id'];
        }
    }

    // prepare selects/updates
    $selIssues = $pdo->query("SELECT mapping_id, proposed_description FROM migration_mapping_issues");
    $updIssue = $pdo->prepare("UPDATE migration_mapping_issues SET proposed_description = :desc, last_updated_at = CURRENT_TIMESTAMP WHERE mapping_id = :mid");

    // do for issues
    while ($row = $selIssues->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$row['mapping_id'];
        $desc = (string)$row['proposed_description'];

        $new = transform_text_in_transform_phase($desc, $userMap);
        if ($new !== $desc) {
            $updIssue->execute([':desc' => $new, ':mid' => $mid]);
            printf("Transform: updated mapping_id %d\n", $mid);
        }
    }

    // optionally do the same for staged comments
    $selComments = $pdo->query("SELECT id, body_html FROM staging_jira_comments");
    $updComment = $pdo->prepare("UPDATE staging_jira_comments SET body_html = :b WHERE id = :id");
    while ($r = $selComments->fetch(PDO::FETCH_ASSOC)) {
        $id = $r['id'];
        $html = (string)$r['body_html'];
        $new = transform_text_in_transform_phase($html, $userMap);
        if ($new !== $html) {
            $updComment->execute([':b' => $new, ':id' => $id]);
            printf("Transform: updated comment id %s\n", $id);
        }
    }
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
    // [![image](...rest/api/universal_avatar... ) ](real_url)  => [ label ](real_url)
    $text = preg_replace_callback(
        '/\[\s*!\[[^]]*]\(\s*https?:\/\/[^)]+\/rest\/api\/[^)]+\)\s*([^]]*)]\((https?:\/\/[^)]+)\)/i',
        function($m){ $visible = trim($m[1]); return '[' . ($visible ?: $m[2]) . '](' . $m[2] . ')'; },
        $text
    );
    // remove standalone avatar images
    $text = preg_replace('/!\[[^]]*]\(\s*https?:\/\/[^)]+\/rest\/api\/[^)]+\)/i', '', $text);

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
        '/\[(.*?)]\(\s*(https?:\/\/[^\s)]+\/secure\/ViewProfile(?:\.jspa)?[^)]*)\)/i',
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
        '/\[(.*?)]\(\s*(https?:\/\/[^\s)]+)(?:\s+"[^"]*")?\s*\)/i',
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

    return $text;
}

/**
 * Vervang Jira issue links door #redmine_id in DB (issues + comments).
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
    // preload mapping: JIRA key -> redmine_issue_id
    $issueMap = [];
    $st = $pdo->query("SELECT jira_issue_key, redmine_issue_id FROM migration_mapping_issues WHERE redmine_issue_id IS NOT NULL");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $issueMap[strtoupper($r['jira_issue_key'])] = (int)$r['redmine_issue_id'];
    }
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

    // 1) Update migration_mapping_issues.proposed_description
    $sel = $pdo->query("SELECT mapping_id, proposed_description, redmine_issue_id FROM migration_mapping_issues");
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
        $new = preg_replace_callback('/\[(.*?)]\(\s*(https?:\/\/[^\s)]+)\s*\)/i', function($m) use ($replaceUrlToHash) {
            $url = $m[2];
            $repl = $replaceUrlToHash($url);
            if ($repl === $url) return $m[0]; // geen wijziging
            return $repl; // we vervangen de volledige markdown door #1234
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

    // 2) Update staging_jira_comments.body_html (journals)
    $sel = $pdo->query("SELECT id, body_html FROM staging_jira_comments");
    $upd = $pdo->prepare("UPDATE staging_jira_comments SET body_html = :b WHERE id = :id");
    while ($r = $sel->fetch(PDO::FETCH_ASSOC)) {
        $id = $r['id'];
        $html = (string)$r['body_html'];

        // protect Original Jira issue lines (if your comment had them)
        $preserve = [];
        $i = 0;
        $htmlProt = preg_replace_callback(
            '/(?m)^[[:space:]>]*Original Jira issue:[^\r\n]*(?:\r?\n)?/',
            function($m) use (&$preserve, &$i) {
                $key = "__ORIGJIRA_{$i}__";
                $preserve[$key] = $m[0];
                $i++;
                return $key . PHP_EOL;
            },
            $html
        );

        // replace markdown links and raw URLs as above
        $newHtml = preg_replace_callback('/\[(.*?)]\(\s*(https?:\/\/[^\s)]+)\s*\)/i', function($m) use ($replaceUrlToHash) {
            $url = $m[2];
            $repl = $replaceUrlToHash($url);
            if ($repl === $url) return $m[0];
            return $repl;
        }, $htmlProt);

        $newHtml = preg_replace_callback('/https?:\/\/[^\s)\]>]+/i', function($m) use ($replaceUrlToHash) {
            return $replaceUrlToHash($m[0]);
        }, $newHtml);

        if (!empty($preserve)) {
            foreach ($preserve as $k => $v) $newHtml = str_replace($k . PHP_EOL, $v, $newHtml);
        }

    if ($newHtml !== $html) {
            $upd->execute([':b' => $newHtml, ':id' => $id]);
        }
    }

    // 3) Update migration_mapping_journals.proposed_notes
    $sel = $pdo->query("SELECT mapping_id, proposed_notes FROM migration_mapping_journals WHERE proposed_notes IS NOT NULL");
    $upd = $pdo->prepare("UPDATE migration_mapping_journals SET proposed_notes = :notes, automation_hash = :hash, last_updated_at = CURRENT_TIMESTAMP WHERE mapping_id = :mid");
    while ($r = $sel->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$r['mapping_id'];
        $notes = (string)$r['proposed_notes'];

        // protect Original Jira issue lines
        $preserve = [];
        $i = 0;
        $prot = preg_replace_callback(
            '/(?m)^[[:space:]>]*Original Jira issue:[^\r\n]*(?:\r?\n)?/',
            function($m) use (&$preserve, &$i) {
                $key = "__ORIGJIRA_{$i}__";
                $preserve[$key] = $m[0];
                $i++;
                return $key . PHP_EOL;
            },
            $notes
        );

        $newNotes = preg_replace_callback('/\[(.*?)]\(\s*(https?:\/\/[^\s)]+)\s*\)/i', function($m) use ($replaceUrlToHash) {
            $url = $m[2];
            $repl = $replaceUrlToHash($url);
            if ($repl === $url) return $m[0];
            return $repl;
        }, $prot);

        $newNotes = preg_replace_callback('/https?:\/\/[^\s)\]>]+/i', function($m) use ($replaceUrlToHash) {
            return $replaceUrlToHash($m[0]);
        }, $newNotes);

        if (!empty($preserve)) {
            foreach ($preserve as $k => $v) $newNotes = str_replace($k . PHP_EOL, $v, $newNotes);
        }

        if ($newNotes !== $notes) {
            $upd->execute([
                ':notes' => $newNotes,
                ':hash' => hash('sha256', $newNotes),
                ':mid' => $mid,
            ]);
        }
    }
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
    if (trim($text) === '') return $text;

    $replaceAccountId = function (?string $maybeUrl) use ($userLookup): ?string {
        if ($maybeUrl === null || $maybeUrl === '') return null;

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
    $text = preg_replace_callback('/\[(.*?)]\(\s*(https?:\/\/[^)\s]+)\s*\)/mi', function ($m) use ($replaceAccountId) {
        $label = $m[1];
        $url = $m[2];
        $repl = $replaceAccountId($url);
        if ($repl !== null) {
            // we vervangen de hele markdown link door user#id
            return $repl;
        }
        return $m[0];
    }, $text);

    // 2) HTML anchors: <a href="...">Label</a>
    $text = preg_replace_callback('/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/mi', function ($m) use ($replaceAccountId) {
        $url = $m[2];
        $repl = $replaceAccountId($url);
        if ($repl !== null) {
            return $repl;
        }
        return $m[0];
    }, $text);

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

    return $text;
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
    $text = preg_replace_callback('/\[(.*?)]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/mi', function($m) use ($jiraToRedmine) {
        $url = $m[2];
        if (preg_match('~/browse/([A-Z][A-Z0-9]+-[0-9]+)~i', $url, $k)) {
            $key = strtoupper($k[1]);
            if (isset($jiraToRedmine[$key])) return '#' . $jiraToRedmine[$key];
        }
        if (preg_match('/[?&]selectedIssue=([A-Z][A-Z0-9]+-[0-9]+)/i', $url, $k2)) {
            $key = strtoupper($k2[1]);
            if (isset($jiraToRedmine[$key])) return '#' . $jiraToRedmine[$key];
        }
        return $m[0];
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

    return $text;
}

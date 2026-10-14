<?php

declare(strict_types=1);

/**
 * Text conversion bench (docs/MIGRATION-PLAN.md, "Text conversion protocol", step 2 and 4).
 *
 *   php tools/conversion_bench.php --redmine=/path/to/redmine [--report=FILE] [--keys-out=FILE] [--details=FILE]
 *
 * For every staged issue description and comment, three Markdown versions are rendered by Redmine's own
 * CommonMark formatter and compared with Jira's rendered HTML:
 *   html      lib convertJiraHtmlToMarkdown() on Jira's rendered HTML (the main path)
 *   adf       the vendored Karvaka AdfToGfm on the ADF document (the fallback path)
 *   pipeline  what the transform phases proposed (migration_mapping_issues.proposed_description,
 *             migration_mapping_journals.proposed_notes), when the staging database has them
 * An item has "no loss" when >= 99% of Jira's words are in the rendered text and it has no fewer links
 * and images. Results are split per construct found in Jira's HTML (tables, code, panels, ...).
 * Staging connection: config/config.local.php (STAGING_DSN). The report holds issue keys: keep it local.
 */

use Karvaka\AdfToGfm\Converter as AdfConverter;

require_once __DIR__ . '/../lib/verify.php';
$config = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/description_conversion.php';

$options = getopt('', ['redmine:', 'report::', 'keys-out::', 'limit::', 'details::']);
if (!isset($options['redmine'])) {
    fwrite(STDERR, "Usage: php tools/conversion_bench.php --redmine=/path/to/redmine [--report=FILE] [--keys-out=FILE] [--limit=N]\n");
    exit(2);
}
$redminePath = rtrim((string)$options['redmine'], '/');
$tmp = rtrim((string)$config['paths']['tmp'], '/');
@mkdir($tmp, 0775, true);
$reportPath = (string)($options['report'] ?? $tmp . '/conversion-bench.md');
$limit = isset($options['limit']) ? (int)$options['limit'] : null;
// --details: one JSON line per item and path with the full measure, to classify the losses (holds Jira words: keep it local)
$detailsPath = isset($options['details']) ? (string)$options['details'] : null;
$details = [];

$pdo = new PDO((string)$config['database']['dsn'], (string)$config['database']['username'], (string)$config['database']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/** Constructs recognised in Jira's rendered HTML. */
const BENCH_CONSTRUCTS = [
    'table' => '/<table\b/i',
    'code block' => '/<pre\b|class="code\b/i',
    'panel' => '/class="panel(?! preformatted)|class="[^"]*\bpanelContent\b(?![^"]*preformatted)/i',
    'nested list' => '/<li\b[^>]*>(?:(?!<\/li>).)*<(?:ul|ol)\b/is',
    'image' => '/<img\b(?![^>]*emoticon)(?![^>]*rendericon)/i',
    'attachment link' => '#<a\b[^>]*href="[^"]*/(?:secure/attachment|rest/api/\d+/attachment)/#i',
    'mention' => '/class="user-hover"/i',
    'issue link' => '#href="[^"]*/browse/[A-Z][A-Z0-9_]*-\d+#',
    'task list' => '/task-list|data-task-local-id|type="checkbox"/i',
    'heading' => '/<h[1-6]\b/i',
    'quote' => '/<blockquote\b/i',
    'colour' => '/style="[^"]*\bcolor\s*:/i',
    'emoji' => '/emoticon|data-emoji/i',
    'date' => '/<time\b/i',
    'expand' => '/expand-container|class="[^"]*\bexpand\b/i',
    'status lozenge' => '/aui-lozenge|status-macro/i',
    'email link' => '/href="mailto:/i',
];

/** Element the rendered Redmine HTML must have when Jira's HTML has the construct. */
const BENCH_EXPECTED_ELEMENT = [
    'table' => '/<table\b/i',
    'code block' => '/<pre\b/i',
    'heading' => '/<h[1-6]\b/i',
    'quote' => '/<blockquote\b/i',
    'nested list' => '/<li\b[^>]*>(?:(?!<\/li>).)*<(?:ul|ol)\b/is',
    'email link' => '/href="mailto:[^"]+@/i',
    'image' => '/<img\b/i',
];

/** Markdown that did not render: visible syntax in the text of the rendered HTML. */
function benchResidue(string $renderedHtml): array
{
    $withoutCode = preg_replace('#<(pre|code)\b.*?</\1>#is', ' ', $renderedHtml) ?? $renderedHtml;
    $text = html_entity_decode(strip_tags($withoutCode), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $found = [];
    foreach (['**' => '/\*\*\S/', '\\|' => '/\\\\\|/', '](' => '/\]\(/', '|---' => '/\|\s*-{3,}/', '<tag>' => '/<\/?(?:p|div|span|br|table|td|tr)\b/i'] as $label => $pattern) {
        if (preg_match($pattern, $text) === 1) {
            $found[] = $label;
        }
    }

    return $found;
}

// Items: issue descriptions and comments.
$items = [];
$sql = 'SELECT i.id, i.issue_key, i.description_html AS html, i.description_adf AS adf, m.proposed_description AS pipeline
    FROM staging_jira_issues i LEFT JOIN migration_mapping_issues m ON m.jira_issue_id = i.id
    WHERE i.description_html IS NOT NULL AND TRIM(i.description_html) <> \'\'';
foreach ($pdo->query($sql) as $row) {
    $items['d' . $row['id']] = ['label' => $row['issue_key'] . ' description', 'html' => $row['html'], 'adf' => $row['adf'], 'pipeline' => $row['pipeline']];
}
$sql = 'SELECT c.id, i.issue_key, c.body_html AS html, c.body_adf AS adf, j.proposed_notes AS pipeline
    FROM staging_jira_comments c JOIN staging_jira_issues i ON i.id = c.issue_id
    LEFT JOIN migration_mapping_journals j ON j.jira_entity_id = c.id AND j.entity_type = \'COMMENT\'
    WHERE c.body_html IS NOT NULL AND TRIM(c.body_html) <> \'\'';
foreach ($pdo->query($sql) as $row) {
    $items['c' . $row['id']] = ['label' => $row['issue_key'] . ' comment ' . $row['id'], 'html' => $row['html'], 'adf' => $row['adf'], 'pipeline' => $row['pipeline']];
}
if ($limit !== null) {
    $items = array_slice($items, 0, $limit, true);
}
fwrite(STDOUT, sprintf("Items: %d\n", count($items)));

// Users for user#id and @login, as Redmine renders them.
$userNames = [];
foreach ($pdo->query('SELECT redmine_user_id, proposed_redmine_login, proposed_firstname, proposed_lastname FROM migration_mapping_users') as $row) {
    $name = trim($row['proposed_firstname'] . ' ' . $row['proposed_lastname']);
    if ($row['redmine_user_id'] !== null) {
        $userNames[(string)$row['redmine_user_id']] = $name;
    }
    if ($row['proposed_redmine_login'] !== null) {
        $userNames[(string)$row['proposed_redmine_login']] = $name;
    }
}
$userName = static fn (string $ref): ?string => $userNames[$ref] ?? null;
// Redmine links user#1, @login and #123 when it renders in a project; the plain formatter does not, so do it here.
$resolveUsers = static function (string $markdown) use ($userName): string {
    $markdown = preg_replace_callback('/\buser#(\d+)/', static fn (array $m): string => ($n = $userName($m[1])) !== null ? '[' . $n . '](/users/' . $m[1] . ')' : $m[0], $markdown) ?? $markdown;
    $markdown = preg_replace_callback('/(?<![\w@\/])@([A-Za-z0-9_.-]*[A-Za-z0-9_])/', static fn (array $m): string => ($n = $userName($m[1])) !== null ? '[' . $n . '](/users/' . $m[1] . ')' : $m[0], $markdown) ?? $markdown;

    return preg_replace('/(?<![\w&\/#\[])#(\d+)\b/', '[#$1](/issues/$1)', $markdown) ?? $markdown;
};

// Convert along both paths.
$adfConverter = new AdfConverter();
$markdown = [];
foreach ($items as $id => $item) {
    try {
        $markdown[$id . ':html'] = convertJiraHtmlToMarkdown($item['html'], []) ?? '';
    } catch (Throwable $exception) {
        $markdown[$id . ':html'] = '';
    }
    try {
        $markdown[$id . ':adf'] = $item['adf'] !== null && trim((string)$item['adf']) !== '' ? convertDescriptionToMarkdown((string)$item['adf'], $adfConverter) : '';
    } catch (Throwable $exception) {
        $markdown[$id . ':adf'] = '';
    }
    if ($item['pipeline'] !== null) {
        $markdown[$id . ':pipeline'] = $resolveUsers((string)$item['pipeline']);
    }
}

// Render with Redmine.
$in = $tmp . '/bench-in.json';
$out = $tmp . '/bench-out.json';
file_put_contents($in, json_encode($markdown, JSON_THROW_ON_ERROR));
$command = sprintf(
    'cd %s && IN=%s OUT=%s RAILS_ENV=production bundle exec rails runner %s 2>&1',
    escapeshellarg($redminePath),
    escapeshellarg($in),
    escapeshellarg($out),
    escapeshellarg(__DIR__ . '/testenv/render_markdown.rb')
);
exec($command, $renderOutput, $renderStatus);
if ($renderStatus !== 0) {
    fwrite(STDERR, implode("\n", $renderOutput) . "\n");
    exit(1);
}
$rendered = json_decode((string)file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);

// Compare.
$paths = ['html', 'adf', 'pipeline'];
$totals = [];
$perConstruct = [];
$losses = [];
$constructKeys = [];
$defects = [];
foreach ($items as $id => $item) {
    $expectedWords = verifyWords(verifyHtmlToText($item['html']));
    $expectedLinks = verifyHtmlLinkCounts($item['html']);
    $constructs = [];
    foreach (BENCH_CONSTRUCTS as $construct => $pattern) {
        if (preg_match($pattern, $item['html']) === 1) {
            $constructs[] = $construct;
            $constructKeys[$construct][explode(' ', $item['label'])[0]] = true;
        }
    }
    foreach ($paths as $path) {
        if (!isset($rendered[$id . ':' . $path])) {
            continue;
        }
        $html = (string)$rendered[$id . ':' . $path];
        $coverage = verifyWordCoverage($expectedWords, verifyWords(verifyHtmlToText($html)));
        $links = verifyHtmlLinkCounts($html);
        // Attachment links are rewritten to attachment names and do not count as links on the Jira side.
        $images = preg_match_all('/<img\b/i', $html) ?: 0;
        $missingElements = [];
        foreach ($constructs as $construct) {
            if (isset(BENCH_EXPECTED_ELEMENT[$construct]) && preg_match(BENCH_EXPECTED_ELEMENT[$construct], $html) !== 1) {
                $missingElements[] = 'no ' . $construct;
            }
        }
        $residue = benchResidue($html);
        $lossy = $coverage['coverage'] < 0.99 || $links['links'] < $expectedLinks['links'] || $images < $expectedLinks['images']
            || $missingElements !== [] || $residue !== [];
        $totals[$path] ??= ['items' => 0, 'clean' => 0, 'coverage' => 0.0];
        $totals[$path]['items']++;
        $totals[$path]['clean'] += $lossy ? 0 : 1;
        $totals[$path]['coverage'] += $coverage['coverage'];
        foreach ($constructs === [] ? ['(plain)'] : $constructs as $construct) {
            $perConstruct[$construct][$path] ??= ['items' => 0, 'clean' => 0];
            $perConstruct[$construct][$path]['items']++;
            $perConstruct[$construct][$path]['clean'] += $lossy ? 0 : 1;
        }
        foreach (array_merge($missingElements, array_map(static fn (string $r): string => 'shows ' . $r, $residue)) as $defect) {
            $defects[$path][$defect] = ($defects[$path][$defect] ?? 0) + 1;
        }
        if ($coverage['coverage'] < 0.99) {
            $defects[$path]['words < 99%'] = ($defects[$path]['words < 99%'] ?? 0) + 1;
        }
        if ($links['links'] < $expectedLinks['links']) {
            $defects[$path]['fewer links'] = ($defects[$path]['fewer links'] ?? 0) + 1;
        }
        if ($images < $expectedLinks['images']) {
            $defects[$path]['fewer images'] = ($defects[$path]['fewer images'] ?? 0) + 1;
        }
        if ($detailsPath !== null) {
            $details[] = json_encode([
                'id' => $id, 'label' => $item['label'], 'path' => $path, 'lossy' => $lossy, 'coverage' => $coverage['coverage'],
                'missing' => $coverage['missing'], 'links' => $links, 'expected' => $expectedLinks, 'images' => $images,
                'elements' => $missingElements, 'residue' => $residue, 'constructs' => $constructs,
            ], JSON_UNESCAPED_UNICODE);
        }
        if ($lossy && $path !== 'adf') {
            $losses[] = [$path, $item['label'], sprintf('%.1f%%', 100 * $coverage['coverage']),
                sprintf('links %d/%d images %d/%d %s', $links['links'], $expectedLinks['links'], $images, $expectedLinks['images'],
                    implode(' ', array_merge($missingElements, array_map(static fn (string $r): string => 'shows ' . $r, $residue)))),
                implode(' ', array_slice($coverage['missing'], 0, 10)), implode(', ', $constructs)];
        }
    }
}

$lines = ['# Conversion bench', '', sprintf('%s, %d items (descriptions and comments), rendered by Redmine at %s', date('c'), count($items), $redminePath), ''];
$lines[] = '| path | items | without loss | share | mean word coverage |';
$lines[] = '|---|---:|---:|---:|---:|';
foreach ($paths as $path) {
    if (!isset($totals[$path])) {
        continue;
    }
    $t = $totals[$path];
    $lines[] = sprintf('| %s | %d | %d | %.1f%% | %.2f%% |', $path, $t['items'], $t['clean'], 100 * $t['clean'] / max(1, $t['items']), 100 * $t['coverage'] / max(1, $t['items']));
}
$lines[] = '';
$lines[] = '| construct | items | html: without loss | adf: without loss | pipeline: without loss |';
$lines[] = '|---|---:|---:|---:|---:|';
ksort($perConstruct);
foreach ($perConstruct as $construct => $byPath) {
    $cell = static fn (string $p): string => isset($byPath[$p]) ? sprintf('%d/%d', $byPath[$p]['clean'], $byPath[$p]['items']) : '-';
    $lines[] = sprintf('| %s | %d | %s | %s | %s |', $construct, $byPath['html']['items'] ?? 0, $cell('html'), $cell('adf'), $cell('pipeline'));
}
$lines[] = '';
$lines[] = '| defect | ' . implode(' | ', array_keys($defects)) . ' |';
$lines[] = '|---|' . str_repeat('---:|', count($defects));
$allDefects = [];
foreach ($defects as $byDefect) {
    $allDefects += array_fill_keys(array_keys($byDefect), true);
}
ksort($allDefects);
foreach (array_keys($allDefects) as $defect) {
    $lines[] = '| ' . $defect . ' | ' . implode(' | ', array_map(static fn (array $d): string => (string)($d[$defect] ?? 0), $defects)) . ' |';
}
$lines[] = '';
$lines[] = '## Issues per construct (curated set candidates)';
$lines[] = '';
ksort($constructKeys);
foreach ($constructKeys as $construct => $keys) {
    $keys = array_keys($keys);
    natsort($keys);
    $lines[] = sprintf('- %s (%d): %s', $construct, count($keys), implode(', ', array_slice(array_values($keys), 0, 8)));
}
$lines[] = '';
$lines[] = '## Items with loss (html and pipeline paths)';
$lines[] = '';
$lines[] = '| path | item | words | links, images | missing words | constructs |';
$lines[] = '|---|---|---:|---|---|---|';
foreach ($losses as $loss) {
    $lines[] = '| ' . implode(' | ', array_map(static fn (string $c): string => str_replace(['|', "\n"], ['\|', ' '], $c), $loss)) . ' |';
}
file_put_contents($reportPath, implode("\n", $lines) . "\n");
if ($detailsPath !== null) {
    file_put_contents($detailsPath, implode("\n", $details) . "\n");
}
fwrite(STDOUT, implode("\n", array_slice($lines, 0, 8)) . "\nReport: " . $reportPath . "\n");

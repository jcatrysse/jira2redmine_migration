<?php
// B14 (Jan, 2026-10-13): how does Redmine handle two attachments with the same file name on one issue
// and in one journal? Creates an issue with two different images named "image.png" and a journal with
// two different images named "shot.png", each referenced inline, then reports what the API lists,
// whether each download returns its own content, and which attachment the inline images resolve to on
// the rendered page (tools/testenv/page_html.mjs). Usage: php duplicate_names_test.php PROJECT
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
use GuzzleHttp\Client;

$project = $argv[1] ?? 'test';
$client = new Client(['base_uri' => getenv('REDMINE_URL') . '/', 'headers' => ['X-Redmine-API-Key' => getenv('REDMINE_API_KEY'), 'Accept' => 'application/json']]);

/** A distinct 1x1 PNG per colour. */
function png(int $r, int $g, int $b): string
{
    $image = imagecreatetruecolor(4, 4);
    imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
    ob_start();
    imagepng($image);

    return (string)ob_get_clean();
}
function upload(Client $client, string $name, string $content): string
{
    $response = $client->post('uploads.json?filename=' . rawurlencode($name), ['headers' => ['Content-Type' => 'application/octet-stream'], 'body' => $content]);

    return json_decode((string)$response->getBody(), true)['upload']['token'];
}

$files = ['A' => png(255, 0, 0), 'B' => png(0, 255, 0), 'C' => png(0, 0, 255), 'D' => png(255, 255, 0)];
// --rule: name the attachments as the migration does (B14): the second of a pair gets the Jira id prefix.
$rule = in_array('--rule', $argv, true);
$names = \Jira2Redmine\Attachments\AttachmentNames::assign(['1' => 'image.png', '2' => 'image.png', '3' => 'shot.png', '4' => 'shot.png']);
$n = static fn (string $id, string $name): string => $rule ? $names[$id] : $name;
$issue = json_decode((string)$client->post('issues.json', ['json' => ['issue' => [
    'project_id' => $project,
    'subject' => 'B14 duplicate attachment names',
    'description' => "First image.png (red):\n\n![](image.png)\n\nSecond (green):\n\n![](" . rawurlencode($n('2', 'image.png')) . ")",
    'uploads' => [
        ['token' => upload($client, 'image.png', $files['A']), 'filename' => $n('1', 'image.png'), 'description' => 'A red, first'],
        ['token' => upload($client, 'image.png', $files['B']), 'filename' => $n('2', 'image.png'), 'description' => 'B green, second'],
    ],
]]])->getBody(), true)['issue'];
$client->put(sprintf('issues/%d.json', $issue['id']), ['json' => ['issue' => [
    'notes' => "Comment, first shot.png (blue):\n\n![](shot.png)\n\nsecond (yellow):\n\n![](" . rawurlencode($n('4', 'shot.png')) . ")",
    'uploads' => [
        ['token' => upload($client, 'shot.png', $files['C']), 'filename' => $n('3', 'shot.png'), 'description' => 'C blue, first'],
        ['token' => upload($client, 'shot.png', $files['D']), 'filename' => $n('4', 'shot.png'), 'description' => 'D yellow, second'],
    ],
]]]);
$detail = json_decode((string)$client->get(sprintf('issues/%d.json', $issue['id']), ['query' => ['include' => 'attachments,journals']])->getBody(), true)['issue'];
$byHash = array_flip(array_map('sha1', $files));
printf("issue #%d, API lists %d attachments\n", $issue['id'], count($detail['attachments']));
foreach ($detail['attachments'] as $attachment) {
    $content = (string)$client->get($attachment['content_url'])->getBody();
    printf("  #%d %s (%s): download is file %s\n", $attachment['id'], $attachment['filename'], $attachment['description'], $byHash[sha1($content)] ?? '?');
}
echo "ISSUE=" . $issue['id'] . "\n";

<?php
// Large attachment test (plan, "Large attachment protocol"): upload one file the way 09_migrate_attachments.php
// does (Guzzle, Utils::streamFor on a file handle, POST uploads.json), create an issue with it, and report
// time and the PHP peak memory. Usage: php large_upload.php FILE NAME PROJECT_IDENTIFIER
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;

[$file, $name, $project] = [$argv[1], $argv[2], $argv[3]];
$client = new Client(['base_uri' => getenv('REDMINE_URL') . '/', 'headers' => ['X-Redmine-API-Key' => getenv('REDMINE_API_KEY'), 'Accept' => 'application/json'], 'timeout' => 0]);
$start = microtime(true);
$response = $client->post('uploads.json?' . http_build_query(['filename' => $name]), [
    'headers' => ['Content-Type' => 'application/octet-stream'],
    'body' => Utils::streamFor(fopen($file, 'rb')),
]);
$upload = microtime(true) - $start;
$token = json_decode((string)$response->getBody(), true)['upload']['token'];
$issue = json_decode((string)$client->post('issues.json', ['json' => ['issue' => [
    'project_id' => $project, 'subject' => 'Large attachment test ' . $name,
    'uploads' => [['token' => $token, 'filename' => $name]],
]]])->getBody(), true)['issue'];
$detail = json_decode((string)$client->get(sprintf('issues/%d.json', $issue['id']), ['query' => ['include' => 'attachments']])->getBody(), true)['issue'];
$attachment = $detail['attachments'][0];
printf("upload_s=%.1f size=%d stored=%d php_peak_mb=%.1f issue=%d attachment=%d content_url=%s\n",
    $upload, filesize($file), $attachment['filesize'], memory_get_peak_usage(true) / 1048576, $issue['id'], $attachment['id'], $attachment['content_url']);

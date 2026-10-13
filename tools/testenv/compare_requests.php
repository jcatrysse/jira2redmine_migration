<?php
// Compare two request logs of tools/testenv/record_proxy.mjs (phase 6: no behaviour change).
//   php compare_requests.php before.jsonl after.jsonl
// Upload tokens are random and are normalised; everything else must be identical, in the same order.
declare(strict_types=1);

function loadRequests(string $file): array
{
    $requests = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $body = isset($r['body']) ? preg_replace('/"token"\s*:\s*"[^"]*"/', '"token":"<token>"', $r['body']) : $r['sha256'];
        $requests[] = sprintf('%s %s %s %s', $r['method'], $r['url'], $r['status'], $body);
    }

    return $requests;
}

[$before, $after] = [loadRequests($argv[1]), loadRequests($argv[2])];
printf("before: %d requests, after: %d requests\n", count($before), count($after));
$differences = 0;
for ($i = 0, $n = max(count($before), count($after)); $i < $n; $i++) {
    if (($before[$i] ?? null) === ($after[$i] ?? null)) {
        continue;
    }
    if (++$differences <= 10) {
        printf("#%d\n  before: %s\n  after:  %s\n", $i, substr($before[$i] ?? '(none)', 0, 400), substr($after[$i] ?? '(none)', 0, 400));
    }
}
printf("%s: %d differing requests\n", $differences === 0 ? 'IDENTICAL' : 'DIFFERENT', $differences);
exit($differences === 0 ? 0 : 1);

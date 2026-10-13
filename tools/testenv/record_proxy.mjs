// Recording reverse proxy for Redmine, the proof of "no behaviour change" in phase 6:
//   LISTEN=3151 TARGET=http://127.0.0.1:3051 LOG=requests.jsonl node record_proxy.mjs
// Every request is streamed to Redmine unchanged; method, path, size, sha256 and (for JSON up to 1 MB)
// the body go to LOG as one JSON line, plus the response status.
// Compare two runs with tools/testenv/compare_requests.php.
import http from 'node:http';
import fs from 'node:fs';
import crypto from 'node:crypto';

const target = new URL(process.env.TARGET);
const log = fs.createWriteStream(process.env.LOG, { flags: 'a' });
const LIMIT = 1 << 20;
let seq = 0;

http.createServer((req, res) => {
  const id = seq++;
  const type = req.headers['content-type'] || '';
  const hash = crypto.createHash('sha256');
  const kept = [];
  let size = 0;
  const upstream = http.request({
    host: target.hostname, port: target.port, method: req.method, path: req.url,
    headers: { ...req.headers, host: target.host },
  }, (up) => {
    const record = { seq: id, method: req.method, url: req.url, type, size, sha256: hash.digest('hex'), status: up.statusCode };
    if (type.includes('json') && size <= LIMIT) record.body = Buffer.concat(kept).toString('utf8');
    log.write(JSON.stringify(record) + '\n');
    res.writeHead(up.statusCode, up.headers);
    up.pipe(res);
  });
  upstream.on('error', (e) => { res.writeHead(502); res.end(String(e)); });
  req.on('data', (chunk) => {
    hash.update(chunk);
    size += chunk.length;
    if (size <= LIMIT) kept.push(chunk);
    upstream.write(chunk);
  });
  req.on('end', () => upstream.end());
}).listen(Number(process.env.LISTEN), '127.0.0.1');

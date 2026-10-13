#!/bin/bash
# Large attachment test against one target: tools/testenv/large_attachment_test.sh 51|70 FILE NAME PROJECT
# Measures upload time and PHP memory (large_upload.php), the peak RSS of the Redmine (puma) process during
# the upload, download time and checksum, whether a range request is honoured, and the issue page load time.
v=$1; file=$2; name=$3; project=$4
HERE=$(cd "$(dirname "$0")" && pwd)
source "${BASE:-/home/user/src}/env.sh" "$v"
pid=$(pgrep -f "puma.*:30$v" | head -1)
peak=0
( while kill -0 $pid 2>/dev/null; do r=$(ps -o rss= -p $(pgrep -f "puma.*:30$v" | tr '\n' ',' | sed 's/,$//') | awk '{s+=$1} END {print s}'); echo $r; sleep 0.5; done ) > /tmp/rss.$v &
sampler=$!
rss_before=$(ps -o rss= -p $pid)
out=$(php "$HERE/large_upload.php" "$file" "$name" "$project")
kill $sampler
peak=$(sort -n /tmp/rss.$v | tail -1)
echo "$out"
echo "redmine_rss_mb before=$((rss_before/1024)) peak_during_upload=$((peak/1024))"
url=$(echo "$out" | grep -o 'content_url=[^ ]*' | cut -d= -f2-)
curl -s -o /tmp/dl.$v -w "download http=%{http_code} bytes=%{size_download} time_s=%{time_total}\n" -H "X-Redmine-API-Key: $REDMINE_API_KEY" "$url"
echo "sha256 match: $([ "$(sha256sum < /tmp/dl.$v)" = "$(sha256sum < "$file")" ] && echo yes || echo NO)"; rm -f /tmp/dl.$v
curl -s -o /dev/null -r 1000-1999 -w "range 1000-1999: http=%{http_code} bytes=%{size_download}\n" -H "X-Redmine-API-Key: $REDMINE_API_KEY" "$url"
issue=$(echo "$out" | grep -o 'issue=[0-9]*' | cut -d= -f2)
REDMINE_URL=$REDMINE_URL ISSUE=$issue node "$HERE/page_load.mjs"

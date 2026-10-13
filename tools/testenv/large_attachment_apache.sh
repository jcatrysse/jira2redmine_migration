#!/bin/bash
# Large attachment test through Apache + Passenger (port 8070, X-Sendfile on): upload with the scripts'
# streaming upload, download, range request, memory of the Passenger app processes, issue page load.
# Usage: large_attachment_apache.sh FILE NAME PROJECT
file=$1; name=$2; project=$3
HERE=$(cd "$(dirname "$0")" && pwd)
source "${BASE:-/home/user/src}/env.sh" 70
export REDMINE_URL=http://127.0.0.1:8070
rss() { ps -eo rss,args | awk '/Passenger RubyApp|apache2 -k/ && !/awk/ {s+=$1} END {print int(s/1024)}'; }
sample() { while true; do rss; sleep 0.3; done; }
before=$(rss); sample > /tmp/rss.apache & s=$!
out=$(php "$HERE/large_upload.php" "$file" "$name" "$project" 2>&1); kill $s
echo "$out" | cut -c1-200
echo "apache+passenger RSS MB: before upload $before, peak during upload $(sort -n /tmp/rss.apache | tail -1)"
url=$(echo "$out" | grep -o 'content_url=[^ ]*' | cut -d= -f2- | sed 's#http://127.0.0.1:3070#http://127.0.0.1:8070#')
[ -z "$url" ] && exit 1
before=$(rss); sample > /tmp/rss.apache & s=$!
curl -s -o /tmp/dl.apache -D /tmp/dl.headers -w "download http=%{http_code} bytes=%{size_download} time_s=%{time_total}\n" -H "X-Redmine-API-Key: $REDMINE_API_KEY" "$url"
kill $s; echo "apache+passenger RSS MB: before download $before, peak during download $(sort -n /tmp/rss.apache | tail -1)"
grep -iE "^(accept-ranges|content-length|x-sendfile|content-type):" /tmp/dl.headers | tr -d '\r'
echo "sha256 match: $([ "$(sha256sum < /tmp/dl.apache)" = "$(sha256sum < "$file")" ] && echo yes || echo NO)"; rm -f /tmp/dl.apache
curl -s -o /dev/null -r 1000-1999 -w "range 1000-1999: http=%{http_code} bytes=%{size_download}\n" -H "X-Redmine-API-Key: $REDMINE_API_KEY" "$url"
issue=$(echo "$out" | grep -o 'issue=[0-9]*' | cut -d= -f2)
REDMINE_URL=$REDMINE_URL ISSUE=$issue node "$HERE/page_load.mjs"

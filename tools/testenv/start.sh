#!/bin/bash
# start.sh 51|70 : start Redmine on port 30xx in background
export RBENV_ROOT=/opt/rbenv PATH=/opt/rbenv/shims:/opt/rbenv/bin:$PATH RAILS_ENV=production
v=$1; cd /home/user/src/redmine$v
pkill -f "puma.*:30$v" 2>/dev/null; sleep 1
nohup bundle exec puma -e production -b tcp://127.0.0.1:30$v -t 2:8 -w 0 > /home/user/src/puma$v.log 2>&1 &
for i in $(seq 1 90); do curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:30$v/login | grep -q 200 && { echo "redmine$v up"; exit 0; }; sleep 2; done; echo "redmine$v NOT up"; tail -20 /home/user/src/puma$v.log; exit 1

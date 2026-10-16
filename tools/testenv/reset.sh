#!/bin/bash
# reset.sh 51|70 : stop Redmine, restore the clean baseline dump, wipe files, restart
v=$1; set -e
pkill -f "puma.*:30$v" || true; sleep 2
export PGPASSWORD=redmine
dropdb -h 127.0.0.1 -U redmine --if-exists redmine$v
createdb -h 127.0.0.1 -U redmine -T template0 -E UTF8 redmine$v
pg_restore -h 127.0.0.1 -U redmine -d redmine$v --no-owner /home/user/src/dumps/redmine$v-baseline.dump
rm -rf /home/user/src/redmine$v/files/*
# the workflows step's list belongs to the database just dropped (B82)
rm -f /home/user/src/redmine$v/tmp/j2r_added_workflows.json
/home/user/src/start.sh $v

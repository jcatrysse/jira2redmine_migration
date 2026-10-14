#!/bin/bash
# Full test run against one target, from a clean Redmine and an empty staging database:
#   tools/testenv/run_all.sh 51|70 LABEL
# Logs per step go to $BASE/runs/<target>-<LABEL>/; the verify report is the last step.
# STABILITY=1 reruns 08 to 15 after the run and reports whether Redmine's counts changed.
# BATCH=1 keeps Redmine and the staging database of the previous run: a later batch, run with every
# project migrated so far plus the new ones in J2R_PROJECTS (the jira phases of 07 and 08 read only the
# run's projects and replace their staging tables).
# Expects tools/testenv/setup.sh to have run, and config/config.local.php to read the environment
# (JIRA_*, JIRA_CLOUD_ID, REDMINE_URL, REDMINE_API_KEY, STAGING_DSN, J2R_TMP, J2R_JQL, J2R_PROJECTS).
set -u
v=$1; label=$2
BASE=${BASE:-/home/user/src}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
export RBENV_ROOT=/opt/rbenv PATH=/opt/rbenv/shims:/opt/rbenv/bin:$PATH
source "$BASE/env.sh" "$v"
export J2R_PROJECTS=${J2R_PROJECTS:-geos,sis,oac}
logdir="$BASE/runs/$v-$label"
mkdir -p "$logdir" "$J2R_TMP"

step() { # step NAME script args...
  local name=$1; shift
  local start=$(date +%s)
  (cd "$REPO" && php "$@") > "$logdir/$name.log" 2>&1
  local rc=$?
  printf '%-14s exit=%d %4ds errors=%d\n' "$name" $rc $(( $(date +%s) - start )) "$(grep -ciE '\[error\]|fatal|exception' "$logdir/$name.log")" | tee -a "$logdir/summary.txt"
}
manual() { # manual workflows|members
  (cd "$BASE/redmine$v" && J2R_STEP=$1 RAILS_ENV=production bundle exec rails runner "$REPO/tools/testenv/manual_steps.rb") 2>&1 | tail -1 | sed "s/^/manual-$1   /" | tee -a "$logdir/summary.txt"
}

: > "$logdir/summary.txt"
[ -z "${BATCH:-}" ] && "$BASE/reset.sh" "$v" > /dev/null
if [ -n "${RECORD:-}" ]; then
  # Record every Redmine request (phase 6 proof): the scripts talk to a proxy on port 31xx.
  : > "$RECORD"
  LISTEN=31$v TARGET=$REDMINE_URL LOG=$RECORD node "$REPO/tools/testenv/record_proxy.mjs" &
  proxy=$!
  trap 'kill $proxy 2>/dev/null' EXIT
  export REDMINE_URL=http://127.0.0.1:31$v
  sleep 1
fi
db=jira2redmine_$v
if [ -z "${BATCH:-}" ]; then
  mysql -e "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON $db.* TO 'jira2redmine'@'localhost';"
  mysql "$db" < "$REPO/database/schema.sql"
  rm -rf "$J2R_TMP"/*
fi

step 01 01_migrate_projects.php
step 01-push 01_migrate_projects.php --phases=push --confirm-push
step 02 02_migrate_users.php
step 02-push 02_migrate_users.php --phases=push --confirm-push
step 03 03_migrate_groups.php
step 03-push 03_migrate_groups.php --phases=push --confirm-push
# group members wait for their group: a second pass once the push above created the groups (B32)
step 03-members 03_migrate_groups.php --skip=jira
step 03-members-push 03_migrate_groups.php --phases=push --confirm-push
for s in 05_migrate_statuses 06_migrate_priorities 07_migrate_trackers; do
  step "${s:0:2}" "$s.php"
  step "${s:0:2}-push" "$s.php" --phases=push --confirm-push
done
manual workflows
step 08-jira 08_migrate_custom_fields.php --phases=jira
step 10-jira 10_migrate_issues.php --phases=jira
step 09-jira 09_migrate_attachments.php --phases=jira
step 14-jira 14_migrate_watchers.php --phases=jira
# 04 after the issues and watchers are staged: it proposes assignees (B46) and watchers (B50) without membership too
step 04 04_migrate_roles.php
# activate locked users before 04's push: assignees and watchers among them get their membership (B46, B50)
manual members
step 04-push 04_migrate_roles.php --phases=push --confirm-push
step 08-transform 08_migrate_custom_fields.php --phases=usage,redmine,transform
step 08-push 08_migrate_custom_fields.php --phases=push --confirm-push
step 09-pull 09_migrate_attachments.php --phases=pull --confirm-pull
step 09-push 09_migrate_attachments.php --phases=transform,push --confirm-push
step 10 10_migrate_issues.php --phases=transform,push --confirm-push
# A second run writes descriptions and parents (READY_FOR_REWRITE -> final update); see Findings.
step 10-final 10_migrate_issues.php --phases=transform,push --confirm-push
step 11 11_migrate_journals.php --confirm-push --use-extended-api
step 12 12_migrate_issue_relations.php --confirm-push
step 13 13_migrate_tags.php --confirm-push
step 14 14_migrate_watchers.php --confirm-push
step 15 15_migrate_checklists.php --confirm-push
step 16 16_export_workflows.php
if [ -n "${STABILITY:-}" ]; then
  # Second pass of everything after the structure (plan, B35): it must change nothing in Redmine.
  counts() {
    PGPASSWORD=redmine psql -h 127.0.0.1 -U redmine -d redmine$v -Atc "SELECT 'issues '||(SELECT COUNT(*) FROM issues)
      ||', journals '||(SELECT COUNT(*) FROM journals)||', details '||(SELECT COUNT(*) FROM journal_details)
      ||', attachments '||(SELECT COUNT(*) FROM attachments)||', relations '||(SELECT COUNT(*) FROM issue_relations)
      ||', watchers '||(SELECT COUNT(*) FROM watchers)||', custom values '||(SELECT COUNT(*) FROM custom_values WHERE value <> '')
      ||', members '||(SELECT COUNT(*) FROM members)||', custom fields '||(SELECT COUNT(*) FROM custom_fields)"
  }
  before=$(counts)
  step 08-again 08_migrate_custom_fields.php --phases=usage,redmine,transform
  step 08-again-push 08_migrate_custom_fields.php --phases=push --confirm-push
  step 09-again 09_migrate_attachments.php --phases=transform,push --confirm-push
  step 10-again 10_migrate_issues.php --phases=transform,push --confirm-push
  step 11-again 11_migrate_journals.php --confirm-push --use-extended-api
  step 12-again 12_migrate_issue_relations.php --confirm-push
  step 13-again 13_migrate_tags.php --confirm-push
  step 14-again 14_migrate_watchers.php --confirm-push
  step 15-again 15_migrate_checklists.php --confirm-push
  after=$(counts)
  echo "stability before: $before" | tee -a "$logdir/summary.txt"
  echo "stability after:  $after" | tee -a "$logdir/summary.txt"
  [ "$before" = "$after" ] && echo "stability: unchanged" | tee -a "$logdir/summary.txt" || echo "stability: CHANGED" | tee -a "$logdir/summary.txt"
fi
mysql -N "$db" -e "SELECT 'issues', migration_status, COUNT(*) FROM migration_mapping_issues GROUP BY 2
  UNION ALL SELECT 'journals', migration_status, COUNT(*) FROM migration_mapping_journals GROUP BY 2
  UNION ALL SELECT 'attachments', migration_status, COUNT(*) FROM migration_mapping_attachments GROUP BY 2
  UNION ALL SELECT 'relations', migration_status, COUNT(*) FROM migration_mapping_issue_relations GROUP BY 2
  UNION ALL SELECT 'watchers', migration_status, COUNT(*) FROM migration_mapping_watchers GROUP BY 2" | tee -a "$logdir/summary.txt"
# run report: select values Jira no longer offers (B37), issues waiting for a parent outside the run (B38)
grep -h "(B37)\|\[unoffered\]" "$logdir/08-transform.log" | sed 's/^ *//' | tee -a "$logdir/summary.txt"
grep -h "(B38)\|\[waiting\]" "$logdir/10-final.log" | sed 's/^ *//' | tee -a "$logdir/summary.txt"
keys=$(echo "$J2R_PROJECTS" | tr 'a-z' 'A-Z')
(cd "$REPO" && php tools/verify.php --projects="$keys" --sample=${SAMPLE:-25} --report="$logdir/verify.md") > "$logdir/verify.out" 2>&1
sed -n '/| check/,/^\*\*/p' "$logdir/verify.md" | tee -a "$logdir/summary.txt"

#!/bin/bash
# Full test run against one target, from a clean Redmine and an empty staging database:
#   tools/testenv/run_all.sh 51|70 LABEL
# Logs per step go to $BASE/runs/<target>-<LABEL>/; the verify report is the last step.
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
"$BASE/reset.sh" "$v" > /dev/null
db=jira2redmine_$v
mysql -e "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON $db.* TO 'jira2redmine'@'localhost';"
mysql "$db" < "$REPO/database/schema.sql"
rm -rf "$J2R_TMP"/*

step 01 01_migrate_projects.php
step 01-push 01_migrate_projects.php --phases=push --confirm-push
step 02 02_migrate_users.php
step 02-push 02_migrate_users.php --phases=push --confirm-push
manual members
step 03 03_migrate_groups.php
step 03-nojira 03_migrate_groups.php --skip=jira
step 03-push 03_migrate_groups.php --phases=push --confirm-push
step 04 04_migrate_roles.php
for s in 05_migrate_statuses 06_migrate_priorities 07_migrate_trackers; do
  step "${s:0:2}" "$s.php"
  step "${s:0:2}-push" "$s.php" --phases=push --confirm-push
done
manual workflows
step 08-jira 08_migrate_custom_fields.php --phases=jira
step 10-jira 10_migrate_issues.php --phases=jira
step 09-jira 09_migrate_attachments.php --phases=jira
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
mysql -N "$db" -e "SELECT 'issues', migration_status, COUNT(*) FROM migration_mapping_issues GROUP BY 2
  UNION ALL SELECT 'journals', migration_status, COUNT(*) FROM migration_mapping_journals GROUP BY 2
  UNION ALL SELECT 'attachments', migration_status, COUNT(*) FROM migration_mapping_attachments GROUP BY 2
  UNION ALL SELECT 'relations', migration_status, COUNT(*) FROM migration_mapping_issue_relations GROUP BY 2
  UNION ALL SELECT 'watchers', migration_status, COUNT(*) FROM migration_mapping_watchers GROUP BY 2" | tee -a "$logdir/summary.txt"
keys=$(echo "$J2R_PROJECTS" | tr 'a-z' 'A-Z')
(cd "$REPO" && php tools/verify.php --projects="$keys" --sample=${SAMPLE:-25} --report="$logdir/verify.md") > "$logdir/verify.out" 2>&1
sed -n '/| check/,/^\*\*/p' "$logdir/verify.md" | tee -a "$logdir/summary.txt"

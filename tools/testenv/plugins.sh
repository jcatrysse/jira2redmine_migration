#!/bin/bash
# Brings the plugins of a test instance to their branch heads, as core.sh does for the core: a fetch with a forced
# refspec (branches get rewritten upstream), then the branch head, and the hash per plugin. Branches: 7.0 runs every
# plugin on redmine70-migration; 5.1 runs what production will run: plugins-5.1.tsv, except redmine_custom_workflows
# (commit 0398974, "2.1.3 devel", what production 5.1 runs; its 5.x and master need Redmine 6) and
# redmine_project_workflows (its new version on redmine70-migration, which supports 5.1, 6.1 and 7.0; decision 13).
# With --migrate: bundle install, plugins:migrate and a restart when a plugin changed. The baseline dump that
# run_all.sh restores holds the old plugin schema: refresh it afterwards (reset.sh, plugins.sh --migrate, pg_dump;
# docs/MIGRATION-PLAN.md, Results, dress rehearsal).
# A local change to a tracked file stops it (checkout without --force).
# Usage: tools/testenv/plugins.sh 51|70 [base dir, default /home/user/src] [--migrate]
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
v=$1
shift
BASE=/home/user/src
MIGRATE=
for arg in "$@"; do
  case $arg in
    --migrate) MIGRATE=1 ;;
    *) BASE=$arg ;;
  esac
done
export RBENV_ROOT=/opt/rbenv PATH=/opt/rbenv/shims:/opt/rbenv/bin:$PATH RAILS_ENV=production

changed=0
while IFS=$'\t' read -r repo branch; do
  ref=$branch
  [ "$v" = 70 ] && ref=redmine70-migration
  [ "$v" = 51 ] && [ "$repo" = redmine_custom_workflows ] && ref=03989743d72289afa84d62747a447b13a062fd2d
  [ "$v" = 51 ] && [ "$repo" = redmine_project_workflows ] && ref=redmine70-migration
  dir=
  for d in "$BASE/redmine$v/plugins"/*/; do
    case "$(git -C "$d" remote get-url origin 2>/dev/null)" in
      */"$repo".git | */"$repo") dir=${d%/} ;;
    esac
  done
  if [ -z "$dir" ]; then
    echo "$repo: not installed in redmine$v" >&2
    exit 1
  fi
  before=$(git -C "$dir" rev-parse --short HEAD)
  if [[ $ref =~ ^[0-9a-f]{40}$ ]]; then
    git -C "$dir" fetch -q --depth 1 origin "$ref"
    git -C "$dir" checkout -q --detach "$ref"
  elif git -C "$dir" ls-remote --exit-code --tags origin "refs/tags/$ref" > /dev/null 2>&1; then
    git -C "$dir" fetch -q --depth 1 origin "+refs/tags/$ref:refs/tags/$ref"
    git -C "$dir" checkout -q --detach "refs/tags/$ref"
  else
    git -C "$dir" fetch -q --depth 1 origin "+$ref:refs/remotes/origin/$ref"
    git -C "$dir" checkout -q -B "$ref" "origin/$ref"
  fi
  after=$(git -C "$dir" rev-parse --short HEAD)
  [ "$before" != "$after" ] && changed=$((changed + 1))
  printf '%s\t%s\t%s%s\n' "$(basename "$dir")" "$ref" "$after" "$([ "$before" != "$after" ] && echo " (was $before)")"
done < "$HERE/plugins-5.1.tsv"
echo "redmine$v: $changed plugin(s) changed"

if [ -n "$MIGRATE" ] && [ "$changed" -gt 0 ]; then
  (cd "$BASE/redmine$v" && bundle install -j4 > /dev/null && bundle exec rake redmine:plugins:migrate)
  if [ -x "$BASE/start.sh" ]; then
    "$BASE/start.sh" "$v"
  fi
fi

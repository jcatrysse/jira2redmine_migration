#!/bin/bash
# The Redmine cores of the test instances: clone, or bring an existing checkout to its branch head with a forced
# refspec, since a branch can be rewritten upstream (7.0-stable-GEOxyz was on 2026-10-14: 8067e23/1bf0ef6 to
# 1d8c2a3). A plain fetch would refuse that, and "clone only when absent" left an old core in place silently.
# Prints each instance's hash: put it in the plan with every run.
# With --migrate, an instance whose hash changed gets bundle install, db:migrate, plugins:migrate and a restart.
# A local change to a tracked file stops it (checkout without --force).
# Usage: tools/testenv/core.sh [base dir, default /home/user/src] [--migrate]
set -euo pipefail

BASE=/home/user/src
MIGRATE=
for arg in "$@"; do
  case $arg in
    --migrate) MIGRATE=1 ;;
    *) BASE=$arg ;;
  esac
done
export RBENV_ROOT=/opt/rbenv PATH=/opt/rbenv/shims:/opt/rbenv/bin:$PATH RAILS_ENV=production

for v in 51 70; do
  branch=$([ $v = 51 ] && echo 5.1-stable-GEOxyz || echo 7.0-stable-GEOxyz)
  dir="$BASE/redmine$v"
  before=
  if [ -d "$dir/.git" ]; then
    before=$(git -C "$dir" rev-parse --short HEAD)
    git -C "$dir" fetch -q --depth 1 origin "+$branch:refs/remotes/origin/$branch"
    git -C "$dir" checkout -q -B "$branch" "origin/$branch"
  else
    git clone -q --depth 1 -b "$branch" https://github.com/jcatrysse/redmine.git "$dir"
  fi
  after=$(git -C "$dir" rev-parse --short HEAD)
  echo "redmine$v core: $branch $after${before:+ (was $before)}"
  if [ -n "$MIGRATE" ] && [ -n "$before" ] && [ "$before" != "$after" ]; then
    (cd "$dir" && bundle install -j4 >/dev/null && bundle exec rake db:migrate && bundle exec rake redmine:plugins:migrate)
    if [ -x "$BASE/start.sh" ]; then
      "$BASE/start.sh" $v
    fi
  fi
done

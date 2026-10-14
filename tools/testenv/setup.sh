#!/bin/bash
# Build the two test targets of docs/MIGRATION-PLAN.md (work list 5) in a fresh cloud container:
#   Redmine 5.1 (jcatrysse/redmine 5.1-stable-GEOxyz, production plugin set, Ruby 3.2) on port 3051
#   Redmine 7.0 (jcatrysse/redmine 7.0-stable-GEOxyz, plugins on redmine70-migration, Ruby 3.3) on port 3070
# both on PostgreSQL 16, plus MariaDB for the staging database. Takes ~15 minutes.
# Private plugin repositories must be attached to the session first (add_repo).
# Usage: tools/testenv/setup.sh [base dir, default /home/user/src]
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
BASE=${1:-/home/user/src}
export RBENV_ROOT=/opt/rbenv PATH=/opt/rbenv/shims:/opt/rbenv/bin:$PATH RAILS_ENV=production
mkdir -p "$BASE/dumps"
cp "$HERE/start.sh" "$HERE/reset.sh" "$HERE/env.sh" "$BASE/"

# a fresh container has an empty package index
apt-get update -q >/dev/null
apt-get install -y -q libpq-dev libmysqlclient-dev mariadb-server >/dev/null
service postgresql start
service mariadb start

mysql -e "CREATE DATABASE IF NOT EXISTS jira2redmine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'jira2redmine'@'localhost' IDENTIFIED BY 'j2r';
  GRANT ALL ON jira2redmine.* TO 'jira2redmine'@'localhost';"
su postgres -c "psql -q" <<'SQL'
DO $$ BEGIN CREATE ROLE redmine LOGIN SUPERUSER PASSWORD 'redmine'; EXCEPTION WHEN duplicate_object THEN NULL; END $$;
SQL
for v in 51 70; do
  su postgres -c "createdb -O redmine -E UTF8 -T template0 redmine$v" 2>/dev/null || true
done

# clone the cores, or bring existing checkouts to the branch heads (forced refspec); prints each hash
"$HERE/core.sh" "$BASE"
echo 3.2.6 > "$BASE/redmine51/.ruby-version"
echo 3.3.6 > "$BASE/redmine70/.ruby-version"

while IFS=$'\t' read -r repo branch; do
  for v in 51 70; do
    b=$branch; [ $v = 70 ] && b=redmine70-migration
    # 5.x and master of redmine_custom_workflows are v3.1.1 (Redmine 6); production 5.1 runs commit 0398974
    # (2.1.3 devel, the coordinator, 2026-10-14)
    [ $v = 51 ] && [ "$repo" = redmine_custom_workflows ] && b=03989743d72289afa84d62747a447b13a062fd2d
    src="$BASE/p$v/$repo"
    if [ ! -d "$src" ] && [[ $b =~ ^[0-9a-f]{40}$ ]]; then
      git clone -q --filter=blob:none "https://github.com/jcatrysse/$repo.git" "$src" && git -C "$src" checkout -q --detach "$b"
    fi
    [ -d "$src" ] || git clone -q --depth 1 -b "$b" "https://github.com/jcatrysse/$repo.git" "$src"
    # Redmine requires the directory to carry the plugin id
    id=$(grep -hoE "Plugin\.register\s*\(?\s*:?['\"]?[a-z_0-9]+" "$src/init.rb" | head -1 | sed -E "s/.*register\s*\(?\s*:?['\"]?//")
    [ -d "$BASE/redmine$v/plugins/${id:-$repo}" ] || cp -r "$src" "$BASE/redmine$v/plugins/${id:-$repo}"
  done
done < "$HERE/plugins-5.1.tsv"

# GEOxyz relation types (production config of redmine_issue_view_columns, B18), from custom_mappings
for v in 51 70; do
  cp "$HERE/redmine_issue_view_columns.local.rb" "$BASE/redmine$v/plugins/redmine_issue_view_columns/config/"
done

for v in 51 70; do
  cat > "$BASE/redmine$v/config/database.yml" <<YML
production:
  adapter: postgresql
  database: redmine$v
  host: 127.0.0.1
  username: redmine
  password: redmine
  encoding: utf8
YML
done
# 5.1-stable-GEOxyz uses active_record_store without declaring the gem (7.0 declares it)
printf "gem 'activerecord-session_store', '~> 2.1'\ngem 'puma'\n" > "$BASE/redmine51/Gemfile.local"
echo "gem 'puma'" > "$BASE/redmine70/Gemfile.local"

for v in 51 70; do
  (cd "$BASE/redmine$v" && bundle config set --local without 'development test' && bundle install -j4 >/dev/null)
done
# redmineup >= 1.1.5 defines the route auto_complete_taggable_tags that redmine_contacts (master) defines too
(cd "$BASE/redmine51" && sed -i -E 's/^    redmineup \([0-9.]+\)/    redmineup (1.1.4)/; /^  redmineup \([0-9.]+\) sha256/d' Gemfile.lock && bundle install >/dev/null)

for v in 51 70; do
  cd "$BASE/redmine$v"
  # issue_recurring loads models from its rake file, which fails on an empty database (any rake task)
  mv plugins/issue_recurring ../issue_recurring.tmp
  bundle exec rake generate_secret_token
  bundle exec rake db:migrate
  REDMINE_LANG=en bundle exec rake redmine:load_default_data
  bundle exec rake redmine:plugins:migrate
  mv ../issue_recurring.tmp plugins/issue_recurring
  bundle exec rake redmine:plugins:migrate
  bundle exec rails runner '
    Setting.rest_api_enabled = "1"; Setting.text_formatting = "common_mark"
    Setting.attachment_max_size = 4194304; Setting.login_required = "1"; Setting.parent_issue_priority = "independent"
    u = User.find_by_login("admin"); u.password = u.password_confirmation = "Admin-Test-2026"
    u.must_change_passwd = false; u.save!
    puts "KEY=#{Token.find_or_create_by!(user_id: u.id, action: "api").value}"' | grep '^KEY=' > "$BASE/setup$v.out"
  PGPASSWORD=redmine pg_dump -h 127.0.0.1 -U redmine -Fc redmine$v > "$BASE/dumps/redmine$v-baseline.dump"
  "$BASE/start.sh" $v
done
echo "Admin API keys in $BASE/setup51.out and setup70.out (read by env.sh)"

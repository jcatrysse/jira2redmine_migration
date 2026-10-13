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
cp "$HERE/start.sh" "$HERE/reset.sh" "$BASE/"

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

[ -d "$BASE/redmine51" ] || git clone -q --depth 1 -b 5.1-stable-GEOxyz https://github.com/jcatrysse/redmine.git "$BASE/redmine51"
[ -d "$BASE/redmine70" ] || git clone -q --depth 1 -b 7.0-stable-GEOxyz https://github.com/jcatrysse/redmine.git "$BASE/redmine70"
echo 3.2.6 > "$BASE/redmine51/.ruby-version"
echo 3.3.6 > "$BASE/redmine70/.ruby-version"

while IFS=$'\t' read -r repo branch; do
  for v in 51 70; do
    b=$branch; [ $v = 70 ] && b=redmine70-migration
    # 5.x of redmine_custom_workflows requires Redmine 6 (v3.1.1); v2.1.2 is the last release for 5.1
    [ $v = 51 ] && [ "$repo" = redmine_custom_workflows ] && b=v2.1.2
    src="$BASE/p$v/$repo"
    [ -d "$src" ] || git clone -q --depth 1 -b "$b" "https://github.com/jcatrysse/$repo.git" "$src"
    # Redmine requires the directory to carry the plugin id
    id=$(grep -hoE "Plugin\.register\s*\(?\s*:?['\"]?[a-z_0-9]+" "$src/init.rb" | head -1 | sed -E "s/.*register\s*\(?\s*:?['\"]?//")
    [ -d "$BASE/redmine$v/plugins/${id:-$repo}" ] || cp -r "$src" "$BASE/redmine$v/plugins/${id:-$repo}"
  done
done < "$HERE/plugins-5.1.tsv"

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
  bundle exec rake generate_secret_token
  # issue_recurring loads models from its rake file, which fails on an empty database
  mv plugins/issue_recurring ../issue_recurring.tmp
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
    Token.find_or_create_by!(user_id: u.id, action: "api")'
  PGPASSWORD=redmine pg_dump -h 127.0.0.1 -U redmine -Fc redmine$v > "$BASE/dumps/redmine$v-baseline.dump"
  "$BASE/start.sh" $v
done
echo "API keys: bundle exec rails runner 'puts Token.find_by(action: \"api\", user_id: 1).value' in each instance"

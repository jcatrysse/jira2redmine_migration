# source env.sh 51|70
export JIRA_CLOUD_ID=$(cat /tmp/claude-0/cid)
export TARGET=$1
export REDMINE_URL=http://127.0.0.1:30$1
export REDMINE_API_KEY=$(grep -o 'KEY=.*' /home/user/src/setup$1.out | cut -d= -f2)
export STAGING_DSN="mysql:host=127.0.0.1;port=3306;dbname=jira2redmine_$1;charset=utf8mb4"
export J2R_TMP=/home/user/src/tmp$1
# decision 42: Jira content without a Redmine account goes to Redmine's Anonymous user (its id differs per Redmine)
export J2R_DEFAULT_AUTHOR_ID=${J2R_DEFAULT_AUTHOR_ID:-$(PGPASSWORD=redmine psql -h 127.0.0.1 -U redmine -d redmine$1 -Atc "SELECT id FROM users WHERE type = 'AnonymousUser'")}

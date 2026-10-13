# CLAUDE.md: jira2redmine_migration

PHP scripts that migrate Jira Cloud to Redmine through a staging database, run by GEOxyz.

**Current work: test migrations and a quality upgrade of the scripts.** Read
[docs/MIGRATION-PLAN.md](docs/MIGRATION-PLAN.md) before anything else; it holds the goal, Jan's
decisions, the measured state, the work list and the rules. Work on branch `redmine70-migration`.

## Always

- Run commands yourself (scripts, tests, a real Redmine, a browser) and quote the results. Never
  call something green you did not see green.
- Jira is read-only: use the scoped read token from the environment and prove it read-only before
  the first call of a session (see the plan, decision 1). Never send a write request to Jira.
- This repository is public: never commit Jira data (payloads, dumps, attachments, real issue
  text, screenshots of real issues) or secrets.
- Never skip, delete or weaken a test. Every fix gets a test that fails without it.
- Refactor without behaviour change first, prove it with identical dry-run payloads, then change
  behaviour in separate commits.
- Keep both targets working: Redmine 5.1 and Redmine 7.0.
- Never push to `main`, never force-push a shared branch.
- End with a short report in Dutch.

# Changelog

All notable changes to this project will be documented in this file.

## [TODO]

- NONE at this time

## [DURING MIGRATION]
- Check status and tracker language
- Status DONE = CLOSED
- Status TO DO = NEW

## [AFTER MIGRATION]
- Re-enable: RAILS_ENV=development bundle exec rake --silent redmine:attachments:prune
- Lock all flagged users again (I have temporarily put all locked users to a flagged status and activated them for the migration)
- Re-enable the ldap sync if any
- Check group memberships
- Reset the workflows to accept only valid statuses.
- Re-enable stealth mode

## [Issue solutions]
Apache config: `SetEnv RACK_QUERY_PARSER_PARAMS_LIMIT 12000`

## [0.0.1]

- Initial release

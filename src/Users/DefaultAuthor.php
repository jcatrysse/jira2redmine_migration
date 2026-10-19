<?php

declare(strict_types=1);

namespace Jira2Redmine\Users;

use RuntimeException;

/**
 * The Redmine user of Jira content whose author has no Redmine account (Jan, decision 42, 2026-10-19): "In principle
 * every Jira user should have a Redmine user during the migration. If an account is still missing, the config file of
 * the migration scripts picks a user id: that can be the migration user, the admin or Anonymous. Default: Anonymous."
 *
 * It applies to the issue author (10), the comments and changelog entries (11), the history journals and
 * jira-history.json (11) and the attachment uploader (09). The scripts used to fall back to the API user (the migration
 * account) when no id was set; with the Extended API the id must be set now. Anonymous has no fixed id: the runbook
 * reads it (`rails runner 'puts User.anonymous.id'`) into J2R_DEFAULT_AUTHOR_ID.
 */
final class DefaultAuthor
{
    public const HOW = "set J2R_DEFAULT_AUTHOR_ID to Redmine's Anonymous user (in the Redmine directory: "
        . "RAILS_ENV=production bundle exec rails runner 'puts User.anonymous.id') or to the account Jan chooses";

    /**
     * The configured id, null when none is set.
     *
     * @param array<string, mixed> $config
     * @param 'issues'|'journals'|'attachments' $section
     */
    public static function configured(array $config, string $section): ?int
    {
        $values = $section === 'attachments'
            ? ($config['attachments'] ?? [])
            : ($config['migration'][$section] ?? []);
        $value = is_array($values) ? ($values['default_redmine_author_id'] ?? null) : null;
        if (!is_numeric($value) || (int)$value <= 0) {
            return null;
        }

        return (int)$value;
    }

    /**
     * The configured id; without one the script stops instead of letting the API user stand in.
     *
     * @param array<string, mixed> $config
     * @param 'issues'|'journals'|'attachments' $section
     */
    public static function required(array $config, string $section): int
    {
        $id = self::configured($config, $section);
        if ($id === null) {
            $key = $section === 'attachments' ? 'attachments' : 'migration.' . $section;
            throw new RuntimeException(sprintf(
                'No default author for Jira content without a Redmine account (%s.default_redmine_author_id; '
                . 'decision 42): %s.',
                $key,
                self::HOW
            ));
        }

        return $id;
    }
}

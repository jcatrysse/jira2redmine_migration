<?php

declare(strict_types=1);

namespace Jira2Redmine\CustomFields;

use Jira2Redmine\Redmine\Names;

/**
 * The Redmine name 08 proposes for a Jira custom field (decision 44, B88). A name Jan configured for the field
 * (migration.custom_fields.names, by Jira field id) wins, also over a name an earlier run stored; else the stored
 * name, else the Jira name. A name longer than Redmine's 30 characters is shortened, with a note on the mapping row,
 * as 07 does for trackers (B1); before, 08 proposed it as it was and Redmine refused the field with 422. A row edited
 * by hand never gets here: 08 keeps it as it is.
 */
final class FieldNames
{
    /**
     * @return array{name: string, note: string|null}
     */
    public static function propose(mixed $configured, string $jiraFieldId, ?string $stored, ?string $jiraName): array
    {
        $configuredName = is_array($configured) && is_scalar($configured[$jiraFieldId] ?? null)
            ? trim((string)$configured[$jiraFieldId])
            : '';
        $jiraName = $jiraName !== null && trim($jiraName) !== '' ? trim($jiraName) : null;
        $stored = $stored !== null && trim($stored) !== '' ? trim($stored) : null;
        $name = $configuredName !== '' ? $configuredName : ($stored ?? $jiraName ?? $jiraFieldId);
        $shortened = Names::customField($name);
        $shortenedJiraName = $jiraName !== null ? Names::customField($jiraName) : null;

        $notes = [];
        if ($configuredName !== '') {
            $notes[] = sprintf('Name from migration.custom_fields.names (Jan, decision 44; Jira: "%s").', $jiraName ?? $jiraFieldId);
        }
        if ($shortened !== $name) {
            $notes[] = sprintf('Name shortened to %d characters for Redmine (was: "%s").', Names::CUSTOM_FIELD_MAX, $name);
        } elseif ($configuredName === '' && $shortenedJiraName !== $jiraName && $name === $shortenedJiraName) {
            // a rerun reads the name it shortened before: the note stays
            $notes[] = sprintf('Name shortened to %d characters for Redmine (was: "%s").', Names::CUSTOM_FIELD_MAX, $jiraName);
        }

        return ['name' => $shortened, 'note' => $notes === [] ? null : implode(' ', $notes)];
    }
}

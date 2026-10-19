<?php

declare(strict_types=1);

namespace Jira2Redmine\History;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Jira's status, assignee and priority changes of one issue as Redmine journal details (Jan, decision 41). A change
 * to a value Redmine does not have is left out and the chain stays connected: A to X at t1 and X to B at t2 becomes
 * A to B at t2, so every detail's old value is the value before it. A change that is no change after the mapping
 * (two Jira statuses on one Redmine status) is dropped. The first detail of a field starts from Jira's old value,
 * or from none when Redmine does not have that one either.
 *
 * @phpstan-import-type Journal from IssueHistory
 * @phpstan-import-type Transition from IssueHistory
 */
final class HistoryChain
{
    /** Jira field => Redmine attribute */
    public const FIELDS = ['status' => 'status_id', 'assignee' => 'assigned_to_id', 'priority' => 'priority_id'];

    /**
     * @param list<array{id: string, created: string, author: ?string, items: list<array<string, mixed>>}> $entries
     *        the issue's Jira history entries (staging_jira_changelogs), created in UTC
     */
    public static function build(array $entries, HistoryLookups $lookups): IssueHistory
    {
        usort($entries, static fn (array $a, array $b): int => [$a['created'], (int)$a['id']] <=> [$b['created'], (int)$b['id']]);

        /** @var list<Journal> $journals */
        $journals = [];
        /** @var list<Transition> $transitions */
        $transitions = [];
        $notInRedmine = [];
        $known = [];
        $last = [];
        $skipped = 0;
        $dropped = 0;

        foreach ($entries as $entry) {
            $time = self::time($entry['created']);
            $details = [];
            foreach ($entry['items'] as $item) {
                $field = self::field($item);
                if ($field === null) {
                    continue;
                }
                $from = self::text($item['from'] ?? null);
                $to = self::text($item['to'] ?? null);
                $transitions[] = [
                    'history_id' => $entry['id'],
                    'time' => $time,
                    'field' => $field,
                    'from' => ['id' => $from, 'name' => self::name($field, $from, $item['fromString'] ?? null, $lookups)],
                    'to' => ['id' => $to, 'name' => self::name($field, $to, $item['toString'] ?? null, $lookups)],
                    'author' => ['jira_account_id' => $entry['author'], 'redmine_login' => $lookups->login($entry['author'])],
                ];
                foreach ([[$from, $item['fromString'] ?? null], [$to, $item['toString'] ?? null]] as [$value, $name]) {
                    if (!$lookups->maps($field, $value)) {
                        $notInRedmine[$field . "\0" . $value] = [
                            'field' => $field,
                            'id' => (string)$value,
                            'name' => self::name($field, $value, $name, $lookups),
                        ];
                    }
                }

                if (!array_key_exists($field, $known)) {
                    $known[$field] = $lookups->value($field, $from);
                }
                if (!$lookups->maps($field, $to)) {
                    $skipped++;
                    continue;
                }
                $new = $lookups->value($field, $to);
                if ($new === $known[$field]) {
                    $dropped++;
                    continue;
                }
                $details[] = [
                    'property' => 'attr',
                    'prop_key' => self::FIELDS[$field],
                    'old_value' => $known[$field] === null ? null : (string)$known[$field],
                    'value' => $new === null ? null : (string)$new,
                ];
                $known[$field] = $new;
                $last[$field] = $new;
            }
            if ($details !== []) {
                $journals[] = [
                    'history_id' => $entry['id'],
                    'user_id' => $lookups->author($entry['author']),
                    'created_on' => $time,
                    'details' => $details,
                ];
            }
        }

        return new IssueHistory($journals, $transitions, array_values($notInRedmine), $skipped, $dropped, $last);
    }

    /** @param array<string, mixed> $item */
    private static function field(array $item): ?string
    {
        $field = $item['field'] ?? null;
        if (!is_string($field) || !isset(self::FIELDS[$field]) || ($item['fieldtype'] ?? 'jira') !== 'jira') {
            return null;
        }

        return $field;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string)$value !== '' ? (string)$value : null;
    }

    /** Jira's name of a status or priority; for an assignee the Redmine login, never a person's display name. */
    private static function name(string $field, ?string $value, mixed $jiraName, HistoryLookups $lookups): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($field === 'assignee') {
            return $lookups->login($value);
        }

        return is_scalar($jiraName) ? (string)$jiraName : null;
    }

    private static function time(string $created): string
    {
        return (new DateTimeImmutable($created, new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}

<?php

declare(strict_types=1);

namespace Jira2Redmine\Workflows;

/**
 * Decision 13 checked against Jira: per project, tracker, role and status (0: a new issue) the statuses Redmine
 * offers (tools/testenv/workflow_fingerprint.rb) against what the project's Jira workflows allow that role.
 * Every difference gets a named category; one without a known reason is unexpected.
 */
final class WorkflowComparison
{
    public const EQUAL = 'equal';
    public const CURRENT = 'current status (Redmine lists it with the targets)';
    public const DEFAULT_STATUS = 'default status of a new issue (Redmine offers it when no creation transition applies)';

    /**
     * @param array<string, array<int, list<string>>> $expected "project|tracker|role|from" => target status => the reasons
     *     the role does not get that transition ([] = Jira allows it the role)
     * @param array<string, list<int>> $offered "project|tracker|role|from" => the statuses Redmine offers
     * @param array<int, int> $defaultStatus tracker => its default status
     * @return array{counts: array<string, int>, unexpected: list<string>}
     */
    public static function compare(array $expected, array $offered, array $defaultStatus): array
    {
        $counts = [self::EQUAL => 0];
        $unexpected = [];
        $count = static function (string $category) use (&$counts): void {
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        };
        foreach (array_unique(array_merge(array_keys($expected), array_keys($offered))) as $key) {
            [, $tracker, , $from] = array_map('intval', explode('|', (string)$key));
            $targets = $expected[$key] ?? [];
            $shown = array_values(array_unique(array_map('intval', $offered[$key] ?? [])));
            foreach ($shown as $status) {
                if (isset($targets[$status]) && $targets[$status] === []) {
                    $count(self::EQUAL);
                } elseif ($status === $from) {
                    $count(self::CURRENT);
                } elseif ($from === 0 && $status === ($defaultStatus[$tracker] ?? null) && !self::allowsAny($targets)) {
                    $count(self::DEFAULT_STATUS);
                } else {
                    $why = isset($targets[$status])
                        ? 'Jira does not allow it this role (' . implode('; ', $targets[$status]) . ')'
                        : 'not in Jira';
                    $count('unexpected: offered, ' . (isset($targets[$status]) ? 'Jira does not allow it this role' : 'not in Jira'));
                    $unexpected[] = sprintf('%s -> %d offered, %s', $key, $status, $why);
                }
            }
            foreach ($targets as $status => $reasons) {
                if (in_array($status, $shown, true)) {
                    continue;
                }
                if ($reasons === []) {
                    $count('unexpected: missing');
                    $unexpected[] = sprintf('%s -> %d missing', $key, $status);
                } else {
                    $count($reasons[0]);
                }
            }
        }
        ksort($counts);

        return ['counts' => $counts, 'unexpected' => $unexpected];
    }

    /** @param array<int, list<string>> $targets */
    private static function allowsAny(array $targets): bool
    {
        foreach ($targets as $reasons) {
            if ($reasons === []) {
                return true;
            }
        }

        return false;
    }
}

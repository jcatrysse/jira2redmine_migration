<?php

declare(strict_types=1);

namespace Jira2Redmine\Relations;

use JsonException;
use RuntimeException;

/**
 * Automation hash of a migration_mapping_issue_relations row: the state the script last wrote. A row
 * whose stored hash differs from its current state was edited by hand and is left alone.
 */
final class RelationState
{
    public static function hash(
        mixed $redmineIssueFromId,
        mixed $redmineIssueToId,
        mixed $redmineRelationId,
        mixed $proposedRelationType,
        mixed $migrationStatus,
        mixed $notes
    ): string {
        $payload = [
            'redmine_issue_from_id' => $redmineIssueFromId,
            'redmine_issue_to_id' => $redmineIssueToId,
            'redmine_relation_id' => $redmineRelationId,
            'proposed_relation_type' => $proposedRelationType,
            'migration_status' => $migrationStatus,
            'notes' => $notes,
        ];

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode relation automation payload: ' . $exception->getMessage(), 0, $exception);
        }

        return hash('sha256', $encoded);
    }

    /**
     * A row edited by hand since the script last wrote it: its stored hash no longer matches its state.
     *
     * @param array<string, mixed> $row
     */
    public static function isHandEdited(array $row): bool
    {
        $stored = trim((string)($row['automation_hash'] ?? ''));

        return $stored !== '' && $stored !== self::hash(
            $row['redmine_issue_from_id'] ?? null,
            $row['redmine_issue_to_id'] ?? null,
            $row['redmine_relation_id'] ?? null,
            $row['proposed_relation_type'] ?? null,
            $row['migration_status'] ?? null,
            $row['notes'] ?? null
        );
    }

    /**
     * What the push writes when Redmine refuses a relation. The hash is written with it: before, the push
     * set CREATION_FAILED and its message without the hash, so every later transform took the row for a
     * hand edit ("preserved manual overrides") and never tried it again (17 relations after a missing
     * relation type was added to Redmine).
     *
     * @param array<string, mixed> $row the row as the push read it
     * @return array{migration_status: string, notes: string, automation_hash: string}
     */
    public static function failed(array $row, string $message): array
    {
        return [
            'migration_status' => 'CREATION_FAILED',
            'notes' => $message,
            'automation_hash' => self::hash(
                $row['redmine_issue_from_id'] ?? null,
                $row['redmine_issue_to_id'] ?? null,
                null,
                $row['proposed_relation_type'] ?? null,
                'CREATION_FAILED',
                $message
            ),
        ];
    }
}

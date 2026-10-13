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
}

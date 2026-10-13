<?php

declare(strict_types=1);

use Jira2Redmine\Relations\RelationState;
use PHPUnit\Framework\TestCase;

final class RelationStateTest extends TestCase
{
    private const READY = [
        'redmine_issue_from_id' => 18,
        'redmine_issue_to_id' => 15,
        'redmine_relation_id' => null,
        'proposed_relation_type' => 'relates_to_wiki',
        'migration_status' => 'READY_FOR_CREATION',
        'notes' => null,
    ];

    public function testAFailedPushIsNotTakenForAHandEdit(): void
    {
        $ready = self::READY + ['automation_hash' => RelationState::hash(18, 15, null, 'relates_to_wiki', 'READY_FOR_CREATION', null)];

        $message = 'Failed to create Redmine relation (HTTP 422): Relation type is not included in the list';
        $failed = array_merge($ready, RelationState::failed($ready, $message));

        self::assertFalse(RelationState::isHandEdited($failed));
    }

    public function testAStatusWrittenWithoutItsHashLooksHandEdited(): void
    {
        // what the push did before: status and notes changed, hash of the READY state kept
        $row = array_merge(self::READY, [
            'migration_status' => 'CREATION_FAILED',
            'notes' => 'Failed',
            'automation_hash' => RelationState::hash(18, 15, null, 'relates_to_wiki', 'READY_FOR_CREATION', null),
        ]);

        self::assertTrue(RelationState::isHandEdited($row));
    }

    public function testAnEditedTypeIsAHandEditAndNoHashIsNot(): void
    {
        $edited = array_merge(self::READY, [
            'proposed_relation_type' => 'relates',
            'automation_hash' => RelationState::hash(18, 15, null, 'relates_to_wiki', 'READY_FOR_CREATION', null),
        ]);

        self::assertTrue(RelationState::isHandEdited($edited));
        self::assertFalse(RelationState::isHandEdited(self::READY + ['automation_hash' => null]));
    }

    public function testThePushWritesTheHashWithAFailure(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../12_migrate_issue_relations.php');

        self::assertTrue(str_contains($script, 'RelationState::failed('), '12 marks a failure without its automation hash');
    }
}

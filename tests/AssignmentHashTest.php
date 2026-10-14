<?php

declare(strict_types=1);

use Jira2Redmine\Roles\AssignmentHash;
use PHPUnit\Framework\TestCase;

final class AssignmentHashTest extends TestCase
{
    private const PROPOSAL = [
        'redmine_project_id' => 5,
        'redmine_group_id' => 30,
        'redmine_role_id' => 4,
        'proposed_redmine_role_id' => 4,
        'proposed_redmine_role_name' => 'Developer',
        'migration_status' => 'READY_FOR_ASSIGNMENT',
        'notes' => null,
    ];

    /** @return array<string, mixed> the row as 04 writes it */
    private static function written(): array
    {
        return self::PROPOSAL + ['automation_hash' => AssignmentHash::compute(5, 30, 4, 4, 'Developer', 'READY_FOR_ASSIGNMENT', null)];
    }

    public function testTheProposalAsWrittenIsNotAHandEdit(): void
    {
        self::assertFalse(AssignmentHash::isHandEdited(self::written()));
        // MySQL returns integers as strings
        self::assertFalse(AssignmentHash::isHandEdited(array_map(
            static fn (mixed $value): mixed => is_int($value) ? (string)$value : $value,
            self::written()
        )));
        $upper = strtoupper((string)self::written()['automation_hash']);
        self::assertFalse(AssignmentHash::isHandEdited(['automation_hash' => $upper] + self::written()));
    }

    public function testJansReviewEditsSurviveARerun(): void
    {
        // he ignores a proposal, or gives the group another role
        self::assertTrue(AssignmentHash::isHandEdited(['migration_status' => 'IGNORED'] + self::written()));
        self::assertTrue(AssignmentHash::isHandEdited(['redmine_role_id' => 3] + self::written()));
        self::assertTrue(AssignmentHash::isHandEdited(['notes' => 'checked with the project lead'] + self::written()));
    }

    public function testARowWithoutAStoredHashIsNotAHandEdit(): void
    {
        self::assertFalse(AssignmentHash::isHandEdited(self::PROPOSAL + ['automation_hash' => null]));
        self::assertFalse(AssignmentHash::isHandEdited(self::PROPOSAL + ['automation_hash' => '']));
        self::assertFalse(AssignmentHash::isHandEdited(self::PROPOSAL + ['automation_hash' => 'not a hash']));
    }
}

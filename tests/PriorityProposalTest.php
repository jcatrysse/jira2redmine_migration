<?php

declare(strict_types=1);

use Jira2Redmine\Priorities\PriorityProposal;
use PHPUnit\Framework\TestCase;

final class PriorityProposalTest extends TestCase
{
    /** @return array<string, array{id: int, name: string, is_default: ?bool, position: ?int}> */
    private static function redmine(): array
    {
        return [
            'low' => ['id' => 1, 'name' => 'Low', 'is_default' => false, 'position' => null],
            'normal' => ['id' => 2, 'name' => 'Normal', 'is_default' => true, 'position' => null],
            'high' => ['id' => 3, 'name' => 'High', 'is_default' => false, 'position' => null],
        ];
    }

    public function testAJiraPriorityMatchesTheRedminePriorityOfTheSameName(): void
    {
        $proposals = new PriorityProposal(self::redmine());

        $high = $proposals->propose('2', 'HIGH', null, null, null);
        self::assertSame(['status' => 'MATCH_FOUND', 'redmine_id' => 3, 'name' => 'High', 'is_default' => false, 'position' => null,
            'notes' => null], $high);
        self::assertTrue($proposals->propose('3', 'normal', null, null, null)['is_default'], 'the default comes from Redmine');
    }

    public function testAPriorityWithoutANameIsForReview(): void
    {
        $proposal = (new PriorityProposal(self::redmine()))->propose('9', null, null, null, null);

        self::assertSame('MANUAL_INTERVENTION_REQUIRED', $proposal['status']);
        self::assertSame('9', $proposal['name']);
        self::assertSame('Missing Jira priority name in the staging snapshot.', $proposal['notes']);
    }
}

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

    public function testANewPriorityGetsNoPositionSoTheExistingOnesStayWhereTheyAre(): void
    {
        // B73: the API lists Redmine's priorities without a position; 06 proposed 1, 2, 3 and Redmine inserted the new
        // ones below every existing priority (the new "Blocker" became the lowest, 7.0 test instance); creating one is
        // opt-in since decision 35
        $proposals = new PriorityProposal(self::redmine(), [], true);

        self::assertSame(['READY_FOR_CREATION', null], array_values(array_intersect_key(
            $proposals->propose('1', 'Blocker', null, null, null),
            ['status' => 0, 'position' => 0]
        )));
        self::assertNull($proposals->propose('10000', 'Showstopper', null, null, null)['position']);
        // a position set by hand on the mapping row stays
        self::assertSame(7, $proposals->propose('1', 'Blocker', 'Blocker', false, 7)['position']);
    }

    public function testAJiraPriorityMapsOntoAnExistingOneAndNothingIsCreatedByDefault(): void
    {
        // Jan, decision 35: one priority scheme for every project; a Jira priority Redmine does not have is for review
        $mappings = ['Medium' => 'Normal', 'blocker' => ' High ', 'Showstopper' => 'Critical'];
        $proposals = new PriorityProposal(self::redmine(), $mappings);

        $medium = $proposals->propose('3', 'Medium', null, null, null);
        self::assertSame(
            ['MATCH_FOUND', 2, 'Normal', true],
            [$medium['status'], $medium['redmine_id'], $medium['name'], $medium['is_default']]
        );
        self::assertSame(3, $proposals->propose('1', 'Blocker', null, null, null)['redmine_id'], 'the mapping ignores case and spaces');
        // a mapping wins over the same name
        $lowToHigh = new PriorityProposal(self::redmine(), ['Low' => 'High']);
        self::assertSame(3, $lowToHigh->propose('4', 'Low', null, null, null)['redmine_id']);

        $unknown = $proposals->propose('10000', 'Showstopper', null, null, null);
        self::assertSame(['MANUAL_INTERVENTION_REQUIRED', null], [$unknown['status'], $unknown['redmine_id']]);
        self::assertSame(sprintf(PriorityProposal::UNKNOWN_TARGET_NOTE, 'Showstopper', 'Critical'), $unknown['notes']);

        $unmapped = $proposals->propose('5', 'Lowest', null, null, null);
        self::assertSame(
            ['MANUAL_INTERVENTION_REQUIRED', null, 'Lowest'],
            [$unmapped['status'], $unmapped['redmine_id'], $unmapped['name']]
        );
        self::assertSame(sprintf(PriorityProposal::UNMAPPED_NOTE, 'Lowest'), $unmapped['notes']);
        $creating = new PriorityProposal(self::redmine(), [], true);
        self::assertSame('READY_FOR_CREATION', $creating->propose('5', 'Lowest', null, null, null)['status']);
    }

    public function testAPriorityWithoutANameIsForReview(): void
    {
        $proposal = (new PriorityProposal(self::redmine()))->propose('9', null, null, null, null);

        self::assertSame('MANUAL_INTERVENTION_REQUIRED', $proposal['status']);
        self::assertSame('9', $proposal['name']);
        self::assertSame('Missing Jira priority name in the staging snapshot.', $proposal['notes']);
    }
}

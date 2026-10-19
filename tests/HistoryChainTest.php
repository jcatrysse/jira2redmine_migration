<?php

declare(strict_types=1);

use Jira2Redmine\History\HistoryChain;
use Jira2Redmine\History\HistoryFile;
use Jira2Redmine\History\HistoryLookups;
use Jira2Redmine\History\HistoryRequest;
use Jira2Redmine\History\IssueHistory;
use PHPUnit\Framework\TestCase;

/**
 * Decision 41 (Jan, 2026-10-19): Jira's status, assignee and priority history becomes Redmine journals during the
 * migration. A value Redmine does not have is skipped with the chain kept connected, and only such an issue gets
 * jira-history.json.
 */
final class HistoryChainTest extends TestCase
{
    private function lookups(): HistoryLookups
    {
        return new HistoryLookups(
            ['1' => 11, '3' => 13, '6' => 16, '7' => 16],
            ['2' => 22, '3' => 23],
            ['acc-ann' => ['id' => 31, 'login' => 'ann'], 'acc-bob' => ['id' => 32, 'login' => 'bob']],
            99
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{id: string, created: string, author: ?string, items: list<array<string, mixed>>}
     */
    private function entry(string $id, string $created, ?string $author, array $items): array
    {
        return ['id' => $id, 'created' => $created, 'author' => $author, 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function item(string $field, ?string $from, ?string $to, ?string $fromName = null, ?string $toName = null): array
    {
        return [
            'field' => $field,
            'fieldtype' => 'jira',
            'fieldId' => $field,
            'from' => $from,
            'fromString' => $fromName,
            'to' => $to,
            'toString' => $toName,
        ];
    }

    /** @param array<string, mixed> $item */
    private function single(?string $author, array $item, string $created = '2024-01-01 08:00:00'): IssueHistory
    {
        return HistoryChain::build([$this->entry('1', $created, $author, [$item])], $this->lookups());
    }

    public function testAStatusChangeBecomesADetailWithTheRedmineStatusesAuthorAndTime(): void
    {
        $history = $this->single('acc-ann', $this->item('status', '1', '3', 'Open', 'In Progress'), '2024-03-01 10:15:00');
        self::assertSame([[
            'history_id' => '1',
            'user_id' => 31,
            'created_on' => '2024-03-01T10:15:00+00:00',
            'details' => [['property' => 'attr', 'prop_key' => 'status_id', 'old_value' => '11', 'value' => '13']],
        ]], $history->journals);
        self::assertFalse($history->needsFile());
        self::assertSame(['status' => 13], $history->last);
    }

    public function testAnAssigneeMapsThroughTheUserAccountsAndUnassignedIsEmpty(): void
    {
        $history = HistoryChain::build([
            $this->entry('1', '2024-01-01 08:00:00', 'acc-ann', [$this->item('assignee', null, 'acc-bob', null, 'Bob Display')]),
            $this->entry('2', '2024-01-02 08:00:00', 'acc-ann', [$this->item('assignee', 'acc-bob', null, 'Bob Display', null)]),
        ], $this->lookups());
        self::assertSame(
            [['property' => 'attr', 'prop_key' => 'assigned_to_id', 'old_value' => null, 'value' => '32']],
            $history->journals[0]['details']
        );
        self::assertSame(
            [['property' => 'attr', 'prop_key' => 'assigned_to_id', 'old_value' => '32', 'value' => null]],
            $history->journals[1]['details']
        );
    }

    public function testAPriorityMapsThroughSixesMapping(): void
    {
        $history = $this->single('acc-bob', $this->item('priority', '3', '2', 'Medium', 'High'));
        self::assertSame(
            [['property' => 'attr', 'prop_key' => 'priority_id', 'old_value' => '23', 'value' => '22']],
            $history->journals[0]['details']
        );
    }

    public function testAStatusRedmineDoesNotHaveIsSkippedAndTheChainStaysConnected(): void
    {
        // A to X at t1 and X to B at t2 becomes A to B at t2 (LABO's statuses that no project uses)
        $history = HistoryChain::build([
            $this->entry('1', '2024-01-01 08:00:00', 'acc-ann', [$this->item('status', '1', '900', 'Open', 'Labo check')]),
            $this->entry('2', '2024-01-05 08:00:00', 'acc-bob', [$this->item('status', '900', '3', 'Labo check', 'In Progress')]),
        ], $this->lookups());
        self::assertCount(1, $history->journals);
        self::assertSame('2', $history->journals[0]['history_id']);
        self::assertSame('2024-01-05T08:00:00+00:00', $history->journals[0]['created_on']);
        self::assertSame(32, $history->journals[0]['user_id']);
        self::assertSame(
            [['property' => 'attr', 'prop_key' => 'status_id', 'old_value' => '11', 'value' => '13']],
            $history->journals[0]['details']
        );
        self::assertSame(1, $history->skipped);
        self::assertTrue($history->needsFile());
        self::assertSame([['field' => 'status', 'id' => '900', 'name' => 'Labo check']], $history->notInRedmine);
    }

    public function testAnAssigneeWithoutAnAccountAndAnUnmappedPriorityAreSkippedToo(): void
    {
        $history = HistoryChain::build([
            $this->entry('1', '2024-01-01 08:00:00', 'acc-ann', [$this->item('assignee', 'acc-ann', 'acc-app', null, 'Automation')]),
            $this->entry('2', '2024-01-02 08:00:00', 'acc-ann', [$this->item('assignee', 'acc-app', 'acc-bob', 'Automation', null)]),
            $this->entry('3', '2024-01-03 08:00:00', 'acc-ann', [$this->item('priority', '3', '8', 'Medium', 'Lowest')]),
        ], $this->lookups());
        self::assertSame(2, $history->skipped);
        self::assertCount(1, $history->journals);
        self::assertSame(
            [['property' => 'attr', 'prop_key' => 'assigned_to_id', 'old_value' => '31', 'value' => '32']],
            $history->journals[0]['details']
        );
        // no display name of a person in the file: an account without a Redmine login has no name
        self::assertSame(
            [['field' => 'assignee', 'id' => 'acc-app', 'name' => null], ['field' => 'priority', 'id' => '8', 'name' => 'Lowest']],
            $history->notInRedmine
        );
    }

    public function testAnEmptyNewStatusOrPriorityIsSkippedButAnEmptyOldOneIsFine(): void
    {
        // Redmine refuses an issue without a priority; an issue that got its first priority starts from none
        $history = HistoryChain::build([
            $this->entry('1', '2024-01-01 08:00:00', 'acc-ann', [$this->item('priority', null, '3', null, 'Medium')]),
            $this->entry('2', '2024-01-02 08:00:00', 'acc-ann', [$this->item('priority', '3', null, 'Medium', null)]),
            $this->entry('3', '2024-01-03 08:00:00', 'acc-ann', [$this->item('priority', null, '2', null, 'High')]),
        ], $this->lookups());
        self::assertSame(
            [
                [['property' => 'attr', 'prop_key' => 'priority_id', 'old_value' => null, 'value' => '23']],
                [['property' => 'attr', 'prop_key' => 'priority_id', 'old_value' => '23', 'value' => '22']],
            ],
            array_column($history->journals, 'details')
        );
        self::assertSame(1, $history->skipped);
        self::assertSame([['field' => 'priority', 'id' => null, 'name' => null]], $history->notInRedmine);
    }

    public function testAChangeThatIsNoChangeAfterTheMappingIsDropped(): void
    {
        // Jira statuses 6 and 7 map onto the same Redmine status
        $history = HistoryChain::build([
            $this->entry('1', '2024-01-01 08:00:00', 'acc-ann', [$this->item('status', '6', '7', 'Done', 'Closed')]),
        ], $this->lookups());
        self::assertSame([], $history->journals);
        self::assertSame(1, $history->dropped);
        self::assertFalse($history->needsFile());
    }

    public function testASkippedValueBetweenTwoEqualValuesLeavesNothing(): void
    {
        // A to X, X to A: after the skip it is A to A, so no detail at all
        $history = HistoryChain::build([
            $this->entry('1', '2024-01-01 08:00:00', 'acc-ann', [$this->item('status', '1', '900')]),
            $this->entry('2', '2024-01-02 08:00:00', 'acc-ann', [$this->item('status', '900', '1')]),
        ], $this->lookups());
        self::assertSame([], $history->journals);
        self::assertSame(1, $history->skipped);
        self::assertSame(1, $history->dropped);
        self::assertTrue($history->needsFile());
    }

    public function testAFirstValueRedmineDoesNotHaveStartsFromNone(): void
    {
        $history = HistoryChain::build([
            $this->entry('1', '2024-01-01 08:00:00', 'acc-ann', [$this->item('status', '900', '3', 'Labo check', 'In Progress')]),
        ], $this->lookups());
        self::assertSame(
            [['property' => 'attr', 'prop_key' => 'status_id', 'old_value' => null, 'value' => '13']],
            $history->journals[0]['details']
        );
        self::assertSame(0, $history->skipped);
        self::assertTrue($history->needsFile());
    }

    public function testAnAuthorWithoutAnAccountGetsElevensFallback(): void
    {
        $history = $this->single('acc-app', $this->item('status', '1', '3'));
        self::assertSame(99, $history->journals[0]['user_id']);
        $none = $this->single(null, $this->item('status', '1', '3'));
        self::assertSame(99, $none->journals[0]['user_id']);
    }

    public function testEntriesFollowJiraTimeThenIdAndOneEntryIsOneJournal(): void
    {
        $history = HistoryChain::build([
            $this->entry('20', '2024-01-02 08:00:00', 'acc-ann', [$this->item('status', '3', '1')]),
            $this->entry('9', '2024-01-01 08:00:00', 'acc-ann', [$this->item('status', '1', '3'), $this->item('priority', '3', '2')]),
            $this->entry('10', '2024-01-01 08:00:00', 'acc-bob', [$this->item('assignee', null, 'acc-bob')]),
        ], $this->lookups());
        self::assertSame(['9', '10', '20'], array_column($history->journals, 'history_id'));
        self::assertCount(2, $history->journals[0]['details']);
        self::assertSame(4, $history->detailCount());
        self::assertSame(['status' => 11, 'priority' => 22, 'assignee' => 32], $history->last);
    }

    public function testOnlyJirasOwnStatusAssigneeAndPriorityCount(): void
    {
        $history = HistoryChain::build([$this->entry('1', '2024-01-01 08:00:00', 'acc-ann', [
            ['field' => 'status', 'fieldtype' => 'custom', 'from' => '1', 'to' => '3'],
            $this->item('resolution', null, '10000'),
            $this->item('Attachment', null, '12345'),
        ])], $this->lookups());
        self::assertSame([], $history->journals);
        self::assertSame([], $history->transitions);
    }

    public function testTheLastStatusIsCheckedAgainstTheIssue(): void
    {
        $history = $this->single('acc-ann', $this->item('status', '1', '6'));
        self::assertNull($history->statusCheck(16, 16));
        // 10's open parent rule gave the issue "Resolved"; the history ends in Jira's status
        self::assertSame('open parent', $history->statusCheck(13, 16));
        self::assertSame('mismatch', $history->statusCheck(13, 13));
        $noStatus = $this->single('acc-ann', $this->item('priority', '3', '2'));
        self::assertNull($noStatus->statusCheck(13, 13));
    }

    public function testTheFileHoldsEveryTransitionWithAccountIdAndLoginButNoDisplayName(): void
    {
        $history = HistoryChain::build([
            $this->entry('1', '2024-01-01 08:00:00', 'acc-ann', [$this->item('status', '1', '900', 'Open', 'Labo check')]),
            $this->entry('2', '2024-01-02 08:00:00', 'acc-app', [$this->item('assignee', null, 'acc-bob', null, 'Bob Display')]),
        ], $this->lookups());
        $file = json_decode(HistoryFile::content('LABO-7', '10007', $history), true);
        self::assertSame('LABO-7', $file['jira_key']);
        self::assertSame([
            'time' => '2024-01-01T08:00:00+00:00',
            'field' => 'status',
            'from' => ['id' => '1', 'name' => 'Open'],
            'to' => ['id' => '900', 'name' => 'Labo check'],
            'author' => ['jira_account_id' => 'acc-ann', 'redmine_login' => 'ann'],
        ], $file['transitions'][0]);
        self::assertSame(['id' => 'acc-bob', 'name' => 'bob'], $file['transitions'][1]['to']);
        self::assertStringNotContainsString('Bob Display', HistoryFile::content('LABO-7', '10007', $history));
        self::assertSame(HistoryFile::content('LABO-7', '10007', $history), HistoryFile::content('LABO-7', '10007', $history));
    }

    public function testTheFileHasTheAuthorAndTimeOfItsLastTransition(): void
    {
        $history = HistoryChain::build([
            $this->entry('1', '2024-01-01 08:00:00', 'acc-ann', [$this->item('status', '1', '3')]),
            $this->entry('2', '2024-01-09 17:30:00', 'acc-bob', [$this->item('status', '3', '900')]),
        ], $this->lookups());
        self::assertSame(
            ['author_id' => 32, 'created_on' => '2024-01-09T17:30:00+00:00', 'history_id' => '2'],
            HistoryFile::stamp($history, $this->lookups())
        );
    }

    public function testTheRequestCarriesTheJournalsAndTheFile(): void
    {
        $history = $this->single('acc-ann', $this->item('status', '1', '3'));
        self::assertSame('issues/42/history.json', HistoryRequest::path(42));
        self::assertSame(['journals' => [[
            'user_id' => 31,
            'created_on' => '2024-01-01T08:00:00+00:00',
            'details' => [['property' => 'attr', 'prop_key' => 'status_id', 'old_value' => '11', 'value' => '13']],
        ]]], HistoryRequest::body($history->journals, null));
        $file = ['token' => '7.abc', 'author_id' => 31, 'created_on' => '2024-01-01T08:00:00+00:00'];
        $body = HistoryRequest::body($history->journals, $file);
        self::assertSame([[
            'token' => '7.abc',
            'filename' => 'jira-history.json',
            'content_type' => 'application/json',
            'description' => HistoryFile::DESCRIPTION,
            'author_id' => 31,
            'created_on' => '2024-01-01T08:00:00+00:00',
        ]], $body['attachments'] ?? null);
    }

    public function testTheAnswerGivesTheJournalIdsInOrder(): void
    {
        self::assertSame([5, 6], HistoryRequest::journalIds(['journals' => [['id' => 5], ['id' => 6]]]));
        self::assertSame([5, 6], HistoryRequest::journalIds(['journal_ids' => [5, 6]]));
        self::assertNull(HistoryRequest::journalIds(['issue' => ['id' => 1]]));
        self::assertSame(9, HistoryRequest::attachmentId(['attachments' => [['id' => 9]]]));
        // redmine_extended_api e87ed79
        self::assertSame([5, 6], HistoryRequest::journalIds(['journal_ids' => [5, 6], 'attachment_ids' => [9]]));
        self::assertSame(9, HistoryRequest::attachmentId(['journal_ids' => [5], 'attachment_ids' => [9]]));
        self::assertNull(HistoryRequest::attachmentId([]));
    }
}

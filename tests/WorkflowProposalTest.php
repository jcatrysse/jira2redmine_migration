<?php

declare(strict_types=1);

use Jira2Redmine\Workflows\JiraTransitions;
use Jira2Redmine\Workflows\WorkflowProposal;
use PHPUnit\Framework\TestCase;

final class WorkflowProposalTest extends TestCase
{
    private const WORKFLOW = [
        'id' => ['name' => 'ABC workflow'],
        'statuses' => [['id' => '1', 'name' => 'Open'], ['id' => '3', 'name' => 'In Progress'], ['id' => '6', 'name' => 'Closed']],
        'transitions' => [
            ['id' => '1', 'name' => 'Create', 'to' => '1', 'type' => 'initial',
                'rules' => ['postFunctions' => [['type' => 'IssueCreateFunction']]]],
            ['id' => '11', 'name' => 'Start', 'from' => ['1'], 'to' => '3', 'type' => 'directed',
                'rules' => ['conditionsTree' => ['nodeType' => 'simple', 'type' => 'UserInGroupCondition',
                    'configuration' => ['group' => 'Support team']]]],
            ['id' => '21', 'name' => 'Close', 'to' => '6', 'type' => 'global',
                'rules' => [
                    'conditionsTree' => ['nodeType' => 'compound', 'operator' => 'AND', 'conditions' => [
                        ['nodeType' => 'simple', 'type' => 'PermissionCondition', 'configuration' => ['permissionKey' => 'CLOSE_ISSUES']],
                        ['nodeType' => 'simple', 'type' => 'AllowOnlyAssignee'],
                    ]],
                    'postFunctions' => [['type' => 'UpdateIssueStatusFunction'], ['type' => 'AssignToCurrentUserFunction'],
                        ['type' => 'ClearFieldValuePostFunction', 'configuration' => ['fieldId' => 'resolution']]],
                ],
                'screen' => ['id' => '3', 'name' => 'Resolve screen']],
        ],
    ];

    public function testTransitionsAreReadWithWhatRedmineCannotDo(): void
    {
        $transitions = JiraTransitions::fromWorkflow(self::WORKFLOW);

        self::assertSame(['0'], $transitions[0]['from']);
        self::assertSame(['Support team'], $transitions[1]['groups']);
        // a global transition comes from every other status
        self::assertSame(['1', '3'], $transitions[2]['from']);
        self::assertTrue($transitions[2]['assignee_only']);
        self::assertSame(['CLOSE_ISSUES'], $transitions[2]['permissions']);
        // the resolution clean-up has no Redmine counterpart and nothing to do; the assignment has
        $notMapped = ['post function AssignToCurrentUserFunction', 'transition screen "Resolve screen"'];
        self::assertSame($notMapped, $transitions[2]['not_mapped']);
    }

    public function testTheProposalIsPerTrackerAndRole(): void
    {
        $sources = [
            ['project' => 'ABC', 'workflow' => 'ABC workflow', 'issue_type' => '10001',
                'transitions' => JiraTransitions::fromWorkflow(self::WORKFLOW)],
            ['project' => 'XYZ', 'workflow' => 'XYZ workflow', 'issue_type' => '10001', 'transitions' => [
                ['name' => 'Start', 'from' => ['1'], 'to' => '3', 'groups' => [], 'permissions' => [], 'assignee_only' => false,
                    'not_mapped' => []],
            ]],
            ['project' => 'ABC', 'workflow' => 'Other', 'issue_type' => '99999', 'transitions' => []],
        ];
        $result = WorkflowProposal::build(
            $sources,
            ['10001' => 2],
            ['1' => 1, '3' => 2, '6' => 5],
            ['ABC' => [3, 4], 'XYZ' => [4]],
            ['ABC' => ['support team' => [4]]]
        );
        $rows = $result['rows'];

        // creation for every role of the project, the group's transition for its role only
        self::assertArrayHasKey('2|3|0|1|0', $rows);
        self::assertArrayHasKey('2|4|1|2|0', $rows);
        self::assertArrayNotHasKey('2|3|1|2|0', $rows);
        // the same transition from another project's workflow is merged
        self::assertSame(['ABC', 'XYZ'], $rows['2|4|1|2|0']['projects']);
        // only the assignee: Redmine's assignee flag
        self::assertTrue($rows['2|3|2|5|1']['assignee_only']);
        self::assertContains('Jira permission CLOSE_ISSUES (given to every role of the project)', $rows['2|3|2|5|1']['notes']);
        self::assertContains('ABC: issue type 99999 has no Redmine tracker; workflow "Other" not proposed', $result['problems']);
        self::assertStringContainsString('tracker #2: 2 Jira workflows', implode("\n", $result['problems']));
    }

    public function testAGroupWithoutMembershipIsReported(): void
    {
        $result = WorkflowProposal::build(
            [['project' => 'ABC', 'workflow' => 'W', 'issue_type' => '1', 'transitions' => [
                ['name' => 'Start', 'from' => ['1'], 'to' => '3', 'groups' => ['Nobody'], 'permissions' => [], 'assignee_only' => false,
                    'not_mapped' => []],
            ]]],
            ['1' => 2],
            ['1' => 1, '3' => 2],
            ['ABC' => [4]],
            []
        );

        self::assertSame([], $result['rows']);
        $problem = 'ABC, workflow "W": group "Nobody" is not a member of the project; its transitions go to no role';
        self::assertSame([$problem], $result['problems']);
    }
}

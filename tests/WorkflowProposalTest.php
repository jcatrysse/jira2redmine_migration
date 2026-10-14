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
        // the resolution clean-up has no Redmine counterpart and nothing to do; the assignment and the screen
        // are applied by the runner (Jan, decision 13): a custom workflow script and field permissions
        self::assertSame([], $transitions[2]['not_mapped']);
        self::assertTrue($transitions[2]['assign_to_current_user']);
        self::assertSame(['id' => '3', 'name' => 'Resolve screen'], $transitions[2]['screen']);
        self::assertFalse($transitions[1]['assign_to_current_user']);
        self::assertNull($transitions[1]['screen']);
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
        // each project keeps its own workflow too (redmine_project_workflows, Jan 2026-10-14): XYZ has no creation
        self::assertArrayHasKey('ABC|2|3|0|1|0', $result['project_rows']);
        self::assertArrayHasKey('XYZ|2|4|1|2|0', $result['project_rows']);
        self::assertArrayNotHasKey('XYZ|2|4|0|1|0', $result['project_rows']);
        self::assertSame(['XYZ workflow'], $result['project_rows']['XYZ|2|4|1|2|0']['workflows']);
        self::assertStringContainsString('tracker #2: 2 Jira workflows', implode("\n", $result['problems']));
    }

    public function testAGroupWithoutMembershipIsAReviewRow(): void
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

        // Jan, decision 13: a review row with the group, he decides per case (role 0 is never applied)
        self::assertSame(['2|0|1|2|0'], array_keys($result['rows']));
        self::assertSame(WorkflowProposal::NO_ROLE, $result['rows']['2|0|1|2|0']['role']);
        self::assertSame(
            ['Jira limits it to group "Nobody", which is not a member of ABC: set review_role_id and PROPOSED, or IGNORED'],
            $result['rows']['2|0|1|2|0']['notes']
        );
        $problem = 'ABC, workflow "W": group "Nobody" is not a member of the project; review row with role 0';
        self::assertSame([$problem], $result['problems']);
    }

    /**
     * @param list<string> $groups
     * @return list<array{project: string, workflow: string, issue_type: string, transitions: list<array<string, mixed>>}>
     */
    private static function limitedTo(array $groups): array
    {
        return [['project' => 'ABC', 'workflow' => 'W', 'issue_type' => '1', 'transitions' => [
            ['name' => 'Start', 'from' => ['1'], 'to' => '3', 'groups' => $groups, 'permissions' => [], 'assignee_only' => false,
                'not_mapped' => []],
        ]]];
    }

    public function testJansGroupSettingsResolveTheReviewRows(): void
    {
        // Jan, decision 14: R gives it to the roles the group's users hold, ignore makes the review row IGNORED
        $context = [
            'group_modes' => ['ABC' => ['everyone' => 'R', 'apps' => 'ignore']],
            'group_users_roles' => ['ABC' => ['everyone' => [3, 4]]],
        ];

        $r = WorkflowProposal::build(self::limitedTo(['Everyone']), ['1' => 2], ['1' => 1, '3' => 2], ['ABC' => [3, 4, 5]], [], $context);
        self::assertSame(['2|3|1|2|0', '2|4|1|2|0'], array_keys($r['rows']));
        self::assertSame([], $r['rows']['2|3|1|2|0']['notes']);
        self::assertFalse($r['rows']['2|3|1|2|0']['ignored']);
        self::assertSame([], $r['problems']);

        $ignored = WorkflowProposal::build(self::limitedTo(['Apps']), ['1' => 2], ['1' => 1, '3' => 2], ['ABC' => [4]], [], $context);
        self::assertSame(['2|0|1|2|0'], array_keys($ignored['rows']));
        self::assertTrue($ignored['rows']['2|0|1|2|0']['ignored']);
        self::assertTrue($ignored['project_rows']['ABC|2|0|1|2|0']['ignored']);
        self::assertSame(
            ['Jira limits it to group "Apps", which is not a member of ABC: ignored (Jan, decision 14)'],
            $ignored['rows']['2|0|1|2|0']['notes']
        );
    }

    public function testAGroupStillToReviewKeepsTheRowInReview(): void
    {
        // one group ignored, another not listed: the row of role 0 stays in review with both notes
        $context = ['group_modes' => ['ABC' => ['apps' => 'ignore']]];

        $sources = self::limitedTo(['Apps', 'Nobody']);
        $result = WorkflowProposal::build($sources, ['1' => 2], ['1' => 1, '3' => 2], ['ABC' => [4]], [], $context);

        self::assertFalse($result['rows']['2|0|1|2|0']['ignored']);
        self::assertCount(2, $result['rows']['2|0|1|2|0']['notes']);
    }

    public function testInTheUnionARowIsIgnoredOnlyWhenEveryProjectIgnoresIt(): void
    {
        $sources = array_merge(self::limitedTo(['Apps']), [['project' => 'XYZ', 'workflow' => 'W', 'issue_type' => '1', 'transitions' => [
            ['name' => 'Start', 'from' => ['1'], 'to' => '3', 'groups' => ['Apps'], 'permissions' => [], 'assignee_only' => false,
                'not_mapped' => []],
        ]]]);
        $context = ['group_modes' => ['ABC' => ['apps' => 'ignore']]];

        $result = WorkflowProposal::build($sources, ['1' => 2], ['1' => 1, '3' => 2], ['ABC' => [4], 'XYZ' => [4]], [], $context);

        self::assertFalse($result['rows']['2|0|1|2|0']['ignored'], 'XYZ has no setting: the union row stays in review');
        self::assertTrue($result['project_rows']['ABC|2|0|1|2|0']['ignored']);
        self::assertFalse($result['project_rows']['XYZ|2|0|1|2|0']['ignored']);
    }

    public function testAPermissionConditionGoesToTheRolesThatHoldIt(): void
    {
        $transitions = [
            ['name' => 'Create', 'from' => ['0'], 'to' => '1', 'groups' => [], 'permissions' => ['CREATE_ISSUES'],
                'assignee_only' => false, 'not_mapped' => []],
            ['name' => 'Resolve', 'from' => ['1'], 'to' => '3', 'groups' => [], 'permissions' => ['RESOLVE_ISSUES'],
                'assignee_only' => false, 'not_mapped' => []],
        ];
        $result = WorkflowProposal::build(
            [['project' => 'ABC', 'workflow' => 'W', 'issue_type' => '1', 'transitions' => $transitions]],
            ['1' => 2],
            ['1' => 1, '3' => 2],
            ['ABC' => [3, 4]],
            [],
            ['permission_roles' => ['ABC' => ['CREATE_ISSUES' => [4], 'RESOLVE_ISSUES' => []]]]
        );

        // only Developer (4) holds CREATE_ISSUES: Manager (3) gets no creation; nobody holds RESOLVE_ISSUES
        self::assertSame(['2|4|0|1|0'], array_keys($result['rows']));
        self::assertSame([], $result['rows']['2|4|0|1|0']['notes'], 'an applied rule needs no review');
        self::assertContains(
            'ABC, workflow "W": no role of the project holds RESOLVE_ISSUES; transition "Resolve" goes to no role',
            $result['problems']
        );
    }

    public function testRequiredScreenFieldsAndTheAssignmentArePlanned(): void
    {
        $sources = [['project' => 'ABC', 'workflow' => 'ABC workflow', 'issue_type' => '10001',
            'transitions' => JiraTransitions::fromWorkflow(self::WORKFLOW)]];
        $context = [
            'permission_roles' => ['ABC' => ['CLOSE_ISSUES' => [3, 4]]],
            'screen_fields' => ['3' => ['customfield_1', 'resolution', 'assignee', 'customfield_2']],
            'field_rules' => [
                'customfield_1' => ['required_in' => ['Default', 'ABC'], 'configurations' => 2],
                'resolution' => ['required_in' => [], 'configurations' => 2],
                'assignee' => ['required_in' => ['ABC'], 'configurations' => 2],
                'customfield_2' => ['required_in' => ['Default', 'ABC'], 'configurations' => 2],
            ],
            'field_targets' => ['customfield_1' => '23', 'resolution' => null, 'assignee' => 'assigned_to_id', 'customfield_2' => null],
        ];
        $result = WorkflowProposal::build($sources, ['10001' => 2], ['1' => 1, '3' => 2, '6' => 5], ['ABC' => [3, 4]], [], $context);

        // the screen of "Close" (to status 5): a field required everywhere, one required in one configuration only
        self::assertSame(
            ['2|3|5|23', '2|3|5|assigned_to_id', '2|4|5|23', '2|4|5|assigned_to_id'],
            array_keys($result['field_permissions'])
        );
        self::assertSame('required', $result['field_permissions']['2|3|5|23']['rule']);
        self::assertSame([], $result['field_permissions']['2|3|5|23']['notes']);
        self::assertSame(['required in Jira field configurations ABC only'], $result['field_permissions']['2|4|5|assigned_to_id']['notes']);
        self::assertContains(
            'screen "Resolve screen": field customfield_2 is required in Jira and has no Redmine field',
            $result['problems']
        );
        // "assign to the current user" on the global Close: one script for tracker 2, status 5, from every other status
        self::assertSame(['2|5'], array_keys($result['scripts']));
        self::assertSame([1, 2], $result['scripts']['2|5']['old']);
        self::assertSame(['Close'], $result['scripts']['2|5']['transitions']);
        // and the transition itself needs no review any more
        self::assertSame([], $result['rows']['2|3|2|5|1']['notes']);

        // without the field configurations the screen's required fields are unknown: the transition is reviewed
        unset($context['field_rules']);
        $unknown = WorkflowProposal::build($sources, ['10001' => 2], ['1' => 1, '3' => 2, '6' => 5], ['ABC' => [3, 4]], [], $context);
        self::assertSame(
            ['transition screen "Resolve screen": its required fields are unknown (screens or field configurations not exported by 16)'],
            $unknown['rows']['2|3|2|5|1']['notes']
        );
        self::assertSame([], $unknown['field_permissions']);
    }
}

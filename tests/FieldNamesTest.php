<?php

declare(strict_types=1);

use Jira2Redmine\CustomFields\FieldNames;
use Jira2Redmine\Redmine\Names;
use PHPUnit\Framework\TestCase;

/**
 * Decision 44 (B88): Redmine refused "Remotely Operated Towed Vehicle" (31 characters, Redmine allows 30) in every
 * run. 08 now shortens any proposed name to 30 with a note, and Jan named this field "ROTV" in the configuration.
 */
final class FieldNamesTest extends TestCase
{
    private const LONG = 'Remotely Operated Towed Vehicle';

    public function testANameWithinThirtyCharactersStaysAsItIs(): void
    {
        self::assertSame(['name' => 'Department', 'note' => null], FieldNames::propose([], 'customfield_10058', null, 'Department'));
        self::assertSame(['name' => 'Dept', 'note' => null], FieldNames::propose([], 'customfield_10058', 'Dept', 'Department'));
        self::assertSame(['name' => 'customfield_1', 'note' => null], FieldNames::propose([], 'customfield_1', null, null));
        self::assertSame(['name' => 'Department', 'note' => null], FieldNames::propose([], 'customfield_10058', ' ', 'Department'));
    }

    public function testALongerNameIsShortenedWithANoteAlsoOnARerun(): void
    {
        $note = 'Name shortened to 30 characters for Redmine (was: "Remotely Operated Towed Vehicle").';
        $first = FieldNames::propose([], 'customfield_10067', null, self::LONG);
        self::assertSame(['name' => 'Remotely Operated Towed Vehicl', 'note' => $note], $first);
        // the name an older run stored as it was, and the shortened one this rule stored
        self::assertSame($first, FieldNames::propose([], 'customfield_10067', self::LONG, self::LONG));
        self::assertSame($first, FieldNames::propose([], 'customfield_10067', $first['name'], self::LONG));
    }

    public function testAConfiguredNameWinsAlsoOverAStoredOne(): void
    {
        $rotv = [
            'name' => 'ROTV',
            'note' => 'Name from migration.custom_fields.names (Jan, decision 44; Jira: "Remotely Operated Towed Vehicle").',
        ];
        $names = ['customfield_10067' => 'ROTV'];
        self::assertSame($rotv, FieldNames::propose($names, 'customfield_10067', null, self::LONG));
        self::assertSame($rotv, FieldNames::propose($names, 'customfield_10067', self::LONG, self::LONG));
        self::assertSame($rotv, FieldNames::propose($names, 'customfield_10067', 'ROTV', self::LONG));
        // another field, an empty or a non-text entry: the rule without configuration
        self::assertSame('Department', FieldNames::propose($names, 'customfield_10058', null, 'Department')['name']);
        foreach ([' ', ['x']] as $notAName) {
            $proposal = FieldNames::propose(['customfield_10067' => $notAName], 'customfield_10067', null, self::LONG);
            self::assertSame('Remotely Operated Towed Vehicl', $proposal['name']);
        }
        // a configured name that is too long is shortened too
        self::assertSame(
            'Name from migration.custom_fields.names (Jan, decision 44; Jira: "x"). Name shortened to 30 characters for'
                . ' Redmine (was: "A configured name that is far too long").',
            FieldNames::propose(['f' => 'A configured name that is far too long'], 'f', null, 'x')['note']
        );
    }

    public function testACascadingParentKeepsItsSuffix(): void
    {
        self::assertSame('ICT Hardware (Parent)', Names::customField('ICT Hardware', ' (Parent)'));
        self::assertSame('Remotely Operated Tow (Parent)', Names::customField(self::LONG, ' (Parent)'));
        self::assertSame(30, mb_strlen(Names::customField(self::LONG, ' (Parent)')));
    }

    public function testJanNamedTheFieldAndEightAppliesTheRule(): void
    {
        $config = require __DIR__ . '/../config/config.default.php';
        self::assertSame(['customfield_10067' => 'ROTV'], $config['migration']['custom_fields']['names']);

        $eight = sourceWithoutLayout((string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php'));
        $needles = [
            "runCustomFieldTransformationPhase(\$pdo, \$config['migration']['custom_fields']['not_migrated'] ?? [],"
                . " \$config['migration']['custom_fields']['names'] ?? [])",
            '$proposal = FieldNames::propose($fieldNames, $jiraFieldId, $currentProposedName, $defaultName);',
            "\$parentName = Names::customField(\$proposedName, ' (Parent)');",
        ];
        foreach ($needles as $needle) {
            self::assertStringContainsString(sourceWithoutLayout($needle), $eight);
        }
    }
}

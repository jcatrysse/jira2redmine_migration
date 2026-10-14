<?php

declare(strict_types=1);

use Jira2Redmine\Workflows\DroppedConditions;
use PHPUnit\Framework\TestCase;

final class DroppedConditionsTest extends TestCase
{
    public function testOnlyTheDroppedConditionOfThatProjectGoes(): void
    {
        $dropped = DroppedConditions::fromConfig(['FPS' => ['ValueFieldCondition'], 'ABC' => 'OtherCondition', 'XYZ' => ['', ' ']]);
        $notes = ['condition ValueFieldCondition', 'validator FieldRequiredValidator', 'condition OtherCondition'];

        self::assertSame(['FPS' => ['ValueFieldCondition'], 'ABC' => ['OtherCondition']], $dropped);
        $left = DroppedConditions::filter($notes, 'FPS', $dropped);
        self::assertSame(['validator FieldRequiredValidator', 'condition OtherCondition'], $left);
        self::assertSame($notes, DroppedConditions::filter($notes, 'GEOS', $dropped));
        self::assertSame([], DroppedConditions::fromConfig(null));
    }

    public function testJansDecisionIsTheConfiguredOutcome(): void
    {
        // decision 19: FPS's "Screen" condition (one option of a select field) is left out, the transition proposed
        $config = require __DIR__ . '/../config/config.default.php';

        self::assertSame(['FPS' => ['ValueFieldCondition']], $config['migration']['workflows']['dropped_conditions']);
    }
}

<?php

declare(strict_types=1);

use Jira2Redmine\Jira\ProjectSelection;
use PHPUnit\Framework\TestCase;

final class ProjectSelectionTest extends TestCase
{
    public function testEmptySelectionIncludesEverything(): void
    {
        self::assertTrue((new ProjectSelection(null))->includes('SURV'));
        self::assertTrue((new ProjectSelection([]))->includes('SURV'));
    }

    public function testOnlyTheListedKeys(): void
    {
        $selection = new ProjectSelection(['geos', ' SIS ']);
        self::assertTrue($selection->includes('GEOS'));
        self::assertTrue($selection->includes('sis'));
        self::assertFalse($selection->includes('SURV'));
        self::assertTrue((new ProjectSelection('GEOS, OAC'))->includes('OAC'));
    }
}

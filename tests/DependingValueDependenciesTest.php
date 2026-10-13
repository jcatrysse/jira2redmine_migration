<?php

declare(strict_types=1);

use Jira2Redmine\CustomFields\DependingValueDependencies;
use PHPUnit\Framework\TestCase;

final class DependingValueDependenciesTest extends TestCase
{
    private const PARENTS = [['id' => 46, 'name' => 'Processing'], ['id' => 47, 'name' => 'Acquisition']];
    private const CHILDREN = [['id' => 657, 'name' => 'Tool 2.9.1'], ['id' => 658, 'name' => '2023'], ['id' => 659, 'name' => 'Other']];

    public function testParentAndChildLabelsBecomeEnumerationIds(): void
    {
        // As 08 sent it at creation (anonymised): parent id key, child labels.
        $result = DependingValueDependencies::labelsToIds(
            ['46' => ['Tool 2.9.1', 'Other'], 'Acquisition' => ['2023']],
            self::PARENTS,
            self::CHILDREN
        );
        self::assertSame(['46' => ['657', '659'], '47' => ['658']], $result['dependencies']);
        self::assertSame([], $result['unresolved']);
    }

    public function testANumericLabelIsALabelNotAnId(): void
    {
        $result = DependingValueDependencies::labelsToIds(['46' => ['2023']], self::PARENTS, self::CHILDREN);
        self::assertSame(['46' => ['658']], $result['dependencies']);
    }

    public function testUnknownLabelsAreReported(): void
    {
        $result = DependingValueDependencies::labelsToIds(['Missing' => ['x'], '46' => ['Nope']], self::PARENTS, self::CHILDREN);
        self::assertSame(['46' => []], $result['dependencies']);
        self::assertSame(['parent Missing', 'child Nope'], $result['unresolved']);
    }
}

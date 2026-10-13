<?php

declare(strict_types=1);

use Jira2Redmine\Jira\ExtractionGuard;
use PHPUnit\Framework\TestCase;

final class ExtractionGuardTest extends TestCase
{
    public function testNoProjectsStopsTheRun(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_id');
        ExtractionGuard::requireProjects(0);
    }

    public function testProjectsPass(): void
    {
        ExtractionGuard::requireProjects(15);
        $this->addToAssertionCount(1);
    }
}

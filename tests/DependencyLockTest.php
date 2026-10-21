<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B93: composer.lock was ignored, so every `composer install` resolved composer.json's ranges anew. league/html-to-markdown
 * 5.2.1 (2026-10-18) writes a link whose text is its address as `<https://...>`, and a fresh install on 2026-10-21 got
 * 5.2.3: two ConversionLossesTest cases failed and production would have converted every link of Jira's text untested.
 * The lock now holds the set the proofs and the one-run used (installed 2026-10-13).
 */
final class DependencyLockTest extends TestCase
{
    public function testTheLockFileBelongsToComposerJson(): void
    {
        // Composer's Locker::getContentHash; a different hash is "The lock file is not up to date" at install
        $composer = self::json('composer.json');
        $keys = ['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability',
            'prefer-stable', 'repositories', 'extra'];
        $relevant = array_intersect_key($composer, array_flip($keys));
        if (isset($composer['config']['platform'])) {
            $relevant['config']['platform'] = $composer['config']['platform'];
        }
        ksort($relevant);

        self::assertSame(md5((string)json_encode($relevant)), self::json('composer.lock')['content-hash']);
    }

    public function testTheLockHoldsTheTestedRuntimeSet(): void
    {
        $versions = [];
        foreach (self::json('composer.lock')['packages'] as $package) {
            $versions[$package['name']] = $package['version'];
        }

        self::assertSame('5.1.4', $versions['league/html-to-markdown'] ?? null);
        self::assertSame('7.15.5', $versions['guzzlehttp/guzzle'] ?? null);
        self::assertSame('2.5.3', $versions['guzzlehttp/promises'] ?? null);
        self::assertSame('2.13.1', $versions['guzzlehttp/psr7'] ?? null);
    }

    public function testGitKeepsTheLockFile(): void
    {
        $ignored = array_map('trim', explode("\n", (string)file_get_contents(__DIR__ . '/../.gitignore')));

        self::assertNotContains('/composer.lock', $ignored);
        self::assertNotContains('composer.lock', $ignored);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $file): array
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../' . $file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}

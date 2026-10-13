<?php

declare(strict_types=1);

use Jira2Redmine\Attachments\AttachmentSample;
use PHPUnit\Framework\TestCase;

final class AttachmentSampleTest extends TestCase
{
    private const MB = 1024 * 1024;

    public function testSmallFilesAndTheLargestPerExtensionUpTo20Mb(): void
    {
        $selected = AttachmentSample::select([
            ['id' => '1', 'filename' => 'small.png', 'size' => 100 * 1024],
            ['id' => '2', 'filename' => 'limit.pdf', 'size' => 512 * 1024],
            ['id' => '3', 'filename' => 'medium.pdf', 'size' => 3 * self::MB],
            ['id' => '4', 'filename' => 'bigger.PDF', 'size' => 12 * self::MB],
            ['id' => '5', 'filename' => 'video.mp4', 'size' => 431 * self::MB],
            ['id' => '6', 'filename' => 'clip.mp4', 'size' => 19 * self::MB],
            ['id' => '7', 'filename' => 'scan.tif', 'size' => 1811 * self::MB],
        ]);

        self::assertSame(['1', '2', '4', '6'], self::ids($selected));
    }

    public function testAnExtensionWithOnlyFilesAbove20MbGetsNone(): void
    {
        $selected = AttachmentSample::select([
            ['id' => '7', 'filename' => 'scan.tif', 'size' => 1811 * self::MB],
            ['id' => '8', 'filename' => 'scan2.tif', 'size' => 21 * self::MB],
        ]);

        self::assertSame([], $selected);
    }

    public function testATieKeepsTheLowestIdSoARerunSelectsTheSameFile(): void
    {
        $files = [
            ['id' => '20', 'filename' => 'a.zip', 'size' => 5 * self::MB],
            ['id' => '10', 'filename' => 'b.zip', 'size' => 5 * self::MB],
        ];

        self::assertSame(['10'], self::ids(AttachmentSample::select($files)));
        self::assertSame(['10'], self::ids(AttachmentSample::select(array_reverse($files))));
    }

    public function testExtension(): void
    {
        self::assertSame('pdf', AttachmentSample::extension('Report.PDF'));
        self::assertSame('gz', AttachmentSample::extension('dump.tar.gz'));
        self::assertSame('', AttachmentSample::extension('README'));
        self::assertSame('', AttachmentSample::extension('.htaccess'));
        self::assertSame('', AttachmentSample::extension('trailing.'));
    }

    /**
     * @param array<string, true> $selected
     * @return list<string>
     */
    private static function ids(array $selected): array
    {
        $ids = array_map('strval', array_keys($selected));
        sort($ids);

        return $ids;
    }
}

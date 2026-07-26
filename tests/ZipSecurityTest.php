<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/index.php';

final class ZipSecurityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/gitinstall_zipsectest_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->tempDir);
        $this->cleanupEscapedFiles();
    }

    private function removeRecursively(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path)) {
            unlink($path);

            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    private function cleanupEscapedFiles(): void
    {
        foreach (['escaped.txt', 'escape_target_xyz'] as $candidate) {
            $path = sys_get_temp_dir() . '/' . $candidate;
            if (is_file($path)) {
                @unlink($path);
            }
            if (is_dir($path)) {
                $this->removeRecursively($path);
            }
        }
    }

    private function createZip(array $entries): string
    {
        $zipFile = $this->tempDir . '/test.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $zipFile;
    }

    public function testIsZipEntryPathSafeValidPaths(): void
    {
        $this->assertTrue(isZipEntryPathSafe('file.txt'));
        $this->assertTrue(isZipEntryPathSafe('dir/file.txt'));
        $this->assertTrue(isZipEntryPathSafe('deep/nested/path/file.txt'));
        $this->assertTrue(isZipEntryPathSafe('./file.txt'));
        $this->assertTrue(isZipEntryPathSafe('dir/./file.txt'));
        $this->assertTrue(isZipEntryPathSafe('dir/sub/'));
    }

    public function testIsZipEntryPathRejectsPathTraversal(): void
    {
        $this->assertFalse(isZipEntryPathSafe('../file.txt'));
        $this->assertFalse(isZipEntryPathSafe('../../etc/passwd'));
        $this->assertFalse(isZipEntryPathSafe('dir/../file.txt'));
        $this->assertFalse(isZipEntryPathSafe('dir/../../escape.txt'));
    }

    public function testIsZipEntryPathRejectsAbsolutePaths(): void
    {
        $this->assertFalse(isZipEntryPathSafe('/etc/passwd'));
        $this->assertFalse(isZipEntryPathSafe('/file.txt'));
        $this->assertFalse(isZipEntryPathSafe('C:\\Windows\\file.txt'));
        $this->assertFalse(isZipEntryPathSafe('C:/Windows/file.txt'));
        $this->assertFalse(isZipEntryPathSafe('D:\\file.txt'));
    }

    public function testIsZipEntryPathRejectsEmptyAndNul(): void
    {
        $this->assertFalse(isZipEntryPathSafe(''));
        $this->assertFalse(isZipEntryPathSafe("file\0.txt"));
        $this->assertFalse(isZipEntryPathSafe("\0"));
    }

    public function testOpenZipForSafeExtractionAcceptsValidArchive(): void
    {
        $zipFile = $this->createZip([
            'repo/file.txt' => 'safe content',
            'repo/dir/other.txt' => 'more content',
        ]);

        $opened = openZipForSafeExtraction($zipFile);
        $this->assertSame(2, $opened['entry_count']);
        $opened['zip']->close();
    }

    public function testOpenZipForSafeExtractionRejectsTraversalArchive(): void
    {
        $zipFile = $this->createZip([
            'repo/legit.txt' => 'fine',
            '../../escaped.txt' => 'malicious content',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unsafe path|Zip Slip/i');
        try {
            openZipForSafeExtraction($zipFile);
        } finally {
            $this->assertFileDoesNotExist(sys_get_temp_dir() . '/escaped.txt');
        }
    }

    public function testOpenZipForSafeExtractionRejectsAbsolutePathArchive(): void
    {
        $zipFile = $this->createZip([
            '/tmp/escaped.txt' => 'malicious',
        ]);

        $this->expectException(\RuntimeException::class);
        try {
            openZipForSafeExtraction($zipFile);
        } finally {
            $this->assertFileDoesNotExist(sys_get_temp_dir() . '/escaped.txt');
        }
    }

    public function testIsZipEntryPathRejectsNulByteDirectly(): void
    {
        $this->assertFalse(isZipEntryPathSafe("safe\0.txt"));
    }

    public function testExtractZipWithMaliciousArchiveDoesNotEscapeTarget(): void
    {
        $zipFile = $this->createZip([
            'repo/' => '',
            'repo/legit.txt' => 'legit content',
            'repo/../escape_target_xyz/file.txt' => 'malicious',
        ]);

        $zipContent = file_get_contents($zipFile);
        $targetDir = $this->tempDir . '/target';

        $threw = false;
        try {
            extractZip($zipContent, $targetDir, [], [], [], []);
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Malicious archive should have been rejected');
        $this->assertDirectoryDoesNotExist(sys_get_temp_dir() . '/escape_target_xyz');
    }

    public function testExtractZipWithSafeArchiveExtractsCorrectly(): void
    {
        $zipFile = $this->createZip([
            'repo/file1.txt' => 'content 1',
            'repo/dir/file2.txt' => 'content 2',
        ]);

        $zipContent = file_get_contents($zipFile);
        $targetDir = $this->tempDir . '/target';

        $result = extractZip($zipContent, $targetDir, [], [], [], []);

        $this->assertFileExists($targetDir . '/file1.txt');
        $this->assertFileExists($targetDir . '/dir/file2.txt');
        $this->assertSame('content 1', file_get_contents($targetDir . '/file1.txt'));
        $this->assertSame('content 2', file_get_contents($targetDir . '/dir/file2.txt'));
        $this->assertCount(2, $result['extracted']);
    }

    public function testExtractZipRejectsMultipleRootEntries(): void
    {
        $zipFile = $this->createZip([
            'repo/file.txt' => 'x',
            'other/file.txt' => 'y',
        ]);

        $zipContent = file_get_contents($zipFile);
        $targetDir = $this->tempDir . '/target';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exactly one top-level directory/i');
        extractZip($zipContent, $targetDir, [], [], [], []);
    }

    public function testExtractZipExtractsOnlyAllowedFiles(): void
    {
        $zipFile = $this->createZip([
            'repo/keep.txt' => 'keep',
            'repo/skip.txt' => 'skip',
        ]);

        $zipContent = file_get_contents($zipFile);
        $targetDir = $this->tempDir . '/target';

        $result = extractZip($zipContent, $targetDir, [], ['skip.txt'], [], []);

        $this->assertFileExists($targetDir . '/keep.txt');
        $this->assertFileDoesNotExist($targetDir . '/skip.txt');
        $this->assertContains('keep.txt', $result['extracted']);
        $this->assertContains('skip.txt', $result['skipped_files']);
    }

    public function testExtractZipTracksWhitelistFoldersAsSkipped(): void
    {
        $zipFile = $this->createZip([
            'repo/uploads/file.txt' => 'uploads content',
            'repo/other.txt' => 'other content',
        ]);

        $zipContent = file_get_contents($zipFile);
        $targetDir = $this->tempDir . '/target';

        $result = extractZip($zipContent, $targetDir, [], [], ['uploads'], []);

        $this->assertFileExists($targetDir . '/other.txt');
        $this->assertContains('uploads/file.txt', $result['skipped_files']);
        $this->assertNotContains('uploads/file.txt', $result['extracted']);
    }

    public function testExtractZipPreservesExistingWhitelistedFiles(): void
    {
        $zipFile = $this->createZip([
            'repo/uploads/file.txt' => 'archive content',
        ]);

        $targetDir = $this->tempDir . '/target';
        mkdir($targetDir . '/uploads', 0755, true);
        file_put_contents($targetDir . '/uploads/file.txt', 'existing user content');

        $zipContent = file_get_contents($zipFile);
        extractZip($zipContent, $targetDir, [], [], ['uploads'], []);

        $this->assertSame('existing user content', file_get_contents($targetDir . '/uploads/file.txt'));
    }
}
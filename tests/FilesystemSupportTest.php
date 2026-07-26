<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/index.php';

final class FilesystemSupportTest extends TestCase
{
    public function testCreateDirectoryTree(): void
    {
        $base = sys_get_temp_dir() . '/gitinstall_fs_test_' . uniqid();
        $nested = $base . '/a/b/c';

        $this->assertTrue(createDirectoryTree($nested));
        $this->assertDirectoryExists($nested);

        if (is_dir($base)) {
            rmdir($nested);
            rmdir(dirname($nested));
            rmdir(dirname(dirname($nested)));
            rmdir($base);
        }
    }

    public function testCreateDirectoryTreeIsIdempotent(): void
    {
        $path = sys_get_temp_dir() . '/gitinstall_fs_test_' . uniqid();
        $this->assertTrue(createDirectoryTree($path));
        $this->assertTrue(createDirectoryTree($path));
        if (is_dir($path)) {
            rmdir($path);
        }
    }

    public function testFormatFileSize(): void
    {
        $this->assertSame('0 B', formatFileSize(0));
        $this->assertSame('512B', formatFileSize(512));
        $this->assertSame('1KB', formatFileSize(1024));
        $this->assertSame('1.5KB', formatFileSize(1536));
        $this->assertSame('1MB', formatFileSize(1048576));
        $this->assertSame('1GB', formatFileSize(1073741824));
    }

    public function testGetDirectorySize(): void
    {
        $dir = sys_get_temp_dir() . '/gitinstall_size_test_' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/a.txt', 'hello');
        file_put_contents($dir . '/b.txt', 'world!');

        $size = getDirectorySize($dir);

        $this->assertSame(11, $size);

        unlink($dir . '/a.txt');
        unlink($dir . '/b.txt');
        rmdir($dir);
    }

    public function testGetDirectorySizeForNonExistentDir(): void
    {
        $this->assertSame(0, getDirectorySize(sys_get_temp_dir() . '/definitely_not_existing_' . uniqid()));
    }

    public function testInstallerLogPath(): void
    {
        $this->assertStringEndsWith('/git-installer.log', installerLogPath('/tmp'));
        $this->assertStringEndsWith('/git-installer.log', installerLogPath('/tmp/'));
    }

    public function testInstallerLogBaseDirectoryDefaultsToLogs(): void
    {
        $dir = installerLogBaseDirectory('/some/installer/root');
        $this->assertStringEndsWith('/logs', $dir);
    }

    public function testInstallerLogBaseDirectoryAbsolutePath(): void
    {
        $dir = installerLogBaseDirectory('/some/root', '/var/log/myapp');
        $this->assertSame('/var/log/myapp', $dir);
    }

    public function testLogInstallerEvent(): void
    {
        $logDir = sys_get_temp_dir() . '/gitinstall_log_test_' . uniqid();
        mkdir($logDir, 0755, true);

        logInstallerEvent($logDir, 'info', 'Test message', ['key' => 'value']);
        logInstallerEvent($logDir, 'warning', 'Another message');

        $lines = readInstallerLog($logDir);

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('INFO', $lines[0]);
        $this->assertStringContainsString('Test message', $lines[0]);
        $this->assertStringContainsString('WARNING', $lines[1]);

        if (is_file($logDir . '/git-installer.log')) {
            unlink($logDir . '/git-installer.log');
        }
        rmdir($logDir);
    }
}
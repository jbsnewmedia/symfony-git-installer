<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/index.php';

final class InstallUuidManagerTest extends TestCase
{
    private string $tempDir;
    private \InstallUuidManager $manager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/gitinstall_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->manager = new \InstallUuidManager();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    public function testGenerateAndExtractUuidV7Like(): void
    {
        $envPath = $this->tempDir . '/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $uuid = $this->manager->ensureEnvLocalInstallUuid($envPath);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testEnsureUuidIsIdempotent(): void
    {
        $envPath = $this->tempDir . '/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $first = $this->manager->ensureEnvLocalInstallUuid($envPath);
        $second = $this->manager->ensureEnvLocalInstallUuid($envPath);

        $this->assertSame($first, $second);
    }

    public function testReplaceUuidGeneratesNew(): void
    {
        $envPath = $this->tempDir . '/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $first = $this->manager->ensureEnvLocalInstallUuid($envPath);
        $second = $this->manager->ensureEnvLocalInstallUuid($envPath, true);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first,
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $second,
        );
        $this->assertNotSame($first, $second);
    }

    public function testExtractExistingUuid(): void
    {
        $envPath = $this->tempDir . '/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\nINSTALL_UUID=01234567-89ab-7cde-8f01-234567890abc\n");

        $uuid = $this->manager->ensureEnvLocalInstallUuid($envPath);

        $this->assertSame('01234567-89ab-7cde-8f01-234567890abc', $uuid);
    }

    public function testExtractIgnoresInvalidUuid(): void
    {
        $envPath = $this->tempDir . '/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\nINSTALL_UUID=invalid-uuid\n");

        $uuid = $this->manager->ensureEnvLocalInstallUuid($envPath);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }
}
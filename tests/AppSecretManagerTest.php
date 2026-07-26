<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/index.php';

final class AppSecretManagerTest extends TestCase
{
    private string $tempDir;
    private \AppSecretManager $manager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/gitinstall_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->manager = new \AppSecretManager();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
            @rmdir($this->tempDir);
        }
    }

    public function testGenerateNewSecret(): void
    {
        $envPath = $this->tempDir . '/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $secret = $this->manager->ensureEnvLocalAppSecret($envPath);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{16,128}$/', $secret);
    }

    public function testEnsureSecretIsIdempotent(): void
    {
        $envPath = $this->tempDir . '/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $first = $this->manager->ensureEnvLocalAppSecret($envPath);
        $second = $this->manager->ensureEnvLocalAppSecret($envPath);

        $this->assertSame($first, $second);
    }

    public function testReplaceSecretGeneratesNew(): void
    {
        $envPath = $this->tempDir . '/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $first = $this->manager->ensureEnvLocalAppSecret($envPath);
        $second = $this->manager->ensureEnvLocalAppSecret($envPath, true);

        $this->assertNotSame($first, $second);
    }

    public function testExtractExistingSecret(): void
    {
        $envPath = $this->tempDir . '/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\nAPP_SECRET=existingsecret1234567890\n");

        $secret = $this->manager->ensureEnvLocalAppSecret($envPath);

        $this->assertSame('existingsecret1234567890', $secret);
    }

    public function testExtractIgnoresInvalidSecret(): void
    {
        $envPath = $this->tempDir . '/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\nAPP_SECRET=short\n");

        $secret = $this->manager->ensureEnvLocalAppSecret($envPath);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{16,128}$/', $secret);
        $this->assertNotSame('short', $secret);
    }
}
<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/index.php';

final class IndexFunctionsTest extends TestCase
{
    public function testExtractSemverFromTag(): void
    {
        $this->assertSame('1.0.0', extractSemverFromTag('1.0.0'));
        $this->assertSame('1.2.3', extractSemverFromTag('v1.2.3'));
        $this->assertSame('1.2.3', extractSemverFromTag('V1.2.3'));
        $this->assertNull(extractSemverFromTag('latest'));
        $this->assertNull(extractSemverFromTag('1.0'));
        $this->assertNull(extractSemverFromTag('1.0.0-alpha'));
    }

    public function testNormalizeRelativePath(): void
    {
        $this->assertSame('index.php', normalizeRelativePath('index.php'));
        $this->assertSame('index.php', normalizeRelativePath('/index.php'));
        $this->assertSame('index.php', normalizeRelativePath('\\index.php'));
        $this->assertSame('lang/de.php', normalizeRelativePath('lang/de.php'));
        $this->assertSame('lang/de.php', normalizeRelativePath('lang\\de.php'));
        $this->assertSame('lang/de.php', normalizeRelativePath('/lang/de.php/'));
    }

    public function testIsAllowedUpdaterFile(): void
    {
        $this->assertTrue(isAllowedUpdaterFile('index.php'));
        $this->assertTrue(isAllowedUpdaterFile('/index.php'));
        $this->assertTrue(isAllowedUpdaterFile('.htaccess'));
        $this->assertTrue(isAllowedUpdaterFile('lang/de.php'));
        $this->assertTrue(isAllowedUpdaterFile('lang/en.php'));
        $this->assertTrue(isAllowedUpdaterFile('app/Filesystem.php'));

        $this->assertFalse(isAllowedUpdaterFile('config.php'));
        $this->assertFalse(isAllowedUpdaterFile('lang/de.txt'));
    }

    public function testCanUpdateInstallerToTag(): void
    {
        $this->assertTrue(canUpdateInstallerToTag('unknown', 'v1.0.0'));
        $this->assertTrue(canUpdateInstallerToTag('v1.0.0', 'latest'));

        $this->assertTrue(canUpdateInstallerToTag('v1.0.0', 'v1.1.0'));
        $this->assertTrue(canUpdateInstallerToTag('v1.1.0', 'v1.1.0'));

        $this->assertFalse(canUpdateInstallerToTag('v1.1.0', 'v1.0.0'));
        $this->assertFalse(canUpdateInstallerToTag('v1.2.0', 'v1.1.0'));
    }

    public function testResolveLangKey(): void
    {
        $lang = ['test' => 'Das ist ein Test', 'greet' => 'Hallo :name'];

        $this->assertSame('Das ist ein Test', resolveLangKey('test', $lang));
        $this->assertSame('Hallo Welt', resolveLangKey('greet', $lang, ['name' => 'Welt']));
        $this->assertSame('unknown', resolveLangKey('unknown', $lang));
    }

    public function testResolveInstallerVersion(): void
    {
        $tags = [
            ['name' => 'v1.0.0', 'commit' => 'sha1'],
            ['name' => 'v1.2.0', 'commit' => 'sha2'],
            ['name' => 'v1.1.0', 'commit' => 'sha3'],
        ];

        $this->assertSame('v1.5.0', resolveInstallerVersion(['installer_version' => 'v1.5.0'], $tags));

        $this->assertSame('unknown', resolveInstallerVersion([], $tags));
        $this->assertSame('unknown', resolveInstallerVersion([], []));
    }

    public function testResolveInstallerVersionWithCommit(): void
    {
        $this->assertSame('mainabcdefg', resolveInstallerVersion([
            'installer_version' => 'main',
            'installer_commit' => 'abcdefg123456',
        ], []));
    }

    public function testClearCacheDirectory(): void
    {
        $cacheDir = __DIR__ . '/test_cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir);
        }
        file_put_contents($cacheDir . '/test.txt', 'test');
        mkdir($cacheDir . '/subdir');
        file_put_contents($cacheDir . '/subdir/test2.txt', 'test2');

        $result = clearCacheDirectory($cacheDir);

        $this->assertSame(2, $result['deleted_count']);
        $this->assertEmpty($result['errors']);
        $this->assertDirectoryDoesNotExist($cacheDir);
    }

    public function testParseEnvLocal(): void
    {
        $envPath = __DIR__ . '/.env.test';
        $content = <<<ENV
APP_ENV=dev
DATABASE_URL="mysql://user:pass@127.0.0.1/db1" # Database 1
# DATABASE_URL="mysql://user:pass@127.0.0.1/db2" # Database 2
ENV;
        file_put_contents($envPath, $content);

        $result = parseEnvLocal($envPath);

        $this->assertSame('dev', $result['app_env']);
        $this->assertSame('Database 1', $result['current_db']);
        $this->assertCount(2, $result['databases']);

        $this->assertSame('Database 1', $result['databases'][0]['id']);
        $this->assertTrue($result['databases'][0]['active']);

        $this->assertSame('Database 2', $result['databases'][1]['id']);
        $this->assertFalse($result['databases'][1]['active']);

        unlink($envPath);
    }

    public function testParseEnvLocalWithAppSecretAndInstallUuid(): void
    {
        $envPath = __DIR__ . '/.env.test';
        $content = <<<ENV
APP_ENV=prod
APP_SECRET=abcdef1234567890abcdef1234567890
INSTALL_UUID=01234567-89ab-7cde-8f01-234567890abc
DATABASE_URL="mysql://user:pass@127.0.0.1/db1" # DB1
ENV;
        file_put_contents($envPath, $content);

        $result = parseEnvLocal($envPath);

        $this->assertSame('prod', $result['app_env']);
        $this->assertSame('abcdef1234567890abcdef1234567890', $result['app_secret']);
        $this->assertSame('01234567-89ab-7cde-8f01-234567890abc', $result['install_uuid']);

        unlink($envPath);
    }
}
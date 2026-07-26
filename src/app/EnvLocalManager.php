<?php

declare(strict_types=1);

/**
 * Helpers for parsing, updating and managing the target project's
 * `.env.local` file. Supports the "DATABASE_URL # DBID" comment
 * syntax used by the installer for multi-database setups.
 */

if (!function_exists('parseEnvLocal')) {
    /**
     * @return array{app_env: string, current_db: ?string, databases: array<int, array{id: string, url: string, active: bool}>, install_uuid: ?string, app_secret: ?string, raw_content: string}
     */
    function parseEnvLocal(string $envPath): array
    {
        $result = [
            'app_env' => 'prod',
            'current_db' => null,
            'databases' => [],
            'install_uuid' => null,
            'app_secret' => null,
            'raw_content' => '',
        ];

        if (!file_exists($envPath)) {
            return $result;
        }

        $content = @file_get_contents($envPath);
        if (false === $content) {
            return $result;
        }
        $result['raw_content'] = $content;
        $lines = explode("\n", $result['raw_content']);

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);

            if (preg_match('/^APP_ENV\s*=\s*(dev|prod)/i', $line, $matches)) {
                $result['app_env'] = strtolower($matches[1]);
            }

            if (preg_match('/^#?\s*DATABASE_URL\s*=\s*"([^"]+)"\s*#\s*(.+)$/i', $line, $matches)) {
                $isActive = !str_starts_with(ltrim($lines[$lineNum]), '#');
                $dbId = trim($matches[2]);
                $result['databases'][] = [
                    'id' => $dbId,
                    'url' => $matches[1],
                    'active' => $isActive,
                ];
                if ($isActive) {
                    $result['current_db'] = $dbId;
                }
            }

            if (preg_match('/^\s*INSTALL_UUID\s*=\s*("?)([0-9a-fA-F-]+)\1\s*$/', $line, $matches)) {
                $result['install_uuid'] = strtolower($matches[2]);
            }

            if (preg_match('/^\s*APP_SECRET\s*=\s*("?)([^"\s]+)\1\s*$/', $line, $matches)) {
                $candidate = trim($matches[2]);
                if (1 === preg_match('/^[A-Za-z0-9._-]{16,128}$/', $candidate)) {
                    $result['app_secret'] = $candidate;
                }
            }
        }

        return $result;
    }
}

if (!function_exists('updateEnvLocal')) {
    function updateEnvLocal(string $envPath, string $appEnv, string $activeDb): bool
    {
        if (!file_exists($envPath)) {
            return false;
        }

        $content = @file_get_contents($envPath);
        if (false === $content) {
            return false;
        }
        $lines = explode("\n", $content);
        $appEnvWritten = false;

        foreach ($lines as $lineNum => &$line) {
            if (preg_match('/^\s*#?\s*APP_ENV\s*=\s*(dev|prod)/i', $line)) {
                $line = 'APP_ENV='.$appEnv;
                $appEnvWritten = true;
                continue;
            }

            if (preg_match('/^(#?)\s*DATABASE_URL\s*=\s*"[^"]+"\s*#\s*(.+)$/i', $line, $matches)) {
                $isCommented = '#' === $matches[1];
                $dbId = trim($matches[2]);

                if ($dbId === $activeDb && $isCommented) {
                    $line = preg_replace('/^#\s*/', '', $line);
                } elseif ($dbId !== $activeDb && !$isCommented) {
                    $line = '#'.ltrim($line);
                }
            }
        }
        unset($line);

        if (!$appEnvWritten) {
            $lines[] = 'APP_ENV='.$appEnv;
        }

        return false !== @file_put_contents($envPath, implode("\n", $lines));
    }
}

if (!function_exists('saveEnvLocalContent')) {
    function saveEnvLocalContent(string $envPath, string $content): bool
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);

        return false !== @file_put_contents($envPath, $normalized);
    }
}

if (!function_exists('setEnvLocalValue')) {
    function setEnvLocalValue(string $content, string $key, string $value): string
    {
        $normalizedContent = str_replace(["\r\n", "\r"], "\n", $content);
        $pattern = '/^\s*#?\s*'.preg_quote($key, '/').'\s*=.*$/m';
        $line = $key.'='.$value;

        if (1 === preg_match($pattern, $normalizedContent)) {
            return (string) preg_replace($pattern, $line, $normalizedContent, 1);
        }

        $trimmedContent = rtrim($normalizedContent, "\n");

        return ('' === $trimmedContent ? '' : $trimmedContent."\n").$line."\n";
    }
}

if (!function_exists('addDatabaseToEnvLocal')) {
    function addDatabaseToEnvLocal(string $envPath, string $dbId, string $dbUrl): bool
    {
        if (!file_exists($envPath)) {
            $defaultContent = "APP_ENV=prod\n";
            $dir = dirname($envPath);
            if (!is_dir($dir)) {
                if (!createDirectoryTree($dir, 0o755)) {
                    return false;
                }
            }
            if (false === file_put_contents($envPath, $defaultContent)) {
                return false;
            }
        }

        $dbId = trim($dbId);
        $dbUrl = trim($dbUrl);

        if ('' === $dbId || '' === $dbUrl) {
            return false;
        }

        if (1 === preg_match('/["\n\r]/', $dbId) || 1 === preg_match('/[\n\r]/', $dbUrl)) {
            return false;
        }

        $envConfig = parseEnvLocal($envPath);
        foreach ($envConfig['databases'] as $database) {
            if (0 === strcasecmp((string) $database['id'], $dbId)) {
                return false;
            }
        }

        $line = '#DATABASE_URL="'.str_replace('"', '\\"', $dbUrl).'" # '.$dbId;
        $content = rtrim((string) @file_get_contents($envPath), "\n")."\n".$line."\n";

        return false !== @file_put_contents($envPath, $content);
    }
}

if (!function_exists('removeDatabaseFromEnvLocal')) {
    function removeDatabaseFromEnvLocal(string $envPath, string $dbId): bool
    {
        if (!file_exists($envPath)) {
            return false;
        }

        $dbId = trim($dbId);
        if ('' === $dbId) {
            return false;
        }

        $rawContent = @file_get_contents($envPath);
        if (false === $rawContent) {
            return false;
        }
        $lines = explode("\n", $rawContent);
        $keptLines = [];
        $removed = false;

        foreach ($lines as $line) {
            if (preg_match('/^(#?)\s*DATABASE_URL\s*=\s*"[^"]+"\s*#\s*(.+)$/i', $line, $matches)) {
                $currentId = trim($matches[2]);
                if (0 === strcasecmp($currentId, $dbId)) {
                    $removed = true;
                    continue;
                }
            }

            $keptLines[] = $line;
        }

        if (!$removed) {
            return false;
        }

        return false !== @file_put_contents($envPath, implode("\n", $keptLines));
    }
}

if (!function_exists('updateInstallUuidInEnvLocal')) {
    function updateInstallUuidInEnvLocal(InstallUuidManager $manager, string $envPath, string $installUuid): bool
    {
        $content = '';
        if (file_exists($envPath)) {
            $currentContent = @file_get_contents($envPath);
            if (false === $currentContent) {
                return false;
            }
            $content = $currentContent;
        }

        $normalizedUuid = strtolower(trim($installUuid));
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $normalizedUuid)) {
            return false;
        }

        $updated = $manager->upsertInstallUuid($content, true);
        $replacedContent = str_replace('INSTALL_UUID='.$updated['uuid'], 'INSTALL_UUID='.$normalizedUuid, $updated['content']);
        $directory = dirname($envPath);
        if (!createDirectoryTree($directory, 0o755)) {
            return false;
        }

        return false !== @file_put_contents($envPath, $replacedContent);
    }
}

if (!function_exists('updateAppSecretInEnvLocal')) {
    function updateAppSecretInEnvLocal(AppSecretManager $manager, string $envPath, string $appSecret): bool
    {
        $content = '';
        if (file_exists($envPath)) {
            $currentContent = @file_get_contents($envPath);
            if (false === $currentContent) {
                return false;
            }
            $content = $currentContent;
        }

        $normalizedSecret = trim($appSecret);
        if (1 !== preg_match('/^[A-Za-z0-9._-]{16,128}$/', $normalizedSecret)) {
            return false;
        }

        $updated = $manager->upsertAppSecret($content, true);
        $replacedContent = str_replace('APP_SECRET='.$updated['secret'], 'APP_SECRET='.$normalizedSecret, $updated['content']);
        $directory = dirname($envPath);
        if (!createDirectoryTree($directory, 0o755)) {
            return false;
        }

        return false !== @file_put_contents($envPath, $replacedContent);
    }
}
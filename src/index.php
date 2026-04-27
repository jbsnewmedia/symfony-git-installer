<?php

declare(strict_types=1);

/**
 * GitInstall - GitHub Repository Installer.
 *
 * Downloads and extracts branches or tags from GitHub repositories.
 * Style inspired by Composer.
 */
session_start();

$configPath = __DIR__.'/config.php';
$config = file_exists($configPath) ? require $configPath : require __DIR__.'/config.example.php';

$langDir = __DIR__.'/lang/';
$availableLangs = array_map(fn ($f) => basename($f, '.php'), glob($langDir.'*.php'));
$defaultLang = $config['default_language'] ?? 'en';

if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $defaultLang;
}
if (isset($_GET['lang'])) {
    if (in_array($_GET['lang'], $availableLangs, true)) {
        $_SESSION['lang'] = $_GET['lang'];
    }
}

$lang = require $langDir.$_SESSION['lang'].'.php';

function __(string $key, array $placeholders = []): string
{
    global $lang;
    $text = $lang[$key] ?? $key;
    foreach ($placeholders as $k => $v) {
        $text = str_replace(':'.$k, (string) $v, $text);
    }

    return $text;
}

function extractSemverFromTag(string $tag): ?string
{
    $normalized = ltrim(trim($tag), 'vV');
    if (1 === preg_match('/^\d+\.\d+\.\d+$/', $normalized)) {
        return $normalized;
    }

    return null;
}

function resolveInstallerVersion(array $config, array $tags): string
{
    $configured = trim((string) ($config['installer_version'] ?? ''));
    if ('' !== $configured) {
        return $configured;
    }

    $semverTags = [];
    foreach ($tags as $tag) {
        $name = (string) ($tag['name'] ?? '');
        $semver = extractSemverFromTag($name);
        if (null !== $semver) {
            $semverTags[$name] = $semver;
        }
    }

    if (empty($semverTags)) {
        return 'unknown';
    }

    uasort($semverTags, static fn (string $a, string $b): int => version_compare($b, $a));

    return (string) array_key_first($semverTags);
}

function writeConfigValues(string $configPath, array $updates): bool
{
    if (!file_exists($configPath)) {
        return false;
    }

    $current = require $configPath;
    if (!is_array($current)) {
        return false;
    }

    $merged = array_replace($current, $updates);
    $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($merged, true).";\n";

    return false !== file_put_contents($configPath, $content);
}

function canUpdateInstallerToTag(string $currentInstallerVersion, string $targetTag): bool
{
    $currentSemver = extractSemverFromTag($currentInstallerVersion);
    $targetSemver = extractSemverFromTag($targetTag);

    if (null === $currentSemver || null === $targetSemver) {
        return true;
    }

    if (version_compare($targetSemver, $currentSemver, '>=')) {
        return true;
    }

    return version_compare($currentSemver, '1.2.0', '>=');
}

function normalizeRelativePath(string $path): string
{
    return trim(str_replace('\\', '/', $path), '/');
}

function isAllowedUpdaterFile(string $relativePath): bool
{
    $relativePath = normalizeRelativePath($relativePath);

    if ('index.php' === $relativePath || '.htaccess' === $relativePath) {
        return true;
    }

    return 1 === preg_match('/^lang\/.+\.php$/i', $relativePath);
}

function updateUpdaterFromTag(
    GitHubClient $client,
    string $repository,
    string $tag,
    string $updaterSourcePath,
    string $destinationDir,
): array {
    $zipContent = $client->downloadArchive($repository, $tag, 'tag');
    $tempFile = tempnam(sys_get_temp_dir(), 'updater_self_');
    file_put_contents($tempFile, $zipContent);

    $updatedFiles = [];
    $skippedFiles = [];

    try {
        $zip = new ZipArchive();
        if (true !== $zip->open($tempFile)) {
            throw new RuntimeException('Failed to open update ZIP');
        }

        $tempExtractDir = sys_get_temp_dir().'/updater_self_'.uniqid();
        mkdir($tempExtractDir, 0755, true);
        $zip->extractTo($tempExtractDir);
        $zip->close();

        $dirs = glob($tempExtractDir.'/*', GLOB_ONLYDIR);
        if (empty($dirs)) {
            throw new RuntimeException('No directory in update archive');
        }

        $archiveRoot = $dirs[0];
        $sourceDir = $archiveRoot.'/'.normalizeRelativePath($updaterSourcePath);
        if (!is_dir($sourceDir)) {
            throw new RuntimeException('Updater source path not found in archive: '.$updaterSourcePath);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $relativePath = normalizeRelativePath(substr((string) $item->getPathname(), strlen($sourceDir) + 1));

            if (!isAllowedUpdaterFile($relativePath)) {
                $skippedFiles[] = $relativePath;
                continue;
            }

            if (str_contains($relativePath, '..')) {
                $skippedFiles[] = $relativePath;
                continue;
            }

            $targetPath = rtrim($destinationDir, '/').'/'.$relativePath;
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            if (!copy($item->getPathname(), $targetPath)) {
                throw new RuntimeException('Failed to update file: '.$relativePath);
            }

            $updatedFiles[] = $relativePath;
        }

        recursiveDelete($tempExtractDir);
    } finally {
        @unlink($tempFile);
    }

    return [
        'updated_files' => $updatedFiles,
        'skipped_files' => $skippedFiles,
    ];
}

class GitHubClient
{
    private readonly string $baseUrl;
    private readonly string $userAgent;

    public function __construct(string $baseUrl, private readonly string $token, private readonly string $installerVersion = 'unknown')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->userAgent = 'SymfonyGitInstaller/'.$this->installerVersion;
    }

    private function request(string $endpoint): array
    {
        $url = $this->baseUrl.$endpoint;
        $headers = ['User-Agent: '.$this->userAgent, 'Accept: application/vnd.github.v3+json'];
        if (!empty($this->token)) {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 400) {
            throw new RuntimeException("GitHub API Error: HTTP {$httpCode}");
        }

        return json_decode($response, true) ?? [];
    }

    public function getBranches(string $repo): array
    {
        $branches = [];
        $page = 1;
        do {
            $response = $this->request("/repos/{$repo}/branches?per_page=100&page={$page}");
            if (empty($response)) {
                break;
            }
            foreach ($response as $branch) {
                $branches[] = ['name' => $branch['name'], 'commit' => $branch['commit']['sha'] ?? ''];
            }
            ++$page;
        } while (100 === count($response));

        return $branches;
    }

    public function getTags(string $repo): array
    {
        $tags = [];
        $page = 1;
        do {
            $response = $this->request("/repos/{$repo}/tags?per_page=100&page={$page}");
            if (empty($response)) {
                break;
            }
            foreach ($response as $tag) {
                $tags[] = ['name' => $tag['name'], 'commit' => $tag['commit']['sha'] ?? ''];
            }
            ++$page;
        } while (100 === count($response));

        return $tags;
    }

    public function downloadArchive(string $repo, string $ref, string $refType = 'branch'): string
    {
        $url = $this->baseUrl."/repos/{$repo}/zipball/{$ref}";
        $headers = ['User-Agent: '.$this->userAgent];
        if (!empty($this->token)) {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (200 !== $httpCode) {
            throw new RuntimeException("Download failed: HTTP {$httpCode}");
        }

        return $response;
    }
}

function handleAuthentication(array $config, bool $showVersionsBeforeLogin = false, array $versionMeta = []): void
{
    $password = $config['password'] ?? '';

    if (empty($password)) {
        return;
    }

    if (isset($_GET['logout'])) {
        session_destroy();
        session_start();
        $_SESSION = [];
        header('Location: ?');
        exit;
    }

    if (isset($_SESSION['gitinstall_authenticated']) && true === $_SESSION['gitinstall_authenticated']) {
        return;
    }

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['password'])) {
        $inputPassword = $_POST['password'] ?? '';

        if (password_verify((string) $inputPassword, (string) $password) || hash_equals($password, $inputPassword)) {
            $_SESSION['gitinstall_authenticated'] = true;
            $_SESSION['gitinstall_auth_time'] = time();
            header('Location: ?');
            exit;
        }

        renderLoginForm(__('incorrect_password'), $showVersionsBeforeLogin ? $versionMeta : []);
        exit;
    }

    renderLoginForm('', $showVersionsBeforeLogin ? $versionMeta : []);
    exit;
}

function renderLoginForm(string $error = '', array $versionMeta = []): void
{
    $errorHtml = $error ? '<div class="error">'.htmlspecialchars($error).'</div>' : '';
    $text_please_enter_password = __('please_enter_password');
    $text_password_placeholder = __('password_placeholder');
    $text_login = __('login');

    $versionInfoHtml = '';
    if (!empty($versionMeta)) {
        $installerVersion = htmlspecialchars((string) ($versionMeta['installer_version'] ?? 'unknown'));
        $projectVersion = htmlspecialchars((string) ($versionMeta['project_version'] ?? 'unknown'));
        $versionInfoHtml = '<div class="repo-info" style="margin-top:15px">'
            .'<strong>'.__('updater_version').':</strong> <code>'.$installerVersion.'</code><br>'
            .'<strong>'.__('project_version').':</strong> <code>'.$projectVersion.'</code>'
            .'</div>';
    }

    $content = <<<HTML
<div class="login-form">
    <p>{$text_please_enter_password}</p>
    {$errorHtml}
    <form method="post">
        <input type="password" name="password" placeholder="{$text_password_placeholder}" autofocus>
        <button type="submit" class="btn">{$text_login}</button>
    </form>
    {$versionInfoHtml}
</div>
HTML;
    echo renderPage(__('title'), $content, null, null, false);
    exit;
}

function clearCacheDirectory(string $cacheDir): array
{
    $result = ['deleted_count' => 0, 'errors' => []];

    if (!is_dir($cacheDir)) {
        return $result;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();
        try {
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
                ++$result['deleted_count'];
            }
        } catch (Exception $e) {
            $result['errors'][] = $path.': '.$e->getMessage();
        }
    }

    @rmdir($cacheDir);

    return $result;
}

function cleanTargetDirectory(string $targetDir, array $whitelistFolders, array $whitelistFiles): array
{
    $preserved = [];
    $deletedCount = 0;

    if (!is_dir($targetDir)) {
        return ['deleted_count' => 0, 'preserved' => []];
    }

    $preservePaths = [];

    // Whitelist entries like "public/update" should match "update" when target is ".../public"
    $targetBasename = basename($targetDir);

    foreach ($whitelistFolders as $folder) {
        $folderNormalized = trim(str_replace('\\', '/', $folder), '/');
        $folderBasename = basename($folderNormalized);
        $folderParent = dirname($folderNormalized);

        // Match if whitelist parent matches target dir name, or try direct path
        if ($folderParent === $targetBasename || '.' === $folderParent) {
            $fullPath = rtrim($targetDir, '/').'/'.$folderBasename;
            if (is_dir($fullPath)) {
                $preservePaths[] = $fullPath;
            }
        } else {
            $fullPath = rtrim($targetDir, '/').'/'.$folderNormalized;
            if (is_dir($fullPath)) {
                $preservePaths[] = $fullPath;
            }
        }
    }

    foreach ($whitelistFiles as $file) {
        $fileNormalized = trim(str_replace('\\', '/', $file), '/');
        $fileBasename = basename($fileNormalized);
        $fileParent = dirname($fileNormalized);

        if ($fileParent === $targetBasename || '.' === $fileParent) {
            $fullPath = rtrim($targetDir, '/').'/'.$fileBasename;
            if (is_file($fullPath)) {
                $preservePaths[] = $fullPath;
            }
        } else {
            $fullPath = rtrim($targetDir, '/').'/'.$fileNormalized;
            if (is_file($fullPath)) {
                $preservePaths[] = $fullPath;
            }
        }
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();

        $shouldPreserve = false;
        foreach ($preservePaths as $preservePath) {
            if ($path === $preservePath || str_starts_with((string) $path, $preservePath.'/')) {
                $shouldPreserve = true;
                break;
            }
        }

        if (!$shouldPreserve) {
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
                ++$deletedCount;
            }
        } else {
            $preserved[] = substr((string) $path, strlen($targetDir) + 1);
        }
    }

    return ['deleted_count' => $deletedCount, 'preserved' => $preserved];
}

function extractZip(string $zipContent, string $targetDir, array $excludeFolders, array $excludeFiles, array $whitelistFolders, array $whitelistFiles): array
{
    $tempFile = tempnam(sys_get_temp_dir(), 'gitinstall_');
    file_put_contents($tempFile, $zipContent);
    try {
        $zip = new ZipArchive();
        if (true !== $zip->open($tempFile)) {
            throw new RuntimeException('Failed to open ZIP');
        }
        $tempExtractDir = sys_get_temp_dir().'/gitinstall_'.uniqid();
        mkdir($tempExtractDir, 0755, true);
        $zip->extractTo($tempExtractDir);
        $zip->close();
        $dirs = glob($tempExtractDir.'/*', GLOB_ONLYDIR);
        if (empty($dirs)) {
            throw new RuntimeException('No directory in archive');
        }
        $sourceDir = $dirs[0];
        $extractedFiles = [];
        $skippedFiles = [];
        $skippedFolders = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $hasWhitelistFolders = !empty($whitelistFolders);
        $hasWhitelistFiles = !empty($whitelistFiles);
        $targetBasename = basename($targetDir);

        foreach ($iterator as $item) {
            $relativePath = substr((string) $item->getPathname(), strlen($sourceDir) + 1);
            $relativePathNormalized = str_replace('\\', '/', $relativePath);
            $parentDir = dirname($relativePathNormalized);
            if ('.' === $parentDir) {
                $parentDir = '';
            }

            // Check if this path is in whitelist (should be skipped to preserve existing)
            $isInWhitelist = false;

            if ($hasWhitelistFolders) {
                foreach ($whitelistFolders as $wlFolder) {
                    $wlNormalized = trim(str_replace('\\', '/', $wlFolder), '/');
                    $wlBasename = basename($wlNormalized);
                    $wlParent = dirname($wlNormalized);

                    // Match relative path against whitelist (accounting for parent dir)
                    $matchPath = $relativePathNormalized;
                    if ($wlParent === $targetBasename || '.' === $wlParent) {
                        $matchPath = $targetBasename.'/'.$relativePathNormalized;
                    }

                    if ($matchPath === $wlNormalized || str_starts_with($matchPath, $wlNormalized.'/')) {
                        $isInWhitelist = true;
                        break;
                    }
                }
            }

            if (!$isInWhitelist && $hasWhitelistFiles && !$item->isDir()) {
                foreach ($whitelistFiles as $wlFile) {
                    $wlNormalized = trim(str_replace('\\', '/', $wlFile), '/');
                    if ($relativePathNormalized === $wlNormalized) {
                        $isInWhitelist = true;
                        break;
                    }
                }
            }

            // Skip whitelisted items (preserve existing)
            if ($isInWhitelist) {
                if ($item->isDir()) {
                    $skippedFolders[] = $relativePath;
                } else {
                    $skippedFiles[] = $relativePath;
                }
                continue;
            }

            // Always check exclude folders/files
            if ($item->isDir()) {
                foreach ($excludeFolders as $excludeFolder) {
                    $excludeNormalized = trim(str_replace('\\', '/', $excludeFolder), '/');
                    if ($relativePathNormalized === $excludeNormalized || str_starts_with($relativePathNormalized, $excludeNormalized.'/')) {
                        $skippedFolders[] = $relativePath;
                        continue 2;
                    }
                }
                $targetPath = rtrim($targetDir, '/').'/'.$relativePath;
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
                continue;
            }

            // Check if parent dir is in exclude list
            if ('' !== $parentDir) {
                foreach ($excludeFolders as $excludeFolder) {
                    $excludeNormalized = trim(str_replace('\\', '/', $excludeFolder), '/');
                    if ($parentDir === $excludeNormalized || str_starts_with($parentDir, $excludeNormalized.'/')) {
                        $skippedFiles[] = $relativePath;
                        continue 2;
                    }
                }
            }

            // Check exclude files
            $fileName = basename($relativePathNormalized);
            foreach ($excludeFiles as $excludeFile) {
                $excludeNormalized = trim(str_replace('\\', '/', $excludeFile), '/');
                if ($relativePathNormalized === $excludeNormalized || $fileName === $excludeNormalized) {
                    $skippedFiles[] = $relativePath;
                    continue 2;
                }
            }

            // Extract file
            $targetPath = rtrim($targetDir, '/').'/'.$relativePath;
            $targetDirPath = dirname($targetPath);
            if (!is_dir($targetDirPath)) {
                mkdir($targetDirPath, 0755, true);
            }
            copy($item->getPathname(), $targetPath);
            $extractedFiles[] = $relativePath;
        }

        recursiveDelete($tempExtractDir);

        return ['extracted' => $extractedFiles, 'skipped_files' => $skippedFiles, 'skipped_folders' => $skippedFolders];
    } finally {
        unlink($tempFile);
    }
}

function recursiveDelete(string $path): void
{
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

function parseEnvLocal(string $envPath): array
{
    $result = [
        'app_env' => 'prod',
        'current_db' => null,
        'databases' => [],
        'raw_content' => '',
    ];

    if (!file_exists($envPath)) {
        return $result;
    }

    $result['raw_content'] = file_get_contents($envPath);
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
    }

    return $result;
}

function updateEnvLocal(string $envPath, string $appEnv, string $activeDb): bool
{
    if (!file_exists($envPath)) {
        return false;
    }

    $content = file_get_contents($envPath);
    $lines = explode("\n", $content);

    foreach ($lines as $lineNum => &$line) {
        if (preg_match('/^APP_ENV\s*=\s*(dev|prod)/i', $line)) {
            $line = 'APP_ENV='.$appEnv;
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

    return false !== file_put_contents($envPath, implode("\n", $lines));
}

function saveEnvLocalContent(string $envPath, string $content): bool
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $content);

    return false !== file_put_contents($envPath, $normalized);
}

function addDatabaseToEnvLocal(string $envPath, string $dbId, string $dbUrl): bool
{
    if (!file_exists($envPath)) {
        $defaultContent = "APP_ENV=prod\n";
        $dir = dirname($envPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
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
    $content = rtrim((string) file_get_contents($envPath), "\n")."\n".$line."\n";

    return false !== file_put_contents($envPath, $content);
}

function removeDatabaseFromEnvLocal(string $envPath, string $dbId): bool
{
    if (!file_exists($envPath)) {
        return false;
    }

    $dbId = trim($dbId);
    if ('' === $dbId) {
        return false;
    }

    $content = (string) file_get_contents($envPath);
    $lines = explode("\n", $content);
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

    return false !== file_put_contents($envPath, implode("\n", $keptLines));
}

function getMigrationsStatus(string $targetDir): array
{
    $console = rtrim($targetDir, '/').'/bin/console';
    if (!file_exists($console)) {
        return ['html' => 'bin/console not found', 'count' => 0, 'error' => true];
    }

    $migrationsDir = rtrim($targetDir, '/').'/migrations';
    $hasMigrations = is_dir($migrationsDir) && count(glob($migrationsDir.'/*.php')) > 0;

    if (!$hasMigrations) {
        return [
            'html' => '<span style="color:#6a737d;">'.__('no_migrations_found').'</span>',
            'count' => 0,
            'error' => false,
            'no_migrations' => true,
        ];
    }

    $cmd = 'php '.escapeshellarg($console).' doctrine:migrations:status --no-interaction 2>&1';
    $output = shell_exec($cmd);

    if (null === $output) {
        return ['html' => 'Could not execute migrations:status', 'count' => 0, 'error' => true];
    }

    // Try to find the line with "New Migrations"
    if (preg_match('/New Migrations:\s+(\d+)/i', $output, $matches)) {
        $count = (int) $matches[1];
        if ($count > 0) {
            return [
                'html' => '<span style="color:#d73a49; font-weight:bold;">'.$count.' pending</span>',
                'count' => $count,
                'error' => false,
            ];
        }

        return [
            'html' => '<span style="color:#28a745; font-weight:bold;">'.__('no_migrations_to_execute').'</span>',
            'count' => 0,
            'error' => false,
        ];
    }

    // Handle errors: extract message from JSON if possible, or just take first line
    if (str_starts_with(trim($output), '{')) {
        $json = json_decode($output, true);
        if (isset($json['message'])) {
            $msg = $json['message'];
            // If it's a long message with "Message: ...", try to extract the inner message
            if (preg_match('/Message: "(.*?)"/s', (string) $msg, $m)) {
                $msg = $m[1];
            }

            if (str_contains((string) $msg, 'could not find driver') || str_contains((string) $msg, 'Connection refused')) {
                return [
                    'html' => '<span style="color:#6a737d;">'.__('migrations_disabled_no_db').'</span>',
                    'count' => 0,
                    'error' => false,
                    'no_db' => true,
                ];
            }

            return [
                'html' => '<span style="color:#d73a49; font-size:0.9em;">Error: '.htmlspecialchars(strtok($msg, "\n")).'</span>',
                'count' => 0,
                'error' => true,
            ];
        }
    }

    // Fallback: take first non-empty line
    $lines = explode("\n", trim($output));
    foreach ($lines as $line) {
        $line = trim($line);
        if ('' !== $line && !str_contains($line, 'CRITICAL') && !str_contains($line, 'DEBUG')) {
            // Check for common Doctrine/PDO errors to make them compact
            if (str_contains($line, 'ExceptionConverter.php') || str_contains($line, 'Connection refused') || str_contains($line, 'could not find driver')) {
                return [
                    'html' => '<span style="color:#6a737d;">'.__('migrations_disabled_no_db').'</span>',
                    'count' => 0,
                    'error' => false,
                    'no_db' => true,
                ];
            }

            return [
                'html' => '<span style="color:#d73a49; font-size:0.9em;">Error: '.htmlspecialchars($line).'</span>',
                'count' => 0,
                'error' => true,
            ];
        }
    }

    return [
        'html' => '<span style="color:#d73a49; font-size:0.9em;">Error: '.htmlspecialchars(strtok(trim($output), "\n")).'</span>',
        'count' => 0,
        'error' => true,
    ];
}

function renderPage(string $title, string $content, ?string $error = null, ?string $envPath = null, bool $showLogout = false): string
{
    $errorHtml = $error ? '<div class="error">'.htmlspecialchars($error).'</div>' : '';
    $homeButton = '<a href="?" class="btn btn-secondary btn-small home-btn">'.__('home').'</a>';
    $logoutButton = $showLogout ? '<form method="get" class="logout-form"><input type="hidden" name="logout" value="1"><button type="submit" class="btn btn-secondary btn-small">'.__('logout').'</button></form>' : '';

    $text_language = __('language');
    $langOptions = '';
    global $availableLangs;
    foreach ($availableLangs as $code) {
        $selected = $_SESSION['lang'] === $code ? 'selected' : '';
        $langName = strtoupper((string) $code);
        $langOptions .= '<option value="'.$code.'" '.$selected.'>'.$langName.'</option>';
    }

    $langSwitcherHtml = <<<HTML
<div class="lang-switcher">
    <form method="get" class="lang-form" id="langForm">
        <label>{$text_language}:</label>
        <select name="lang" class="env-select" onchange="document.getElementById('langForm').submit()">
            {$langOptions}
        </select>
    </form>
</div>
HTML;

    $envConfigHtml = '';
    $dbConfigHtml = '';
    $dashboardNavHtml = '';
    $dashboardScript = '';
    if (null !== $envPath) {
        $envConfig = parseEnvLocal($envPath);

        $devSelected = 'dev' === $envConfig['app_env'] ? 'selected' : '';
        $prodSelected = 'prod' === $envConfig['app_env'] ? 'selected' : '';

        $dbOptions = '';
        foreach ($envConfig['databases'] as $db) {
            $selected = $db['active'] ? 'selected' : '';
            $dbOptions .= '<option value="'.htmlspecialchars((string) $db['id']).'" '.$selected.'>'.htmlspecialchars((string) $db['id']).'</option>';
        }

        $text_mode = __('mode');
        $text_database = __('database');
        $text_save = __('save');
        $text_env_editor = __('env_editor');
        $text_env_content = __('env_content');
        $text_save_env_file = __('save_env_file');
        $text_db_manager = __('database_manager');
        $text_db_id = __('database_id');
        $text_db_url = __('database_url');
        $text_add_database = __('add_database');
        $text_remove_database = __('remove_database');
        $text_select_database = __('select_database');
        $text_dashboard_updates = __('dashboard_updates');
        $text_dashboard_environment = __('dashboard_environment');
        $text_dashboard_databases = __('dashboard_databases');
        $text_migrations_status = __('migrations_status');
        $text_run_migrations = __('run_migrations');
        $confirm_run_migrations = __('confirm_run_migrations');

        $migrationsData = getMigrationsStatus(dirname(__DIR__, 2));
        $migrationsStatusHtml = $migrationsData['html'];
        $migrationsDisabled = (0 === $migrationsData['count'] || $migrationsData['error'] || isset($migrationsData['no_migrations']) || isset($migrationsData['no_db'])) ? 'disabled' : '';

        $dbRemoveOptions = '';
        foreach ($envConfig['databases'] as $db) {
            $dbLabel = htmlspecialchars($db['id'].($db['active'] ? ' (active)' : ''));
            $dbValue = htmlspecialchars((string) $db['id']);
            $dbRemoveOptions .= '<option value="'.$dbValue.'">'.$dbLabel.'</option>';
        }

        if ('' === $dbRemoveOptions) {
            $dbRemoveOptions = '<option value="">-</option>';
        }

        $envRawContent = htmlspecialchars((string) $envConfig['raw_content']);

        $envConfigHtml = <<<HTML
<div class="env-config">
    <form method="post" class="env-form" style="margin-bottom: 20px;">
        <div class="env-row">
            <label>{$text_mode}:</label>
            <select name="app_env" class="env-select">
                <option value="dev" {$devSelected}>Dev</option>
                <option value="prod" {$prodSelected}>Prod</option>
            </select>
        </div>
        <button type="submit" name="save_env" class="btn btn-secondary btn-small">{$text_save}</button>
    </form>

    <h3 style="margin-bottom:10px;">{$text_env_editor}</h3>
    <form method="post">
        <label style="display:block; margin-bottom:6px; font-weight:500; color:#586069;">{$text_env_content}:</label>
        <textarea name="env_content" class="env-textarea">{$envRawContent}</textarea>
        <button type="submit" name="save_env_content" class="btn btn-secondary btn-small" style="margin-top:8px;">{$text_save_env_file}</button>
    </form>
</div>
HTML;

        $dbConfigHtml = <<<HTML
<div class="env-config">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
        <div>
            <h3 style="margin-bottom:5px;">{$text_migrations_status}</h3>
            <div>{$migrationsStatusHtml}</div>
        </div>
        <form method="post" onsubmit="return confirm('{$confirm_run_migrations}')">
            <button type="submit" name="run_migrations" class="btn btn-secondary" {$migrationsDisabled}>{$text_run_migrations}</button>
        </form>
    </div>

    <hr style="margin:15px 0; border:none; border-top:1px solid #d1d5db;">

    <form method="post" class="env-form" style="margin-bottom: 20px;">
        <div class="env-row">
            <label>{$text_database}:</label>
            <select name="database" class="env-select">
                {$dbOptions}
            </select>
        </div>
        <button type="submit" name="save_env" class="btn btn-secondary btn-small">{$text_save}</button>
    </form>

    <hr style="margin:15px 0; border:none; border-top:1px solid #d1d5db;">

    <h3 style="margin-bottom:10px;">{$text_db_manager}</h3>
    <form method="post" class="env-form" style="margin-bottom:8px;">
        <div class="env-row">
            <label>{$text_db_id}:</label>
            <input type="text" name="db_id" class="env-input" required>
        </div>
        <div class="env-row" style="flex:1; min-width:300px;">
            <label>{$text_db_url}:</label>
            <input type="text" name="db_url" class="env-input" style="width:100%;" required>
        </div>
        <button type="submit" name="add_database" class="btn btn-secondary btn-small">{$text_add_database}</button>
    </form>

    <form method="post" class="env-form">
        <div class="env-row">
            <label>{$text_select_database}:</label>
            <select name="remove_db_id" class="env-select">
                {$dbRemoveOptions}
            </select>
        </div>
        <button type="submit" name="remove_database" class="btn btn-small">{$text_remove_database}</button>
    </form>
</div>
HTML;

        $dashboardNavHtml = <<<HTML
<div class="dashboard-nav">
    <button type="button" class="btn btn-secondary dashboard-btn active" id="btn-updates" onclick="showDashboardSection('updates')">{$text_dashboard_updates}</button>
    <button type="button" class="btn btn-secondary dashboard-btn" id="btn-environment" onclick="showDashboardSection('environment')">{$text_dashboard_environment}</button>
    <button type="button" class="btn btn-secondary dashboard-btn" id="btn-databases" onclick="showDashboardSection('databases')">{$text_dashboard_databases}</button>
</div>
HTML;

        $dashboardScript = <<<HTML
<script>
function showDashboardSection(section){
    var updates = document.getElementById('dashboard-updates');
    var env = document.getElementById('dashboard-environment');
    var dbs = document.getElementById('dashboard-databases');
    var btnUpdates = document.getElementById('btn-updates');
    var btnEnvironment = document.getElementById('btn-environment');
    var btnDatabases = document.getElementById('btn-databases');
    if(!updates || !env || !dbs || !btnUpdates || !btnEnvironment || !btnDatabases){ return; }

    updates.style.display = 'none';
    env.style.display = 'none';
    dbs.style.display = 'none';
    btnUpdates.classList.remove('active');
    btnEnvironment.classList.remove('active');
    btnDatabases.classList.remove('active');

    if(section === 'environment'){
        env.style.display = 'block';
        btnEnvironment.classList.add('active');
    } else if(section === 'databases'){
        dbs.style.display = 'block';
        btnDatabases.classList.add('active');
    } else {
        updates.style.display = 'block';
        btnUpdates.classList.add('active');
    }
}
</script>
HTML;
    }

    $appTitle = __('title');

    return <<<HTML
<!DOCTYPE html>
<html lang="{$_SESSION['lang']}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - GitInstall</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 30px; }
        header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
        .header-left h1 { color: #24292e; margin-bottom: 5px; }
        .header-left h2 { color: #586069; font-size: 1.1em; margin-bottom: 0; font-weight: normal; }
        .header-right { text-align: right; }
        .repo-info { background: #f6f8fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .repo-info code { background: #24292e; color: #fff; padding: 2px 8px; border-radius: 3px; }
        .error { background: #ffeef0; color: #86181d; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #fdc8c8; }
        .success { background: #e6ffed; color: #144620; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #bef5cb; }
        .warning { background: #fff8e6; color: #735c0f; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f0d8a8; }
        .branch-list, .tag-list { list-style: none; }
        .branch-list li, .tag-list li { padding: 10px 15px; border-bottom: 1px solid #e1e4e8; display: flex; justify-content: space-between; align-items: center; }
        .branch-list li:last-child, .tag-list li:last-child { border-bottom: none; }
        .branch-list li:hover, .tag-list li:hover { background: #f6f8fa; }
        .branch-name, .tag-name { font-family: 'SF Mono', Consolas, monospace; color: #0366d6; font-weight: 500; }
        .commit-sha { font-family: 'SF Mono', Consolas, monospace; font-size: 0.85em; color: #6a737d; margin-left: 10px; }
        .btn { background: #28a745; color: white; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 0.9em; }
        .btn:hover { background: #22863a; }
        .btn-secondary { background: #0366d6; }
        .btn-secondary:hover { background: #0056b3; }
        .dashboard-nav { display: flex; gap: 10px; margin-bottom: 15px; }
        .dashboard-btn.active { background: #24292e; }
        .tabs { margin-bottom: 20px; }
        .tab { background: #f6f8fa; border: 1px solid #e1e4e8; padding: 8px 16px; cursor: pointer; border-radius: 6px 6px 0 0; margin-right: 5px; }
        .tab.active { background: white; border-bottom-color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; border: 1px solid #e1e4e8; border-radius: 0 0 6px 6px; }
        .file-list { list-style: none; padding: 15px; max-height: 300px; overflow-y: auto; }
        .file-list li { padding: 5px 0; font-family: monospace; font-size: 0.9em; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #0366d6; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .logout-form { margin-bottom: 10px; display: inline-block; }
        .home-btn { display: inline-block; margin-bottom: 10px; text-decoration: none; }
        .login-form { max-width: 300px; margin: 20px auto; }
        .login-form input[type="password"] { width: 100%; padding: 12px; border: 1px solid #e1e4e8; border-radius: 6px; margin-bottom: 10px; font-size: 1em; }
        .login-form .btn { width: 100%; padding: 12px; }
        .env-config { background: #f6f8fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .env-form { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .env-row { display: flex; align-items: center; gap: 8px; }
        .env-row label { font-weight: 500; color: #586069; }
        .env-select { padding: 6px 12px; border: 1px solid #e1e4e8; border-radius: 6px; font-size: 0.9em; min-width: 100px; }
        .env-input { padding: 6px 12px; border: 1px solid #e1e4e8; border-radius: 6px; font-size: 0.9em; min-width: 120px; }
        .env-textarea { width: 100%; min-height: 180px; padding: 10px; border: 1px solid #e1e4e8; border-radius: 6px; font-family: monospace; font-size: 0.9em; }
        .btn-small { padding: 6px 12px; font-size: 0.85em; }
        .lang-switcher { margin-bottom: 10px; }
        .lang-form label { font-size: 0.85em; color: #586069; margin-right: 5px; }
        footer { margin-top: 20px; text-align: center; }
        .footer-link { color: #586069; text-decoration: none; font-size: 0.8em; }
        .footer-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-left">
                <h1>🔄 GitInstall</h1>
                <h2>{$appTitle}</h2>
            </div>
            <div class="header-right">
                {$homeButton}
                {$logoutButton}
                {$langSwitcherHtml}
            </div>
        </header>
        {$errorHtml}
        {$dashboardNavHtml}
        <div id="dashboard-updates" style="display:block">{$content}</div>
        <div id="dashboard-environment" style="display:none">{$envConfigHtml}</div>
        <div id="dashboard-databases" style="display:none">{$dbConfigHtml}</div>
    </div>
    <footer>
        <a href="https://github.com/jbsnewmedia/symfony-git-installer" target="_blank" class="footer-link">github.com/jbsnewmedia/symfony-git-installer</a>
    </footer>
    {$dashboardScript}
</body>
</html>
HTML;
}

try {
    $repository = $config['repository'] ?? '';
    $token = $config['github_token'] ?? '';
    $apiBaseUrl = $config['api_base_url'] ?? 'https://api.github.com';
    $targetDirRelative = $config['target_directory'] ?? '../';
    $showVersionsBeforeLogin = (bool) ($config['show_versions_before_login'] ?? false);
    $currentProjectVersion = (string) ($config['project_version'] ?? 'unknown');
    $excludeFolders = $config['exclude_folders'] ?? [];
    $excludeFiles = $config['exclude_files'] ?? [];
    $whitelistFolders = $config['whitelist_folders'] ?? [];
    $whitelistFiles = $config['whitelist_files'] ?? [];

    if (empty($repository)) {
        throw new RuntimeException(__('repository_not_configured'));
    }

    $targetDir = realpath(__DIR__.'/'.$targetDirRelative);
    if (false === $targetDir) {
        $absoluteTarget = __DIR__.'/'.$targetDirRelative;
        if (!is_dir($absoluteTarget)) {
            if (!mkdir($absoluteTarget, 0755, true)) {
                throw new RuntimeException('Target directory cannot be created: '.$absoluteTarget);
            }
        }
        $targetDir = realpath($absoluteTarget);
        if (false === $targetDir) {
            throw new RuntimeException('Target directory cannot be resolved: '.$absoluteTarget);
        }
    }

    $versionProbeClient = new GitHubClient($apiBaseUrl, $token);
    $tags = $versionProbeClient->getTags($repository);
    $currentInstallerVersion = resolveInstallerVersion($config, $tags);

    handleAuthentication(
        $config,
        $showVersionsBeforeLogin,
        [
            'installer_version' => $currentInstallerVersion,
            'project_version' => $currentProjectVersion,
        ]
    );

    $client = new GitHubClient($apiBaseUrl, $token, $currentInstallerVersion);

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['save_env'])) {
        $envPath = rtrim($targetDir, '/').'/.env.local';
        $newEnv = $_POST['app_env'] ?? 'prod';
        $newDb = $_POST['database'] ?? 'DB1';

        if (updateEnvLocal($envPath, $newEnv, $newDb)) {
            $content = '<div class="success">'.__('config_saved').'<br>';
            $content .= '<strong>'.__('mode').':</strong> '.htmlspecialchars((string) $newEnv).'<br>';
            $content .= '<strong>'.__('database').':</strong> '.htmlspecialchars((string) $newDb).'</div>';
            $content .= '<a href="?" class="back-link">'.__('back').'</a>';
            echo renderPage(__('configuration'), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }
        $error = 'Error saving .env.local';
    }

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['save_env_content'])) {
        $envPath = rtrim($targetDir, '/').'/.env.local';
        $newContent = (string) ($_POST['env_content'] ?? '');

        if (saveEnvLocalContent($envPath, $newContent)) {
            $content = '<div class="success">'.__('env_file_saved').'</div>';
            $content .= '<a href="?" class="back-link">'.__('back').'</a>';
            echo renderPage(__('configuration'), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        throw new RuntimeException(__('env_file_save_failed'));
    }

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['add_database'])) {
        $envPath = rtrim($targetDir, '/').'/.env.local';
        $dbId = (string) ($_POST['db_id'] ?? '');
        $dbUrl = (string) ($_POST['db_url'] ?? '');

        if (addDatabaseToEnvLocal($envPath, $dbId, $dbUrl)) {
            $content = '<div class="success">'.__('database_added', ['id' => htmlspecialchars($dbId)]).'</div>';
            $content .= '<a href="?" class="back-link">'.__('back').'</a>';
            echo renderPage(__('configuration'), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        throw new RuntimeException(__('database_add_failed'));
    }

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['remove_database'])) {
        $envPath = rtrim($targetDir, '/').'/.env.local';
        $removeDbId = (string) ($_POST['remove_db_id'] ?? '');

        if (removeDatabaseFromEnvLocal($envPath, $removeDbId)) {
            $content = '<div class="success">'.__('database_removed', ['id' => htmlspecialchars($removeDbId)]).'</div>';
            $content .= '<a href="?" class="back-link">'.__('back').'</a>';
            echo renderPage(__('configuration'), $content, null, $envPath, !empty($config['password'] ?? ''));
            exit;
        }

        throw new RuntimeException(__('database_remove_failed'));
    }

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['run_migrations'])) {
        $console = rtrim($targetDir, '/').'/bin/console';
        $cmd = 'php '.escapeshellarg($console).' doctrine:migrations:migrate --no-interaction 2>&1';
        $output = shell_exec($cmd);

        $content = '<div class="success">'.__('migrations_run_successfully').'</div>';
        $content .= '<h3>'.__('migrations_output').'</h3>';
        $content .= '<pre style="background:#f6f8fa; padding:15px; border-radius:6px; font-size:0.9em; white-space:pre-wrap;">'.htmlspecialchars(trim((string) $output)).'</pre>';
        $content .= '<a href="?" class="back-link">'.__('back').'</a>';
        echo renderPage(__('run_migrations'), $content, null, $envPath, !empty($config['password'] ?? ''));
        exit;
    }

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['clear_cache'])) {
        $cacheDir = rtrim($targetDir, '/').'/var';
        $cacheResult = clearCacheDirectory($cacheDir);

        $content = '<div class="success">'.__('cache_cleared').'<br>';
        $content .= __('files_deleted', ['count' => $cacheResult['deleted_count'], 'dir' => htmlspecialchars($cacheDir)]);
        if (!empty($cacheResult['errors'])) {
            $content .= '<br><small>'.__('errors').': '.count($cacheResult['errors']).'</small>';
        }
        $content .= '</div>';
        $content .= '<a href="?" class="back-link">'.__('back').'</a>';
        echo renderPage(__('cache_cleared'), $content, null, $envPath, !empty($config['password'] ?? ''));
        exit;
    }

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['self_update'])) {
        $tag = trim((string) ($_POST['ref'] ?? ''));
        if ('' === $tag) {
            throw new RuntimeException(__('no_ref_specified'));
        }

        $tagNames = array_map(static fn (array $tagItem): string => (string) ($tagItem['name'] ?? ''), $tags);
        if (!in_array($tag, $tagNames, true)) {
            throw new RuntimeException(__('tag_not_found'));
        }

        if (!canUpdateInstallerToTag($currentInstallerVersion, $tag)) {
            throw new RuntimeException(__('downgrade_not_allowed'));
        }

        $updaterSourcePath = $config['updater_source_path'] ?? 'public/update';
        $selfUpdateResult = updateUpdaterFromTag($client, $repository, $tag, $updaterSourcePath, __DIR__);
        $updatedCount = count($selfUpdateResult['updated_files']);

        writeConfigValues($configPath, [
            'installer_version' => $tag,
            'project_version' => $currentProjectVersion,
        ]);

        $content = '<div class="success">'.__('updater_updated', ['tag' => htmlspecialchars($tag)]).'<br>';
        $content .= __('files_updated', ['count' => $updatedCount]).'</div>';
        $content .= '<a href="?" class="back-link">'.__('back').'</a>';

        if ($updatedCount > 0) {
            $content .= '<h3>'.__('updated_files').'</h3><ul class="file-list">';
            foreach ($selfUpdateResult['updated_files'] as $file) {
                $content .= '<li>'.htmlspecialchars((string) $file).'</li>';
            }
            $content .= '</ul>';
        }

        echo renderPage(__('title'), $content, null, $envPath, !empty($config['password'] ?? ''));
        exit;
    }

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['install'])) {
        $ref = $_POST['ref'] ?? '';
        $refType = $_POST['ref_type'] ?? 'branch';
        if (empty($ref)) {
            throw new RuntimeException(__('no_ref_specified'));
        }

        writeConfigValues($configPath, [
            'installer_version' => $currentInstallerVersion,
            'project_version' => $ref,
        ]);

        $cleanResult = cleanTargetDirectory($targetDir, $whitelistFolders, $whitelistFiles);

        $zipContent = $client->downloadArchive($repository, $ref, $refType);
        $result = extractZip($zipContent, $targetDir, $excludeFolders, $excludeFiles, $whitelistFolders, $whitelistFiles);

        $extractedCount = count($result['extracted']);
        $skippedFilesCount = count($result['skipped_files']);
        $skippedFoldersCount = count($result['skipped_folders']);
        $preservedCount = count($cleanResult['preserved']);

        $content = '<div class="success">'.__('installation_successful').'<br>';
        $content .= __('files_extracted', ['count' => $extractedCount, 'dir' => htmlspecialchars($targetDir)]);
        if ($preservedCount > 0) {
            $content .= '<br>'.__('preserved_files', ['count' => $preservedCount]);
        }
        if ($skippedFilesCount > 0 || $skippedFoldersCount > 0) {
            $content .= '<br><small>'.__('skipped', ['folders' => $skippedFoldersCount, 'files' => $skippedFilesCount]).'</small>';
        }
        $content .= '</div>';

        if ($preservedCount > 0) {
            $content .= '<div class="warning"><strong>'.__('preserved_list_title').'</strong><ul class="file-list">';
            foreach (array_slice($cleanResult['preserved'], 0, 20) as $item) {
                $content .= '<li>'.htmlspecialchars((string) $item).'</li>';
            }
            if ($preservedCount > 20) {
                $content .= '<li><em>'.__('and_more', ['count' => ($preservedCount - 20)]).'</em></li>';
            }
            $content .= '</ul></div>';
        }

        $content .= '<a href="?" class="back-link">'.__('back').'</a><h3>'.__('installed_files').'</h3><ul class="file-list">';
        foreach (array_slice($result['extracted'], 0, 50) as $file) {
            $content .= '<li>'.htmlspecialchars((string) $file).'</li>';
        }
        if ($extractedCount > 50) {
            $content .= '<li><em>'.__('and_more', ['count' => ($extractedCount - 50)]).'</em></li>';
        }
        $content .= '</ul>';
        echo renderPage(__('installation_successful'), $content, null, $envPath, !empty($config['password'] ?? ''));
        exit;
    }

    $branches = $client->getBranches($repository);

    $branchHtml = '';
    foreach ($branches as $branch) {
        $name = htmlspecialchars((string) $branch['name']);
        $sha = substr((string) $branch['commit'], 0, 7);
        $branchHtml .= '<li><span><span class="branch-name">'.$name.'</span><span class="commit-sha">'.$sha.'</span></span>';
        $branchHtml .= '<form method="post" style="display:inline"><input type="hidden" name="ref" value="'.$name.'"><input type="hidden" name="ref_type" value="branch"><button type="submit" name="install" class="btn">'.__('install').'</button></form></li>';
    }

    $tagHtml = '';
    foreach ($tags as $tag) {
        $name = htmlspecialchars((string) $tag['name']);
        $sha = substr((string) $tag['commit'], 0, 7);
        $tagHtml .= '<li><span><span class="tag-name">'.$name.'</span><span class="commit-sha">'.$sha.'</span></span>';
        $tagHtml .= '<span>';
        $tagHtml .= '<form method="post" style="display:inline; margin-right:6px"><input type="hidden" name="ref" value="'.$name.'"><input type="hidden" name="ref_type" value="tag"><button type="submit" name="install" class="btn btn-secondary">'.__('install').'</button></form>';
        $tagHtml .= '<form method="post" style="display:inline"><input type="hidden" name="ref" value="'.$name.'"><button type="submit" name="self_update" class="btn">'.__('update_updater').'</button></form>';
        $tagHtml .= '</span></li>';
    }

    if (empty($branches)) {
        $branchHtml = '<li><em>'.__('no_branches_found').'</em></li>';
    }
    if (empty($tags)) {
        $tagHtml = '<li><em>'.__('no_tags_found').'</em></li>';
    }

    $content = '<div class="repo-info"><strong>'.__('updater_version').':</strong> <code>'.htmlspecialchars($currentInstallerVersion).'</code><br><strong>'.__('project_version').':</strong> <code>'.htmlspecialchars($currentProjectVersion).'</code><br><strong>'.__('repository').':</strong> <code>'.htmlspecialchars((string) $repository).'</code><br><strong>'.__('target_directory').':</strong> <code>'.htmlspecialchars($targetDir).'</code>';
    if (!empty($whitelistFolders) || !empty($whitelistFiles)) {
        $content .= '<br><strong>'.__('whitelist_active').':</strong> ';
        $wlItems = array_merge($whitelistFolders, $whitelistFiles);
        $content .= htmlspecialchars(implode(', ', array_slice($wlItems, 0, 5)));
        if (count($wlItems) > 5) {
            $content .= ' ...';
        }
    }
    $content .= '</div>';

    $text_confirm_clear_cache = __('confirm_clear_cache');
    $content .= '<form method="post" style="margin-bottom:20px"><button type="submit" name="clear_cache" class="btn btn-secondary" onclick="return confirm(\''.$text_confirm_clear_cache.'\')">'.__('clear_cache').'</button></form>';
    $content .= '<div class="tabs"><button class="tab active" onclick="showTab(\'branches\')">'.__('branches').' ('.count($branches).')</button><button class="tab" onclick="showTab(\'tags\')">'.__('tags').' ('.count($tags).')</button></div>';
    $content .= '<div id="branches" class="tab-content active"><ul class="branch-list">'.$branchHtml.'</ul></div>';
    $content .= '<div id="tags" class="tab-content"><ul class="tag-list">'.$tagHtml.'</ul></div>';
    $content .= '<script>function showTab(t){document.querySelectorAll(".tab-content").forEach(e=>e.classList.remove("active"));document.querySelectorAll(".tab").forEach(e=>e.classList.remove("active"));document.getElementById(t).classList.add("active");event.target.classList.add("active");}</script>';

    $envPath = rtrim($targetDir, '/').'/.env.local';
    $hasPassword = !empty($config['password'] ?? '');
    echo renderPage(__('title'), $content, null, $envPath, $hasPassword);
} catch (Exception $e) {
    $hasPassword = !empty($config['password'] ?? '');
    echo renderPage(__('error'), '<p>'.__('error_occurred').'</p>', $e->getMessage(), null, $hasPassword);
}

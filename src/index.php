<?php

declare(strict_types=1);

/**
 * GitInstall - GitHub Repository Installer
 * 
 * Downloads and extracts branches or tags from GitHub repositories.
 * Style inspired by Composer.
 */

session_start();

$configPath = __DIR__ . '/config.php';
$config = file_exists($configPath) ? require $configPath : require __DIR__ . '/config.example.php';

$langDir = __DIR__ . '/lang/';
$availableLangs = array_map(fn($f) => basename($f, '.php'), glob($langDir . '*.php'));
$defaultLang = $config['default_language'] ?? 'en';

if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $defaultLang;
}
if (isset($_GET['lang'])) {
    if (in_array($_GET['lang'], $availableLangs)) {
        $_SESSION['lang'] = $_GET['lang'];
    }
}

$lang = require $langDir . $_SESSION['lang'] . '.php';

function __(string $key, array $placeholders = []): string
{
    global $lang;
    $text = $lang[$key] ?? $key;
    foreach ($placeholders as $k => $v) {
        $text = str_replace(':' . $k, (string)$v, $text);
    }
    return $text;
}

handleAuthentication($config);

class GitHubClient
{
    private string $baseUrl;
    private string $token;
    private string $userAgent;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->userAgent = 'SymfonyGitInstaller/1.0';
    }

    private function request(string $endpoint): array
    {
        $url = $this->baseUrl . $endpoint;
        $headers = ['User-Agent: ' . $this->userAgent, 'Accept: application/vnd.github.v3+json'];
        if (!empty($this->token)) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
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
            if (empty($response)) break;
            foreach ($response as $branch) {
                $branches[] = ['name' => $branch['name'], 'commit' => $branch['commit']['sha'] ?? ''];
            }
            $page++;
        } while (count($response) === 100);
        return $branches;
    }

    public function getTags(string $repo): array
    {
        $tags = [];
        $page = 1;
        do {
            $response = $this->request("/repos/{$repo}/tags?per_page=100&page={$page}");
            if (empty($response)) break;
            foreach ($response as $tag) {
                $tags[] = ['name' => $tag['name'], 'commit' => $tag['commit']['sha'] ?? ''];
            }
            $page++;
        } while (count($response) === 100);
        return $tags;
    }

    public function downloadArchive(string $repo, string $ref, string $refType = 'branch'): string
    {
        $url = $this->baseUrl . "/repos/{$repo}/zipball/{$ref}";
        $headers = ['User-Agent: ' . $this->userAgent];
        if (!empty($this->token)) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
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
        if ($httpCode !== 200) {
            throw new RuntimeException("Download failed: HTTP {$httpCode}");
        }
        return $response;
    }
}

function handleAuthentication(array $config): void
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
    
    if (isset($_SESSION['gitinstall_authenticated']) && $_SESSION['gitinstall_authenticated'] === true) {
        return;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $inputPassword = $_POST['password'] ?? '';
        
        if (password_verify($inputPassword, $password) || hash_equals($password, $inputPassword)) {
            $_SESSION['gitinstall_authenticated'] = true;
            $_SESSION['gitinstall_auth_time'] = time();
            header('Location: ?');
            exit;
        }
        
        renderLoginForm(__('incorrect_password'));
        exit;
    }
    
    renderLoginForm();
    exit;
}

function renderLoginForm(string $error = ''): void
{
    $errorHtml = $error ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
    $text_please_enter_password = __('please_enter_password');
    $text_password_placeholder = __('password_placeholder');
    $text_login = __('login');
    $content = <<<HTML
<div class="login-form">
    <p>{$text_please_enter_password}</p>
    {$errorHtml}
    <form method="post">
        <input type="password" name="password" placeholder="{$text_password_placeholder}" autofocus>
        <button type="submit" class="btn">{$text_login}</button>
    </form>
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
                $result['deleted_count']++;
            }
        } catch (Exception $e) {
            $result['errors'][] = $path . ': ' . $e->getMessage();
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
        if ($folderParent === $targetBasename || $folderParent === '.') {
            $fullPath = rtrim($targetDir, '/') . '/' . $folderBasename;
            if (is_dir($fullPath)) {
                $preservePaths[] = $fullPath;
            }
        } else {
            $fullPath = rtrim($targetDir, '/') . '/' . $folderNormalized;
            if (is_dir($fullPath)) {
                $preservePaths[] = $fullPath;
            }
        }
    }
    
    foreach ($whitelistFiles as $file) {
        $fileNormalized = trim(str_replace('\\', '/', $file), '/');
        $fileBasename = basename($fileNormalized);
        $fileParent = dirname($fileNormalized);
        
        if ($fileParent === $targetBasename || $fileParent === '.') {
            $fullPath = rtrim($targetDir, '/') . '/' . $fileBasename;
            if (is_file($fullPath)) {
                $preservePaths[] = $fullPath;
            }
        } else {
            $fullPath = rtrim($targetDir, '/') . '/' . $fileNormalized;
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
            if ($path === $preservePath || str_starts_with($path, $preservePath . '/')) {
                $shouldPreserve = true;
                break;
            }
        }
        
        if (!$shouldPreserve) {
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
                $deletedCount++;
            }
        } else {
            $preserved[] = substr($path, strlen($targetDir) + 1);
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
        if ($zip->open($tempFile) !== true) {
            throw new RuntimeException('Failed to open ZIP');
        }
        $tempExtractDir = sys_get_temp_dir() . '/gitinstall_' . uniqid();
        mkdir($tempExtractDir, 0755, true);
        $zip->extractTo($tempExtractDir);
        $zip->close();
        $dirs = glob($tempExtractDir . '/*', GLOB_ONLYDIR);
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
            $relativePath = substr($item->getPathname(), strlen($sourceDir) + 1);
            $relativePathNormalized = str_replace('\\', '/', $relativePath);
            $parentDir = dirname($relativePathNormalized);
            if ($parentDir === '.') $parentDir = '';
            
            // Check if this path is in whitelist (should be skipped to preserve existing)
            $isInWhitelist = false;
            
            if ($hasWhitelistFolders) {
                foreach ($whitelistFolders as $wlFolder) {
                    $wlNormalized = trim(str_replace('\\', '/', $wlFolder), '/');
                    $wlBasename = basename($wlNormalized);
                    $wlParent = dirname($wlNormalized);
                    
                    // Match relative path against whitelist (accounting for parent dir)
                    $matchPath = $relativePathNormalized;
                    if ($wlParent === $targetBasename || $wlParent === '.') {
                        $matchPath = $targetBasename . '/' . $relativePathNormalized;
                    }
                    
                    if ($matchPath === $wlNormalized || str_starts_with($matchPath, $wlNormalized . '/')) {
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
                    if ($relativePathNormalized === $excludeNormalized || str_starts_with($relativePathNormalized, $excludeNormalized . '/')) {
                        $skippedFolders[] = $relativePath;
                        continue 2;
                    }
                }
                $targetPath = rtrim($targetDir, '/') . '/' . $relativePath;
                if (!is_dir($targetPath)) mkdir($targetPath, 0755, true);
                continue;
            }
            
            // Check if parent dir is in exclude list
            if ($parentDir !== '') {
                foreach ($excludeFolders as $excludeFolder) {
                    $excludeNormalized = trim(str_replace('\\', '/', $excludeFolder), '/');
                    if ($parentDir === $excludeNormalized || str_starts_with($parentDir, $excludeNormalized . '/')) {
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
            $targetPath = rtrim($targetDir, '/') . '/' . $relativePath;
            $targetDirPath = dirname($targetPath);
            if (!is_dir($targetDirPath)) mkdir($targetDirPath, 0755, true);
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
        'raw_content' => ''
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
                'active' => $isActive
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
            $line = 'APP_ENV=' . $appEnv;
            continue;
        }
        
        if (preg_match('/^(#?)\s*DATABASE_URL\s*=\s*"[^"]+"\s*#\s*(.+)$/i', $line, $matches)) {
            $isCommented = $matches[1] === '#';
            $dbId = trim($matches[2]);
            
            if ($dbId === $activeDb && $isCommented) {
                $line = preg_replace('/^#\s*/', '', $line);
            } elseif ($dbId !== $activeDb && !$isCommented) {
                $line = '#' . ltrim($line);
            }
        }
    }
    
    return file_put_contents($envPath, implode("\n", $lines)) !== false;
}

function renderPage(string $title, string $content, ?string $error = null, ?string $envPath = null, bool $showLogout = false): string
{
    $errorHtml = $error ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
    $logoutButton = $showLogout ? '<form method="get" class="logout-form"><input type="hidden" name="logout" value="1"><button type="submit" class="btn btn-secondary btn-small">' . __('logout') . '</button></form>' : '';
    
    $text_language = __('language');
    $langOptions = '';
    global $availableLangs;
    foreach ($availableLangs as $code) {
        $selected = $_SESSION['lang'] === $code ? 'selected' : '';
        $langName = strtoupper($code);
        $langOptions .= '<option value="' . $code . '" ' . $selected . '>' . $langName . '</option>';
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
    if ($envPath !== null) {
        $envConfig = parseEnvLocal($envPath);
        
        $devSelected = $envConfig['app_env'] === 'dev' ? 'selected' : '';
        $prodSelected = $envConfig['app_env'] === 'prod' ? 'selected' : '';
        
        $dbOptions = '';
        foreach ($envConfig['databases'] as $db) {
            $selected = $db['active'] ? 'selected' : '';
            $dbOptions .= '<option value="' . htmlspecialchars($db['id']) . '" ' . $selected . '>' . htmlspecialchars($db['id']) . '</option>';
        }
        
        $text_mode = __('mode');
        $text_database = __('database');
        $text_save = __('save');

        $envConfigHtml = <<<HTML
<div class="env-config">
    <form method="post" class="env-form">
        <div class="env-row">
            <label>{$text_mode}:</label>
            <select name="app_env" class="env-select">
                <option value="dev" {$devSelected}>Dev</option>
                <option value="prod" {$prodSelected}>Prod</option>
            </select>
        </div>
        <div class="env-row">
            <label>{$text_database}:</label>
            <select name="database" class="env-select">
                {$dbOptions}
            </select>
        </div>
        <button type="submit" name="save_env" class="btn btn-secondary btn-small">{$text_save}</button>
    </form>
</div>
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
        .login-form { max-width: 300px; margin: 20px auto; }
        .login-form input[type="password"] { width: 100%; padding: 12px; border: 1px solid #e1e4e8; border-radius: 6px; margin-bottom: 10px; font-size: 1em; }
        .login-form .btn { width: 100%; padding: 12px; }
        .env-config { background: #f6f8fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .env-form { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .env-row { display: flex; align-items: center; gap: 8px; }
        .env-row label { font-weight: 500; color: #586069; }
        .env-select { padding: 6px 12px; border: 1px solid #e1e4e8; border-radius: 6px; font-size: 0.9em; min-width: 100px; }
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
                {$logoutButton}
                {$langSwitcherHtml}
            </div>
        </header>
        {$errorHtml}
        {$envConfigHtml}
        {$content}
    </div>
    <footer>
        <a href="https://github.com/jbsnewmedia/symfony-git-installer" target="_blank" class="footer-link">github.com/jbsnewmedia/symfony-git-installer</a>
    </footer>
</body>
</html>
HTML;
}

try {
    $repository = $config['repository'] ?? '';
    $token = $config['github_token'] ?? '';
    $apiBaseUrl = $config['api_base_url'] ?? 'https://api.github.com';
    $targetDirRelative = $config['target_directory'] ?? '../';
    $excludeFolders = $config['exclude_folders'] ?? [];
    $excludeFiles = $config['exclude_files'] ?? [];
    $whitelistFolders = $config['whitelist_folders'] ?? [];
    $whitelistFiles = $config['whitelist_files'] ?? [];
    
    if (empty($repository)) throw new RuntimeException(__('repository_not_configured'));
    
    $targetDir = realpath(__DIR__ . '/' . $targetDirRelative);
    if ($targetDir === false) {
        $absoluteTarget = __DIR__ . '/' . $targetDirRelative;
        if (!is_dir($absoluteTarget)) {
            if (!mkdir($absoluteTarget, 0755, true)) {
                throw new RuntimeException('Target directory cannot be created: ' . $absoluteTarget);
            }
        }
        $targetDir = realpath($absoluteTarget);
        if ($targetDir === false) {
            throw new RuntimeException('Target directory cannot be resolved: ' . $absoluteTarget);
        }
    }
    
    $client = new GitHubClient($apiBaseUrl, $token);
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_env'])) {
        $envPath = rtrim($targetDir, '/') . '/.env.local';
        $newEnv = $_POST['app_env'] ?? 'prod';
        $newDb = $_POST['database'] ?? 'DB1';
        
        if (updateEnvLocal($envPath, $newEnv, $newDb)) {
            $content = '<div class="success">' . __('config_saved') . '<br>';
            $content .= '<strong>' . __('mode') . ':</strong> ' . htmlspecialchars($newEnv) . '<br>';
            $content .= '<strong>' . __('database') . ':</strong> ' . htmlspecialchars($newDb) . '</div>';
            $content .= '<a href="?" class="back-link">' . __('back') . '</a>';
            echo renderPage(__('configuration'), $content);
            exit;
        } else {
            $error = 'Error saving .env.local';
        }
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_cache'])) {
        $cacheDir = rtrim($targetDir, '/') . '/var';
        $cacheResult = clearCacheDirectory($cacheDir);
        
        $content = '<div class="success">' . __('cache_cleared') . '<br>';
        $content .= __('files_deleted', ['count' => $cacheResult['deleted_count'], 'dir' => htmlspecialchars($cacheDir)]);
        if (!empty($cacheResult['errors'])) {
            $content .= '<br><small>' . __('errors') . ': ' . count($cacheResult['errors']) . '</small>';
        }
        $content .= '</div>';
        $content .= '<a href="?" class="back-link">' . __('back') . '</a>';
        echo renderPage(__('cache_cleared'), $content);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
        $ref = $_POST['ref'] ?? '';
        $refType = $_POST['ref_type'] ?? 'branch';
        if (empty($ref)) throw new RuntimeException(__('no_ref_specified'));
        
        $cleanResult = cleanTargetDirectory($targetDir, $whitelistFolders, $whitelistFiles);
        
        $zipContent = $client->downloadArchive($repository, $ref, $refType);
        $result = extractZip($zipContent, $targetDir, $excludeFolders, $excludeFiles, $whitelistFolders, $whitelistFiles);
        
        $extractedCount = count($result['extracted']);
        $skippedFilesCount = count($result['skipped_files']);
        $skippedFoldersCount = count($result['skipped_folders']);
        $preservedCount = count($cleanResult['preserved']);
        
        $content = '<div class="success">' . __('installation_successful') . '<br>';
        $content .= __('files_extracted', ['count' => $extractedCount, 'dir' => htmlspecialchars($targetDir)]);
        if ($preservedCount > 0) {
            $content .= '<br>' . __('preserved_files', ['count' => $preservedCount]);
        }
        if ($skippedFilesCount > 0 || $skippedFoldersCount > 0) {
            $content .= '<br><small>' . __('skipped', ['folders' => $skippedFoldersCount, 'files' => $skippedFilesCount]) . '</small>';
        }
        $content .= '</div>';
        
        if ($preservedCount > 0) {
            $content .= '<div class="warning"><strong>' . __('preserved_list_title') . '</strong><ul class="file-list">';
            foreach (array_slice($cleanResult['preserved'], 0, 20) as $item) {
                $content .= '<li>' . htmlspecialchars($item) . '</li>';
            }
            if ($preservedCount > 20) {
                $content .= '<li><em>' . __('and_more', ['count' => ($preservedCount - 20)]) . '</em></li>';
            }
            $content .= '</ul></div>';
        }
        
        $content .= '<a href="?" class="back-link">' . __('back') . '</a><h3>' . __('installed_files') . '</h3><ul class="file-list">';
        foreach (array_slice($result['extracted'], 0, 50) as $file) {
            $content .= '<li>' . htmlspecialchars($file) . '</li>';
        }
        if ($extractedCount > 50) {
            $content .= '<li><em>' . __('and_more', ['count' => ($extractedCount - 50)]) . '</em></li>';
        }
        $content .= '</ul>';
        echo renderPage(__('installation_successful'), $content);
        exit;
    }
    
    $branches = $client->getBranches($repository);
    $tags = $client->getTags($repository);
    
    $branchHtml = '';
    foreach ($branches as $branch) {
        $name = htmlspecialchars($branch['name']);
        $sha = substr($branch['commit'], 0, 7);
        $branchHtml .= '<li><span><span class="branch-name">' . $name . '</span><span class="commit-sha">' . $sha . '</span></span>';
        $branchHtml .= '<form method="post" style="display:inline"><input type="hidden" name="ref" value="' . $name . '"><input type="hidden" name="ref_type" value="branch"><button type="submit" name="install" class="btn">' . __('install') . '</button></form></li>';
    }
    
    $tagHtml = '';
    foreach ($tags as $tag) {
        $name = htmlspecialchars($tag['name']);
        $sha = substr($tag['commit'], 0, 7);
        $tagHtml .= '<li><span><span class="tag-name">' . $name . '</span><span class="commit-sha">' . $sha . '</span></span>';
        $tagHtml .= '<form method="post" style="display:inline"><input type="hidden" name="ref" value="' . $name . '"><input type="hidden" name="ref_type" value="tag"><button type="submit" name="install" class="btn btn-secondary">' . __('install') . '</button></form></li>';
    }
    
    if (empty($branches)) $branchHtml = '<li><em>' . __('no_branches_found') . '</em></li>';
    if (empty($tags)) $tagHtml = '<li><em>' . __('no_tags_found') . '</em></li>';
    
    $content = '<div class="repo-info"><strong>' . __('repository') . ':</strong> <code>' . htmlspecialchars($repository) . '</code><br><strong>' . __('target_directory') . ':</strong> <code>' . htmlspecialchars($targetDir) . '</code>';
    if (!empty($whitelistFolders) || !empty($whitelistFiles)) {
        $content .= '<br><strong>' . __('whitelist_active') . ':</strong> ';
        $wlItems = array_merge($whitelistFolders, $whitelistFiles);
        $content .= htmlspecialchars(implode(', ', array_slice($wlItems, 0, 5)));
        if (count($wlItems) > 5) $content .= ' ...';
    }
    $content .= '</div>';
    
    $text_confirm_clear_cache = __('confirm_clear_cache');
    $content .= '<form method="post" style="margin-bottom:20px"><button type="submit" name="clear_cache" class="btn btn-secondary" onclick="return confirm(\'' . $text_confirm_clear_cache . '\')">' . __('clear_cache') . '</button></form>';
    $content .= '<div class="tabs"><button class="tab active" onclick="showTab(\'branches\')">' . __('branches') . ' (' . count($branches) . ')</button><button class="tab" onclick="showTab(\'tags\')">' . __('tags') . ' (' . count($tags) . ')</button></div>';
    $content .= '<div id="branches" class="tab-content active"><ul class="branch-list">' . $branchHtml . '</ul></div>';
    $content .= '<div id="tags" class="tab-content"><ul class="tag-list">' . $tagHtml . '</ul></div>';
    $content .= '<script>function showTab(t){document.querySelectorAll(".tab-content").forEach(e=>e.classList.remove("active"));document.querySelectorAll(".tab").forEach(e=>e.classList.remove("active"));document.getElementById(t).classList.add("active");event.target.classList.add("active");}</script>';
    
    $envPath = rtrim($targetDir, '/') . '/.env.local';
    $hasPassword = !empty($config['password'] ?? '');
    echo renderPage(__('title'), $content, null, $envPath, $hasPassword);
} catch (Exception $e) {
    $hasPassword = !empty($config['password'] ?? '');
    echo renderPage(__('error'), '<p>' . __('error_occurred') . '</p>', $e->getMessage(), null, $hasPassword);
}
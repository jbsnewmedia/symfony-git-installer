<?php

declare(strict_types=1);

final class InstallerApplication
{
    public function run(): void
    {
        global $lang, $availableLangs;

        $srcRoot = dirname(__DIR__);
        $projectRoot = dirname($srcRoot);
        $configPath = $srcRoot.'/config.php';
        $loadedConfig = file_exists($configPath) ? require $configPath : require $srcRoot.'/config.example.php';
        if (!is_array($loadedConfig)) {
            $loadedConfig = [];
        }
        /** @var array<string, mixed> $config */
        $config = [];
        foreach ($loadedConfig as $k => $v) {
            if (is_string($k)) {
                $config[$k] = $v;
            } elseif (is_int($k)) {
                $config[(string) $k] = $v;
            }
        }

        $langDir = $srcRoot.'/lang/';
        $foundLangs = glob($langDir.'*.php');
        /** @var array<string> $availableLangs */
        $availableLangs = (false !== $foundLangs) ? array_map(fn ($f) => basename((string) $f, '.php'), $foundLangs) : [];
        $defaultLang = '';
        if (isset($config['default_language']) && is_scalar($config['default_language'])) {
            $defaultLang = (string) $config['default_language'];
        }
        if ('' === $defaultLang) {
            $defaultLang = 'en';
        }

        if (!isset($_SESSION['lang']) || !is_string($_SESSION['lang'])) {
            $_SESSION['lang'] = $defaultLang;
        }
        $getLangStr = '';
        if (isset($_GET['lang']) && (is_string($_GET['lang']) || is_int($_GET['lang']))) {
            $getLangStr = (string) $_GET['lang'];
        }
        if ('' !== $getLangStr) {
            if (in_array($getLangStr, $availableLangs, true)) {
                $_SESSION['lang'] = $getLangStr;
                $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
                if (!is_string($requestUri)) {
                    $requestUri = '/';
                }
                $cleanUrl = strtok($requestUri, '?');
                $params = $_GET;
                unset($params['lang']);
                if (!empty($params)) {
                    $cleanUrl .= '?'.http_build_query($params);
                }
                header('Location: '.$cleanUrl);

                return;
            }
        }

        $sessionLang = 'en';
        if (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) {
            $sessionLang = $_SESSION['lang'];
        }
        $langFile = $langDir.$sessionLang.'.php';
        $loadedLang = file_exists($langFile) ? require $langFile : [];
        if (!is_array($loadedLang)) {
            $loadedLang = [];
        }
        /** @var array<string, string> $lang */
        $lang = [];
        foreach ($loadedLang as $k => $v) {
            $kStr = (string) $k;
            if (is_scalar($v)) {
                $vStr = (string) $v;
                $lang[$kStr] = $vStr;
            }
        }

        try {
            $repository = '';
            if (isset($config['repository']) && (is_string($config['repository']) || is_int($config['repository']))) {
                $repository = (string) $config['repository'];
            }
            if ('' === $repository) {
                throw new RuntimeException(__('repository_not_configured'));
            }

            $token = '';
            if (isset($config['github_token']) && (is_string($config['github_token']) || is_int($config['github_token']))) {
                $token = (string) $config['github_token'];
            }

            $apiBaseUrl = '';
            if (isset($config['api_base_url']) && (is_string($config['api_base_url']) || is_int($config['api_base_url']))) {
                $apiBaseUrl = (string) $config['api_base_url'];
            }
            if ('' === $apiBaseUrl) {
                $apiBaseUrl = 'https://api.github.com';
            }

            $targetDirRelative = '';
            if (isset($config['target_directory']) && (is_string($config['target_directory']) || is_int($config['target_directory']))) {
                $targetDirRelative = (string) $config['target_directory'];
            }
            if ('' === $targetDirRelative) {
                $targetDirRelative = '../';
            }

            $showVersionsBeforeLogin = (bool) ($config['show_versions_before_login'] ?? false);

            $currentProjectVersion = 'unknown';
            if (isset($config['project_version']) && (is_string($config['project_version']) || is_int($config['project_version']))) {
                $currentProjectVersion = (string) $config['project_version'];
            }

            $rawExcludeFolders = $config['exclude_folders'] ?? [];
            /** @var array<string> $excludeFolders */
            $excludeFolders = [];
            if (is_array($rawExcludeFolders)) {
                foreach ($rawExcludeFolders as $val) {
                    $valStr = (is_scalar($val)) ? (string) $val : '';
                    if ('' !== $valStr) {
                        $excludeFolders[] = $valStr;
                    }
                }
            }

            $rawExcludeFiles = $config['exclude_files'] ?? [];
            /** @var array<string> $excludeFiles */
            $excludeFiles = [];
            if (is_array($rawExcludeFiles)) {
                foreach ($rawExcludeFiles as $val) {
                    $valStr = (is_scalar($val)) ? (string) $val : '';
                    if ('' !== $valStr) {
                        $excludeFiles[] = $valStr;
                    }
                }
            }

            $rawWhitelistFolders = $config['whitelist_folders'] ?? [];
            /** @var array<string> $whitelistFolders */
            $whitelistFolders = [];
            if (is_array($rawWhitelistFolders)) {
                foreach ($rawWhitelistFolders as $val) {
                    $valStr = (is_scalar($val)) ? (string) $val : '';
                    if ('' !== $valStr) {
                        $whitelistFolders[] = $valStr;
                    }
                }
            }

            $rawWhitelistFiles = $config['whitelist_files'] ?? [];
            /** @var array<string> $whitelistFiles */
            $whitelistFiles = [];
            if (is_array($rawWhitelistFiles)) {
                foreach ($rawWhitelistFiles as $val) {
                    $valStr = (is_scalar($val)) ? (string) $val : '';
                    if ('' !== $valStr) {
                        $whitelistFiles[] = $valStr;
                    }
                }
            }

            $installerRepo = 'jbsnewmedia/symfony-git-installer';
            if (isset($config['installer_repository']) && is_scalar($config['installer_repository'])) {
                $installerRepo = (string) $config['installer_repository'];
            }

            $targetDir = realpath($srcRoot.'/'.$targetDirRelative);
            if (false === $targetDir) {
                $absoluteTarget = $srcRoot.'/'.$targetDirRelative;
                if (!is_dir($absoluteTarget)) {
                    if (!createDirectoryTree($absoluteTarget, 0o755)) {
                        throw new RuntimeException('Target directory cannot be created: '.$absoluteTarget);
                    }
                }
                $targetDir = realpath($absoluteTarget);
                if (false === $targetDir) {
                    throw new RuntimeException('Target directory cannot be resolved: '.$absoluteTarget);
                }
            }

            $targetDirStr = (string) $targetDir;
            /** @var non-empty-string $targetDirFinal */
            $targetDirFinal = (strlen($targetDirStr) > 0) ? $targetDirStr : '.';
            $targetDirStr = $targetDirFinal;

            $configuredLogDir = '';
            if (isset($config['log_directory']) && is_scalar($config['log_directory'])) {
                $configuredLogDir = trim((string) $config['log_directory']);
            }
            $logContext = installerLogBaseDirectory($projectRoot, $configuredLogDir);

            $githubCacheDir = $projectRoot.'/var/cache/github-api';
            $versionProbeClient = new GitHubClient($apiBaseUrl, $token);
            $tags = getCachedGitHubRepositoryRefs($versionProbeClient, $repository, $githubCacheDir)['tags'];

            $currentInstallerVersion = resolveInstallerVersion($config, $tags);

            /** @var array<string, mixed> $configForAuth */
            $configForAuth = $config;
            /** @var array<string, mixed> $metaForAuth */
            $metaForAuth = [
                'installer_version' => (string) $currentInstallerVersion,
                'project_version' => (string) $currentProjectVersion,
            ];
            $authResult = evaluateAuthentication(
                $configForAuth,
                $showVersionsBeforeLogin,
                $metaForAuth
            );

            if ('login-failed' === $authResult['outcome'] || 'show-form' === $authResult['outcome']) {
                renderLoginForm($authResult['error'], $authResult['version_meta']);

                return;
            }
            if ('login-ok' === $authResult['outcome'] || 'logged-out' === $authResult['outcome']) {
                header('Location: ?');

                return;
            }

            $client = new GitHubClient($apiBaseUrl, $token, $currentInstallerVersion);
            $installUuidManager = new InstallUuidManager();
            $appSecretManager = new AppSecretManager();
            $envPath = rtrim($targetDirFinal, '/').'/.env.local';

            global $lang;
            /** @var array<string, string> $langForGlobal */
            $langForGlobal = (isset($lang) && is_array($lang)) ? $lang : [];
            $dashboardState = resolveDashboardState($_GET['view'] ?? null, $_GET['itab'] ?? null, $_POST['view'] ?? null, $_POST['itab'] ?? null);
            $dashboardBackHref = buildDashboardViewHref($dashboardState['view'], $dashboardState['itab']);
            $hasPassword = !empty($config['password'] ?? '');

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['save_env'])) {
                $newEnv = 'prod';
                if (isset($_POST['app_env']) && is_scalar($_POST['app_env'])) {
                    $newEnv = (string) $_POST['app_env'];
                }
                $newDb = 'DB1';
                if (isset($_POST['database']) && is_scalar($_POST['database'])) {
                    $newDb = (string) $_POST['database'];
                }

                if (updateEnvLocal($envPath, $newEnv, $newDb)) {
                    $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                    $appSecretManager->ensureEnvLocalAppSecret($envPath);
                    $content = '<div class="success">'.resolveLangKey('config_saved', $langForGlobal).'<br>';
                    $content .= '<strong>'.resolveLangKey('mode', $langForGlobal).':</strong> '.htmlspecialchars($newEnv).'<br>';
                    $content .= '<strong>'.resolveLangKey('database', $langForGlobal).':</strong> '.htmlspecialchars($newDb).'</div>';
                    echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, $hasPassword, $dashboardState['view'], $content);

                    return;
                }
                $dashboardNotice = '<div class="error">Error saving .env.local</div>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, $hasPassword, $dashboardState['view'], $dashboardNotice);

                return;
            }

            $installUuid = $installUuidManager->ensureEnvLocalInstallUuid($envPath);
            $appSecretManager->ensureEnvLocalAppSecret($envPath);

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['save_env_content'])) {
                $newContent = '';
                if (isset($_POST['env_content']) && is_scalar($_POST['env_content'])) {
                    $newContent = (string) $_POST['env_content'];
                }

                $newEnv = 'prod';
                if (isset($_POST['app_env']) && is_scalar($_POST['app_env'])) {
                    $candidateEnv = strtolower(trim((string) $_POST['app_env']));
                    if (in_array($candidateEnv, ['dev', 'prod'], true)) {
                        $newEnv = $candidateEnv;
                    }
                }

                $appSecretInput = '';
                if (isset($_POST['app_secret']) && is_scalar($_POST['app_secret'])) {
                    $appSecretInput = trim((string) $_POST['app_secret']);
                }

                $appSecretGenerated = false;
                if ('' === $appSecretInput) {
                    $appSecretInput = bin2hex(random_bytes(16));
                    $appSecretGenerated = true;
                } elseif (1 !== preg_match('/^[A-Za-z0-9._-]{16,128}$/', $appSecretInput)) {
                    throw new RuntimeException(resolveLangKey('app_secret_invalid', $langForGlobal));
                }

                $newContent = setEnvLocalValue($newContent, 'APP_ENV', $newEnv);
                $newContent = setEnvLocalValue($newContent, 'APP_SECRET', $appSecretInput);

                if (saveEnvLocalContent($envPath, $newContent)) {
                    $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                    $content = '<div class="success">'.resolveLangKey('env_file_saved', $langForGlobal).'<br>';
                    $content .= '<strong>'.resolveLangKey('mode', $langForGlobal).':</strong> '.htmlspecialchars($newEnv).'<br>';
                    $content .= '<strong>'.resolveLangKey('app_secret', $langForGlobal).':</strong> '.htmlspecialchars($appSecretInput);
                    if ($appSecretGenerated) {
                        $content .= ' <em>('.resolveLangKey('app_secret_generated', $langForGlobal).')</em>';
                    }
                    $content .= '</div>';
                    echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, $hasPassword, $dashboardState['view'], $content);

                    return;
                }

                throw new RuntimeException(resolveLangKey('env_file_save_failed', $langForGlobal));
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['save_install_uuid'])) {
                $newInstallUuid = '';
                if (isset($_POST['install_uuid']) && is_scalar($_POST['install_uuid'])) {
                    $newInstallUuid = (string) $_POST['install_uuid'];
                }

                if (updateInstallUuidInEnvLocal($installUuidManager, $envPath, $newInstallUuid)) {
                    $content = '<div class="success">'.resolveLangKey('install_uuid_saved', $langForGlobal).'<br>';
                    $content .= '<strong>'.resolveLangKey('install_uuid', $langForGlobal).':</strong> '.htmlspecialchars(strtolower(trim($newInstallUuid))).'</div>';
                    echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, $hasPassword, 'install-uuid', $content);

                    return;
                }

                throw new RuntimeException(resolveLangKey('install_uuid_invalid', $langForGlobal));
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['regenerate_install_uuid'])) {
                $newInstallUuid = $installUuidManager->ensureEnvLocalInstallUuid($envPath, true);

                $content = '<div class="success">'.resolveLangKey('install_uuid_saved', $langForGlobal).'<br>';
                $content .= '<strong>'.resolveLangKey('install_uuid', $langForGlobal).':</strong> '.htmlspecialchars($newInstallUuid).'</div>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, $hasPassword, 'install-uuid', $content);

                return;
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['regenerate_app_secret'])) {
                $newAppSecret = $appSecretManager->ensureEnvLocalAppSecret($envPath, true);

                $content = '<div class="success">'.resolveLangKey('app_secret_saved', $langForGlobal).'<br>';
                $content .= '<strong>'.resolveLangKey('app_secret', $langForGlobal).':</strong> '.htmlspecialchars($newAppSecret).'</div>';
                echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, $hasPassword, 'environment', $content);

                return;
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['add_database'])) {
                $dbId = '';
                if (isset($_POST['db_id']) && is_scalar($_POST['db_id'])) {
                    $dbId = (string) $_POST['db_id'];
                }
                $dbUrl = '';
                if (isset($_POST['db_url']) && is_scalar($_POST['db_url'])) {
                    $dbUrl = (string) $_POST['db_url'];
                }

                if (addDatabaseToEnvLocal($envPath, $dbId, $dbUrl)) {
                    $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                    $appSecretManager->ensureEnvLocalAppSecret($envPath);
                    $content = '<div class="success">'.resolveLangKey('database_added', $langForGlobal, ['id' => htmlspecialchars($dbId)]).'</div>';
                    echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, $hasPassword, 'databases', $content);

                    return;
                }

                throw new RuntimeException(resolveLangKey('database_add_failed', $langForGlobal));
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['remove_database'])) {
                $removeDbId = '';
                if (isset($_POST['remove_db_id']) && is_scalar($_POST['remove_db_id'])) {
                    $removeDbId = (string) $_POST['remove_db_id'];
                }

                if (removeDatabaseFromEnvLocal($envPath, $removeDbId)) {
                    $installUuidManager->ensureEnvLocalInstallUuid($envPath);
                    $appSecretManager->ensureEnvLocalAppSecret($envPath);
                    $content = '<div class="success">'.resolveLangKey('database_removed', $langForGlobal, ['id' => htmlspecialchars($removeDbId)]).'</div>';
                    echo renderPage(resolveLangKey('configuration', $langForGlobal), '', null, $envPath, $hasPassword, 'databases', $content);

                    return;
                }

                throw new RuntimeException(resolveLangKey('database_remove_failed', $langForGlobal));
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['run_migrations'])) {
                $console = rtrim($targetDirFinal, '/').'/bin/console';
                $cmd = 'php '.escapeshellarg($console).' doctrine:migrations:migrate --no-interaction 2>&1';
                $output = shell_exec($cmd);
                $exitOk = null === $output || '' === $output;

                if ($exitOk) {
                    $content = '<div class="success">'.resolveLangKey('migrations_run_successfully', $langForGlobal).'</div>';
                } else {
                    $firstLine = trim((string) strtok((string) $output, "\n"));
                    $content = '<div class="error">'.resolveLangKey('migrations_run_failed', $langForGlobal).'<br><small>'.htmlspecialchars($firstLine).'</small></div>';
                }
                $content .= '<h3>'.resolveLangKey('migrations_output', $langForGlobal).'</h3>';
                $content .= '<pre>'.htmlspecialchars(trim((string) $output)).'</pre>';
                echo renderPage(resolveLangKey('run_migrations', $langForGlobal), '', null, $envPath, $hasPassword, 'databases', $content);

                return;
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['clear_cache'])) {
                $cacheDir = rtrim($targetDirFinal, '/').'/var';
                $cacheResult = clearCacheDirectory($cacheDir);

                $errorsCount = (int) count($cacheResult['errors']);
                $content = '<div class="success">'.resolveLangKey('cache_cleared', $langForGlobal).'<br>';
                $content .= resolveLangKey('files_deleted', $langForGlobal, ['count' => (int) $cacheResult['deleted_count'], 'dir' => htmlspecialchars($cacheDir)]);
                if ($errorsCount > 0) {
                    $content .= '<br><small>'.resolveLangKey('errors', $langForGlobal).': '.$errorsCount.'</small>';
                }
                $content .= '</div>';
                echo renderPage(resolveLangKey('cache_cleared', $langForGlobal), '', null, $envPath, $hasPassword, $dashboardState['view'], $content);

                return;
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['self_update'])) {
                $tag = '';
                if (isset($_POST['ref']) && is_scalar($_POST['ref'])) {
                    $tag = trim((string) $_POST['ref']);
                }
                if ('' === $tag) {
                    throw new RuntimeException(resolveLangKey('no_ref_specified', $langForGlobal));
                }

                $installerRefs = getCachedGitHubRepositoryRefs($client, $installerRepo, $githubCacheDir);
                $instTagNames = array_map(static fn (array $t): string => $t['name'], $installerRefs['tags']);
                $instBranchNames = array_map(static fn (array $b): string => $b['name'], $installerRefs['branches']);
                $allRefs = array_merge($instTagNames, $instBranchNames);

                if (!in_array($tag, $allRefs, true)) {
                    throw new RuntimeException(resolveLangKey('tag_not_found', $langForGlobal));
                }

                $updaterSourcePath = 'src';
                if (isset($config['updater_source_path']) && is_scalar($config['updater_source_path'])) {
                    $updaterSourcePath = (string) $config['updater_source_path'];
                }

                $refCommit = '';
                if (isset($_POST['ref_commit']) && is_scalar($_POST['ref_commit'])) {
                    $refCommit = trim((string) $_POST['ref_commit']);
                }

                $selfUpdateResult = updateUpdaterFromTag($client, $installerRepo, $tag, $updaterSourcePath, $srcRoot);
                $updatedCount = (int) count($selfUpdateResult['updated_files']);

                writeConfigValues($configPath, [
                    'installer_version' => (string) $tag,
                    'installer_commit' => $refCommit,
                ]);

                $content = '<div class="success">'.resolveLangKey('updater_updated', $langForGlobal, ['tag' => htmlspecialchars($tag)]).'<br>';
                $content .= resolveLangKey('files_updated', $langForGlobal, ['count' => $updatedCount]).'</div>';

                if ($updatedCount > 0) {
                    $content .= '<article class="home-card updates-section-card">'
                        .'<div class="home-card-header home-card-header--static">'
                        .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.lucideIcon('archive', 14).'</span>'.htmlspecialchars((string) resolveLangKey('updated_files', $langForGlobal)).'</span>'
                        .'</div>'
                        .'<ul class="file-list file-list--in-card">';
                    /** @var array<string> $updatedFilesList */
                    $updatedFilesList = $selfUpdateResult['updated_files'];
                    foreach ($updatedFilesList as $file) {
                        $content .= '<li>'.htmlspecialchars($file).'</li>';
                    }
                    $content .= '</ul></article>';
                }

                $content .= '<a href="'.htmlspecialchars((string) $dashboardBackHref).'" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                echo renderPage(resolveLangKey('title', $langForGlobal), $content, null, $envPath, $hasPassword, 'installer');

                return;
            }

            if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['install'])) {
                $ref = '';
                if (isset($_POST['ref']) && is_scalar($_POST['ref'])) {
                    $ref = (string) $_POST['ref'];
                }
                $refType = 'branch';
                if (isset($_POST['ref_type']) && is_scalar($_POST['ref_type'])) {
                    $refType = (string) $_POST['ref_type'];
                }
                if ('' === $ref) {
                    throw new RuntimeException(resolveLangKey('no_ref_specified', $langForGlobal));
                }

                writeConfigValues($configPath, [
                    'installer_version' => (string) $currentInstallerVersion,
                    'project_version' => (string) $ref,
                ]);

                logInstallerEvent($logContext, 'info', 'Starting install', [
                    'ref' => $ref,
                    'ref_type' => $refType,
                    'target_dir' => (string) $targetDirFinal,
                ]);

                $cleanResult = cleanTargetDirectory((string) $targetDirStr, $whitelistFolders, $whitelistFiles);
                $cleanFailedCount = count($cleanResult['failed'] ?? []);

                $zipContent = $client->downloadArchive($repository, $ref, $refType);
                $extractZipResult = extractZip($zipContent, (string) $targetDirStr, $excludeFolders, $excludeFiles, $whitelistFolders, $whitelistFiles);

                $extractedCount = count($extractZipResult['extracted']);
                $skippedFilesCount = count($extractZipResult['skipped_files']);
                $skippedFoldersCount = count($extractZipResult['skipped_folders']);
                $preservedCount = count($cleanResult['preserved']);

                logInstallerEvent($logContext, 'info', 'Install completed', [
                    'ref' => $ref,
                    'ref_type' => $refType,
                    'extracted' => $extractedCount,
                    'skipped_files' => $skippedFilesCount,
                    'skipped_folders' => $skippedFoldersCount,
                    'preserved' => $preservedCount,
                    'cleanup_failures' => $cleanFailedCount,
                ]);

                $content = '<div class="success">'.resolveLangKey('installation_successful', $langForGlobal).'<br>';
                $content .= resolveLangKey('files_extracted', $langForGlobal, ['count' => $extractedCount, 'dir' => htmlspecialchars((string) $targetDirStr)]);
                if ($preservedCount > 0) {
                    $content .= '<br>'.resolveLangKey('preserved_files', $langForGlobal, ['count' => $preservedCount]);
                }
                if ($skippedFilesCount > 0 || $skippedFoldersCount > 0) {
                    $content .= '<br><small>'.resolveLangKey('skipped', $langForGlobal, ['folders' => $skippedFoldersCount, 'files' => $skippedFilesCount]).'</small>';
                }
                $content .= '</div>';

                if ($preservedCount > 0) {
                    $content .= '<div class="warning"><strong>'.resolveLangKey('preserved_list_title', $langForGlobal).'</strong><ul class="file-list">';
                    foreach (array_slice($cleanResult['preserved'], 0, 20) as $item) {
                        $content .= '<li>'.htmlspecialchars((string) $item).'</li>';
                    }
                    if ($preservedCount > 20) {
                        $content .= '<li><em>'.resolveLangKey('and_more', $langForGlobal, ['count' => ($preservedCount - 20)]).'</em></li>';
                    }
                    $content .= '</ul></div>';
                }

                if ($cleanFailedCount > 0) {
                    $content .= '<div class="warning"><strong>'.resolveLangKey('cleanup_warnings_title', $langForGlobal).'</strong>';
                    $content .= '<p><small>'.resolveLangKey('cleanup_warnings_help', $langForGlobal, ['path' => htmlspecialchars(installerLogPath($logContext))]).'</small></p>';
                    $content .= '<ul class="file-list">';
                    foreach (array_slice($cleanResult['failed'], 0, 20) as $item) {
                        $content .= '<li><code>'.htmlspecialchars((string) $item).'</code></li>';
                    }
                    if ($cleanFailedCount > 20) {
                        $content .= '<li><em>'.resolveLangKey('and_more', $langForGlobal, ['count' => ($cleanFailedCount - 20)]).'</em></li>';
                    }
                    $content .= '</ul></div>';
                }

                $content .= '<a href="'.htmlspecialchars((string) $dashboardBackHref).'" class="back-link">'.resolveLangKey('back', $langForGlobal).'</a>';
                $content .= '<h3>'.resolveLangKey('installed_files', $langForGlobal).'</h3><ul class="file-list">';
                /** @var array<string> $extractedFiles */
                $extractedFiles = $extractZipResult['extracted'];
                $slice = array_slice($extractedFiles, 0, 50);
                foreach ($slice as $file) {
                    $content .= '<li>'.htmlspecialchars($file).'</li>';
                }
                if ($extractedCount > 50) {
                    $content .= '<li><em>'.resolveLangKey('and_more', $langForGlobal, ['count' => ($extractedCount - 50)]).'</em></li>';
                }
                $content .= '</ul>';
                echo renderPage(resolveLangKey('installation_successful', $langForGlobal), $content, null, $envPath, $hasPassword, $dashboardState['view']);

                return;
            }

            $projectRefs = getCachedGitHubRepositoryRefs($client, $repository, $githubCacheDir);
            $branches = $projectRefs['branches'];
            $tags = $projectRefs['tags'];

            $view = resolveDashboardView($_GET['view'] ?? null);

            if ('installer' === $view) {
                $installerRefs = getCachedGitHubRepositoryRefs($client, $installerRepo, $githubCacheDir);
                $instTags = $installerRefs['tags'];
                $instBranches = $installerRefs['branches'];
                $itab = resolveInstallerTab($_GET['itab'] ?? null);

                $installerConfirmAttr = renderConfirmAttributes(
                    resolveLangKey('install', $langForGlobal),
                    resolveLangKey('confirm_install_installer', $langForGlobal),
                    resolveLangKey('install', $langForGlobal),
                );

                $instBranchHtml = '';
                foreach ($instBranches as $branch) {
                    $bName = (isset($branch['name'])) ? (string) $branch['name'] : '';
                    $bCommit = (isset($branch['commit'])) ? (string) $branch['commit'] : '';
                    $bCommitShort = substr($bCommit, 0, 7);
                    $instBranchHtml .= '<li><span class="ref-item-info"><span class="branch-name">'.htmlspecialchars($bName).'</span>'
                        .'<span class="commit-sha">'.htmlspecialchars($bCommitShort).'</span></span>';
                    $instBranchHtml .= '<form method="post" class="install-form"'.$installerConfirmAttr.'><input type="hidden" name="self_update" value="1"><input type="hidden" name="ref" value="'.htmlspecialchars($bName).'"><input type="hidden" name="ref_commit" value="'.htmlspecialchars($bCommit).'"><input type="hidden" name="ref_type" value="branch"><button type="submit" name="self_update" class="btn">'.lucideIcon('download', 15).' '.resolveLangKey('install', $langForGlobal).'</button></form></li>';
                }

                $instTagHtml = '';
                foreach ($instTags as $tag) {
                    $tName = (isset($tag['name'])) ? (string) $tag['name'] : '';
                    $tCommit = (isset($tag['commit'])) ? (string) $tag['commit'] : '';
                    $tCommitShort = substr($tCommit, 0, 7);
                    $instTagHtml .= '<li><span class="ref-item-info"><span class="tag-name">'.htmlspecialchars($tName).'</span><span class="commit-sha">'.htmlspecialchars($tCommitShort).'</span></span>';
                    $instTagHtml .= '<form method="post" class="install-form"'.$installerConfirmAttr.'><input type="hidden" name="self_update" value="1"><input type="hidden" name="ref" value="'.htmlspecialchars($tName).'"><input type="hidden" name="ref_commit" value="'.htmlspecialchars($tCommit).'"><button type="submit" name="self_update" class="btn">'.lucideIcon('download', 15).' '.resolveLangKey('install', $langForGlobal).'</button></form></li>';
                }

                $branchesActiveClass = ('tags' === $itab) ? '' : ' active';
                $tagsActiveClass = ('tags' === $itab) ? ' active' : '';

                $installerHeaderCard = '<div class="home-card updates-header-card">'
                    .'<div class="updates-meta">'
                    .'<div class="updates-meta-row"><span class="updates-meta-label">'.resolveLangKey('updater_version', $langForGlobal).':</span><span class="updates-meta-value"><code>'.htmlspecialchars($currentInstallerVersion).'</code></span></div>'
                    .'<div class="updates-meta-row"><span class="updates-meta-label">'.resolveLangKey('installer_repository', $langForGlobal).':</span><span class="updates-meta-value"><code>'.htmlspecialchars($installerRepo).'</code></span></div>'
                    .'</div>'
                    .'</div>';

                $tabsHtml = '<div class="tabs installer-tabs">'
                    .'<a class="tab'.$branchesActiveClass.'" href="?view=installer&itab=branches">'.lucideIcon('git-branch', 15).' '.resolveLangKey('branches', $langForGlobal).' ('.count($instBranches).')</a>'
                    .'<a class="tab'.$tagsActiveClass.'" href="?view=installer&itab=tags">'.lucideIcon('tag', 15).' '.resolveLangKey('tags', $langForGlobal).' ('.count($instTags).')</a>'
                    .'</div>';

                if ('tags' === $itab) {
                    $sectionIcon = 'tag';
                    $sectionTitle = resolveLangKey('tags', $langForGlobal);
                    $isEmpty = [] === $instTags;
                    $emptyText = resolveLangKey('no_tags_found', $langForGlobal);
                    $listBody = '<ul class="tag-list">'.$instTagHtml.'</ul>';
                } else {
                    $sectionIcon = 'git-branch';
                    $sectionTitle = resolveLangKey('branches', $langForGlobal);
                    $isEmpty = [] === $instBranches;
                    $emptyText = resolveLangKey('no_branches_found', $langForGlobal);
                    $listBody = '<ul class="branch-list">'.$instBranchHtml.'</ul>';
                }

                $sectionCard = '<article class="home-card updates-section-card">'
                    .'<div class="home-card-header home-card-header--static">'
                    .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.lucideIcon($sectionIcon, 14).'</span>'.htmlspecialchars($sectionTitle).'</span>'
                    .'</div>'
                    .'<div class="updates-list">'
                    .($isEmpty ? '<p class="updates-empty">'.htmlspecialchars($emptyText).'</p>' : $listBody)
                    .'</div>'
                    .'</article>';

                $content = '<div class="home-stack">'.$installerHeaderCard.$tabsHtml.$sectionCard.'</div>';

                echo renderPage(resolveLangKey('installer_management', $langForGlobal), $content, null, $envPath, $hasPassword, 'installer');

                return;
            }

            if ('system' === $view) {
                $cacheDirPath = rtrim($targetDirStr, '/').'/var';
                $cacheSizeBytes = getDirectorySize($cacheDirPath);
                $textClearCache = resolveLangKey('clear_cache', $langForGlobal);
                $textConfirmClearCache = resolveLangKey('confirm_clear_cache', $langForGlobal);
                $textCacheSize = resolveLangKey('cache_size', $langForGlobal);
                $clearCacheConfirmAttr = renderConfirmAttributes($textClearCache, $textConfirmClearCache, $textClearCache);
                $trashIcon = lucideIcon('trash-2', 16);
                $cacheItemActionHtml = '<form method="post" class="info-list-action-form"'.$clearCacheConfirmAttr.'>'
                    .'<input type="hidden" name="view" value="system">'
                    .'<button type="submit" name="clear_cache" class="info-list-action info-list-action--danger" title="'.htmlspecialchars($textClearCache).'" aria-label="'.htmlspecialchars($textClearCache).'">'.$trashIcon.'</button>'
                    .'</form>';

                $phpVersion = PHP_VERSION;
                $uploadMaxFilesize = (string) ini_get('upload_max_filesize');
                $postMaxSize = (string) ini_get('post_max_size');
                $maxExecutionTime = (string) ini_get('max_execution_time');
                $memoryLimit = (string) ini_get('memory_limit');

                $homeInfoItems = [
                    [
                        'icon' => 'endpoint',
                        'label' => resolveLangKey('repository', $langForGlobal),
                        'value' => '<code>'.htmlspecialchars($repository).'</code>',
                    ],
                    [
                        'icon' => 'folder',
                        'label' => resolveLangKey('target_directory', $langForGlobal),
                        'value' => '<code>'.htmlspecialchars($targetDirStr).'</code>',
                    ],
                    [
                        'icon' => 'code',
                        'label' => resolveLangKey('php_version', $langForGlobal),
                        'value' => '<code>'.htmlspecialchars($phpVersion).'</code>',
                    ],
                    [
                        'icon' => 'upload-cloud',
                        'label' => resolveLangKey('upload_limit', $langForGlobal),
                        'value' => '<code>'.htmlspecialchars($uploadMaxFilesize).'</code> · <span class="info-list-meta">'.htmlspecialchars(resolveLangKey('post_max_size', $langForGlobal)).': <code>'.htmlspecialchars($postMaxSize).'</code></span>',
                    ],
                    [
                        'icon' => 'clock',
                        'label' => resolveLangKey('max_execution_time', $langForGlobal),
                        'value' => '<code>'.htmlspecialchars($maxExecutionTime).'</code> <span class="info-list-meta">'.htmlspecialchars(resolveLangKey('seconds', $langForGlobal)).'</span>',
                    ],
                    [
                        'icon' => 'memory-stick',
                        'label' => resolveLangKey('memory_limit', $langForGlobal),
                        'value' => '<code>'.htmlspecialchars($memoryLimit).'</code>',
                    ],
                    [
                        'icon' => 'hard-drive',
                        'label' => $textCacheSize,
                        'value' => '<code>'.htmlspecialchars($cacheDirPath).'</code> · <span class="info-list-meta">'.htmlspecialchars(formatFileSize($cacheSizeBytes)).'</span>',
                        'action_html' => $cacheItemActionHtml,
                    ],
                ];

                $content = '<div class="home-stack">'
                    .renderHomeSections(
                        resolveLangKey('home_section_system', $langForGlobal),
                        $homeInfoItems,
                        [],
                        '',
                        true,
                    )
                    .'</div>';
                echo renderPage(resolveLangKey('title', $langForGlobal), $content, null, $envPath, $hasPassword, 'system');

                return;
            }

            if ('updates' === $view) {
                $iconRefreshCw = lucideIcon('refresh-cw', 16);
                $textClearCache = resolveLangKey('clear_cache', $langForGlobal);
                $textConfirmClearCache = resolveLangKey('confirm_clear_cache', $langForGlobal);
                $clearCacheConfirmAttr = renderConfirmAttributes($textClearCache, $textConfirmClearCache, $textClearCache);
                $clearCacheHtml = '<form method="post" class="updates-refresh-form"'.$clearCacheConfirmAttr.'>'
                    .'<input type="hidden" name="view" value="updates">'
                    .'<button type="submit" name="clear_cache" value="1" class="btn btn-secondary">'.$iconRefreshCw.' '.$textClearCache.'</button>'
                    .'</form>';

                $branchHtml = '';
                foreach ($branches as $branch) {
                    $bName = (isset($branch['name'])) ? (string) $branch['name'] : '';
                    $bCommit = (isset($branch['commit'])) ? (string) $branch['commit'] : '';
                    $bCommitShort = substr($bCommit, 0, 7);
                    $branchHtml .= '<li><span class="ref-item-info"><span class="branch-name">'.htmlspecialchars($bName).'</span>'
                        .'<span class="commit-sha">'.htmlspecialchars($bCommitShort).'</span></span>';
                    $branchHtml .= '<form method="post" class="install-form"><input type="hidden" name="ref" value="'.htmlspecialchars($bName).'"><input type="hidden" name="ref_type" value="branch"><button type="submit" name="install" class="btn">'.lucideIcon('download', 15).' '.resolveLangKey('install', $langForGlobal).'</button></form></li>';
                }

                $tagHtml = '';
                foreach ($tags as $tag) {
                    $tName = (isset($tag['name'])) ? (string) $tag['name'] : '';
                    $tCommit = (isset($tag['commit'])) ? (string) $tag['commit'] : '';
                    $tCommitShort = substr($tCommit, 0, 7);
                    $tagHtml .= '<li><span class="ref-item-info"><span class="tag-name">'.htmlspecialchars($tName).'</span><span class="commit-sha">'.htmlspecialchars($tCommitShort).'</span></span>';
                    $tagHtml .= '<form method="post" class="install-form"><input type="hidden" name="ref" value="'.htmlspecialchars($tName).'"><input type="hidden" name="ref_type" value="tag"><button type="submit" name="install" class="btn btn-secondary">'.lucideIcon('download', 15).' '.resolveLangKey('install', $langForGlobal).'</button></form></li>';
                }

                if (empty($branches)) {
                    $branchHtml = '<li><em>'.resolveLangKey('no_branches_found', $langForGlobal).'</em></li>';
                }
                if (empty($tags)) {
                    $tagHtml = '<li><em>'.resolveLangKey('no_tags_found', $langForGlobal).'</em></li>';
                }

                $branchesActiveClass = ' active';
                $tagsActiveClass = '';

                $headerCard = '<div class="home-card updates-header-card">'
                    .'<div class="updates-meta">'
                    .'<div class="updates-meta-row"><span class="updates-meta-label">'.resolveLangKey('repository', $langForGlobal).':</span><span class="updates-meta-value"><code>'.htmlspecialchars($repository).'</code></span></div>'
                    .'</div>'
                    .$clearCacheHtml
                    .'</div>';

                $branchSection = '<article class="home-card updates-section-card">'
                    .'<div class="home-card-header home-card-header--static">'
                    .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.lucideIcon('git-branch', 14).'</span>'.htmlspecialchars((string) resolveLangKey('branches', $langForGlobal)).' <span class="status-badge">'.count($branches).'</span></span>'
                    .'</div>'
                    .'<div class="updates-list"><ul class="branch-list">'.$branchHtml.'</ul></div>'
                    .'</article>';

                $tagSection = '<article class="home-card updates-section-card">'
                    .'<div class="home-card-header home-card-header--static">'
                    .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.lucideIcon('tag', 14).'</span>'.htmlspecialchars((string) resolveLangKey('tags', $langForGlobal)).' <span class="status-badge">'.count($tags).'</span></span>'
                    .'</div>'
                    .'<div class="updates-list"><ul class="tag-list">'.$tagHtml.'</ul></div>'
                    .'</article>';

                $content = '<div class="home-stack">'.$headerCard.$branchSection.$tagSection.'</div>';
                echo renderPage(resolveLangKey('dashboard_updates', $langForGlobal), $content, null, $envPath, $hasPassword, 'updates');

                return;
            }

            $homeEnvConfig = parseEnvLocal($envPath);
            $homeEnvValue = static function (string $key, string $default = '') use ($homeEnvConfig): string {
                $value = $homeEnvConfig[$key] ?? null;
                if (is_string($value)) {
                    return $value;
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }

                return $default;
            };
            $homeEnvDisplay = static function (string $value, int $maxLen = 0): string {
                if ('' === $value) {
                    return '';
                }
                $shown = $maxLen > 0 && strlen($value) > $maxLen ? substr($value, 0, $maxLen).'…' : $value;

                return '<code>'.htmlspecialchars($shown).'</code>';
            };

            $configureLabel = resolveLangKey('home_configure_item', $langForGlobal);
            $homeSections = [
                [
                    'title' => resolveLangKey('home_section_configuration', $langForGlobal),
                    'icon' => 'settings',
                    'href' => '?view=environment',
                    'items' => [
                        [
                            'icon' => 'settings',
                            'label' => resolveLangKey('mode', $langForGlobal),
                            'value' => $homeEnvDisplay(strtolower($homeEnvValue('app_env', 'prod'))) ?: '<em>'.htmlspecialchars(resolveLangKey('none_installed', $langForGlobal)).'</em>',
                            'action' => '?view=environment',
                            'action_title' => $configureLabel,
                        ],
                        [
                            'icon' => 'shield',
                            'label' => resolveLangKey('app_secret', $langForGlobal),
                            'value' => '' !== $homeEnvValue('app_secret') ? $homeEnvDisplay($homeEnvValue('app_secret'), 8) : '<em>'.htmlspecialchars(resolveLangKey('app_secret_invalid', $langForGlobal)).'</em>',
                            'action' => '?view=environment',
                            'action_title' => $configureLabel,
                        ],
                        [
                            'icon' => 'fingerprint',
                            'label' => resolveLangKey('install_uuid', $langForGlobal),
                            'value' => '' !== $homeEnvValue('install_uuid') ? $homeEnvDisplay($homeEnvValue('install_uuid')) : '<em>'.htmlspecialchars(resolveLangKey('none_installed', $langForGlobal)).'</em>',
                            'action' => '?view=install-uuid',
                            'action_title' => $configureLabel,
                        ],
                        [
                            'icon' => 'database',
                            'label' => resolveLangKey('database', $langForGlobal),
                            'value' => '' !== $homeEnvValue('current_db') ? $homeEnvDisplay($homeEnvValue('current_db')) : '<em>'.htmlspecialchars(resolveLangKey('none_installed', $langForGlobal)).'</em>',
                            'action' => '?view=databases',
                            'action_title' => $configureLabel,
                        ],
                    ],
                ],
                [
                    'title' => resolveLangKey('home_section_installations', $langForGlobal),
                    'icon' => 'download',
                    'href' => '?view=updates',
                    'items' => [
                        [
                            'icon' => 'tag',
                            'label' => resolveLangKey('project_version', $langForGlobal),
                            'value' => formatVersionBadge($currentProjectVersion),
                            'action' => '?view=updates',
                            'action_title' => $configureLabel,
                        ],
                    ],
                ],
                [
                    'title' => resolveLangKey('home_section_installer', $langForGlobal),
                    'icon' => 'wrench',
                    'href' => '?view=installer',
                    'items' => [
                        [
                            'icon' => 'wrench',
                            'label' => resolveLangKey('updater_version', $langForGlobal),
                            'value' => formatVersionBadge($currentInstallerVersion),
                            'action' => '?view=installer',
                            'action_title' => $configureLabel,
                        ],
                    ],
                ],
            ];

            $welcomeBox = renderWelcomeBox(
                resolveLangKey('welcome_title', $langForGlobal),
                resolveLangKey('welcome_subtitle', $langForGlobal),
                [
                    ['label' => resolveLangKey('welcome_link_installations', $langForGlobal), 'href' => '?view=updates', 'icon' => 'download'],
                    ['label' => resolveLangKey('welcome_link_installer', $langForGlobal), 'href' => '?view=installer', 'icon' => 'wrench'],
                    ['label' => resolveLangKey('welcome_link_documentation', $langForGlobal), 'href' => 'https://github.com/jbsnewmedia/symfony-git-installer', 'icon' => 'external-link', 'external' => true],
                ],
                $langForGlobal,
            );

            $content = '<div class="home-stack">'
                .$welcomeBox
                .renderHomeSections(
                    resolveLangKey('home_section_configuration', $langForGlobal),
                    [],
                    $homeSections,
                    '',
                    false,
                )
                .'</div>';

            echo renderPage(resolveLangKey('title', $langForGlobal), $content, null, $envPath, $hasPassword, $view);
        } catch (Exception $e) {
            /** @var array<string, string> $langForCatch */
            $langForCatch = (isset($lang) && is_array($lang)) ? $lang : [];
            $targetDirStrCatch = (isset($targetDirStr) && is_string($targetDirStr)) ? $targetDirStr : '';
            $envPathCatch = ('' !== $targetDirStrCatch) ? rtrim($targetDirStrCatch, '/').'/.env.local' : null;
            $hasPasswordCatch = !empty($config['password'] ?? '');
            echo renderPage(resolveLangKey('error', $langForCatch), '<p>'.resolveLangKey('error_occurred', $langForCatch).'</p>', $e->getMessage(), $envPathCatch, $hasPasswordCatch);
        }
    }
}
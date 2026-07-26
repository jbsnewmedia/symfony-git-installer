<?php

declare(strict_types=1);

/**
 * GitInstall - GitHub Repository Installer.
 *
 * Downloads and extracts branches or tags from GitHub repositories.
 */

require_once __DIR__.'/app/Filesystem.php';
require_once __DIR__.'/app/Translation.php';
require_once __DIR__.'/app/Authenticator.php';
require_once __DIR__.'/app/GitHubClient.php';
require_once __DIR__.'/app/GitHubRefsCache.php';
require_once __DIR__.'/app/InstallUuidManager.php';
require_once __DIR__.'/app/AppSecretManager.php';
require_once __DIR__.'/app/EnvLocalManager.php';
require_once __DIR__.'/app/FilesystemSupport.php';
require_once __DIR__.'/app/MigrationStatus.php';
require_once __DIR__.'/app/InstallerUpdater.php';
require_once __DIR__.'/app/HtmlRenderer.php';
require_once __DIR__.'/app/InstallerApplication.php';

if (PHP_SAPI !== 'cli') {
    session_start();
    (new InstallerApplication())->run();
} else {
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    if (!isset($_SESSION['lang'])) {
        $_SESSION['lang'] = 'en';
    }
    if (!isset($_SERVER['REQUEST_METHOD'])) {
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }
}
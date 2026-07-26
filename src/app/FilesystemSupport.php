<?php

declare(strict_types=1);

function installerLogRelativePath(): string
{
    return 'git-installer.log';
}

function installerLogPath(string $logDirectory): string
{
    $base = rtrim($logDirectory, '/');
    if ('' === $base) {
        $base = sys_get_temp_dir();
    }

    return $base.'/'.installerLogRelativePath();
}

function installerLogBaseDirectory(string $installerRoot, string $configuredLogDirectory = ''): string
{
    $configured = trim(str_replace('\\', '/', $configuredLogDirectory));
    $normalizedRoot = trim(str_replace('\\', '/', $installerRoot));
    if ('' !== $configured) {
        $isAbsolute = str_starts_with($configured, '/') || 1 === preg_match('#^[A-Za-z]:/#', $configured);
        $base = $isAbsolute ? $configured : rtrim($installerRoot, '/').'/'.$configured;
    } elseif ('' !== $normalizedRoot) {
        $base = rtrim($installerRoot, '/').'/logs';
    } else {
        $base = str_replace('\\', '/', sys_get_temp_dir()).'/git-installer-logs';
    }

    $base = rtrim($base, '/');
    if ('' === $base) {
        $base = str_replace('\\', '/', sys_get_temp_dir()).'/git-installer-logs';
    }

    if (!is_dir($base)) {
        createDirectoryTree($base, 0o755);
    }

    return $base;
}

/**
 * @param array<string, scalar|null> $context
 */
function logInstallerEvent(
    string $logDirectory,
    string $level,
    string $message,
    array $context = [],
): void {
    $path = installerLogPath($logDirectory);
    $directory = dirname($path);
    if (!is_dir($directory)) {
        if (!createDirectoryTree($directory, 0o755)) {
            return;
        }
    }

    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $line = '['.$timestamp.'] ['.strtoupper($level).'] '.$message;
    if ([] !== $context) {
        $parts = [];
        foreach ($context as $key => $value) {
            if (is_bool($value)) {
                $parts[] = $key.'='.($value ? 'true' : 'false');
            } else {
                $parts[] = $key.'='.(string) $value;
            }
        }
        $line .= ' '.implode(' ', $parts);
    }
    $line .= "\n";

    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/**
 * @return list<string>
 */
function readInstallerLog(string $logDirectory, int $maxLines = 200): array
{
    $path = installerLogPath($logDirectory);
    if (!is_file($path)) {
        return [];
    }

    $content = @file_get_contents($path);
    if (!is_string($content) || '' === $content) {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    /** @var list<string> $nonEmptyLines */
    $nonEmptyLines = array_values(array_filter($lines, static fn (string $line): bool => '' !== $line));
    if (count($nonEmptyLines) > $maxLines) {
        $nonEmptyLines = array_slice($nonEmptyLines, -$maxLines);
    }

    return $nonEmptyLines;
}

function getDirectorySize(string $directory): int
{
    if (!is_dir($directory)) {
        return 0;
    }

    $total = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            \assert($item instanceof SplFileInfo);
            if ($item->isFile()) {
                $size = $item->getSize();
                if (is_int($size) && $size > 0) {
                    $total += $size;
                }
            }
        }
    } catch (Exception) {
        return $total;
    }

    return $total;
}

function formatFileSize(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = (int) floor(log($bytes, 1024));
    $power = min($power, count($units) - 1);
    $value = $bytes / (1024 ** $power);
    $formatted = number_format($value, $power > 0 ? 1 : 0, '.', '');

    if (str_contains($formatted, '.')) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    return $formatted.$units[$power];
}

/**
 * @return array{deleted_count: int, errors: array<string>}
 */
function clearCacheDirectory(string $cacheDir): array
{
    $result = ['deleted_count' => 0, 'errors' => []];

    if (!is_dir($cacheDir)) {
        return $result;
    }

    $protectedPrefixes = [];
    foreach (glob($cacheDir.'/*', GLOB_ONLYDIR) ?: [] as $childDir) {
        $baseName = basename($childDir);
        if (in_array($baseName, ['log', 'logs'], true)) {
            $protectedPrefixes[] = str_replace('\\', '/', rtrim($childDir, '/'));
        }
    }

    $isProtected = static function (string $path) use ($protectedPrefixes): bool {
        $normalized = str_replace('\\', '/', $path);
        foreach ($protectedPrefixes as $prefix) {
            if ($normalized === $prefix || str_starts_with($normalized, $prefix.'/')) {
                return true;
            }
        }

        return false;
    };

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            \assert($item instanceof SplFileInfo);
            $path = $item->getPathname();
            if ($isProtected($path)) {
                continue;
            }
            if ($item->isDir()) {
                if (!@rmdir($path)) {
                    $lastError = error_get_last();
                    $result['errors'][] = $path.': '.($lastError['message'] ?? 'unknown');
                }
            } else {
                if (@unlink($path)) {
                    ++$result['deleted_count'];
                } else {
                    $lastError = error_get_last();
                    $result['errors'][] = $path.': '.($lastError['message'] ?? 'unknown');
                }
            }
        }
    } catch (Exception $e) {
        $result['errors'][] = $cacheDir.': '.$e->getMessage();
    }

    if ([] === $protectedPrefixes) {
        @rmdir($cacheDir);
    }

    return $result;
}

/**
 * @param array<string> $whitelistFolders
 * @param array<string> $whitelistFiles
 *
 * @return array{deleted_count: int, preserved: array<string>, failed: array<string>}
 */
function cleanTargetDirectory(string $targetDir, array $whitelistFolders, array $whitelistFiles): array
{
    $preserved = [];
    $failed = [];
    $deletedCount = 0;

    if (!is_dir($targetDir)) {
        return ['deleted_count' => 0, 'preserved' => [], 'failed' => []];
    }

    $preservePaths = [];

    foreach ($whitelistFolders as $folder) {
        $folderNormalized = trim(str_replace('\\', '/', $folder), '/');
        if ('' === $folderNormalized) {
            continue;
        }
        $fullPath = rtrim($targetDir, '/').'/'.$folderNormalized;
        if (is_dir($fullPath)) {
            $preservePaths[] = $fullPath;
        }
    }

    foreach ($whitelistFiles as $file) {
        $fileNormalized = trim(str_replace('\\', '/', $file), '/');
        if ('' === $fileNormalized) {
            continue;
        }
        $fullPath = rtrim($targetDir, '/').'/'.$fileNormalized;
        if (is_file($fullPath)) {
            $preservePaths[] = $fullPath;
        }
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        $pathname = $item->getPathname();
        $path = (is_string($pathname) || is_int($pathname)) ? (string) $pathname : '';
        if ('' === $path) {
            continue;
        }

        $shouldPreserve = false;
        foreach ($preservePaths as $preservePath) {
            if ($path === $preservePath || str_starts_with($path, (string) $preservePath.'/')) {
                $shouldPreserve = true;
                break;
            }
        }

        if (!$shouldPreserve) {
            if ($item->isDir()) {
                if (!@rmdir($path)) {
                    $failed[] = $path;
                }
            } else {
                if (@unlink($path)) {
                    ++$deletedCount;
                } else {
                    $failed[] = $path;
                }
            }
        } else {
            $preserved[] = substr($path, strlen($targetDir) + 1);
        }
    }

    return ['deleted_count' => $deletedCount, 'preserved' => $preserved, 'failed' => $failed];
}

/**
 * @param array<string> $excludeFolders
 * @param array<string> $excludeFiles
 * @param array<string> $whitelistFolders
 * @param array<string> $whitelistFiles
 *
 * @return array{extracted: array<string>, skipped_files: array<string>, skipped_folders: array<string>}
 */
function extractZip(string $zipContent, string $targetDir, array $excludeFolders, array $excludeFiles, array $whitelistFolders, array $whitelistFiles): array
{
    $tempFile = tempnam(sys_get_temp_dir(), 'gitinstall_');
    file_put_contents($tempFile, $zipContent);
    $tempExtractDir = null;
    try {
        $opened = openZipForSafeExtraction($tempFile);
        $zip = $opened['zip'];

        $tempExtractDir = sys_get_temp_dir().'/gitinstall_'.uniqid();
        if (!createDirectoryTree($tempExtractDir, 0o755)) {
            $zip->close();
            throw new RuntimeException('Temp extract directory cannot be created: '.$tempExtractDir);
        }

        $archiveRootEntries = [];
        for ($i = 0; $i < $opened['entry_count']; ++$i) {
            $stat = $zip->statIndex($i);
            $entryName = (string) ($stat['name'] ?? '');
            if (str_contains($entryName, '/')) {
                $firstSegment = explode('/', $entryName, 2)[0];
            } else {
                $firstSegment = $entryName;
            }
            if (!isset($archiveRootEntries[$firstSegment])) {
                $archiveRootEntries[$firstSegment] = true;
            }
        }
        if (1 !== count($archiveRootEntries)) {
            $zip->close();
            throw new RuntimeException('Archive must contain exactly one top-level directory');
        }
        $archiveRootName = array_key_first($archiveRootEntries);
        $archiveRootTarget = $tempExtractDir.'/'.$archiveRootName;

        for ($i = 0; $i < $opened['entry_count']; ++$i) {
            $stat = $zip->statIndex($i);
            $entryName = (string) ($stat['name'] ?? '');
            $content = $zip->getFromIndex($i);
            if (false === $content) {
                continue;
            }
            $absoluteOnDisk = $tempExtractDir.'/'.$entryName;
            if (!createDirectoryTree(dirname($absoluteOnDisk), 0o755)) {
                continue;
            }
            if (str_ends_with($entryName, '/')) {
                if (!is_dir($absoluteOnDisk) && !createDirectoryTree($absoluteOnDisk, 0o755)) {
                    continue;
                }
            } else {
                file_put_contents($absoluteOnDisk, $content);
            }
        }
        $zip->close();

        if (!is_dir($archiveRootTarget)) {
            throw new RuntimeException('Archive root directory not found: '.$archiveRootName);
        }

        $sourceDir = $archiveRootTarget;
        $extractedFiles = [];
        $skippedFiles = [];
        $skippedFolders = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $hasWhitelistFolders = !empty($whitelistFolders);
        $hasWhitelistFiles = !empty($whitelistFiles);

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isLink()) {
                continue;
            }
            $pathname = $item->getPathname();
            $absolutePath = (is_string($pathname) || is_int($pathname)) ? (string) $pathname : '';
            if ('' === $absolutePath) {
                continue;
            }
            $realPath = realpath($absolutePath);
            if (false === $realPath || !str_starts_with($realPath, $sourceDir)) {
                continue;
            }
            $relativePath = substr($realPath, strlen($sourceDir) + 1);
            $relativePathNormalized = str_replace('\\', '/', $relativePath);
            $parentDir = dirname($relativePathNormalized);
            if ('.' === $parentDir) {
                $parentDir = '';
            }

            $isInWhitelist = false;

            if ($hasWhitelistFolders) {
                foreach ($whitelistFolders as $wlFolder) {
                    $wlNormalized = trim(str_replace('\\', '/', $wlFolder), '/');
                    if ('' === $wlNormalized) {
                        continue;
                    }

                    if ($relativePathNormalized === $wlNormalized || str_starts_with($relativePathNormalized, $wlNormalized.'/')) {
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

            if ($isInWhitelist) {
                if ($item->isDir()) {
                    $skippedFolders[] = $relativePath;
                } else {
                    $skippedFiles[] = $relativePath;
                }
                continue;
            }

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
                    if (!createDirectoryTree($targetPath, 0o755)) {
                        throw new RuntimeException('Target directory cannot be created: '.$targetPath);
                    }
                }
                continue;
            }

            if ('' !== $parentDir) {
                foreach ($excludeFolders as $excludeFolder) {
                    $excludeNormalized = trim(str_replace('\\', '/', $excludeFolder), '/');
                    if ($parentDir === $excludeNormalized || str_starts_with($parentDir, $excludeNormalized.'/')) {
                        $skippedFiles[] = $relativePath;
                        continue 2;
                    }
                }
            }

            $fileName = basename($relativePathNormalized);
            foreach ($excludeFiles as $excludeFile) {
                $excludeNormalized = trim(str_replace('\\', '/', $excludeFile), '/');
                if ($relativePathNormalized === $excludeNormalized || $fileName === $excludeNormalized) {
                    $skippedFiles[] = $relativePath;
                    continue 2;
                }
            }

            $resolvedTargetDir = realpath($targetDir);
            if (false === $resolvedTargetDir) {
                if (!createDirectoryTree($targetDir, 0o755)) {
                    throw new RuntimeException('Target directory cannot be created: '.$targetDir);
                }
                $resolvedTargetDir = realpath($targetDir);
                if (false === $resolvedTargetDir) {
                    throw new RuntimeException('Target directory cannot be resolved: '.$targetDir);
                }
            }

            $targetPath = $resolvedTargetDir.'/'.$relativePath;
            if (!str_starts_with($targetPath, $resolvedTargetDir.'/')) {
                throw new RuntimeException('Computed target path escapes target directory: '.$relativePath);
            }
            $targetDirPath = dirname($targetPath);
            if (!is_dir($targetDirPath)) {
                if (!createDirectoryTree($targetDirPath, 0o755)) {
                    throw new RuntimeException('Target parent directory cannot be created: '.$targetDirPath);
                }
            }
            if (!copy($item->getPathname(), $targetPath)) {
                throw new RuntimeException('Failed to copy file: '.$relativePath);
            }
            $extractedFiles[] = $relativePath;
        }

        return ['extracted' => $extractedFiles, 'skipped_files' => $skippedFiles, 'skipped_folders' => $skippedFolders];
    } finally {
        if (null !== $tempExtractDir && is_dir($tempExtractDir)) {
            recursiveDelete($tempExtractDir);
        }
        if (is_file($tempFile)) {
            unlink($tempFile);
        }
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
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}

/**
 * Validates a ZIP entry path against path-traversal attacks (Zip Slip).
 *
 * Returns false for:
 *  - Empty paths
 *  - Absolute paths (Unix "/foo" or Windows "C:\foo" / "C:/foo")
 *  - Paths containing NUL bytes
 *  - Paths that, after normalization, contain ".." segments or escape the root
 */
function isZipEntryPathSafe(string $entryName): bool
{
    if ('' === $entryName) {
        return false;
    }

    if (str_contains($entryName, "\0")) {
        return false;
    }

    $normalized = str_replace('\\', '/', $entryName);

    if (str_starts_with($normalized, '/')) {
        return false;
    }

    if (1 === preg_match('~^[A-Za-z]:[\\\\/]~', $normalized)) {
        return false;
    }

    $segments = explode('/', $normalized);
    foreach ($segments as $segment) {
        if ('' === $segment || '.' === $segment) {
            continue;
        }
        if ('..' === $segment) {
            return false;
        }
    }

    return true;
}

/**
 * Opens a ZIP archive from a file path and validates every entry's path
 * against path-traversal attacks BEFORE any file is extracted to disk.
 * The archive itself is left on disk for the caller to clean up.
 *
 * @return array{zip: ZipArchive, entry_count: int}
 */
function openZipForSafeExtraction(string $zipFile): array
{
    if (!is_file($zipFile)) {
        throw new RuntimeException('ZIP file does not exist: '.$zipFile);
    }

    $zip = new ZipArchive();
    if (true !== $zip->open($zipFile)) {
        throw new RuntimeException('Failed to open ZIP: '.$zipFile);
    }

    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) {
            $zip->close();
            throw new RuntimeException('Failed to read ZIP entry at index '.$i);
        }
        $entryName = (string) ($stat['name'] ?? '');
        if (!isZipEntryPathSafe($entryName)) {
            $zip->close();
            throw new RuntimeException('ZIP entry has unsafe path (Zip Slip): '.$entryName);
        }
    }

    return ['zip' => $zip, 'entry_count' => $zip->numFiles];
}
<?php

declare(strict_types=1);

/**
 * @param array<string, mixed>                            $config
 * @param array<int, array{name: string, commit: string}> $tags
 */
function resolveInstallerVersion(array $config, array $tags = []): string
{
    $configured = '';
    if (isset($config['installer_version']) && is_scalar($config['installer_version'])) {
        $configured = trim((string) $config['installer_version']);
    }
    if ('' !== $configured) {
        return selfAppendInstallerCommit($configured, $config);
    }

    return 'unknown';
}

/**
 * @param array<string, mixed> $config
 */
function selfAppendInstallerCommit(string $version, array $config): string
{
    if (null !== extractSemverFromTag($version)) {
        return $version;
    }

    $commit = '';
    if (isset($config['installer_commit']) && is_scalar($config['installer_commit'])) {
        $commit = trim((string) $config['installer_commit']);
    }
    if ('' !== $commit) {
        return $version.substr($commit, 0, 7);
    }

    return $version;
}

function extractSemverFromTag(string $tag): ?string
{
    $normalized = ltrim(trim($tag), 'vV');
    if (1 === preg_match('/^\d+\.\d+\.\d+$/', $normalized)) {
        return $normalized;
    }

    return null;
}

/**
 * @param array<string, mixed> $updates
 */
function writeConfigValues(string $configPath, array $updates): bool
{
    if (!file_exists($configPath)) {
        return false;
    }

    $current = require $configPath;
    if (!is_array($current)) {
        /** @var array<string, mixed> $current */
        $current = [];
    }

    /** @var array<string, mixed> $merged */
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

    return false;
}

function normalizeRelativePath(string $path): string
{
    return trim(str_replace('\\', '/', $path), '/');
}

function isAllowedUpdaterFile(string $relativePath): bool
{
    $relativePath = normalizeRelativePath($relativePath);

    if ('.htaccess' === $relativePath) {
        return true;
    }

    if ('config.php' === $relativePath) {
        return false;
    }

    if (1 === preg_match('/^(?:[^\/]+\.php|app\/[^\/]+\.php)$/', $relativePath)) {
        return true;
    }

    return 1 === preg_match('/^lang\/.+\.php$/i', $relativePath);
}

/**
 * @return array{updated_files: array<string>, skipped_files: array<string>}
 */
function updateUpdaterFromTag(
    GitHubClient $client,
    string $repository,
    string $tag,
    string $updaterSourcePath,
    string $destinationDir,
): array {
    $zipContent = $client->downloadArchive($repository, $tag, 'tag');
    $tempFile = (string) tempnam(sys_get_temp_dir(), 'updater_self_');
    file_put_contents($tempFile, $zipContent);

    $updatedFiles = [];
    $skippedFiles = [];
    $tempExtractDir = null;

    try {
        $opened = openZipForSafeExtraction($tempFile);
        $zip = $opened['zip'];

        $tempExtractDir = rtrim($destinationDir, '/').'/.updater_self_'.uniqid();
        if (!createDirectoryTree($tempExtractDir, 0o755)) {
            $zip->close();
            throw new RuntimeException('Temp update directory cannot be created: '.$tempExtractDir);
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
            throw new RuntimeException('Update archive must contain exactly one top-level directory');
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

        $archiveRoot = $archiveRootTarget;
        $sourceDir = $archiveRoot;
        if ('' !== $updaterSourcePath) {
            $sourceDir .= '/'.normalizeRelativePath($updaterSourcePath);
        }

        if (!is_dir($sourceDir)) {
            throw new RuntimeException('Updater source path not found in archive: '.$updaterSourcePath);
        }

        $resolvedSourceDir = realpath($sourceDir);
        if (false === $resolvedSourceDir || !str_starts_with($resolvedSourceDir, realpath($archiveRoot))) {
            throw new RuntimeException('Updater source path escapes archive root: '.$updaterSourcePath);
        }

        $resolvedDestinationDir = realpath($destinationDir);
        if (false === $resolvedDestinationDir) {
            throw new RuntimeException('Destination directory cannot be resolved: '.$destinationDir);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedSourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

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
            if (false === $realPath || !str_starts_with($realPath, $resolvedSourceDir)) {
                continue;
            }

            $relativePath = normalizeRelativePath(substr($realPath, strlen($resolvedSourceDir) + 1));

            if (!isAllowedUpdaterFile($relativePath)) {
                $skippedFiles[] = $relativePath;
                continue;
            }

            if (str_contains($relativePath, '..')) {
                $skippedFiles[] = $relativePath;
                continue;
            }

            $targetPath = $resolvedDestinationDir.'/'.$relativePath;
            if (!str_starts_with($targetPath, $resolvedDestinationDir.'/')) {
                $skippedFiles[] = $relativePath;
                continue;
            }

            $parent = dirname($targetPath);
            if (!is_dir($parent)) {
                if (!createDirectoryTree($parent, 0o755)) {
                    throw new RuntimeException('Target directory cannot be created: '.$parent);
                }
            }

            if (!@copy((string) $item->getPathname(), $targetPath)) {
                throw new RuntimeException('Failed to update file: '.$relativePath);
            }

            $updatedFiles[] = $relativePath;
        }
    } finally {
        if (null !== $tempExtractDir && is_dir($tempExtractDir)) {
            recursiveDelete($tempExtractDir);
        }
        @unlink($tempFile);
    }

    return [
        'updated_files' => $updatedFiles,
        'skipped_files' => $skippedFiles,
    ];
}
<?php

declare(strict_types=1);

/**
 * @return array<int, array{name: string, commit: string}>|null
 */
function normalizeGitHubRepositoryRefsCacheValue(mixed $refs): ?array
{
    if (!is_array($refs)) {
        return null;
    }

    $normalized = [];
    foreach ($refs as $ref) {
        if (!is_array($ref)) {
            return null;
        }

        $name = $ref['name'] ?? null;
        $commit = $ref['commit'] ?? null;
        if ((!is_string($name) && !is_int($name)) || (!is_string($commit) && !is_int($commit))) {
            return null;
        }

        $nameNormalized = trim((string) $name);
        $commitNormalized = trim((string) $commit);
        if ('' === $nameNormalized || '' === $commitNormalized) {
            return null;
        }

        $normalized[] = [
            'name' => $nameNormalized,
            'commit' => $commitNormalized,
        ];
    }

    return $normalized;
}

function buildGitHubRepositoryRefsCacheFilePath(string $cacheDir, string $repo): string
{
    return rtrim($cacheDir, '/').'/github-repository-refs-'.sha1($repo).'.php';
}

/**
 * @return array{tags: array<int, array{name: string, commit: string}>, branches: array<int, array{name: string, commit: string}>}|null
 */
function readGitHubRepositoryRefsCache(string $cacheFile, int $ttl = 600): ?array
{
    if (!is_file($cacheFile)) {
        return null;
    }

    $modifiedAt = filemtime($cacheFile);
    if (false === $modifiedAt || $modifiedAt < time() - $ttl) {
        return null;
    }

    $cached = require $cacheFile;
    if (!is_array($cached)) {
        return null;
    }

    $tags = normalizeGitHubRepositoryRefsCacheValue($cached['tags'] ?? null);
    $branches = normalizeGitHubRepositoryRefsCacheValue($cached['branches'] ?? null);
    if (null === $tags || null === $branches) {
        return null;
    }

    return [
        'tags' => $tags,
        'branches' => $branches,
    ];
}

/**
 * @param array{tags: array<int, array{name: string, commit: string}>, branches: array<int, array{name: string, commit: string}>} $refs
 */
function writeGitHubRepositoryRefsCache(string $cacheFile, array $refs): void
{
    $directory = dirname($cacheFile);
    if (file_exists($directory) && !is_dir($directory)) {
        throw new RuntimeException('GitHub cache directory cannot be created: '.$directory);
    }

    if (!createDirectoryTree($directory, 0o755)) {
        throw new RuntimeException('GitHub cache directory cannot be created: '.$directory);
    }

    $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($refs, true).";\n";
    if (false === @file_put_contents($cacheFile, $content, LOCK_EX)) {
        throw new RuntimeException('GitHub cache file cannot be written: '.$cacheFile);
    }
}

/**
 * @return array{tags: array<int, array{name: string, commit: string}>, branches: array<int, array{name: string, commit: string}>}
 */
function getCachedGitHubRepositoryRefs(GitHubClient $client, string $repo, string $cacheDir, int $ttl = 600): array
{
    $cacheFile = buildGitHubRepositoryRefsCacheFilePath($cacheDir, $repo);
    $cached = readGitHubRepositoryRefsCache($cacheFile, $ttl);
    if (null !== $cached) {
        return $cached;
    }

    try {
        $refs = [
            'tags' => $client->getTags($repo),
            'branches' => $client->getBranches($repo),
        ];
    } catch (RuntimeException) {
        return [
            'tags' => [],
            'branches' => [],
        ];
    }

    try {
        writeGitHubRepositoryRefsCache($cacheFile, $refs);
    } catch (RuntimeException $exception) {
        error_log($exception->getMessage());
    }

    return $refs;
}
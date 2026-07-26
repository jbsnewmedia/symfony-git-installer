<?php

declare(strict_types=1);

class GitHubClient
{
    private readonly string $baseUrl;
    private readonly string $userAgent;

    public function __construct(string $baseUrl, private readonly string $token, private readonly string $installerVersion = 'unknown')
    {
        $trimmed = rtrim($baseUrl, '/');
        if ('' === $trimmed) {
            throw new RuntimeException('GitHub base URL must not be empty.');
        }
        $this->baseUrl = $trimmed;
        $this->userAgent = 'SymfonyGitInstaller/'.$this->installerVersion;
    }

    /**
     * @return array<int, array{name: string, commit: string}>
     */
    public function getBranches(string $repo): array
    {
        return $this->fetchAllPages("/repos/{$repo}/branches", function (mixed $branch): ?array {
            if (!is_array($branch)) {
                return null;
            }
            $name = self::normalizeName($branch['name'] ?? null);
            $commit = self::extractSha($branch['commit'] ?? null);

            return null !== $name && null !== $commit ? ['name' => $name, 'commit' => $commit] : null;
        });
    }

    /**
     * @return array<int, array{name: string, commit: string}>
     */
    public function getTags(string $repo): array
    {
        return $this->fetchAllPages("/repos/{$repo}/tags", function (mixed $tag): ?array {
            if (!is_array($tag)) {
                return null;
            }
            $name = self::normalizeName($tag['name'] ?? null);
            $commit = self::extractSha($tag['commit'] ?? null);

            return null !== $name && null !== $commit ? ['name' => $name, 'commit' => $commit] : null;
        });
    }

    public function downloadArchive(string $repo, string $ref, string $refType = 'branch'): string
    {
        $url = $this->baseUrl."/repos/{$repo}/zipball/{$ref}";

        $result = $this->fetchArchive($url);

        if (200 !== $result['httpCode']) {
            throw new RuntimeException("GitHub download failed: HTTP {$result['httpCode']}");
        }

        return $result['body'];
    }

    /**
     * @return array{httpCode: int, body: string}
     */
    private function fetchArchive(string $url): array
    {
        $headers = ['User-Agent: '.$this->userAgent];
        if ('' !== $this->token) {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }

        $body = $this->curlDownload($url, $headers);

        return ['httpCode' => $body['httpCode'], 'body' => $body['body']];
    }

    /**
     * @param list<string> $headers
     *
     * @return array{httpCode: int, body: string}
     */
    private function curlDownload(string $url, array $headers): array
    {
        \assert('' !== $url);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if (403 === $httpCode && '' !== $this->token) {
            $headers = ['User-Agent: '.$this->userAgent];
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 300,
            ]);
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);
        }

        return ['httpCode' => $httpCode, 'body' => is_string($body) ? $body : ''];
    }

    /**
     * @param callable(mixed): ?array{name: string, commit: string} $normalizer
     *
     * @return array<int, array{name: string, commit: string}>
     */
    private function fetchAllPages(string $endpoint, callable $normalizer): array
    {
        $items = [];
        $page = 1;
        do {
            $response = $this->request($endpoint.'?per_page=100&page='.$page);
            if ([] === $response) {
                break;
            }
            foreach ($response as $entry) {
                $normalized = $normalizer($entry);
                if (null !== $normalized) {
                    $items[] = $normalized;
                }
            }
            ++$page;
        } while (100 === count($response));

        return $items;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function request(string $endpoint): array
    {
        $url = $this->baseUrl.$endpoint;
        $headers = ['User-Agent: '.$this->userAgent, 'Accept: application/vnd.github.v3+json'];
        if ('' !== $this->token) {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }

        $result = $this->curlDownload($url, $headers);

        if ($result['httpCode'] >= 400) {
            throw new RuntimeException("GitHub API Error: HTTP {$result['httpCode']}");
        }

        $decoded = json_decode($result['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function normalizeName(mixed $name): ?string
    {
        if (!is_string($name) && !is_int($name)) {
            return null;
        }

        $trimmed = trim((string) $name);

        return '' !== $trimmed ? $trimmed : null;
    }

    private static function extractSha(mixed $commit): ?string
    {
        if (!is_array($commit)) {
            return null;
        }
        $sha = $commit['sha'] ?? null;
        if (!is_string($sha) && !is_int($sha)) {
            return null;
        }
        $trimmed = trim((string) $sha);

        return '' !== $trimmed ? $trimmed : null;
    }
}
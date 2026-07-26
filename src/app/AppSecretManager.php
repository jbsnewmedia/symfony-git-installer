<?php

declare(strict_types=1);

final class AppSecretManager
{
    public function ensureEnvLocalAppSecret(string $envPath, bool $replace = false): string
    {
        $content = '';
        if (is_file($envPath)) {
            $existingContent = @file_get_contents($envPath);
            if (!is_string($existingContent)) {
                throw new \RuntimeException(sprintf('Unable to read "%s".', $envPath));
            }

            $content = $existingContent;
        }

        $updated = $this->upsertAppSecret($content, $replace);
        if (!$updated['changed']) {
            return $updated['secret'];
        }

        $directory = dirname($envPath);
        if (!createDirectoryTree($directory, 0o755)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }

        if (false === @file_put_contents($envPath, $updated['content'])) {
            throw new \RuntimeException(sprintf('Unable to write "%s".', $envPath));
        }

        return $updated['secret'];
    }

    /**
     * @return array{content: string, secret: string, changed: bool}
     */
    public function upsertAppSecret(string $content, bool $replace = false): array
    {
        $normalizedContent = str_replace(["\r\n", "\r"], "\n", $content);
        $existingSecret = $this->extractAppSecret($normalizedContent);

        if (null !== $existingSecret && !$replace) {
            return [
                'content' => $normalizedContent,
                'secret' => $existingSecret,
                'changed' => false,
            ];
        }

        $secret = $this->generateSecret();
        $secretLine = 'APP_SECRET='.$secret;
        $pattern = '/^\s*#?\s*APP_SECRET\s*=.*$/m';

        if (1 === preg_match($pattern, $normalizedContent)) {
            $updatedContent = (string) preg_replace($pattern, $secretLine, $normalizedContent, 1);
        } else {
            $updatedContent = rtrim($normalizedContent, "\n");
            $updatedContent = '' === $updatedContent ? $secretLine."\n" : $updatedContent."\n".$secretLine."\n";
        }

        return [
            'content' => $updatedContent,
            'secret' => $secret,
            'changed' => $updatedContent !== $normalizedContent,
        ];
    }

    private function extractAppSecret(string $content): ?string
    {
        if (1 !== preg_match('/^\s*APP_SECRET\s*=\s*("?)([^"\s]+)\1\s*$/m', $content, $matches)) {
            return null;
        }

        $secret = trim($matches[2]);

        return 1 === preg_match('/^[A-Za-z0-9._-]{16,128}$/', $secret) ? $secret : null;
    }

    private function generateSecret(): string
    {
        return bin2hex(random_bytes(16));
    }
}
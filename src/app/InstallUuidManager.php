<?php

declare(strict_types=1);

final class InstallUuidManager
{
    public function ensureEnvLocalInstallUuid(string $envPath, bool $replace = false): string
    {
        $content = '';
        if (is_file($envPath)) {
            $existingContent = @file_get_contents($envPath);
            if (!is_string($existingContent)) {
                throw new \RuntimeException(sprintf('Unable to read "%s".', $envPath));
            }

            $content = $existingContent;
        }

        $updated = $this->upsertInstallUuid($content, $replace);
        if (!$updated['changed']) {
            return $updated['uuid'];
        }

        $directory = dirname($envPath);
        if (!createDirectoryTree($directory, 0o755)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }

        if (false === @file_put_contents($envPath, $updated['content'])) {
            throw new \RuntimeException(sprintf('Unable to write "%s".', $envPath));
        }

        return $updated['uuid'];
    }

    /**
     * @return array{content: string, uuid: string, changed: bool}
     */
    public function upsertInstallUuid(string $content, bool $replace = false): array
    {
        $normalizedContent = str_replace(["\r\n", "\r"], "\n", $content);
        $existingUuid = $this->extractInstallUuid($normalizedContent);

        if (null !== $existingUuid && !$replace) {
            return [
                'content' => $normalizedContent,
                'uuid' => $existingUuid,
                'changed' => false,
            ];
        }

        $uuid = $this->generateUuidV7Like();
        $uuidLine = 'INSTALL_UUID='.$uuid;
        $pattern = '/^\s*#?\s*INSTALL_UUID\s*=.*$/m';

        if (1 === preg_match($pattern, $normalizedContent)) {
            $updatedContent = (string) preg_replace($pattern, $uuidLine, $normalizedContent, 1);
        } else {
            $updatedContent = rtrim($normalizedContent, "\n");
            $updatedContent = '' === $updatedContent ? $uuidLine."\n" : $updatedContent."\n".$uuidLine."\n";
        }

        return [
            'content' => $updatedContent,
            'uuid' => $uuid,
            'changed' => $updatedContent !== $normalizedContent,
        ];
    }

    private function extractInstallUuid(string $content): ?string
    {
        if (1 !== preg_match('/^\s*INSTALL_UUID\s*=\s*("?)([0-9a-fA-F-]+)\1\s*$/m', $content, $matches)) {
            return null;
        }

        $uuid = strtolower($matches[2]);

        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)
            ? $uuid
            : null;
    }

    private function generateUuidV7Like(): string
    {
        $timestamp = (int) floor(microtime(true) * 1000);
        $bytes = random_bytes(16);

        for ($index = 5; $index >= 0; --$index) {
            $bytes[$index] = chr($timestamp & 0xFF);
            $timestamp >>= 8;
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
<?php

declare(strict_types=1);

/**
 * @return array{html: string, count: int, error: bool, no_migrations?: bool, no_db?: bool}
 */
function getMigrationsStatus(string $targetDir): array
{
    global $lang;
    /** @var array<string, string> $langForMigrations */
    $langForMigrations = (isset($lang) && is_array($lang)) ? $lang : [];

    $console = rtrim($targetDir, '/').'/bin/console';
    if (!file_exists($console)) {
        return ['html' => 'bin/console not found', 'count' => 0, 'error' => true];
    }

    $migrationsDir = rtrim($targetDir, '/').'/migrations';
    $foundMigrations = glob($migrationsDir.'/*.php');
    $hasMigrations = is_dir($migrationsDir) && false !== $foundMigrations && count($foundMigrations) > 0;

    if (!$hasMigrations) {
        return [
            'html' => '<span style="color:#6a737d;">'.resolveLangKey('no_migrations_found', $langForMigrations).'</span>',
            'count' => 0,
            'error' => false,
            'no_migrations' => true,
        ];
    }

    $cmd = 'php '.escapeshellarg($console).' doctrine:migrations:status --no-interaction 2>&1';
    $output = (string) shell_exec($cmd);

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
            'html' => '<span style="color:#28a745; font-weight:bold;">'.resolveLangKey('no_migrations_to_execute', $langForMigrations).'</span>',
            'count' => 0,
            'error' => false,
        ];
    }

    $trimmedOutput = trim((string) $output);
    if (str_starts_with($trimmedOutput, '{')) {
        $json = json_decode($trimmedOutput, true);
        if (is_array($json) && isset($json['message']) && is_scalar($json['message'])) {
            $msg = (string) $json['message'];
            if (preg_match('/Message: "(.*?)"/s', $msg, $m)) {
                $msg = (string) $m[1];
            }

            if (str_contains($msg, 'could not find driver') || str_contains($msg, 'Connection refused')) {
                return [
                    'html' => '<span style="color:#6a737d;">'.resolveLangKey('migrations_disabled_no_db', $langForMigrations).'</span>',
                    'count' => 0,
                    'error' => false,
                    'no_db' => true,
                ];
            }

            $errorMsg = (string) strtok($msg, "\n");

            return [
                'html' => '<span style="color:#d73a49; font-size:0.9em;">Error: '.htmlspecialchars($errorMsg).'</span>',
                'count' => 0,
                'error' => true,
            ];
        }
    }

    $lines = explode("\n", $trimmedOutput);
    foreach ($lines as $line) {
        $line = trim($line);
        if ('' !== $line && !str_contains($line, 'CRITICAL') && !str_contains($line, 'DEBUG')) {
            if (str_contains($line, 'ExceptionConverter.php') || str_contains($line, 'Connection refused') || str_contains($line, 'could not find driver')) {
                return [
                    'html' => '<span style="color:#6a737d;">'.resolveLangKey('migrations_disabled_no_db', $langForMigrations).'</span>',
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

    $errorMsgFallback = (string) strtok($trimmedOutput, "\n");

    return [
        'html' => '<span style="color:#d73a49; font-size:0.9em;">Error: '.htmlspecialchars($errorMsgFallback).'</span>',
        'count' => 0,
        'error' => true,
    ];
}
<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $versionMeta
 *
 * @return array{outcome: string, error: string, version_meta: array<string, mixed>}
 */
function evaluateAuthentication(array $config, bool $showVersionsBeforeLogin = false, array $versionMeta = []): array
{
    /** @var string $password */
    $password = '';
    if (isset($config['password']) && is_scalar($config['password'])) {
        $password = (string) $config['password'];
    }

    if ('' === $password) {
        return ['outcome' => 'no-password', 'error' => '', 'version_meta' => $versionMeta];
    }

    if (isset($_GET['logout'])) {
        $_SESSION = [];

        return ['outcome' => 'logged-out', 'error' => '', 'version_meta' => $versionMeta];
    }

    if (isset($_SESSION['gitinstall_authenticated']) && true === $_SESSION['gitinstall_authenticated']) {
        return ['outcome' => 'authenticated', 'error' => '', 'version_meta' => $versionMeta];
    }

    $versionMetaForForm = $showVersionsBeforeLogin ? $versionMeta : [];

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['password']) && is_string($_POST['password'])) {
        $inputPassword = (string) $_POST['password'];

        if (password_verify($inputPassword, $password) || hash_equals($password, $inputPassword)) {
            $_SESSION['gitinstall_authenticated'] = true;
            $_SESSION['gitinstall_auth_time'] = time();

            return ['outcome' => 'login-ok', 'error' => '', 'version_meta' => $versionMeta];
        }

        return ['outcome' => 'login-failed', 'error' => __('incorrect_password'), 'version_meta' => $versionMetaForForm];
    }

    return ['outcome' => 'show-form', 'error' => '', 'version_meta' => $versionMetaForForm];
}
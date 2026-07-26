<?php

declare(strict_types=1);

/**
 * @param array<string, string>           $lang
 * @param array<string, string|int|float> $placeholders
 */
function resolveLangKey(string $key, array $lang, array $placeholders = []): string
{
    $text = (isset($lang[$key])) ? (string) $lang[$key] : $key;
    foreach ($placeholders as $k => $v) {
        $vStr = (string) $v;
        $text = str_replace(':'.$k, $vStr, (string) $text);
    }

    return (string) $text;
}

/**
 * @param array<string, string|int|float> $placeholders
 */
function __(string $key, array $placeholders = []): string
{
    global $lang;
    /** @var array<string, string> $lang */
    if (!isset($lang) || !is_array($lang)) {
        return $key;
    }

    return resolveLangKey($key, $lang, $placeholders);
}
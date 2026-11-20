<?php

/** WordPress API helpers for the offline artifact test only. */

declare(strict_types=1);

function do_action(string $hook, mixed ...$args): void
{
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function wp_parse_url(string $url): array|false
{
    return parse_url($url);
}

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

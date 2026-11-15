<?php

/** All capabilities granted: CLI/REST must still be refused before state or HTTP access. */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress\Update;

function is_admin(): bool
{
    return true;
}

function wp_doing_cron(): bool
{
    return false;
}

function is_multisite(): bool
{
    return false;
}

function current_user_can(string $capability): bool
{
    return true;
}

function wp_is_file_mod_allowed(string $context): bool
{
    return true;
}

function plugin_basename(string $file): string
{
    return 'sw-cloudcalendar/sw-cloudcalendar.php';
}

function apply_filters(string $hook, mixed $value): mixed
{
    return $value;
}

function set_test_context(string $context): void
{
    define($context, true);
}

<?php

/** Separate process for immutable WP_CLI / REST_REQUEST constants; no WordPress bootstrap, DB or HTTP. */

declare(strict_types=1);

use SW\Calendar\Infrastructure\WordPress\Update\Provider;
use SW\Calendar\Infrastructure\WordPress\Update\Service;

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/policy-functions.php';
require_once dirname(__DIR__) . '/wp/vendor/johnpbloch/wordpress-core/wp-includes/class-wp-error.php';
require_once dirname(__DIR__, 2) . '/src/Infrastructure/WordPress/Update/Service.php';
require_once dirname(__DIR__, 2) . '/src/Infrastructure/WordPress/Update/Provider.php';

$context = $argv[1] ?? '';
if (!in_array($context, ['WP_CLI', 'REST_REQUEST'], true)) {
    fail('Expected WP_CLI or REST_REQUEST.');
}
if (!Service::interactive()) {
    fail('The otherwise authorised backend fixture must be interactive.');
}
\SW\Calendar\Infrastructure\WordPress\Update\set_test_context($context);
if (Service::interactive() || !is_wp_error(Service::check(true))) {
    fail('Non-interactive update check accepted.');
}
$url = 'https://github.com/sw-wordpress/sw-cloudcalendar/releases/download/v9.0.0/sw-cloudcalendar-9.0.0.zip';
$result = Provider::download_guard(false, $url, null, ['plugin' => 'sw-cloudcalendar/sw-cloudcalendar.php']);
if (!is_wp_error($result) || $result->get_error_code() !== 'manual_update_required') {
    fail('Non-interactive update installation accepted.');
}
echo "PASS: $context checks and installs are refused before state or HTTP access.\n";

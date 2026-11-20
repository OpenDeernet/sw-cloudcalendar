<?php

/** Run with php -n -d disable_functions=sodium_crypto_sign_verify_detached to test closed extension gates. */

declare(strict_types=1);

use SW\Calendar\Infrastructure\WordPress\Update\Manifest;
use SW\Calendar\Infrastructure\WordPress\Update\Package;

require_once __DIR__ . '/functions.php';
require_once dirname(__DIR__) . '/wp/vendor/johnpbloch/wordpress-core/wp-includes/class-wp-error.php';
require_once dirname(__DIR__, 2) . '/src/Infrastructure/WordPress/Update/Manifest.php';
require_once dirname(__DIR__, 2) . '/src/Infrastructure/WordPress/Update/Package.php';

if (function_exists('sodium_crypto_sign_verify_detached') || class_exists('ZipArchive')) {
    fail('Run without Sodium verification and ZipArchive.');
}
$result = Manifest::verify('', '', [], []);
if (!is_wp_error($result) || !str_contains($result->get_error_data()['reason'], 'unavailable')) {
    fail('Unavailable signature verification must fail closed.');
}
$result = Package::verify(__FILE__, ['bytes' => filesize(__FILE__), 'sha256' => hash_file('sha256', __FILE__)]);
if (!is_wp_error($result) || $result->get_error_code() !== 'invalid_update_file') {
    fail('Unavailable ZIP verification must fail closed.');
}
echo "PASS: missing Sodium verifier and ZipArchive block updates.\n";

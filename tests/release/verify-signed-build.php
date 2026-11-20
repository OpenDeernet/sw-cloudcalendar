<?php

/**
 * Signs the REAL build ZIP(s) with a disposable key and verifies them with the runtime verifier.
 * Also checks package completeness and that signing aborts without the signing secret.
 *
 * Usage: php tests/release/verify-signed-build.php <directory-with-zips>
 * No WordPress, no network, no production key.
 */

declare(strict_types=1);

use SW\Calendar\Infrastructure\WordPress\Update\Manifest;
use SW\Calendar\Infrastructure\WordPress\Update\Package;

require_once dirname(__DIR__) . '/wp/vendor/johnpbloch/wordpress-core/wp-includes/class-wp-error.php';
require_once __DIR__ . '/functions.php';


$root = dirname(__DIR__, 2);
foreach (array( 'Manifest', 'Package' ) as $class) {
    require_once $root . '/src/Infrastructure/WordPress/Update/' . $class . '.php';
}

$dir = realpath($argv[1] ?? '');
if (false === $dir) {
    fail('ZIP directory missing.');
}
$zips = glob($dir . '/sw-cloudcalendar-*.zip') ?: array();
if (1 !== count($zips)) {
    fail('Expected exactly one sw-cloudcalendar ZIP.');
}
$zip_file = $zips[0];

// Completeness of the installable package (the update must ship its own verifier and keyring).
$zip = new ZipArchive();
$zip->open($zip_file);
$names = array();
for ($i = 0; $i < $zip->count(); $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}
$required = array(
    'sw-cloudcalendar/sw-cloudcalendar.php',
    'sw-cloudcalendar/uninstall.php',
    'sw-cloudcalendar/vendor-prefixed/autoload.php',
    'sw-cloudcalendar/assets/admin/app.js',
    'sw-cloudcalendar/assets/js/sw-cloudcalendar-updates.js',
    'sw-cloudcalendar/languages/sw-cloudcalendar-de_DE.mo',
);
foreach (array( 'Manifest', 'Package', 'Provider', 'Service', 'Store', 'Transport' ) as $class) {
    $required[] = "sw-cloudcalendar/src/Infrastructure/WordPress/Update/$class.php";
}
foreach (array( 'source', 'descriptors', 'trusted-keys' ) as $config) {
    $required[] = "sw-cloudcalendar/src/Infrastructure/WordPress/Update/Config/$config.php";
}
foreach ($required as $path) {
    if (! in_array($path, $names, true)) {
        fail("Package incomplete, missing $path");
    }
}
foreach ($names as $name) {
    if (
        preg_match('#^sw-cloudcalendar/(vendor|tests|apps|bin|node_modules|\.git|\.github)/#', $name)
        || preg_match('#signing-key|\.base64$|\.pem$|secret[-_]key#i', $name)
    ) {
        fail("Unexpected development or key file in package: $name");
    }
}
$shipped_ring_source = (string) $zip->getFromName(
    'sw-cloudcalendar/src/Infrastructure/WordPress/Update/Config/trusted-keys.php'
);
$main = (string) $zip->getFromName('sw-cloudcalendar/sw-cloudcalendar.php');
$zip->close();
if (! preg_match('/^ \* Update URI:\s+https:\/\/github\.com\/sw-wordpress\/sw-cloudcalendar$/m', $main)) {
    fail('Update URI header missing or wrong.');
}
$ring = require $root . '/src/Infrastructure/WordPress/Update/Config/trusted-keys.php';
echo 'Shipped key ring entries: ' . count($ring) . "\n";

// Disposable key, work on a copy so the build output is untouched.
$work = sys_get_temp_dir() . '/sw_calendar-release-check-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
file_put_contents($work . '/shipped-ring.php', $shipped_ring_source);
$shipped_ring = require $work . '/shipped-ring.php';
if ($shipped_ring !== $ring) {
    fail('The packaged keyring differs from the local trust configuration.');
}
$copy = $work . '/' . basename($zip_file);
copy($zip_file, $copy);
$pair   = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($pair));
$public = array( 'throwaway' => base64_encode(sodium_crypto_sign_publickey($pair)) );
$release = array(
    'id'         => 7,
    'tag_name'   => 'v' . preg_replace('/^sw-cloudcalendar-(.+)\.zip$/', '$1', basename($zip_file)),
    'draft'      => false,
    'prerelease' => false,
    'assets'     => array( array( 'id' => 11, 'name' => basename($zip_file), 'size' => filesize($copy) ) ),
);
file_put_contents($work . '/release.json', json_encode($release));

$sign = static function (array $env) use ($root, $work): array {
    $command = [PHP_BINARY, $root . '/bin/release-manifest.php', 'sign',
        $work . '/release.json', $work, str_repeat('b', 40)];
    $p = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return array( proc_close($p), $out );
};
[ $exit ] = $sign(array());
if (0 === $exit || file_exists($work . '/sw-plugin-release.json')) {
    fail('Signing must abort without the signing secret.');
}
[ $exit, $out ] = $sign(array( 'SW_PLUGIN_RELEASE_SIGNING_KEY' => $secret, 'SW_PLUGIN_RELEASE_KEY_ID' => 'throwaway' ));
if (0 !== $exit || str_contains($out, $secret)) {
    fail('Signing failed or leaked the key: ' . $out);
}
$raw = (string) file_get_contents($work . '/sw-plugin-release.json');
$signature = (string) file_get_contents($work . '/sw-plugin-release.json.sig');
$manifest = Manifest::verify($raw, $signature, $public, $release);
if (is_wp_error($manifest)) {
    fail('Runtime verifier rejected the signed manifest: ' . json_encode($manifest->get_error_data()));
}
$package = Manifest::package($manifest, 'sw-cloudcalendar');
if (null === $package) {
    fail('Package entry missing.');
}
$result = Package::verify($copy, $package);
if (is_wp_error($result)) {
    fail('Runtime package check rejected the real build ZIP: ' . json_encode($result->get_error_data()));
}
// Exercise the release workflow's offline validator against downloaded draft artifacts.
$validator_root = $work . '/validator';
mkdir($validator_root . '/bin', 0700, true);
$config = $validator_root . '/src/Infrastructure/WordPress/Update/Config';
mkdir($config, 0700, true);
copy($root . '/bin/verify-release.php', $validator_root . '/bin/verify-release.php');
foreach (['Manifest', 'Package'] as $class) {
    copy($root . '/src/Infrastructure/WordPress/Update/' . $class . '.php', dirname($config) . '/' . $class . '.php');
}
copy($root . '/src/Infrastructure/WordPress/Update/Config/source.php', $config . '/source.php');
file_put_contents($config . '/trusted-keys.php', '<?php return ' . var_export($public, true) . ';');
$draft = $release;
$draft['draft'] = true;
file_put_contents($work . '/draft.json', json_encode($draft));
$validate = static function () use ($validator_root, $work): int {
    $command = [PHP_BINARY, $validator_root . '/bin/verify-release.php',
        $work . '/draft.json', $work, str_repeat('b', 40)];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    return proc_close($process);
};
if ($validate() !== 0) {
    fail('Offline draft validator rejected downloaded signed assets.');
}
file_put_contents($copy, 'tampered', FILE_APPEND);
if ($validate() === 0) {
    fail('Offline draft validator accepted a modified asset.');
}
foreach (['source', 'trusted-keys'] as $name) {
    unlink($config . '/' . $name . '.php');
}
rmdir($config);
foreach (['Manifest', 'Package'] as $class) {
    unlink(dirname($config) . '/' . $class . '.php');
}
foreach (['Update', 'WordPress', 'Infrastructure', 'src'] as $unused) {
    $config = dirname($config);
    rmdir($config);
}
unlink($validator_root . '/bin/verify-release.php');
rmdir($validator_root . '/bin');
rmdir($validator_root);

// The same signature must fail with a different key.
$wrong = array( 'throwaway' => base64_encode(random_bytes(32)) );
if (! is_wp_error(Manifest::verify($raw, $signature, $wrong, $release))) {
    fail('Signature accepted with a foreign key.');
}
foreach (glob($work . '/*') ?: array() as $file) {
    unlink($file);
}
rmdir($work);
echo 'PASS: real build ZIP ' . basename($zip_file)
    . ' is complete, signed with a disposable key and accepted by the runtime verifier.' . "\n";

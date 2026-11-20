<?php
/**
 * Offline validation of downloaded draft assets before publication. No WordPress bootstrap or private key.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

use SW\Calendar\Infrastructure\WordPress\Update\Manifest;
use SW\Calendar\Infrastructure\WordPress\Update\Package;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/src/Infrastructure/WordPress/Update/Manifest.php';
require_once dirname( __DIR__ ) . '/src/Infrastructure/WordPress/Update/Package.php';

try {
	$directory = realpath( $argv[2] ?? '' );
	$release = json_decode( (string) file_get_contents( $argv[1] ?? '' ), true, 32, JSON_THROW_ON_ERROR );
	if ( false === $directory || ! is_array( $release ) || true !== ( $release['draft'] ?? null ) ) {
		throw new RuntimeException( 'A draft release and downloaded asset directory are required.' );
	}
	$release['draft'] = false;
	$keys = require dirname( __DIR__ ) . '/src/Infrastructure/WordPress/Update/Config/trusted-keys.php';
	$manifest = Manifest::validate(
		(string) file_get_contents( $directory . '/sw-plugin-release.json' ),
		(string) file_get_contents( $directory . '/sw-plugin-release.json.sig' ),
		$keys,
		$release
	);
	if ( ( $manifest['commit'] ?? null ) !== ( $argv[3] ?? null ) ) {
		throw new RuntimeException( 'Unexpected release commit.' );
	}
	foreach ( $manifest['packages'] as $package ) {
		$file = $directory . '/' . $package['filename'];
		if ( ! is_file( $file ) || filesize( $file ) !== $package['bytes'] || hash_file( 'sha256', $file ) !== $package['sha256'] ) {
			throw new RuntimeException( 'Downloaded asset size or hash mismatch.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			throw new RuntimeException( 'Unreadable release ZIP.' );
		}
		try {
			Package::inspect( $zip, $package );
		} finally {
			$zip->close();
		}
	}
	echo "Verified downloaded release assets.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Release validation failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}

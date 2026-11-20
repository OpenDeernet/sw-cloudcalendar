<?php
/** Inspect only the disposable E2E database after browser requests. */
require getenv( 'SWC_E2E_WP' ) . '/wp-load.php';
$count = (int) get_option( 'swc_e2e_preload_queries', 0 );
if ( 2 !== $count ) { // One find and one count query in the initial render, no queries for the cached second page.
	fwrite( STDERR, 'Expected exactly two adapter queries for one initial render, got ' . $count . "\n" );
	exit( 1 );
}
echo "PASS preloaded local HTML reused without querying adapter on second page request\n";

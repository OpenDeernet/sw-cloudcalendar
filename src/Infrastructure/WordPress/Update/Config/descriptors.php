<?php
/**
 * Local plugin identities. No requests or option reads.
 *
 * @package sw-cloudcalendar
 */

return array(
	// uninstall.php runs without the plugin's constants.
	defined( 'SW_CALENDAR_FILE' ) ? plugin_basename( SW_CALENDAR_FILE ) : 'sw-cloudcalendar/sw-cloudcalendar.php' => array(
		'slug'    => 'sw-cloudcalendar',
		'version' => defined( 'SW_CALENDAR_VERSION' ) ? SW_CALENDAR_VERSION : '0',
	),
);

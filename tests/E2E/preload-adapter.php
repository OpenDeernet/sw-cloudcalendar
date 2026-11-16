<?php
/** Local-only adapter fixture installed exclusively in the disposable browser WordPress. */

add_action( 'sw_calendar_register_local_sources', static function ( $registry ): void {
	$registry->register( 'e2e-preload', new class() implements \SW\Calendar\Application\Local\LocalEventSourceInterface {
		public function name(): string { return 'E2E preload'; }
		public function calendars(): array { return array(); }
		public function canReadCalendar( string $calendarId, ?int $userId ): bool { return true; }
		public function query( \SW\Calendar\Application\Local\LocalEventQuery $query ): \SW\Calendar\Application\Local\LocalEventPage {
			update_option( 'swc_e2e_preload_queries', (int) get_option( 'swc_e2e_preload_queries', 0 ) + 1 );
			$start = new DateTimeImmutable( 'tomorrow 10:00:00', new DateTimeZone( 'UTC' ) );
			return new \SW\Calendar\Application\Local\LocalEventPage( array(
				new \SW\Calendar\Application\Local\LocalEvent( 'one', 'local', 'Preloaded local event', $start, $start->modify( '+1 hour' ) ),
			), 1 );
		}
		public function event( string $calendarId, string $id ): ?\SW\Calendar\Application\Local\LocalEvent { return null; }
	} );
} );

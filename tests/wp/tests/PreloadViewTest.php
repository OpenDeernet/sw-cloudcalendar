<?php
/** Initial page rendering and explicitly configured long-lived HTML cache. */

use SW\Calendar\Application\Local\LocalEvent;
use SW\Calendar\Application\Local\LocalEventPage;
use SW\Calendar\Application\Local\LocalEventQuery;
use SW\Calendar\Application\Local\LocalEventSourceInterface;
use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Domain\Source\SourceStatus;
use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Presentation\Admin\ViewManager;
use SW\Calendar\Presentation\Frontend\CalendarOutput;
use SW\Calendar\Infrastructure\WordPress\Settings;

final class PreloadViewTest extends SWC_TestCase {
	private LocalEventSourceInterface $adapter;
	private int $source_id;
	private int $view_id;

	public function set_up(): void {
		parent::set_up();
		$this->adapter = new class() implements LocalEventSourceInterface {
			public int $queries = 0;
			public bool $allowed = true;
			public bool $fail = false;
			public ?int $fail_on_query = null;
			public bool $empty = false;
			public string $title = 'Initial local event';
			public function name(): string { return 'Preload test'; }
			public function calendars(): array { return array(); }
			public function canReadCalendar( string $calendarId, ?int $userId ): bool { return $this->allowed; }
			public function query( LocalEventQuery $query ): LocalEventPage {
				++$this->queries;
				if ( $this->fail || $this->queries === $this->fail_on_query ) {
					throw new RuntimeException( 'Transient adapter failure' );
				}
				if ( $this->empty ) {
					return new LocalEventPage( array(), 0 );
				}
				$start = new DateTimeImmutable( '2026-10-25T10:00:00Z' );
				return new LocalEventPage( array( new LocalEvent( 'one', 'cal', $this->title, $start, $start->modify( '+1 hour' ) ) ), 1 );
			}
			public function event( string $calendarId, string $id ): ?LocalEvent { return null; }
		};
		$this->services->local_sources()->register( 'preload-test', $this->adapter );
		$connection = $this->services->connections()->create( Provider::Local, 'Local', AuthMode::None, ConnectionStatus::Connected, array( 'adapter' => 'preload-test' ) );
		$this->source_id = $this->services->sources()->create( $connection, 'cal', 'Local calendar', '#336699', 'UTC', Visibility::Public, array(), new SourceSettings() );
		$this->services->sources()->update( $this->source_id, 'Local calendar', '#336699', 'UTC', Visibility::Public, array(), new SourceSettings(), SourceStatus::Active );
		$this->view_id = $this->view( array( $this->source_id ) );
	}

	private function enable( array $extra = array() ): void {
		( new ViewManager( $this->services ) )->save( $this->view_id, $extra + array( 'preload' => true, 'preload_cache_minutes' => 90 ) );
	}

	public function test_existing_views_stay_dynamic_and_setting_round_trips_through_rest(): void {
		$this->assertFalse( $this->services->views()->find( $this->view_id )->preload );
		$output = new CalendarOutput( $this->services );
		$this->assertStringContainsString( 'swc-dynamic', $output->render( array( 'id' => $this->view_id ) ) );
		$this->assertSame( 0, $this->adapter->queries );
		$this->admin();
		$response = $this->rest( 'PUT', '/admin/views/' . $this->view_id, array(), array( 'preload' => true, 'preload_cache_minutes' => 120 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['preload'] );
		$this->assertSame( 120, $response->get_data()['preload_cache_minutes'] );
		$this->assertFalse( $response->get_data()['dynamic'] );
		$this->assertSame( 120, $this->services->views()->find( $this->view_id )->preloadCacheMinutes );
		$this->rest( 'PUT', '/admin/views/' . $this->view_id, array(), array( 'name' => 'Renamed' ) );
		$this->assertTrue( $this->services->views()->find( $this->view_id )->preload );
	}

	public function test_preloaded_html_is_inline_and_reused_until_expiry(): void {
		$this->enable();
		$names = array();
		add_action( 'set_transient', static function ( $name, $value, $ttl ) use ( &$names ): void {
			if ( str_contains( $value, 'Initial local event' ) ) { $names[ $name ] = $ttl; }
		}, 10, 3 );
		$output = new CalendarOutput( $this->services );
		$first = $output->render( array( 'id' => $this->view_id ) );
		$this->assertStringContainsString( 'Initial local event', $first );
		$this->assertStringNotContainsString( 'swc-dynamic', $first );
		$this->assertNotEmpty( $names );
		$this->assertSame( array( 5400 ), array_values( array_unique( $names ) ) );
		$count = $this->adapter->queries;
		$this->adapter->title = 'Changed local event';
		$this->clock->advance( '+61 minutes' );
		$this->assertStringContainsString( 'Initial local event', $output->render( array( 'id' => $this->view_id ) ) );
		$this->assertSame( $count, $this->adapter->queries );
		// Expire the actual WP transient, never sleep or change production data.
		foreach ( array_keys( $names ) as $name ) {
			update_option( '_transient_timeout_' . $name, time() - 1 );
		}
		$this->assertStringContainsString( 'Changed local event', $output->render( array( 'id' => $this->view_id ) ) );
		$this->assertGreaterThan( $count, $this->adapter->queries );
	}

	public function test_private_cache_is_user_scoped_and_never_marked_public(): void {
		$this->services->sources()->update( $this->source_id, 'Private', '#336699', 'UTC', Visibility::Authenticated, array(), new SourceSettings(), SourceStatus::Active );
		$this->enable();
		$this->admin();
		$user = get_current_user_id();
		$output = new CalendarOutput( $this->services );
		$this->assertStringContainsString( 'Initial local event', $output->render( array( 'id' => $this->view_id ) ) );
		$result = $this->services->renderer()->render( $this->view_id, array(), $user );
		$this->assertTrue( $result->cacheHit );
		$this->assertFalse( $result->public );
		$count = $this->adapter->queries;
		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'Initial local event', $output->render( array( 'id' => $this->view_id ) ) );
		$this->assertSame( $count, $this->adapter->queries );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->adapter->title = 'Other visitor event';
		wp_set_current_user( $other );
		$this->assertStringContainsString( 'Other visitor event', $output->render( array( 'id' => $this->view_id ) ) );
		$this->adapter->allowed = false;
		$this->assertStringNotContainsString( 'Other visitor event', $output->render( array( 'id' => $this->view_id ) ) );
		$this->assertFalse( $this->services->renderer()->render( $this->view_id, array(), $other )->cacheHit );
	}

	public function test_clear_configuration_and_global_cache_controls_apply(): void {
		$this->enable();
		$output = new CalendarOutput( $this->services );
		$output->render( array( 'id' => $this->view_id ) );
		$this->adapter->title = 'After clear';
		$this->services->cache()->clear();
		$this->assertStringContainsString( 'After clear', $output->render( array( 'id' => $this->view_id ) ) );
		$this->enable( array( 'html_cache' => false ) );
		$this->assertFalse( $this->services->renderer()->render( $this->view_id, array(), null )->cacheHit );
		$this->assertFalse( $this->services->renderer()->render( $this->view_id, array(), null )->cacheHit );
		$this->enable();
		$this->adapter->title = 'New view revision';
		$this->assertStringContainsString( 'New view revision', $output->render( array( 'id' => $this->view_id ) ) );
		Settings::update( array( 'html_cache' => false ) );
		$this->services->forget_locale_dependent();
		$this->assertFalse( $this->services->renderer()->render( $this->view_id, array(), null )->cacheHit );
		$this->assertFalse( $this->services->renderer()->render( $this->view_id, array(), null )->cacheHit );
	}

	public function test_invalid_cache_duration_and_non_boolean_preload_are_rejected(): void {
		$this->admin();
		foreach ( array( 59, 10081, -1, '60', 60.5, null, array() ) as $minutes ) {
			$this->assertSame( 400, $this->rest( 'PUT', '/admin/views/' . $this->view_id, array(), array( 'preload_cache_minutes' => $minutes ) )->get_status() );
		}
		$this->assertSame( 400, $this->rest( 'PUT', '/admin/views/' . $this->view_id, array(), array( 'preload' => 'false' ) )->get_status() );
		foreach ( array( 60, 10080 ) as $minutes ) {
			$this->assertSame( 200, $this->rest( 'PUT', '/admin/views/' . $this->view_id, array(), array( 'preload_cache_minutes' => $minutes ) )->get_status() );
		}
	}
	public function test_preload_can_be_disabled_and_filters_do_not_share_cached_html(): void {
		$this->enable();
		$renderer = $this->services->renderer();
		$this->assertFalse( $renderer->render( $this->view_id, array( 'limit' => 1 ), null )->cacheHit );
		$this->assertTrue( $renderer->render( $this->view_id, array( 'limit' => 1 ), null )->cacheHit );
		$this->assertFalse( $renderer->render( $this->view_id, array( 'limit' => 2 ), null )->cacheHit );
		$this->enable( array( 'preload' => false ) );
		$this->assertTrue( $renderer->requiresDynamicLoading( $this->view_id ) );
		$this->assertStringContainsString( 'swc-dynamic', ( new CalendarOutput( $this->services ) )->render( array( 'id' => $this->view_id ) ) );
		$this->enable( array( 'enabled' => false ) );
		$this->assertSame( '', ( new CalendarOutput( $this->services ) )->render( array( 'id' => $this->view_id ) ) );
	}

	/** @dataProvider mixed_source_cases */
	public function test_adapter_failure_does_not_cache_empty_or_partial_html( bool $mixed ): void {
		$ids = array( $this->source_id );
		if ( $mixed ) {
			$stored = $this->source( $this->ics_connection() );
			$this->sync( $stored );
			$ids[] = $stored;
		}
		$this->enable( array( 'source_ids' => $ids ) );
		$this->adapter->fail = true;
		$renderer = $this->services->renderer();
		$filter = array( 'month' => '2026-10' );
		$failed = $renderer->render( $this->view_id, $filter, null );
		$this->assertFalse( $failed->cacheHit );
		$this->assertStringNotContainsString( 'Initial local event', $failed->html );
		$this->assertFalse( $renderer->render( $this->view_id, $filter, null )->cacheHit );
		if ( $mixed ) {
			$this->assertStringContainsString( 'Gottesdienst', $failed->html );
		}
		$this->adapter->fail = false;
		$recovered = $renderer->render( $this->view_id, $filter, null );
		$this->assertFalse( $recovered->cacheHit );
		$this->assertStringContainsString( 'Initial local event', $recovered->html );
		$count = $this->adapter->queries;
		$this->assertTrue( $renderer->render( $this->view_id, $filter, null )->cacheHit );
		$this->assertSame( $count, $this->adapter->queries );
	}

	public static function mixed_source_cases(): array {
		return array( 'local only' => array( false ), 'mixed local and stored' => array( true ) );
	}

	/** @dataProvider page_failure_cases */
	public function test_failed_page_or_navigation_probe_is_retried( int $fail_on, int $page ): void {
		$this->enable();
		$this->adapter->fail_on_query = $fail_on;
		$renderer = $this->services->renderer();
		$filter = array( 'page' => $page );
		$this->assertFalse( $renderer->render( $this->view_id, $filter, null )->cacheHit );
		$this->adapter->fail_on_query = null;
		$recovered = $renderer->render( $this->view_id, $filter, null );
		$this->assertFalse( $recovered->cacheHit );
		$this->assertStringContainsString( 'Initial local event', $recovered->html );
		$this->assertTrue( $renderer->render( $this->view_id, $filter, null )->cacheHit );
	}

	public static function page_failure_cases(): array {
		return array( 'first page' => array( 1, 1 ), 'navigation probe' => array( 2, 1 ), 'backward page' => array( 1, -1 ) );
	}

	public function test_successful_empty_result_is_still_cached(): void {
		$this->enable();
		$this->adapter->empty = true;
		$renderer = $this->services->renderer();
		$this->assertSame( 0, $renderer->render( $this->view_id, array(), null )->total );
		$count = $this->adapter->queries;
		$this->assertTrue( $renderer->render( $this->view_id, array(), null )->cacheHit );
		$this->assertSame( $count, $this->adapter->queries );
	}

}

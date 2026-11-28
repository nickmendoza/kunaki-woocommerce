<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Catch_Up;
use Kunaki_Order_Fulfillment as Fulfillment;

class CatchUpTest extends KunakiTestCase {

	/** @var array<string, mixed> Stand-in for wp_options as seen through get_option(). */
	public $options = array();

	/** @var array<string, string> Rows written with raw SQL: the scan lock, per-order locks and markers. */
	public $rows = array();

	/** @var array<int, mixed> What wc_get_order() returns per ID: a mock order, or a Throwable to throw. */
	public $orders = array();

	/** @var array<int, array<string, mixed>> Each order's meta, including what the code under test writes. */
	public $meta = array();

	/** @var int[] What the paid-date query returns. */
	public $query_ids = array();

	/** @var array[] Arguments of each wc_get_orders() call. */
	public $queries = array();

	/** @var int[] Orders with a submission queued in Action Scheduler. */
	public $queued = array();

	/** @var string[] */
	public $logs = array();

	/** @var string[] Transients deleted. */
	public $transients_deleted = array();

	/** @var array[] wp_cache_delete() calls. */
	public $cache_deletes = array();

	/** @var callable|null Runs each time an order is loaded, to simulate something happening mid-batch. */
	public $on_order_load = null;

	protected function set_up() {
		parent::set_up();
		$this->options            = array();
		$this->rows               = array();
		$this->orders             = array();
		$this->meta               = array();
		$this->query_ids          = array();
		$this->queries            = array();
		$this->queued             = array();
		$this->logs               = array();
		$this->transients_deleted = array();
		$this->cache_deletes      = array();
		$this->on_order_load      = null;
		$this->wire_mocks();
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	// --- Recording the inactive window ---

	public function test_deactivation_records_when_the_plugin_went_inactive() {
		$before = time();

		Kunaki_Catch_Up::mark_deactivated();

		$state = $this->options[ Kunaki_Catch_Up::OPTION ];
		$this->assertSame( array( 'from' ), array_keys( $state ) );
		$this->assertGreaterThanOrEqual( $before, $state['from'] );
		$this->assertLessThanOrEqual( time(), $state['from'] );
	}

	public function test_deactivation_keeps_the_start_of_an_unfinished_window() {
		// A scan was pending or part-way through when the plugin was deactivated again.
		$this->options[ Kunaki_Catch_Up::OPTION ] = array(
			'from'   => 1000,
			'to'     => 2000,
			'cursor' => 55,
			'sent'   => 1,
			'held'   => 2,
		);

		Kunaki_Catch_Up::mark_deactivated();

		// The next activation scans the whole gap again from the original start.
		$this->assertSame( array( 'from' => 1000 ), $this->options[ Kunaki_Catch_Up::OPTION ] );
	}

	/**
	 * @dataProvider states_at_activation
	 */
	public function test_activation_closes_the_window_and_resets_the_cursor( $state_before ) {
		$this->options[ Kunaki_Catch_Up::OPTION ] = $state_before;
		$before = time();

		Kunaki_Catch_Up::mark_activated();

		$state = $this->options[ Kunaki_Catch_Up::OPTION ];
		$this->assertSame( 1000, $state['from'] );
		$this->assertGreaterThanOrEqual( $before, $state['to'] );
		$this->assertSame( 0, $state['cursor'] );
		$this->assertSame( 0, $state['sent'] );
		$this->assertSame( 0, $state['held'] );
		$this->assertArrayNotHasKey( 'tries', $state );
	}

	public function states_at_activation() {
		return array(
			'after a deactivation'                        => array( array( 'from' => 1000 ) ),
			// The activation hook ran again with a scan part-way through.
			'with progress left over from an earlier scan' => array( array( 'from' => 1000, 'to' => 1500, 'cursor' => 77, 'sent' => 1, 'held' => 2, 'tries' => 2 ) ),
		);
	}

	public function test_activation_without_a_record_writes_nothing() {
		// First install, or deactivated by a version that didn't record it.
		Kunaki_Catch_Up::mark_activated();

		$this->assertArrayNotHasKey( Kunaki_Catch_Up::OPTION, $this->options );
	}

	// --- The scan ---

	public function test_init_runs_the_scan_before_the_retry_cron_and_shows_the_notice() {
		$catch_up = new Kunaki_Catch_Up();

		Functions\expect( 'add_action' )->once()
			->with( 'kunaki_wc_retry_failed_orders', array( $catch_up, 'run' ), 5 );
		Functions\expect( 'add_action' )->once()
			->with( 'admin_notices', array( $catch_up, 'render_notice' ) );

		$catch_up->init();
	}

	/**
	 * @dataProvider not_pending_states
	 */
	public function test_nothing_happens_without_a_pending_window( $state ) {
		if ( null !== $state ) {
			$this->options[ Kunaki_Catch_Up::OPTION ] = $state;
		}
		$before = $this->options;

		( new Kunaki_Catch_Up() )->run();

		$this->assertSame( array(), $this->queries, 'No order query without a closed window.' );
		$this->assertSame( $before, $this->options );
		$this->assertSame( array(), $this->rows, 'The lock is not taken.' );
	}

	public function not_pending_states() {
		return array(
			'no record'                 => array( null ),
			'still inactive (no "to")'  => array( array( 'from' => 1000 ) ),
			'not an array'              => array( 'yes' ),
		);
	}

	public function test_query_asks_for_processing_orders_paid_in_the_window() {
		$to = time() - HOUR_IN_SECONDS;
		$this->pending_window( $to - DAY_IN_SECONDS, $to );

		( new Kunaki_Catch_Up() )->run();

		$this->assertCount( 1, $this->queries );
		$args = $this->queries[0];
		$this->assertSame( 'processing', $args['status'] );
		$this->assertSame( 'ids', $args['return'] );
		$this->assertSame( -1, $args['limit'] );
		// Up to five minutes after reactivation, to cover a checkout that was
		// already running when the plugin was switched on.
		$this->assertSame( ( $to - DAY_IN_SECONDS ) . '...' . ( $to + 5 * MINUTE_IN_SECONDS ), $args['date_paid'] );
		// Order queries ignore meta_query on legacy storage, and the record check is done in PHP anyway.
		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertArrayNotHasKey( 'meta_key', $args );
	}

	public function test_query_never_reaches_into_the_future() {
		$to = time() - 30;
		$this->pending_window( $to - DAY_IN_SECONDS, $to );
		$before = time();

		( new Kunaki_Catch_Up() )->run();

		list( , $until ) = explode( '...', $this->queries[0]['date_paid'] );
		$this->assertGreaterThanOrEqual( $before, (int) $until );
		$this->assertLessThanOrEqual( time(), (int) $until );
	}

	public function test_a_gap_longer_than_30_days_is_cut_to_30_days() {
		$to = time() - 60;
		$this->pending_window( $to - 60 * DAY_IN_SECONDS, $to );

		( new Kunaki_Catch_Up() )->run();

		list( $from ) = explode( '...', $this->queries[0]['date_paid'] );
		$this->assertSame( $to - 30 * DAY_IN_SECONDS, (int) $from );
	}

	public function test_recent_order_with_kunaki_shipping_is_handed_over_for_automatic_sending() {
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - 23 * HOUR_IN_SECONDS ) );

		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();
		Actions\expectDone( 'kunaki_wc_catch_up_orders_held' )->never();

		( new Kunaki_Catch_Up() )->run();

		// A retryable failure: the retry cron, which runs next on the same hook, sends it.
		$this->assertStringContainsString( 'inactive', $this->meta[100][ Fulfillment::META_FULFILLMENT_ERROR ] );
		$this->assertArrayNotHasKey( Fulfillment::META_FULFILLMENT_EXHAUSTED, $this->meta[100] );
		// The plugin being off isn't Kunaki's fault, so no retry attempt is used up.
		$this->assertArrayNotHasKey( Fulfillment::META_FULFILLMENT_ATTEMPTS, $this->meta[100] );
		// Nothing was held, so there is nothing to tell the admin about.
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::NOTICE_OPTION, $this->options );
		// The scan is finished.
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::OPTION, $this->options );
		$this->assertStringContainsString( 'Catch-up scan finished', implode( "\n", $this->logs ) );
		$this->assertContains( 'kunaki_dashboard_counts', $this->transients_deleted );
	}

	/**
	 * @dataProvider long_waits
	 */
	public function test_order_paid_a_day_or_more_ago_is_held( $seconds_ago ) {
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - $seconds_ago ) );

		// Reported with the rest of the run, not on its own.
		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();
		Actions\expectDone( 'kunaki_wc_catch_up_orders_held' )->once()->with( 1 );

		( new Kunaki_Catch_Up() )->run();

		$this->assertSame( 'yes', $this->meta[100][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		$this->assertStringContainsString( 'more than a day', $this->meta[100][ Fulfillment::META_FULFILLMENT_LAST_ERROR ] );
		// Not in the retry cron's query, so it is never sent without a click.
		$this->assertArrayNotHasKey( Fulfillment::META_FULFILLMENT_ERROR, $this->meta[100] );
		$this->assertSame( 1, $this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] );
	}

	public function long_waits() {
		return array(
			'exactly 24 hours' => array( DAY_IN_SECONDS ),
			'3 days'           => array( 3 * DAY_IN_SECONDS ),
		);
	}

	public function test_order_paid_shortly_before_reactivation_is_held_when_the_scan_runs_late() {
		// Reactivated 23 hours ago, but the scan only runs now (WooCommerce was
		// inactive, or nothing triggered the cron).
		$to = time() - 23 * HOUR_IN_SECONDS;
		$this->pending_window( $to - DAY_IN_SECONDS, $to );
		// Two hours old at reactivation, 25 hours old now.
		$this->add_order( 100, array( 'paid' => $to - 2 * HOUR_IN_SECONDS ) );

		( new Kunaki_Catch_Up() )->run();

		$this->assertSame( 'yes', $this->meta[100][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		$this->assertArrayNotHasKey( Fulfillment::META_FULFILLMENT_ERROR, $this->meta[100] );
	}

	public function test_order_without_kunaki_shipping_is_held_however_recent() {
		$this->pending_window();
		// Placed while the plugin was inactive: the customer picked another shipping method.
		$this->add_order( 100, array( 'paid' => time() - HOUR_IN_SECONDS, 'kunaki_shipping' => false ) );

		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();
		Actions\expectDone( 'kunaki_wc_catch_up_orders_held' )->once()->with( 1 );

		( new Kunaki_Catch_Up() )->run();

		$this->assertSame( 'yes', $this->meta[100][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		$this->assertStringContainsString( 'cheapest', $this->meta[100][ Fulfillment::META_FULFILLMENT_LAST_ERROR ] );
		$this->assertArrayNotHasKey( Fulfillment::META_FULFILLMENT_ERROR, $this->meta[100] );
		// Retry only chooses shipping for an order that has been told it will.
		$this->assertSame( 'yes', $this->meta[100][ Fulfillment::META_NEEDS_SHIPPING ] );
		// The scan itself chooses nothing.
		$this->assertArrayNotHasKey( Fulfillment::META_CHOSEN_SHIPPING, $this->meta[100] );
		$this->assertSame( 1, $this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] );
	}

	public function test_the_scan_never_calls_kunaki() {
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - HOUR_IN_SECONDS ) );
		$this->add_order( 101, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );
		$this->add_order( 102, array( 'paid' => time() - HOUR_IN_SECONDS, 'kunaki_shipping' => false ) );

		Functions\expect( 'wp_remote_post' )->never();

		( new Kunaki_Catch_Up() )->run();

		$this->assertArrayHasKey( Fulfillment::META_FULFILLMENT_ERROR, $this->meta[100] );
		$this->assertSame( 'yes', $this->meta[101][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		$this->assertSame( 'yes', $this->meta[102][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
	}

	public function test_notice_counts_each_held_order_and_adds_to_an_earlier_count() {
		$this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] = 4;
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );
		$this->add_order( 101, array( 'paid' => time() - HOUR_IN_SECONDS ) );
		$this->add_order( 102, array( 'paid' => time() - HOUR_IN_SECONDS, 'kunaki_shipping' => false ) );

		( new Kunaki_Catch_Up() )->run();

		$this->assertSame( 6, $this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] );
	}

	public function test_the_orders_a_run_holds_are_reported_together() {
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );
		$this->add_order( 101, array( 'paid' => time() - HOUR_IN_SECONDS ) );
		$this->add_order( 102, array( 'paid' => time() - HOUR_IN_SECONDS, 'kunaki_shipping' => false ) );
		$this->add_order( 103, array( 'paid' => time() - 3 * DAY_IN_SECONDS ) );

		// After a long break there can be many. One alert each would flood
		// the admin, and nothing is wrong with any of them.
		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();
		// Three held. Order 101 is recent and is sent automatically.
		Actions\expectDone( 'kunaki_wc_catch_up_orders_held' )->once()->with( 3 );

		( new Kunaki_Catch_Up() )->run();

		$this->assertSame( 3, $this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] );
	}

	public function test_a_listener_that_throws_does_not_stop_the_scan_finishing() {
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );

		Actions\expectDone( 'kunaki_wc_catch_up_orders_held' )
			->once()->with( 1 )
			->whenHappen( function () {
				throw new \RuntimeException( 'listener blew up' );
			} );

		( new Kunaki_Catch_Up() )->run();

		// Left pending, the scan would run again on every tick.
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::OPTION, $this->options );
		$this->assertStringContainsString( 'listener blew up', implode( "\n", $this->logs ) );
		$this->assertStringContainsString( 'Catch-up scan finished', implode( "\n", $this->logs ) );
	}

	public function test_an_order_that_cannot_be_checked_is_tried_again_on_the_next_tick() {
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );
		$this->orders[101] = new \RuntimeException( 'order table is corrupt' );
		$this->query_ids[] = 101;
		$this->add_order( 102, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );

		$catch_up = new Kunaki_Catch_Up();
		$catch_up->run();

		// One bad order doesn't stop the rest.
		$this->assertSame( 'yes', $this->meta[100][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		$this->assertSame( 'yes', $this->meta[102][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		$this->assertSame( 2, $this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] );
		$this->assertStringContainsString( 'order table is corrupt', implode( "\n", $this->logs ) );
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::LOCK, $this->rows );

		// But it may be an order that was missed, so the scan is not finished:
		// the next tick starts again from it.
		$state = $this->options[ Kunaki_Catch_Up::OPTION ];
		$this->assertSame( 100, $state['cursor'] );
		$this->assertSame( 1, $state['tries'] );
		$this->assertStringNotContainsString( 'Catch-up scan finished', implode( "\n", $this->logs ) );

		// The error was temporary. The order can be read on the next tick.
		$this->add_order( 101, array( 'paid' => time() - 2 * DAY_IN_SECONDS ), false );
		$catch_up->run();

		$this->assertSame( 'yes', $this->meta[101][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		// Order 102 was handled on the first tick and is not counted twice.
		$this->assertSame( 3, $this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] );
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::OPTION, $this->options );
		$this->assertStringContainsString( '"held":3', implode( "\n", $this->logs ) );
	}

	public function test_the_scan_gives_up_on_an_order_that_keeps_failing() {
		$this->pending_window();
		$this->orders[100] = new \RuntimeException( 'order table is corrupt' );
		$this->query_ids[] = 100;
		$this->add_order( 101, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );

		$catch_up = new Kunaki_Catch_Up();
		for ( $tick = 1; $tick < Kunaki_Catch_Up::MAX_TRIES; $tick++ ) {
			$catch_up->run();
			$this->assertSame( $tick, $this->options[ Kunaki_Catch_Up::OPTION ]['tries'], "Still pending after tick $tick." );
		}

		$catch_up->run();

		// One order that can never be read must not keep the scan going for ever.
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::OPTION, $this->options );
		$logs = implode( "\n", $this->logs );
		$this->assertStringContainsString( 'gave up on orders it could not check', $logs );
		$this->assertStringContainsString( '"order_ids":[100]', $logs );
		// The order behind it was held once, on the first tick.
		$this->assertSame( 'yes', $this->meta[101][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		$this->assertSame( 1, $this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] );
	}

	/**
	 * @dataProvider orders_to_leave_alone
	 */
	public function test_orders_that_were_not_missed_are_left_alone( array $setup ) {
		$this->pending_window();
		$setup += array( 'paid' => time() - HOUR_IN_SECONDS );
		if ( ! empty( $setup['missing'] ) ) {
			$this->query_ids[] = 100;
		} else {
			$this->add_order( 100, $setup );
		}
		if ( ! empty( $setup['row'] ) ) {
			$this->rows[ $setup['row'] ] = 'x';
		}
		if ( ! empty( $setup['queued'] ) ) {
			$this->queued[] = 100;
		}
		$before = $this->meta[100] ?? array();

		Actions\expectDone( 'kunaki_wc_order_needs_attention' )->never();

		( new Kunaki_Catch_Up() )->run();

		$this->assertSame( $before, $this->meta[100] ?? array(), 'No meta may be written.' );
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::NOTICE_OPTION, $this->options );
		$this->assertNotContains( 'kunaki_dashboard_counts', $this->transients_deleted );
		// The scan still finishes.
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::OPTION, $this->options );
	}

	public function orders_to_leave_alone() {
		$cases = array(
			'order not found'          => array( array( 'missing' => true ) ),
			'no longer processing'     => array( array( 'status' => 'completed' ) ),
			'no Kunaki items'          => array( array( 'kunaki_id' => '' ) ),
			'no paid date'             => array( array( 'paid' => null ) ),
			'durable submitted marker' => array( array( 'row' => '_kunaki_submitted_100' ) ),
			'durable failed marker'    => array( array( 'row' => '_kunaki_failed_100' ) ),
			'submission lock held'     => array( array( 'row' => '_kunaki_lock_100' ) ),
			'submission queued'        => array( array( 'queued' => true ) ),
		);
		$meta_keys = array(
			Fulfillment::META_KUNAKI_SUBMITTED       => 'yes',
			Fulfillment::META_KUNAKI_ORDER_ID        => 'KUN123',
			Fulfillment::META_FULFILLMENT_ERROR      => 'Connection timed out',
			Fulfillment::META_FULFILLMENT_FAILED     => '2026-01-15T10:00:00+00:00',
			Fulfillment::META_FULFILLMENT_EXHAUSTED  => 'yes',
			Fulfillment::META_FULFILLMENT_LAST_ERROR => 'Invalid product ID',
			Fulfillment::META_KUNAKI_STATUS          => 'DECLINED',
		);
		foreach ( $meta_keys as $key => $value ) {
			$cases[ 'already has ' . $key ] = array( array( 'meta' => array( $key => $value ) ) );
		}
		return $cases;
	}

	public function test_a_long_window_is_scanned_one_batch_per_run() {
		$this->pending_window();
		// One more order than a batch holds. Only the last one exists, so the
		// first run checks a full batch without handing anything over.
		$last = Kunaki_Catch_Up::BATCH_SIZE + 1;
		$this->query_ids = range( $last, 1 ); // Returned out of order on purpose.
		// The first and the last order exist; the ones between were deleted.
		$this->add_order( 1, array( 'paid' => time() - HOUR_IN_SECONDS ), false );
		$this->add_order( 2, array( 'paid' => time() - 2 * DAY_IN_SECONDS ), false );
		$this->add_order( $last, array( 'paid' => time() - 2 * DAY_IN_SECONDS ), false );

		// Each run reports what it held: one order in the first, one in the second.
		Actions\expectDone( 'kunaki_wc_catch_up_orders_held' )->twice()->with( 1 );

		$catch_up = new Kunaki_Catch_Up();
		$catch_up->run();

		// Oldest first, so the newest order has not been reached yet.
		$this->assertArrayHasKey( Fulfillment::META_FULFILLMENT_ERROR, $this->meta[1] );
		$this->assertSame( 'yes', $this->meta[2][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		$this->assertArrayNotHasKey( Fulfillment::META_FULFILLMENT_EXHAUSTED, $this->meta[ $last ] );
		$state = $this->options[ Kunaki_Catch_Up::OPTION ];
		$this->assertSame( Kunaki_Catch_Up::BATCH_SIZE, $state['cursor'] );
		$this->assertArrayHasKey( 'to', $state );
		// What this batch did is carried to the next.
		$this->assertSame( 1, $state['sent'] );
		$this->assertSame( 1, $state['held'] );
		$this->assertSame( 0, $state['tries'] );
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::LOCK, $this->rows );

		$catch_up->run();

		$this->assertSame( 'yes', $this->meta[ $last ][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::OPTION, $this->options );
		$logs = implode( "\n", $this->logs );
		$this->assertStringContainsString( '"sent":1', $logs );
		$this->assertStringContainsString( '"held":2', $logs );
	}

	/**
	 * @dataProvider changes_during_a_batch
	 */
	public function test_a_window_that_changed_during_the_batch_is_left_alone( $changed_to ) {
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );

		// The plugin is deactivated (or deactivated and activated again) by
		// another request while this batch is running.
		$test                = $this;
		$this->on_order_load = function () use ( $test, $changed_to ) {
			if ( null === $changed_to ) {
				unset( $test->options[ Kunaki_Catch_Up::OPTION ] );
			} else {
				$test->options[ Kunaki_Catch_Up::OPTION ] = $changed_to;
			}
		};

		// The order in hand is held whatever happened to the window, so it is still reported.
		Actions\expectDone( 'kunaki_wc_catch_up_orders_held' )->once()->with( 1 );

		( new Kunaki_Catch_Up() )->run();

		// The order in hand is still recorded.
		$this->assertSame( 'yes', $this->meta[100][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		// But the option now describes a different window: not deleted, not overwritten.
		if ( null === $changed_to ) {
			$this->assertArrayNotHasKey( Kunaki_Catch_Up::OPTION, $this->options );
		} else {
			$this->assertSame( $changed_to, $this->options[ Kunaki_Catch_Up::OPTION ] );
		}
		$this->assertStringNotContainsString( 'Catch-up scan finished', implode( "\n", $this->logs ) );
		// It was read from the database, not from this request's cached copy:
		// once after taking the lock, and again before saving progress.
		$this->assertSame(
			array( array( Kunaki_Catch_Up::OPTION, 'options' ), array( Kunaki_Catch_Up::OPTION, 'options' ) ),
			$this->cache_deletes
		);
	}

	public function changes_during_a_batch() {
		return array(
			'deactivated again'           => array( array( 'from' => 500 ) ),
			'deactivated and reactivated' => array( array( 'from' => 500, 'to' => 999, 'cursor' => 0, 'sent' => 0, 'held' => 0 ) ),
			'option removed'              => array( null ),
		);
	}

	public function test_a_second_run_cannot_start_while_one_holds_the_lock() {
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );
		$this->rows[ Kunaki_Catch_Up::LOCK ] = (string) ( time() + 200 );

		( new Kunaki_Catch_Up() )->run();

		$this->assertSame( array(), $this->queries );
		$this->assertSame( array(), $this->meta[100] );
		$this->assertArrayHasKey( Kunaki_Catch_Up::LOCK, $this->rows, 'The other run keeps its lock.' );
	}

	public function test_the_window_is_read_again_once_the_lock_is_held() {
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );

		// Another run finished the scan between this run's first look at the
		// option and its taking the lock.
		$test = $this;
		Functions\when( 'wp_cache_delete' )->alias( function () use ( $test ) {
			unset( $test->options[ Kunaki_Catch_Up::OPTION ] );
			return true;
		} );

		( new Kunaki_Catch_Up() )->run();

		$this->assertSame( array(), $this->queries, 'Nothing is scanned from a stale copy of the window.' );
		$this->assertSame( array(), $this->meta[100] );
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::LOCK, $this->rows );
	}

	public function test_an_expired_lock_does_not_block_the_scan() {
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );
		// Left behind by a run that died.
		$this->rows[ Kunaki_Catch_Up::LOCK ] = (string) ( time() - 10 );

		( new Kunaki_Catch_Up() )->run();

		$this->assertSame( 'yes', $this->meta[100][ Fulfillment::META_FULFILLMENT_EXHAUSTED ] );
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::LOCK, $this->rows );
	}

	public function test_a_failing_query_is_caught_so_the_retry_cron_still_runs() {
		$this->pending_window();
		Functions\when( 'wc_get_orders' )->alias( function () {
			throw new \RuntimeException( 'database has gone away' );
		} );
		$state = $this->options[ Kunaki_Catch_Up::OPTION ];

		// retry_failed_orders() runs next on the same hook: nothing may escape.
		( new Kunaki_Catch_Up() )->run();

		$this->assertStringContainsString( 'database has gone away', implode( "\n", $this->logs ) );
		$this->assertArrayNotHasKey( Kunaki_Catch_Up::LOCK, $this->rows, 'The lock is released.' );
		$this->assertSame( $state, $this->options[ Kunaki_Catch_Up::OPTION ], 'The scan stays pending for the next tick.' );
	}

	public function test_running_the_scan_again_changes_nothing() {
		$this->pending_window();
		$this->add_order( 100, array( 'paid' => time() - 2 * DAY_IN_SECONDS ) );

		// The replay holds nothing new, so it reports nothing.
		Actions\expectDone( 'kunaki_wc_catch_up_orders_held' )->once()->with( 1 );

		$catch_up = new Kunaki_Catch_Up();
		$catch_up->run();
		$after_first = $this->meta[100];

		// Replay the same window, as an interrupted scan would.
		$this->pending_window();
		$catch_up->run();

		$this->assertSame( $after_first, $this->meta[100] );
		$this->assertSame( 1, $this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] );
	}

	// --- The admin notice ---

	public function test_notice_says_how_many_orders_were_held_and_links_to_the_failures_page() {
		$this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] = 3;
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		( new Kunaki_Catch_Up() )->render_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( '3 disc orders were paid and were not sent to Kunaki automatically', $html );
		$this->assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=kunaki-failed-orders"', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
	}

	public function test_notice_for_a_single_order() {
		$this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] = 1;
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		( new Kunaki_Catch_Up() )->render_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( '1 disc order was paid and was not sent to Kunaki automatically', $html );
	}

	public function test_no_notice_when_nothing_was_held() {
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		( new Kunaki_Catch_Up() )->render_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_no_notice_for_users_who_cannot_manage_orders() {
		$this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] = 3;
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( false );

		ob_start();
		( new Kunaki_Catch_Up() )->render_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_clearing_the_notice_removes_the_count() {
		$this->options[ Kunaki_Catch_Up::NOTICE_OPTION ] = 3;

		Kunaki_Catch_Up::clear_notice();

		$this->assertArrayNotHasKey( Kunaki_Catch_Up::NOTICE_OPTION, $this->options );
	}

	// --- Helpers ---

	/**
	 * Leave a scan pending, as the activation hook does.
	 */
	private function pending_window( $from = null, $to = null ) {
		$to   = null === $to ? time() - 60 : $to;
		$from = null === $from ? $to - 7 * DAY_IN_SECONDS : $from;

		$this->options[ Kunaki_Catch_Up::OPTION ] = array(
			'from'   => $from,
			'to'     => $to,
			'cursor' => 0,
			'sent'   => 0,
			'held'   => 0,
		);
	}

	/**
	 * Add a Processing disc order with no Kunaki record, unless $setup says otherwise.
	 *
	 * The order keeps its meta in $this->meta, so a test can see exactly what
	 * the scan (through the real fulfillment class) wrote.
	 *
	 * @param int   $id       Order ID.
	 * @param array $setup    status, paid (timestamp or null), kunaki_shipping (bool), kunaki_id, meta.
	 * @param bool  $in_query Whether to add the ID to the paid-date query result.
	 */
	private function add_order( $id, array $setup = array(), $in_query = true ) {
		$setup += array(
			'status'          => 'processing',
			'paid'            => time() - HOUR_IN_SECONDS,
			'kunaki_shipping' => true,
			'kunaki_id'       => 'PXZZ111111',
			'meta'            => array(),
		);
		$test             = $this;
		$this->meta[ $id ] = $setup['meta'];

		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product_id' )->andReturn( '' === $setup['kunaki_id'] ? 43 : 42 );
		$item->shouldReceive( 'get_variation_id' )->andReturn( 0 );

		$shipping = array();
		if ( $setup['kunaki_shipping'] ) {
			$line = Mockery::mock( 'WC_Order_Item_Shipping' );
			$line->shouldReceive( 'get_method_id' )->andReturn( 'kunaki_shipping' );
			$line->shouldReceive( 'get_meta' )->with( 'kunaki_shipping_description' )->andReturn( 'USPS First Class Mail' );
			$shipping[] = $line;
		} else {
			$line = Mockery::mock( 'WC_Order_Item_Shipping' );
			$line->shouldReceive( 'get_method_id' )->andReturn( 'flat_rate' );
			$shipping[] = $line;
		}

		$paid = null;
		if ( null !== $setup['paid'] ) {
			$paid = Mockery::mock( 'WC_DateTime' );
			$paid->shouldReceive( 'getTimestamp' )->andReturn( $setup['paid'] );
		}

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_status' )->andReturn( $setup['status'] );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( $shipping );
		$order->shouldReceive( 'get_date_paid' )->andReturn( $paid );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key ) use ( $test, $id ) {
			return isset( $test->meta[ $id ][ $key ] ) ? $test->meta[ $id ][ $key ] : '';
		} );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing( function ( $key, $value ) use ( $test, $id ) {
			$test->meta[ $id ][ $key ] = $value;
		} );
		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing( function ( $key ) use ( $test, $id ) {
			unset( $test->meta[ $id ][ $key ] );
		} );
		$order->shouldReceive( 'save_meta_data' )->andReturn( null );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );

		$this->orders[ $id ] = $order;
		if ( $in_query ) {
			$this->query_ids[] = $id;
		}

		return $order;
	}

	private function wire_mocks() {
		$test = $this;

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $test ) {
			return array_key_exists( $key, $test->options ) ? $test->options[ $key ] : $default;
		} );
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( $test ) {
			$test->options[ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_option' )->alias( function ( $key ) use ( $test ) {
			unset( $test->options[ $key ] );
			return true;
		} );
		Functions\when( 'wp_cache_delete' )->alias( function ( $key, $group = '' ) use ( $test ) {
			$test->cache_deletes[] = array( $key, $group );
			return true;
		} );
		Functions\when( 'delete_transient' )->alias( function ( $key ) use ( $test ) {
			$test->transients_deleted[] = $key;
			return true;
		} );
		Functions\when( 'wc_get_orders' )->alias( function ( $args ) use ( $test ) {
			$test->queries[] = $args;
			return $test->query_ids;
		} );
		Functions\when( 'wc_get_order' )->alias( function ( $id ) use ( $test ) {
			if ( is_callable( $test->on_order_load ) ) {
				call_user_func( $test->on_order_load, $id );
			}
			if ( ! isset( $test->orders[ $id ] ) ) {
				return false;
			}
			if ( $test->orders[ $id ] instanceof \Throwable ) {
				throw $test->orders[ $id ];
			}
			return $test->orders[ $id ];
		} );
		// Product 42 is a disc; anything else is not.
		Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key ) {
			return 42 === $post_id && '_kunaki_product_id' === $key ? 'PXZZ111111' : '';
		} );
		Functions\when( 'as_next_scheduled_action' )->alias( function ( $hook, $args, $group ) use ( $test ) {
			return 'kunaki_wc_submit_order' === $hook
				&& 'kunaki-woocommerce' === $group
				&& in_array( $args['order_id'], $test->queued, true );
		} );
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
		} );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '_n' )->alias( function ( $single, $plural, $number ) {
			return 1 === (int) $number ? $single : $plural;
		} );

		$logger = Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing();
		$logger->shouldReceive( 'log' )->andReturnUsing( function ( $level, $message ) use ( $test ) {
			$test->logs[] = (string) $message;
		} );
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// The scan lock, the per-order locks and the durable markers are rows
		// read and written with raw SQL.
		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( $query, ...$args ) {
			return array( 'query' => $query, 'args' => $args );
		} );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( function ( $prepared ) use ( $test ) {
			// has_kunaki_record(): how many of these rows exist.
			return count( array_intersect( $prepared['args'], array_keys( $test->rows ) ) );
		} );
		$wpdb->shouldReceive( 'query' )->andReturnUsing( function ( $prepared ) use ( $test ) {
			list( $query, $args ) = array( $prepared['query'], $prepared['args'] );
			$key                  = $args[0];
			if ( 0 === strpos( $query, 'INSERT IGNORE' ) ) {
				if ( isset( $test->rows[ $key ] ) ) {
					return 0;
				}
				$test->rows[ $key ] = $args[1];
				return 1;
			}
			// Only an expired lock is deleted this way.
			if ( 0 === strpos( $query, 'DELETE' ) && isset( $test->rows[ $key ] ) && (int) $test->rows[ $key ] < (int) $args[1] ) {
				unset( $test->rows[ $key ] );
				return 1;
			}
			return 0;
		} );
		$wpdb->shouldReceive( 'delete' )->andReturnUsing( function ( $table, $where ) use ( $test ) {
			unset( $test->rows[ $where['option_name'] ] );
			return 1;
		} );
		$GLOBALS['wpdb'] = $wpdb;
	}
}

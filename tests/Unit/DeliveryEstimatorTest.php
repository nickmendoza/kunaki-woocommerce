<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Delivery_Estimator;

class DeliveryEstimatorTest extends KunakiTestCase {

	/** @var Kunaki_Delivery_Estimator */
	private $estimator;

	protected function set_up() {
		parent::set_up();
		$this->estimator = new Kunaki_Delivery_Estimator();

		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);
	}

	// --- parse_transit_days ---

	public function test_parse_single_day() {
		$this->assertSame( array( 5, 5 ), $this->estimator->parse_transit_days( '5 days' ) );
	}

	public function test_parse_singular_day() {
		$this->assertSame( array( 1, 1 ), $this->estimator->parse_transit_days( '1 day' ) );
	}

	public function test_parse_range() {
		$this->assertSame( array( 2, 3 ), $this->estimator->parse_transit_days( '2-3 days' ) );
	}

	public function test_parse_range_with_spaces() {
		$this->assertSame( array( 5, 10 ), $this->estimator->parse_transit_days( '5 - 10 days' ) );
	}

	public function test_parse_business_days_single() {
		// 5 business days → ceil(5 * 7/5) = 7 calendar days
		$this->assertSame( array( 7, 7 ), $this->estimator->parse_transit_days( '5 Business Days' ) );
	}

	public function test_parse_business_days_range() {
		// 2 business → ceil(2*7/5)=3, 3 business → ceil(3*7/5)=5
		$this->assertSame( array( 3, 5 ), $this->estimator->parse_transit_days( '2-3 Business Days' ) );
	}

	public function test_parse_business_days_case_insensitive() {
		$this->assertSame( array( 3, 5 ), $this->estimator->parse_transit_days( '2-3 business days' ) );
	}

	public function test_parse_en_dash_range() {
		$this->assertSame( array( 5, 10 ), $this->estimator->parse_transit_days( "5\xE2\x80\x9310 days" ) );
	}

	public function test_parse_no_numbers_returns_fallback() {
		$this->assertSame( array( 3, 10 ), $this->estimator->parse_transit_days( 'unknown' ) );
	}

	public function test_parse_empty_string_returns_fallback() {
		$this->assertSame( array( 3, 10 ), $this->estimator->parse_transit_days( '' ) );
	}

	// --- format_date_range ---

	public function test_format_same_day() {
		Functions\expect( 'wp_date' )->twice()->andReturn( 'Mar 25' );

		$result = $this->estimator->format_date_range( 1742860800, 1742860800 );
		$this->assertSame( 'Mar 25', $result );
	}

	public function test_format_different_days() {
		Functions\expect( 'wp_date' )
			->twice()
			->andReturn( 'Mar 25', 'Mar 28' );

		$result = $this->estimator->format_date_range( 1742860800, 1743120000 );
		$this->assertSame( "Mar 25\xE2\x80\x93Mar 28", $result );
	}

	public function test_format_returns_empty_on_wp_date_failure() {
		Functions\expect( 'wp_date' )->andReturn( false );

		$result = $this->estimator->format_date_range( 1742860800, 1743120000 );
		$this->assertSame( '', $result );
	}

	// --- get_manufacturing_stats ---

	public function test_stats_returns_null_with_insufficient_data() {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );

		$this->assertNull( $this->estimator->get_manufacturing_stats() );
	}

	public function test_stats_returns_null_below_minimum_sample() {
		Functions\expect( 'get_transient' )->once()->andReturn( false );

		$orders = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$order = Mockery::mock( 'WC_Order' );
			$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted_at' )
				->andReturn( gmdate( 'c', strtotime( '-5 days' ) ) );
			$order->shouldReceive( 'get_meta' )->with( '_kunaki_shipped_at' )
				->andReturn( gmdate( 'c', strtotime( '-3 days' ) ) );
			$orders[] = $order;
		}

		Functions\expect( 'wc_get_orders' )->once()->andReturn( $orders );

		$this->assertNull( $this->estimator->get_manufacturing_stats() );
	}

	public function test_stats_computed_with_sufficient_data() {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once()
			->with( 'kunaki_manufacturing_stats', Mockery::type( 'array' ), 6 * HOUR_IN_SECONDS );

		$now    = time();
		$orders = array();
		// Create 10 orders with manufacturing durations: 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 days
		for ( $i = 1; $i <= 10; $i++ ) {
			$order = Mockery::mock( 'WC_Order' );
			$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted_at' )
				->andReturn( gmdate( 'c', $now - $i * DAY_IN_SECONDS * 2 ) );
			$order->shouldReceive( 'get_meta' )->with( '_kunaki_shipped_at' )
				->andReturn( gmdate( 'c', $now - $i * DAY_IN_SECONDS * 2 + $i * DAY_IN_SECONDS ) );
			$orders[] = $order;
		}

		Functions\expect( 'wc_get_orders' )->once()->andReturn( $orders );

		$stats = $this->estimator->get_manufacturing_stats();

		$this->assertIsArray( $stats );
		$this->assertSame( 10, $stats['sample_size'] );
		$this->assertSame( 5.5, $stats['mean_days'] );
		// Sorted: 1,2,3,4,5,6,7,8,9,10. P25=floor(10*0.25)=index 2 → 3. P75=index 7 → 8.
		$this->assertSame( 3.0, $stats['p25_days'] );
		$this->assertSame( 8.0, $stats['p75_days'] );
	}

	public function test_stats_uses_transient_cache() {
		$cached = array(
			'mean_days'   => 3.5,
			'p25_days'    => 2.0,
			'p75_days'    => 5.0,
			'sample_size' => 50,
		);
		Functions\expect( 'get_transient' )->once()
			->with( 'kunaki_manufacturing_stats' )
			->andReturn( $cached );

		// Should NOT query orders.
		Functions\expect( 'wc_get_orders' )->never();

		$stats = $this->estimator->get_manufacturing_stats();
		$this->assertSame( $cached, $stats );
	}

	public function test_stats_skips_invalid_durations() {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once();

		$now    = time();
		$orders = array();

		// 5 valid orders with 2-day manufacturing
		for ( $i = 0; $i < 5; $i++ ) {
			$order = Mockery::mock( 'WC_Order' );
			$order->shouldReceive( 'get_meta' )->with( '_kunaki_submitted_at' )
				->andReturn( gmdate( 'c', $now - 4 * DAY_IN_SECONDS ) );
			$order->shouldReceive( 'get_meta' )->with( '_kunaki_shipped_at' )
				->andReturn( gmdate( 'c', $now - 2 * DAY_IN_SECONDS ) );
			$orders[] = $order;
		}

		// 1 order with shipped_at BEFORE submitted_at (invalid — skipped)
		$bad = Mockery::mock( 'WC_Order' );
		$bad->shouldReceive( 'get_meta' )->with( '_kunaki_submitted_at' )
			->andReturn( gmdate( 'c', $now - 1 * DAY_IN_SECONDS ) );
		$bad->shouldReceive( 'get_meta' )->with( '_kunaki_shipped_at' )
			->andReturn( gmdate( 'c', $now - 3 * DAY_IN_SECONDS ) );
		$orders[] = $bad;

		Functions\expect( 'wc_get_orders' )->once()->andReturn( $orders );

		$stats = $this->estimator->get_manufacturing_stats();

		$this->assertIsArray( $stats );
		$this->assertSame( 5, $stats['sample_size'] );
		$this->assertSame( 2.0, $stats['mean_days'] );
	}

	// --- estimate_delivery ---

	public function test_estimate_returns_null_without_stats() {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );

		$this->assertNull( $this->estimator->estimate_delivery( '5 days' ) );
	}

	public function test_estimate_returns_date_range() {
		$stats = array(
			'mean_days'   => 3.0,
			'p25_days'    => 2.0,
			'p75_days'    => 4.0,
			'sample_size' => 20,
		);
		Functions\expect( 'get_transient' )->once()->andReturn( $stats );
		Functions\expect( 'wp_date' )->twice()->andReturn( 'Mar 29', 'Apr 1' );

		$result = $this->estimator->estimate_delivery( '5 days' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'earliest', $result );
		$this->assertArrayHasKey( 'latest', $result );
		$this->assertArrayHasKey( 'label', $result );
		// min_total = ceil(2.0 + 5) = 7 days, max_total = ceil(4.0 + 5) = 9 days
		$expected_earliest = time() + 7 * DAY_IN_SECONDS;
		$expected_latest   = time() + 9 * DAY_IN_SECONDS;
		$this->assertEqualsWithDelta( $expected_earliest, $result['earliest'], 5 );
		$this->assertEqualsWithDelta( $expected_latest, $result['latest'], 5 );
	}

	public function test_estimate_min_floor_is_one_day() {
		$stats = array(
			'mean_days'   => 0.5,
			'p25_days'    => 0.2,
			'p75_days'    => 0.8,
			'sample_size' => 10,
		);
		Functions\expect( 'get_transient' )->once()->andReturn( $stats );
		Functions\expect( 'wp_date' )->twice()->andReturn( 'Mar 22', 'Mar 22' );

		$result = $this->estimator->estimate_delivery( '1 day' );

		// min_total = max(1, ceil(0.2 + 1)) = max(1, 2) = 2
		// max_total = max(2, ceil(0.8 + 1)) = max(2, 2) = 2
		$expected = time() + 2 * DAY_IN_SECONDS;
		$this->assertEqualsWithDelta( $expected, $result['earliest'], 5 );
		$this->assertEqualsWithDelta( $expected, $result['latest'], 5 );
	}

	public function test_estimate_max_not_less_than_min() {
		$stats = array(
			'mean_days'   => 5.0,
			'p25_days'    => 4.0,
			'p75_days'    => 3.0, // Unusual: p75 < p25 — should still produce valid range
			'sample_size' => 10,
		);
		Functions\expect( 'get_transient' )->once()->andReturn( $stats );
		Functions\expect( 'wp_date' )->twice()->andReturn( 'Mar 25', 'Mar 25' );

		$result = $this->estimator->estimate_delivery( '1 day' );

		$this->assertGreaterThanOrEqual( $result['earliest'], $result['latest'] );
	}

	public function test_estimate_with_business_days_transit() {
		$stats = array(
			'mean_days'   => 3.0,
			'p25_days'    => 2.0,
			'p75_days'    => 4.0,
			'sample_size' => 20,
		);
		Functions\expect( 'get_transient' )->once()->andReturn( $stats );
		Functions\expect( 'wp_date' )->twice()->andReturn( 'Mar 30', 'Apr 4' );

		$result = $this->estimator->estimate_delivery( '3-5 Business Days' );

		// Transit: 3 biz→ceil(3*7/5)=5, 5 biz→ceil(5*7/5)=7
		// min_total = ceil(2.0 + 5) = 7, max_total = ceil(4.0 + 7) = 11
		$expected_earliest = time() + 7 * DAY_IN_SECONDS;
		$expected_latest   = time() + 11 * DAY_IN_SECONDS;
		$this->assertEqualsWithDelta( $expected_earliest, $result['earliest'], 5 );
		$this->assertEqualsWithDelta( $expected_latest, $result['latest'], 5 );
	}
}

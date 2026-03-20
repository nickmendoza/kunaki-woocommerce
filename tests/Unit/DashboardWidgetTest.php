<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Dashboard_Widget;
use Kunaki_Order_Fulfillment;

class DashboardWidgetTest extends KunakiTestCase {

	/** @var Kunaki_Dashboard_Widget */
	private $widget;

	protected function set_up() {
		parent::set_up();
		$this->widget = new Kunaki_Dashboard_Widget();

		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);
	}

	public function test_init_registers_dashboard_setup_hook() {
		Functions\expect( 'add_action' )
			->once()->with( 'wp_dashboard_setup', Mockery::type( 'array' ) );

		$this->widget->init();
	}

	public function test_register_widget_skips_without_capability() {
		Functions\expect( 'current_user_can' )
			->once()->with( 'manage_woocommerce' )->andReturn( false );

		Functions\expect( 'wp_add_dashboard_widget' )->never();

		$this->widget->register_widget();
	}

	public function test_register_widget_adds_widget_with_capability() {
		Functions\expect( 'current_user_can' )
			->once()->with( 'manage_woocommerce' )->andReturn( true );

		Functions\expect( 'wp_add_dashboard_widget' )
			->once()
			->with( 'kunaki_orders_summary', Mockery::type( 'string' ), Mockery::type( 'array' ) );

		$this->widget->register_widget();
	}

	public function test_render_shows_zero_counts_with_no_orders() {
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\expect( 'wc_get_orders' )->times( 5 )->andReturn( array() );
		Functions\when( 'admin_url' )->returnArg();

		ob_start();
		$this->widget->render_widget();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/Fulfilling.*?>0</', $html );
		$this->assertMatchesRegularExpression( '/Action needed.*?>0</', $html );
		$this->assertMatchesRegularExpression( '/Retrying.*?>0</', $html );
		$this->assertStringNotContainsString( 'circuit breaker', $html );
	}

	public function test_render_shows_correct_counts() {
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();

		// submitted: [1,2,3,4], shipped: [4], exhausted: [10], retrying: [30,31,32,33]
		// fulfilling = diff([1,2,3,4], [4]) = 3
		// action_needed = unique(merge([10], [4])) = 2
		// retrying = 4
		Functions\expect( 'wc_get_orders' )
			->times( 5 )
			->andReturn(
				array( 1, 2, 3, 4 ),    // submitted
				array( 4 ),             // shipped
				array( 10 ),            // exhausted
				array( 30, 31, 32, 33 ), // retrying
				array()                  // estimator (no data)
			);

		ob_start();
		$this->widget->render_widget();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/Fulfilling.*?>3</', $html );
		$this->assertMatchesRegularExpression( '/Action needed.*?>2</', $html );
		$this->assertMatchesRegularExpression( '/Retrying.*?>4</', $html );
	}

	public function test_render_deduplicates_action_needed() {
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();

		// Order 5 is both exhausted AND shipped — should only count once.
		Functions\expect( 'wc_get_orders' )
			->times( 5 )
			->andReturn(
				array(),        // submitted
				array( 5 ),    // shipped
				array( 5, 6 ), // exhausted (5 overlaps with shipped)
				array(),        // retrying
				array()         // estimator (no data)
			);

		ob_start();
		$this->widget->render_widget();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/Action needed.*?>2</', $html );
	}

	public function test_render_uses_transient_cache() {
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );
		Functions\when( 'admin_url' )->returnArg();

		// Return cached dashboard counts; estimator stats return false (no data).
		$dashboard_counts = array( 'fulfilling' => 7, 'action_needed' => 1, 'retrying' => 0 );
		Functions\when( 'get_transient' )->alias( function ( $key ) use ( $dashboard_counts ) {
			if ( Kunaki_Dashboard_Widget::TRANSIENT_KEY === $key ) {
				return $dashboard_counts;
			}
			return false;
		} );
		// Estimator queries for stats when its transient is a cache miss.
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );

		ob_start();
		$this->widget->render_widget();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/Fulfilling.*?>7</', $html );
		$this->assertMatchesRegularExpression( '/Action needed.*?>1</', $html );
		$this->assertMatchesRegularExpression( '/Retrying.*?>0</', $html );
	}

	public function test_render_shows_circuit_breaker_open_warning() {
		$cb_state = wp_json_encode( array(
			'state'           => 'open',
			'failure_count'   => 5,
			'last_failure_at' => time(),
			'opened_at'       => time(),
		) );

		Functions\expect( 'get_option' )
			->with( 'kunaki_wc_circuit_breaker', '' )
			->andReturn( $cb_state );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\expect( 'wc_get_orders' )->times( 5 )->andReturn( array() );
		Functions\when( 'admin_url' )->returnArg();

		ob_start();
		$this->widget->render_widget();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'circuit breaker is open', $html );
	}

	public function test_render_shows_circuit_breaker_half_open_warning() {
		$cb_state = wp_json_encode( array(
			'state'           => 'half_open',
			'failure_count'   => 5,
			'last_failure_at' => time(),
			'opened_at'       => time(),
		) );

		Functions\expect( 'get_option' )
			->with( 'kunaki_wc_circuit_breaker', '' )
			->andReturn( $cb_state );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\expect( 'wc_get_orders' )->times( 5 )->andReturn( array() );
		Functions\when( 'admin_url' )->returnArg();

		ob_start();
		$this->widget->render_widget();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'half-open', $html );
	}

	public function test_render_links_to_orders_page() {
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\expect( 'wc_get_orders' )->times( 5 )->andReturn( array() );

		$urls = array();
		Functions\expect( 'admin_url' )->zeroOrMoreTimes()->andReturnUsing( function ( $path ) use ( &$urls ) {
			$urls[] = $path;
			return 'https://example.com/' . $path;
		} );

		ob_start();
		$this->widget->render_widget();
		ob_get_clean();

		// Without wc_get_page_screen_id defined, falls back to legacy URL.
		$this->assertContains( 'edit.php?post_type=shop_order&post_status=wc-processing', $urls );
		$this->assertContains( 'admin.php?page=kunaki-failed-orders', $urls );
	}
}

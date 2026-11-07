<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Kunaki_WooCommerce;

class WooCommerceBootstrapTest extends KunakiTestCase {

	public function test_register_shipping_method_adds_kunaki() {
		$instance = $this->create_instance();
		$methods  = $instance->register_shipping_method( array() );

		$this->assertArrayHasKey( 'kunaki_shipping', $methods );
		$this->assertSame( 'WC_Shipping_Kunaki', $methods['kunaki_shipping'] );
	}

	public function test_register_shipping_method_preserves_existing() {
		$instance = $this->create_instance();
		$existing = array( 'flat_rate' => 'WC_Shipping_Flat_Rate' );
		$methods  = $instance->register_shipping_method( $existing );

		$this->assertArrayHasKey( 'flat_rate', $methods );
		$this->assertArrayHasKey( 'kunaki_shipping', $methods );
	}

	public function test_plugin_action_links_prepends_settings() {
		$instance = $this->create_instance();

		Functions\expect( 'admin_url' )
			->once()
			->with( 'admin.php?page=wc-settings&tab=kunaki' )
			->andReturn( 'https://example.com/wp-admin/admin.php?page=wc-settings&tab=kunaki' );

		$links  = array( '<a href="#">Deactivate</a>' );
		$result = $instance->plugin_action_links( $links );

		$this->assertCount( 2, $result );
		$this->assertStringContainsString( 'Settings', $result[0] );
		$this->assertStringContainsString( 'wc-settings&tab=kunaki', $result[0] );
	}

	public function test_registers_shipping_package_split_filter() {
		Functions\expect( 'add_filter' )
			->atLeast()
			->once()
			->with( 'woocommerce_cart_shipping_packages', \Mockery::type( 'array' ) )
			->andReturn( true );
		Functions\expect( 'add_filter' )
			->zeroOrMoreTimes()
			->andReturn( true );
		Functions\expect( 'add_action' )
			->zeroOrMoreTimes()
			->andReturn( true );

		$ref = new \ReflectionClass( Kunaki_WooCommerce::class );
		$prop = $ref->getProperty( 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, null );

		Kunaki_WooCommerce::instance();
	}

	public function test_instance_returns_singleton() {
		$a = $this->create_instance();
		$b = Kunaki_WooCommerce::instance();

		$this->assertSame( $a, $b );
	}

	public function test_enqueue_assets_null_screen_returns_early() {
		$instance = $this->create_instance();

		Functions\when( 'get_current_screen' )->justReturn( null );
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		$instance->enqueue_admin_assets( '' );
	}

	public function test_enqueue_assets_css_only_screen() {
		$instance = $this->create_instance();

		$screen     = new \stdClass();
		$screen->id = 'product';
		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'wc_get_page_screen_id' )->justReturn( '__none__' );

		Functions\expect( 'wp_enqueue_style' )->once()
			->with( 'kunaki-admin', \Mockery::type( 'string' ), \Mockery::type( 'array' ), KUNAKI_WC_VERSION );
		Functions\expect( 'wp_enqueue_script' )->never();

		$instance->enqueue_admin_assets( '' );
	}

	public function test_enqueue_assets_retry_screen() {
		$instance = $this->create_instance();

		$screen     = new \stdClass();
		$screen->id = 'shop_order';
		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'wc_get_page_screen_id' )->justReturn( '__none__' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );

		Functions\expect( 'wp_enqueue_style' )->once();
		Functions\expect( 'wp_enqueue_script' )->once()
			->with( 'kunaki-retry', \Mockery::type( 'string' ), \Mockery::type( 'array' ), KUNAKI_WC_VERSION, true );
		Functions\expect( 'wp_localize_script' )->once()
			->with( 'kunaki-retry', 'kunakiRetry', \Mockery::on( function ( $data ) {
				return isset( $data['nonce'] ) && isset( $data['i18n'] );
			} ) );

		$instance->enqueue_admin_assets( '' );
	}

	public function test_enqueue_assets_hpos_screen() {
		$instance = $this->create_instance();

		$screen     = new \stdClass();
		$screen->id = 'woocommerce_page_wc-orders';
		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'woocommerce_page_wc-orders' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );

		Functions\expect( 'wp_enqueue_style' )->once();
		Functions\expect( 'wp_enqueue_script' )->once();
		Functions\expect( 'wp_localize_script' )->once();

		$instance->enqueue_admin_assets( '' );
	}

	public function test_enqueue_assets_unrelated_screen() {
		$instance = $this->create_instance();

		$screen     = new \stdClass();
		$screen->id = 'edit-post';
		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'wc_get_page_screen_id' )->justReturn( '__none__' );

		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		$instance->enqueue_admin_assets( '' );
	}

	public function test_enqueue_assets_dashboard_screen() {
		$instance = $this->create_instance();

		$screen     = new \stdClass();
		$screen->id = 'dashboard';
		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( 'wc_get_page_screen_id' )->justReturn( '__none__' );

		Functions\expect( 'wp_enqueue_style' )->once()
			->with( 'kunaki-admin', \Mockery::type( 'string' ), \Mockery::type( 'array' ), KUNAKI_WC_VERSION );
		Functions\expect( 'wp_enqueue_script' )->never();

		$instance->enqueue_admin_assets( '' );
	}

	private function create_instance() {
		// Stub hooks used during construction.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		// Reset singleton for a clean test.
		$ref = new \ReflectionClass( Kunaki_WooCommerce::class );
		$prop = $ref->getProperty( 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, null );

		return Kunaki_WooCommerce::instance();
	}
}

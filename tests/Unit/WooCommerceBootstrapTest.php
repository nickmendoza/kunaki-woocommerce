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
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		Kunaki_WooCommerce::instance();
	}

	public function test_instance_returns_singleton() {
		$a = $this->create_instance();
		$b = Kunaki_WooCommerce::instance();

		$this->assertSame( $a, $b );
	}

	private function create_instance() {
		// Stub hooks used during construction.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		// Reset singleton for a clean test.
		$ref = new \ReflectionClass( Kunaki_WooCommerce::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		return Kunaki_WooCommerce::instance();
	}
}

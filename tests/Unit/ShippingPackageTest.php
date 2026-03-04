<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Kunaki_WooCommerce;

class ShippingPackageTest extends KunakiTestCase {

	private $instance;

	protected function set_up() {
		parent::set_up();
		$this->instance = $this->create_instance();
	}

	public function test_mixed_cart_splits_into_two_packages() {
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return 10 === $id ? 'PX00ZJTM4R' : '';
		} );

		$packages = array( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
			array( 'product_id' => 20, 'quantity' => 1 ),
		) ) );

		$result = $this->instance->split_shipping_packages( $packages );

		$this->assertCount( 2, $result );
		$this->assertCount( 1, $result[0]['contents'] );
		$this->assertCount( 1, $result[1]['contents'] );
	}

	public function test_kunaki_only_cart_returns_single_package() {
		Functions\when( 'get_post_meta' )->justReturn( 'PX00ZJTM4R' );

		$packages = array( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
			array( 'product_id' => 20, 'quantity' => 1 ),
		) ) );

		$result = $this->instance->split_shipping_packages( $packages );

		$this->assertCount( 1, $result );
	}

	public function test_non_kunaki_only_cart_returns_single_package() {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$packages = array( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) ) );

		$result = $this->instance->split_shipping_packages( $packages );

		$this->assertCount( 1, $result );
	}

	public function test_destination_preserved_on_both_packages() {
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return 10 === $id ? 'PX00ZJTM4R' : '';
		} );

		$packages = array( $this->build_package(
			array(
				array( 'product_id' => 10, 'quantity' => 1 ),
				array( 'product_id' => 20, 'quantity' => 1 ),
			),
			'US', 'TX', '77377'
		) );

		$result = $this->instance->split_shipping_packages( $packages );

		$this->assertSame( 'US', $result[0]['destination']['country'] );
		$this->assertSame( 'TX', $result[0]['destination']['state'] );
		$this->assertSame( 'US', $result[1]['destination']['country'] );
		$this->assertSame( 'TX', $result[1]['destination']['state'] );
	}

	public function test_multiple_kunaki_products_stay_in_same_package() {
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return in_array( $id, array( 10, 20 ), true ) ? 'PX00ZJTM4R' : '';
		} );

		$packages = array( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
			array( 'product_id' => 20, 'quantity' => 2 ),
			array( 'product_id' => 30, 'quantity' => 1 ),
		) ) );

		$result = $this->instance->split_shipping_packages( $packages );

		$this->assertCount( 2, $result );
		$this->assertCount( 2, $result[0]['contents'] );
		$this->assertCount( 1, $result[1]['contents'] );
	}

	public function test_split_packages_have_names() {
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return 10 === $id ? 'PX00ZJTM4R' : '';
		} );

		$packages = array( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
			array( 'product_id' => 20, 'quantity' => 1 ),
		) ) );

		$result = $this->instance->split_shipping_packages( $packages );

		$this->assertSame( 'Disc Shipping', $result[0]['package_name'] );
		$this->assertSame( 'Other Items', $result[1]['package_name'] );
	}

	public function test_kunaki_package_hides_non_kunaki_rates() {
		Functions\when( 'get_post_meta' )->justReturn( 'PX00ZJTM4R' );

		$package = $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) );

		$rates = array(
			'flat_rate:1'      => $this->make_rate( 'flat_rate', 0 ),
			'kunaki_shipping:usps' => $this->make_rate( 'kunaki_shipping', 5.72 ),
		);

		$result = $this->instance->filter_package_rates( $rates, $package );

		$this->assertArrayHasKey( 'kunaki_shipping:usps', $result );
		$this->assertArrayNotHasKey( 'flat_rate:1', $result );
	}

	public function test_non_kunaki_package_hides_kunaki_rates() {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$package = $this->build_package( array(
			array( 'product_id' => 20, 'quantity' => 1 ),
		) );

		$rates = array(
			'flat_rate:1'      => $this->make_rate( 'flat_rate', 0 ),
			'kunaki_shipping:usps' => $this->make_rate( 'kunaki_shipping', 5.72 ),
		);

		$result = $this->instance->filter_package_rates( $rates, $package );

		$this->assertArrayHasKey( 'flat_rate:1', $result );
		$this->assertArrayNotHasKey( 'kunaki_shipping:usps', $result );
	}

	public function test_mixed_package_keeps_all_rates() {
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return 10 === $id ? 'PX00ZJTM4R' : '';
		} );

		$package = $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
			array( 'product_id' => 20, 'quantity' => 1 ),
		) );

		$rates = array(
			'flat_rate:1'      => $this->make_rate( 'flat_rate', 0 ),
			'kunaki_shipping:usps' => $this->make_rate( 'kunaki_shipping', 5.72 ),
		);

		$result = $this->instance->filter_package_rates( $rates, $package );

		$this->assertCount( 2, $result );
	}

	private function make_rate( $method_id, $cost ) {
		$rate = new \stdClass();
		$rate->method_id = $method_id;
		$rate->cost      = $cost;
		return $rate;
	}

	private function build_package( $items, $country = 'US', $state = 'CA', $postcode = '90210' ) {
		$contents = array();
		foreach ( $items as $index => $item ) {
			$contents[ $index ] = $item;
		}
		return array(
			'contents'    => $contents,
			'destination' => array( 'country' => $country, 'state' => $state, 'postcode' => $postcode ),
		);
	}

	private function create_instance() {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		$ref = new \ReflectionClass( Kunaki_WooCommerce::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		return Kunaki_WooCommerce::instance();
	}
}

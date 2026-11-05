<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Kunaki_WooCommerce;

class ShippingPackageTest extends KunakiTestCase {

	private $instance;

	protected function set_up() {
		parent::set_up();
		$this->instance = $this->create_instance();
		// No Kunaki outage unless a test says otherwise.
		Functions\when( 'get_transient' )->justReturn( false );
	}

	public function test_kunaki_packages_tagged_during_outage_so_wc_recalculates() {
		Functions\when( 'get_transient' )->alias( function ( $key ) {
			return 'kunaki_wc_rates_unavailable' === $key ? 1700000000 : false;
		} );
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return 10 === $id ? 'PX00ZJTM4R' : '';
		} );

		$result = $this->instance->split_shipping_packages( array( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
			array( 'product_id' => 20, 'quantity' => 1 ),
		) ) ) );

		$this->assertSame( 1700000000, $result[0]['kunaki_rates_unavailable'] );
		$this->assertArrayNotHasKey( 'kunaki_rates_unavailable', $result[1] );
	}

	public function test_packages_not_tagged_without_outage() {
		Functions\when( 'get_post_meta' )->justReturn( 'PX00ZJTM4R' );

		$result = $this->instance->split_shipping_packages( array( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1 ),
		) ) ) );

		$this->assertArrayNotHasKey( 'kunaki_rates_unavailable', $result[0] );
	}

	public function test_no_shipping_message_explains_outage_for_disc_carts() {
		Functions\when( 'get_transient' )->justReturn( 1700000000 );
		Functions\when( 'get_post_meta' )->justReturn( 'PX00ZJTM4R' );
		$this->mock_cart( array( array( 'product_id' => 10 ) ) );

		$html = $this->instance->rates_unavailable_message( 'No shipping options were found.' );

		$this->assertStringContainsString( 'temporarily unavailable', $html );
	}

	public function test_no_shipping_message_unchanged_without_outage_or_discs() {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->mock_cart( array( array( 'product_id' => 20 ) ) );

		$this->assertSame( 'default', $this->instance->rates_unavailable_message( 'default' ) );

		Functions\when( 'get_transient' )->justReturn( 1700000000 );
		$this->assertSame( 'default', $this->instance->rates_unavailable_message( 'default' ) );
	}

	public function test_block_notice_shown_when_disc_package_has_no_rates_during_outage() {
		$this->start_outage_with_disc_cart( array( array( 'contents' => array( array( 'product_id' => 10 ) ), 'rates' => array() ) ) );

		$html = $this->instance->block_rates_unavailable_notice( '<div>checkout</div>' );

		$this->assertStringContainsString( 'temporarily unavailable', $html );
		$this->assertStringEndsWith( '<div>checkout</div>', $html );
	}

	public function test_block_notice_hidden_when_cached_rates_still_work() {
		$this->start_outage_with_disc_cart( array( array( 'contents' => array( array( 'product_id' => 10 ) ), 'rates' => array( 'kunaki:1' => new \stdClass() ) ) ) );

		$this->assertSame( '<div>checkout</div>', $this->instance->block_rates_unavailable_notice( '<div>checkout</div>' ) );
	}

	public function test_block_notice_hidden_without_outage() {
		$this->mock_cart( array( array( 'product_id' => 10 ) ) );

		$this->assertSame( '<div>checkout</div>', $this->instance->block_rates_unavailable_notice( '<div>checkout</div>' ) );
	}

	public function test_variation_with_own_id_is_split_from_parent_without_one() {
		Functions\when( 'get_post_meta' )->alias( function ( $id ) {
			return 11 === $id ? 'PX00ZJTM4R' : '';
		} );

		$result = $this->instance->split_shipping_packages( array( $this->build_package( array(
			array( 'product_id' => 10, 'variation_id' => 11, 'quantity' => 1 ),
			array( 'product_id' => 10, 'variation_id' => 12, 'quantity' => 1 ),
		) ) ) );

		$this->assertCount( 2, $result );
	}

	private function start_outage_with_disc_cart( $packages ) {
		Functions\when( 'get_transient' )->justReturn( 1700000000 );
		Functions\when( 'get_post_meta' )->justReturn( 'PX00ZJTM4R' );
		$this->mock_cart( array( array( 'product_id' => 10 ) ), $packages );
	}

	private function mock_cart( $items, $packages = array() ) {
		$cart = \Mockery::mock( 'WC_Cart' );
		$cart->shouldReceive( 'get_cart' )->andReturn( $items );
		$cart->shouldReceive( 'calculate_shipping' );
		$shipping = \Mockery::mock( 'WC_Shipping' );
		$shipping->shouldReceive( 'get_packages' )->andReturn( $packages );
		$wc       = \Mockery::mock( 'WooCommerce' );
		$wc->cart = $cart;
		$wc->shouldReceive( 'shipping' )->andReturn( $shipping );
		Functions\when( 'WC' )->justReturn( $wc );
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

	public function test_split_packages_recalculate_contents_cost() {
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return 10 === $id ? 'PX00ZJTM4R' : '';
		} );

		$packages = array( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1, 'line_total' => 15.00 ),
			array( 'product_id' => 20, 'quantity' => 2, 'line_total' => 40.00 ),
		), 'US', 'CA', '90210', 55.00 ) );

		$result = $this->instance->split_shipping_packages( $packages );

		$this->assertCount( 2, $result );
		$this->assertSame( 15.00, $result[0]['contents_cost'] );
		$this->assertSame( 40.00, $result[1]['contents_cost'] );
	}

	public function test_unsplit_package_preserves_original_contents_cost() {
		Functions\when( 'get_post_meta' )->justReturn( 'PX00ZJTM4R' );

		$packages = array( $this->build_package( array(
			array( 'product_id' => 10, 'quantity' => 1, 'line_total' => 15.00 ),
			array( 'product_id' => 20, 'quantity' => 1, 'line_total' => 25.00 ),
		), 'US', 'CA', '90210', 40.00 ) );

		$result = $this->instance->split_shipping_packages( $packages );

		$this->assertCount( 1, $result );
		$this->assertSame( 40.00, $result[0]['contents_cost'] );
	}

	public function test_multiple_input_packages_each_split_independently() {
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
			return in_array( $id, array( 10, 30 ), true ) ? 'PX00ZJTM4R' : '';
		} );

		$packages = array(
			$this->build_package( array(
				array( 'product_id' => 10, 'quantity' => 1, 'line_total' => 10.00 ),
				array( 'product_id' => 20, 'quantity' => 1, 'line_total' => 20.00 ),
			), 'US', 'CA', '90210', 30.00 ),
			$this->build_package( array(
				array( 'product_id' => 30, 'quantity' => 1, 'line_total' => 5.00 ),
				array( 'product_id' => 40, 'quantity' => 1, 'line_total' => 8.00 ),
			), 'US', 'TX', '77377', 13.00 ),
		);

		$result = $this->instance->split_shipping_packages( $packages );

		$this->assertCount( 4, $result );
		// First package split.
		$this->assertSame( 10.00, $result[0]['contents_cost'] );
		$this->assertSame( 'CA', $result[0]['destination']['state'] );
		$this->assertSame( 20.00, $result[1]['contents_cost'] );
		$this->assertSame( 'CA', $result[1]['destination']['state'] );
		// Second package split.
		$this->assertSame( 5.00, $result[2]['contents_cost'] );
		$this->assertSame( 'TX', $result[2]['destination']['state'] );
		$this->assertSame( 8.00, $result[3]['contents_cost'] );
		$this->assertSame( 'TX', $result[3]['destination']['state'] );
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

	private function build_package( $items, $country = 'US', $state = 'CA', $postcode = '90210', $contents_cost = null ) {
		$contents = array();
		foreach ( $items as $index => $item ) {
			$item += array( 'line_total' => 0 );
			$contents[ $index ] = $item;
		}
		if ( null === $contents_cost ) {
			$contents_cost = array_sum( wp_list_pluck( $contents, 'line_total' ) );
		}
		return array(
			'contents'      => $contents,
			'contents_cost' => $contents_cost,
			'destination'   => array( 'country' => $country, 'state' => $state, 'postcode' => $postcode ),
		);
	}

	private function create_instance() {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		$ref = new \ReflectionClass( Kunaki_WooCommerce::class );
		$prop = $ref->getProperty( 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, null );

		return Kunaki_WooCommerce::instance();
	}
}

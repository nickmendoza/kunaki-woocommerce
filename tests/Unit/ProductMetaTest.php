<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Product_Meta;

class ProductMetaTest extends KunakiTestCase {

	protected function tear_down() {
		unset( $_POST['_kunaki_product_id'], $_POST['woocommerce_meta_nonce'], $_POST[ Kunaki_Product_Meta::VARIATION_FIELD ] );
		parent::tear_down();
	}

	private function stub_nonce() {
		$_POST['woocommerce_meta_nonce'] = 'test_nonce';
		Functions\expect( 'sanitize_key' )->with( 'test_nonce' )->andReturn( 'test_nonce' );
		Functions\expect( 'wp_verify_nonce' )->with( 'test_nonce', 'woocommerce_save_data' )->andReturn( 1 );
	}

	public function test_get_product_id() {
		Functions\expect( 'get_post_meta' )
			->once()->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );

		$this->assertSame( 'PXZZ111111', Kunaki_Product_Meta::get_kunaki_product_id( 42 ) );
	}

	public function test_get_product_id_empty() {
		Functions\expect( 'get_post_meta' )
			->once()->with( 42, '_kunaki_product_id', true )->andReturn( '' );

		$this->assertSame( '', Kunaki_Product_Meta::get_kunaki_product_id( 42 ) );
	}

	public function test_variation_id_overrides_parent() {
		Functions\when( 'get_post_meta' )->alias( function ( $id ) {
			return array( 42 => 'PXPARENT01', 43 => 'PXVARIANT1' )[ $id ] ?? '';
		} );

		$this->assertSame( 'PXVARIANT1', Kunaki_Product_Meta::get_kunaki_id_for_item( 42, 43 ) );
	}

	public function test_variation_without_id_uses_parent() {
		Functions\when( 'get_post_meta' )->alias( function ( $id ) {
			return 42 === $id ? 'PXPARENT01' : '';
		} );

		$this->assertSame( 'PXPARENT01', Kunaki_Product_Meta::get_kunaki_id_for_item( 42, 44 ) );
	}

	public function test_variation_is_kunaki_even_when_parent_has_no_id() {
		Functions\when( 'get_post_meta' )->alias( function ( $id ) {
			return 43 === $id ? 'PXVARIANT1' : '';
		} );

		$this->assertSame( 'PXVARIANT1', Kunaki_Product_Meta::get_kunaki_id_for_cart_item( array( 'product_id' => 42, 'variation_id' => 43 ) ) );
		$this->assertSame( '', Kunaki_Product_Meta::get_kunaki_id_for_cart_item( array( 'product_id' => 42, 'variation_id' => 44 ) ) );
		$this->assertSame( '', Kunaki_Product_Meta::get_kunaki_id_for_cart_item( array( 'product_id' => 42 ) ) );
	}

	public function test_save_variation_field_saves_valid_id() {
		$_POST[ Kunaki_Product_Meta::VARIATION_FIELD ] = array( 2 => 'PXVARIANT1' );
		$this->stub_variation_save();
		Functions\expect( 'update_post_meta' )->once()->with( 43, '_kunaki_product_id', 'PXVARIANT1' );

		( new Kunaki_Product_Meta() )->save_variation_field( 43, 2 );
	}

	public function test_save_variation_field_blank_removes_id_to_inherit_parent() {
		$_POST[ Kunaki_Product_Meta::VARIATION_FIELD ] = array( 2 => '' );
		$this->stub_variation_save();
		Functions\expect( 'delete_post_meta' )->once()->with( 43, '_kunaki_product_id' );
		Functions\expect( 'update_post_meta' )->never();

		( new Kunaki_Product_Meta() )->save_variation_field( 43, 2 );
	}

	public function test_save_variation_field_rejects_invalid_id() {
		$_POST[ Kunaki_Product_Meta::VARIATION_FIELD ] = array( 2 => 'tooshort' );
		$this->stub_variation_save();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\expect( 'set_transient' )->once();
		Functions\expect( 'update_post_meta' )->never();

		( new Kunaki_Product_Meta() )->save_variation_field( 43, 2 );
	}

	public function test_save_variation_field_requires_capability() {
		$_POST[ Kunaki_Product_Meta::VARIATION_FIELD ] = array( 2 => 'PXVARIANT1' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->never();

		( new Kunaki_Product_Meta() )->save_variation_field( 43, 2 );
	}

	private function stub_variation_save() {
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	public function test_save_valid_id() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( true );
		$this->stub_nonce();
		$_POST['_kunaki_product_id'] = 'PXZZ111111';

		Functions\expect( 'update_post_meta' )
			->once()->with( 42, '_kunaki_product_id', 'PXZZ111111' );

		$meta = new Kunaki_Product_Meta();
		$meta->save_field( 42 );
	}

	public function test_save_empty_clears_id() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( true );
		$this->stub_nonce();
		$_POST['_kunaki_product_id'] = '';

		Functions\expect( 'update_post_meta' )
			->once()->with( 42, '_kunaki_product_id', '' );

		$meta = new Kunaki_Product_Meta();
		$meta->save_field( 42 );
	}

	public function test_save_rejects_short_id() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( true );
		$this->stub_nonce();
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'set_transient' )->once()
			->with( 'kunaki_wc_product_meta_error_1', Mockery::pattern( '/exactly 10 alphanumeric/' ), 30 );
		Functions\expect( 'update_post_meta' )->never();

		$_POST['_kunaki_product_id'] = 'SHORT';
		$meta = new Kunaki_Product_Meta();
		$meta->save_field( 42 );
	}

	public function test_save_rejects_special_chars() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( true );
		$this->stub_nonce();
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'set_transient' )->once();
		Functions\expect( 'update_post_meta' )->never();

		$_POST['_kunaki_product_id'] = 'PX-ZZ_1111';
		$meta = new Kunaki_Product_Meta();
		$meta->save_field( 42 );
	}

	public function test_save_rejects_long_id() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( true );
		$this->stub_nonce();
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'set_transient' )->once();
		Functions\expect( 'update_post_meta' )->never();

		$_POST['_kunaki_product_id'] = 'PXZZ11111199';
		$meta = new Kunaki_Product_Meta();
		$meta->save_field( 42 );
	}

	public function test_save_skips_when_field_not_in_post() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( true );
		$this->stub_nonce();
		Functions\expect( 'update_post_meta' )->never();

		unset( $_POST['_kunaki_product_id'] );
		$meta = new Kunaki_Product_Meta();
		$meta->save_field( 42 );
	}

	public function test_save_checks_capability() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( false );
		Functions\expect( 'update_post_meta' )->never();

		$_POST['_kunaki_product_id'] = 'PXZZ111111';
		$meta = new Kunaki_Product_Meta();
		$meta->save_field( 42 );
	}

	public function test_add_column() {
		$meta    = new Kunaki_Product_Meta();
		$columns = $meta->add_column( array( 'title' => 'Title' ) );

		$this->assertArrayHasKey( 'kunaki_product_id', $columns );
		$this->assertSame( 'Kunaki ID', $columns['kunaki_product_id'] );
	}

	public function test_validation_error_display() {
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_transient' )
			->once()->with( 'kunaki_wc_product_meta_error_1' )->andReturn( 'Test error message' );
		Functions\expect( 'delete_transient' )
			->once()->with( 'kunaki_wc_product_meta_error_1' );

		$meta = new Kunaki_Product_Meta();
		ob_start();
		$meta->display_validation_errors();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Test error message', $output );
	}

	public function test_no_validation_error() {
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_transient' )
			->once()->with( 'kunaki_wc_product_meta_error_1' )->andReturn( false );
		Functions\expect( 'delete_transient' )->never();

		$meta = new Kunaki_Product_Meta();
		ob_start();
		$meta->display_validation_errors();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}
}

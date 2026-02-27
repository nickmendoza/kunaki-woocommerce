<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Kunaki_Product_Meta;

class ProductMetaTest extends KunakiTestCase {

	protected function tear_down() {
		unset( $_POST['_kunaki_product_id'] );
		parent::tear_down();
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

	public function test_is_kunaki_product_true() {
		Functions\expect( 'get_post_meta' )
			->once()->with( 42, '_kunaki_product_id', true )->andReturn( 'PXZZ111111' );

		$this->assertTrue( Kunaki_Product_Meta::is_kunaki_product( 42 ) );
	}

	public function test_is_kunaki_product_false() {
		Functions\expect( 'get_post_meta' )
			->once()->with( 42, '_kunaki_product_id', true )->andReturn( '' );

		$this->assertFalse( Kunaki_Product_Meta::is_kunaki_product( 42 ) );
	}

	public function test_save_valid_id() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( true );
		$_POST['_kunaki_product_id'] = 'PXZZ111111';

		Functions\expect( 'update_post_meta' )
			->once()->with( 42, '_kunaki_product_id', 'PXZZ111111' );

		$meta = new Kunaki_Product_Meta();
		$meta->save_field( 42 );
	}

	public function test_save_empty_clears_id() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( true );
		$_POST['_kunaki_product_id'] = '';

		Functions\expect( 'update_post_meta' )
			->once()->with( 42, '_kunaki_product_id', '' );

		$meta = new Kunaki_Product_Meta();
		$meta->save_field( 42 );
	}

	public function test_save_rejects_short_id() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( true );
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
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'set_transient' )->once();
		Functions\expect( 'update_post_meta' )->never();

		$_POST['_kunaki_product_id'] = 'PX-ZZ_1111';
		$meta = new Kunaki_Product_Meta();
		$meta->save_field( 42 );
	}

	public function test_save_rejects_long_id() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( true );
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'set_transient' )->once();
		Functions\expect( 'update_post_meta' )->never();

		$_POST['_kunaki_product_id'] = 'PXZZ11111199';
		$meta = new Kunaki_Product_Meta();
		$meta->save_field( 42 );
	}

	public function test_save_skips_when_field_not_in_post() {
		Functions\expect( 'current_user_can' )->with( 'edit_product', 42 )->andReturn( true );
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

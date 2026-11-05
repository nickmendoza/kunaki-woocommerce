<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;

class UninstallTest extends KunakiTestCase {

	/** @var string[] Values passed to DELETE queries via prepare(). */
	private $deleted_keys = array();

	protected function set_up() {
		parent::set_up();
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		$this->deleted_keys = array();

		$keys = &$this->deleted_keys;
		$GLOBALS['wpdb'] = new class( $keys ) {
			public $options  = 'wp_options';
			public $postmeta = 'wp_postmeta';
			public $prefix   = 'wp_';
			private $keys;

			public function __construct( array &$keys ) {
				$this->keys = &$keys;
			}
			public function prepare( $query, $value ) {
				if ( 0 === strpos( $query, 'DELETE' ) ) {
					$this->keys[] = $value;
				}
				return $query;
			}
			public function esc_like( $text ) {
				return $text;
			}
			public function query( $query ) {
				return 1;
			}
			public function get_var( $query ) {
				return 'wp_wc_orders_meta';
			}
		};

		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'as_unschedule_all_actions' )->justReturn( 1 );
		Functions\when( 'esc_sql' )->returnArg();
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	public function test_order_history_and_product_links_kept_by_default() {
		$this->uninstall( 'no' );

		$this->assertNotContains( '_kunaki_order_id', $this->deleted_keys );
		$this->assertNotContains( '_kunaki_tracking_id', $this->deleted_keys );
		$this->assertNotContains( '_kunaki_product_id', $this->deleted_keys );
		$this->assertNotContains( '_kunaki_submitted_%', $this->deleted_keys );
	}

	public function test_temporary_data_is_always_removed() {
		$this->uninstall( 'no' );

		$this->assertContains( '_kunaki_lock_%', $this->deleted_keys );
		$this->assertContains( '_transient_kunaki_ship_%', $this->deleted_keys );
	}

	public function test_all_data_removed_when_store_opted_in() {
		$this->uninstall( 'yes' );

		$this->assertContains( '_kunaki_order_id', $this->deleted_keys );
		$this->assertContains( '_kunaki_tracking_id', $this->deleted_keys );
		$this->assertContains( '_kunaki_product_id', $this->deleted_keys );
		$this->assertContains( '_kunaki_submitted_%', $this->deleted_keys );
	}

	private function uninstall( $delete_data ) {
		$deleted = array();
		// Like the real thing: reads see what's left after earlier deletes.
		Functions\when( 'delete_option' )->alias( function ( $key ) use ( &$deleted ) {
			$deleted[] = $key;
			return true;
		} );
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $delete_data, &$deleted ) {
			return 'kunaki_wc_delete_data_on_uninstall' === $key && ! in_array( $key, $deleted, true ) ? $delete_data : $default;
		} );

		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertContains( 'kunaki_wc_password', $deleted );
		$this->assertContains( 'kunaki_wc_delete_data_on_uninstall', $deleted );
	}
}

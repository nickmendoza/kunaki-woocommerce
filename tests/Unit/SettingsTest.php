<?php

namespace Kunaki\Tests\Unit;

use Kunaki_Settings;

class SettingsTest extends KunakiTestCase {

	/** @var Kunaki_Settings */
	private $settings;

	protected function set_up() {
		parent::set_up();
		$this->settings = new Kunaki_Settings();
	}

	public function test_settings_id_and_label() {
		$this->assertSame( 'kunaki', $this->settings->id );
		$this->assertSame( 'Kunaki', $this->settings->label );
	}

	public function test_settings_returns_expected_fields() {
		$fields = $this->settings->get_settings_for_default_section();

		// title + 6 fields + sectionend = 8
		$this->assertCount( 8, $fields );
	}

	public function test_settings_field_ids() {
		$fields = $this->settings->get_settings_for_default_section();

		$ids = array_column( $fields, 'id' );
		$this->assertContains( 'kunaki_wc_user_id', $ids );
		$this->assertContains( 'kunaki_wc_password', $ids );
		$this->assertContains( 'kunaki_wc_mode', $ids );
		$this->assertContains( 'kunaki_wc_sync_interval', $ids );
		$this->assertContains( 'kunaki_wc_max_retries', $ids );
		$this->assertContains( 'kunaki_wc_debug_log', $ids );
	}

	public function test_mode_defaults_to_test() {
		$fields  = $this->settings->get_settings_for_default_section();
		$mode    = $this->find_field( $fields, 'kunaki_wc_mode' );

		$this->assertSame( 'TEST', $mode['default'] );
		$this->assertArrayHasKey( 'TEST', $mode['options'] );
		$this->assertArrayHasKey( 'LIVE', $mode['options'] );
	}

	public function test_sync_interval_constraints() {
		$fields   = $this->settings->get_settings_for_default_section();
		$interval = $this->find_field( $fields, 'kunaki_wc_sync_interval' );

		$this->assertSame( 'number', $interval['type'] );
		$this->assertSame( 15, $interval['default'] );
		$this->assertSame( 5, $interval['custom_attributes']['min'] );
		$this->assertSame( 1440, $interval['custom_attributes']['max'] );
	}

	public function test_max_retries_constraints() {
		$fields  = $this->settings->get_settings_for_default_section();
		$retries = $this->find_field( $fields, 'kunaki_wc_max_retries' );

		$this->assertSame( 'number', $retries['type'] );
		$this->assertSame( 3, $retries['default'] );
		$this->assertSame( 1, $retries['custom_attributes']['min'] );
		$this->assertSame( 10, $retries['custom_attributes']['max'] );
	}

	public function test_debug_log_defaults_to_no() {
		$fields = $this->settings->get_settings_for_default_section();
		$debug  = $this->find_field( $fields, 'kunaki_wc_debug_log' );

		$this->assertSame( 'checkbox', $debug['type'] );
		$this->assertSame( 'no', $debug['default'] );
	}

	private function find_field( $fields, $id ) {
		foreach ( $fields as $field ) {
			if ( isset( $field['id'] ) && $field['id'] === $id ) {
				return $field;
			}
		}
		$this->fail( "Field '{$id}' not found in settings." );
	}
}

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

		// (title + 6 fields + sectionend) + (title + 2 fields + sectionend) = 12
		$this->assertCount( 12, $fields );
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

	public function test_circuit_threshold_constraints() {
		$fields    = $this->settings->get_settings_for_default_section();
		$threshold = $this->find_field( $fields, 'kunaki_wc_circuit_threshold' );

		$this->assertSame( 'number', $threshold['type'] );
		$this->assertSame( 5, $threshold['default'] );
		$this->assertSame( 2, $threshold['custom_attributes']['min'] );
		$this->assertSame( 20, $threshold['custom_attributes']['max'] );
	}

	public function test_circuit_cooldown_constraints() {
		$fields   = $this->settings->get_settings_for_default_section();
		$cooldown = $this->find_field( $fields, 'kunaki_wc_circuit_cooldown' );

		$this->assertSame( 'number', $cooldown['type'] );
		$this->assertSame( 5, $cooldown['default'] );
		$this->assertSame( 1, $cooldown['custom_attributes']['min'] );
		$this->assertSame( 60, $cooldown['custom_attributes']['max'] );
	}

	public function test_debug_log_defaults_to_no() {
		$fields = $this->settings->get_settings_for_default_section();
		$debug  = $this->find_field( $fields, 'kunaki_wc_debug_log' );

		$this->assertSame( 'checkbox', $debug['type'] );
		$this->assertSame( 'no', $debug['default'] );
	}

	/**
	 * @dataProvider clamp_option_provider
	 */
	public function test_clamp_option( $value, $min, $max, $default, $expected ) {
		$this->assertSame( $expected, kunaki_wc_clamp_option( $value, $min, $max, $default ) );
	}

	public static function clamp_option_provider() {
		return array(
			'within range'    => array( 10, 5, 1440, 15, 10 ),
			'at minimum'      => array( 5, 5, 1440, 15, 5 ),
			'at maximum'      => array( 1440, 5, 1440, 15, 1440 ),
			'below minimum'   => array( 0, 5, 1440, 15, 15 ),
			'above maximum'   => array( 9999, 5, 1440, 15, 15 ),
			'negative value'  => array( -1, 1, 10, 3, 1 ),
			'string value'    => array( 'abc', 1, 10, 3, 3 ),
		);
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

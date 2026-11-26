<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey\Functions;
use Kunaki_Settings;
use WC_Admin_Settings;

class SettingsTest extends KunakiTestCase {

	/** @var Kunaki_Settings */
	private $settings;

	protected function set_up() {
		parent::set_up();
		$this->settings            = new Kunaki_Settings();
		WC_Admin_Settings::$errors = array();
	}

	public function test_settings_id_and_label() {
		$this->assertSame( 'kunaki', $this->settings->id );
		$this->assertSame( 'Kunaki', $this->settings->label );
	}

	public function test_settings_returns_expected_fields() {
		$fields = $this->settings->get_settings_for_default_section();

		// (title + 7 fields + sectionend) + (title + 2 fields + sectionend)
		// + alerts (title + 5 fields + sectionend) = 20
		$this->assertCount( 20, $fields );
	}

	public function test_alerts_are_off_by_default_and_email_is_the_default_channel() {
		$fields = $this->settings->get_settings_for_default_section();

		$this->assertSame( 'no', $this->find_field( $fields, 'kunaki_wc_alerts_enabled' )['default'] );
		$this->assertSame( 'yes', $this->find_field( $fields, 'kunaki_wc_alert_email_enabled' )['default'] );
		$this->assertSame( 'no', $this->find_field( $fields, 'kunaki_wc_alert_slack_enabled' )['default'] );
		$this->assertSame( '', $this->find_field( $fields, 'kunaki_wc_alert_email_address' )['default'] );
		$this->assertSame( '', $this->find_field( $fields, 'kunaki_wc_alert_slack_webhook' )['default'] );
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

	public function test_password_is_never_rendered() {
		$password = $this->find_field( $this->settings->get_settings_for_default_section(), 'kunaki_wc_password' );

		$this->assertSame( '', $password['value'] );
	}

	public function test_blank_password_keeps_saved_one() {
		// WooCommerce skips saving an option when the sanitized value is null.
		$this->assertNull( $this->settings->keep_password_when_blank( '' ) );
		$this->assertSame( 'new-password', $this->settings->keep_password_when_blank( 'new-password' ) );
	}

	public function test_slack_url_check_is_registered() {
		$this->assertNotFalse( has_filter( 'woocommerce_admin_settings_sanitize_option_kunaki_wc_alert_slack_webhook', array( $this->settings, 'note_refused_slack_url' ) ) );
	}

	public function test_refused_slack_url_is_reported_after_saving() {
		$this->stub_saved_options( array() );

		// WooCommerce runs this while it saves the submitted fields.
		$kept = $this->settings->note_refused_slack_url( 'https://evil.example/hook' );
		$this->settings->save();

		$this->assertSame( 'https://evil.example/hook', $kept, 'The sanitize_option filter blanks it, not this one.' );
		$this->assertCount( 1, WC_Admin_Settings::$errors );
		$this->assertStringContainsString( 'The Slack webhook URL was not saved', WC_Admin_Settings::$errors[0] );
	}

	/**
	 * @dataProvider slack_urls_with_nothing_to_report
	 */
	public function test_accepted_or_blank_slack_url_is_not_reported( $submitted ) {
		$this->stub_saved_options( array() );

		$this->settings->note_refused_slack_url( $submitted );
		$this->settings->save();

		$this->assertSame( array(), WC_Admin_Settings::$errors );
	}

	public static function slack_urls_with_nothing_to_report() {
		return array(
			'slack url'     => array( 'https://hooks.slack.com/services/T/B/X' ),
			'blank'         => array( '' ),
			'only spaces'   => array( '   ' ),
			'not submitted' => array( null ),
		);
	}

	public function test_slack_alerts_on_without_a_url_are_reported() {
		$this->stub_saved_options( array(
			'kunaki_wc_alerts_enabled'      => 'yes',
			'kunaki_wc_alert_slack_enabled' => 'yes',
			'kunaki_wc_alert_slack_webhook' => '',
		) );

		$this->settings->save();

		$this->assertCount( 1, WC_Admin_Settings::$errors );
		$this->assertStringContainsString( 'there is no webhook URL', WC_Admin_Settings::$errors[0] );
	}

	/**
	 * @dataProvider slack_settings_that_need_no_warning
	 */
	public function test_no_warning_when_slack_is_off_or_has_a_url( $saved ) {
		$this->stub_saved_options( $saved );

		$this->settings->save();

		$this->assertSame( array(), WC_Admin_Settings::$errors );
	}

	public static function slack_settings_that_need_no_warning() {
		return array(
			'alerts off'        => array( array( 'kunaki_wc_alerts_enabled' => 'no', 'kunaki_wc_alert_slack_enabled' => 'yes' ) ),
			'Slack off'         => array( array( 'kunaki_wc_alerts_enabled' => 'yes', 'kunaki_wc_alert_slack_enabled' => 'no' ) ),
			'Slack with a URL'  => array( array( 'kunaki_wc_alerts_enabled' => 'yes', 'kunaki_wc_alert_slack_enabled' => 'yes', 'kunaki_wc_alert_slack_webhook' => 'https://hooks.slack.com/services/T/B/X' ) ),
			'nothing saved yet' => array( array() ),
		);
	}

	public function test_data_removal_is_opt_in() {
		$field = $this->find_field( $this->settings->get_settings_for_default_section(), 'kunaki_wc_delete_data_on_uninstall' );

		$this->assertSame( 'no', $field['default'] );
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

	/** What get_option() returns once WooCommerce has saved the page. */
	private function stub_saved_options( array $saved ) {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $saved ) {
			return array_key_exists( $key, $saved ) ? $saved[ $key ] : $default;
		} );
		Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) {
			return parse_url( $url, $component );
		} );
		Functions\when( 'esc_url_raw' )->returnArg();
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

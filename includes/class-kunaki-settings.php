<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Settings extends WC_Settings_Page {

	/** @var bool Whether the Slack webhook URL submitted in this save was refused. */
	private $slack_url_refused = false;

	public function __construct() {
		$this->id    = 'kunaki';
		$this->label = __( 'Kunaki', 'kunaki-woocommerce' );

		parent::__construct();

		add_filter( 'woocommerce_admin_settings_sanitize_option_kunaki_wc_password', array( $this, 'keep_password_when_blank' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_kunaki_wc_alert_slack_webhook', array( $this, 'note_refused_slack_url' ) );
	}

	/**
	 * Save the settings, then warn if Slack alerts can't be posted.
	 *
	 * A refused webhook URL is stored as blank, and WooCommerce still reports
	 * the settings as saved. Without a warning, a store that only uses Slack
	 * would lose every alert and not know.
	 */
	public function save() {
		parent::save();

		if ( $this->slack_url_refused ) {
			WC_Admin_Settings::add_error( __( 'The Slack webhook URL was not saved. It must be a Slack incoming webhook URL, starting with https://hooks.slack.com/. Slack alerts will not be posted until one is saved.', 'kunaki-woocommerce' ) );
			return;
		}

		if ( 'yes' === get_option( 'kunaki_wc_alerts_enabled', 'no' )
			&& 'yes' === get_option( 'kunaki_wc_alert_slack_enabled', 'no' )
			&& '' === (string) get_option( 'kunaki_wc_alert_slack_webhook', '' ) ) {
			WC_Admin_Settings::add_error( __( 'Slack alerts are on, but there is no webhook URL, so nothing will be posted to Slack.', 'kunaki-woocommerce' ) );
		}
	}

	/**
	 * Note when the submitted Slack webhook URL will be refused.
	 *
	 * The value is left as it is: the sanitize_option filter in the main plugin
	 * file is what blanks it, here and for every other way of saving the option.
	 *
	 * @param mixed $value Sanitized value.
	 * @return mixed
	 */
	public function note_refused_slack_url( $value ) {
		$this->slack_url_refused = is_string( $value ) && '' !== trim( $value ) && '' === Kunaki_Alerts::validate_webhook_option( $value );
		return $value;
	}

	/**
	 * Keep the saved password when the field is submitted blank.
	 *
	 * The field is never filled in (see below), so blank means "no change".
	 * WooCommerce skips saving an option whose sanitized value is null.
	 *
	 * @param mixed $value Sanitized value.
	 * @return mixed
	 */
	public function keep_password_when_blank( $value ) {
		return '' === $value ? null : $value;
	}

	public function get_settings_for_default_section() {
		return array(
			array(
				'title' => __( 'Kunaki API Settings', 'kunaki-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Configure your Kunaki publisher account credentials and integration behavior. Orders placed via the XML API require payment on your <a href="https://kunaki.com/accounting/XMLBilling.ASP" target="_blank">Kunaki billing page</a>. You can prepay your account balance so orders ship immediately.', 'kunaki-woocommerce' ),
				'id'    => 'kunaki_wc_api_settings',
			),
			array(
				'title'    => __( 'Kunaki Email (User ID)', 'kunaki-woocommerce' ),
				'desc'     => __( 'The email address associated with your Kunaki publisher account.', 'kunaki-woocommerce' ),
				'id'       => 'kunaki_wc_user_id',
				'type'     => 'email',
				'default'  => '',
				'desc_tip' => true,
				'css'      => 'min-width: 350px;',
			),
			array(
				'title'    => __( 'Kunaki Password', 'kunaki-woocommerce' ),
				'desc'     => __( 'Your Kunaki account password. For security it is never shown here. Leave blank to keep the saved password.', 'kunaki-woocommerce' ),
				'id'       => 'kunaki_wc_password',
				'type'     => 'password',
				// Never send the decrypted password to the browser.
				'value'    => '',
				'default'  => '',
				'desc_tip' => true,
				'css'      => 'min-width: 350px;',
				'custom_attributes' => array(
					'autocomplete' => 'new-password',
				),
			),
			array(
				'title'    => __( 'Mode', 'kunaki-woocommerce' ),
				'desc'     => __( 'Use TEST mode during development. Switch to LIVE for production orders.', 'kunaki-woocommerce' ),
				'id'       => 'kunaki_wc_mode',
				'type'     => 'select',
				'default'  => 'TEST',
				'options'  => array(
					'TEST' => __( 'Test', 'kunaki-woocommerce' ),
					'LIVE' => __( 'Live', 'kunaki-woocommerce' ),
				),
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Status Sync Interval (minutes)', 'kunaki-woocommerce' ),
				'desc'     => __( 'How often to check Kunaki for order status updates.', 'kunaki-woocommerce' ),
				'id'       => 'kunaki_wc_sync_interval',
				'type'     => 'number',
				'default'  => 15,
				'desc_tip' => true,
				'css'      => 'width: 80px;',
				'custom_attributes' => array(
					'min'  => 5,
					'max'  => 1440,
					'step' => 5,
				),
			),
			array(
				'title'    => __( 'Max Retry Attempts', 'kunaki-woocommerce' ),
				'desc'     => __( 'Maximum number of times to automatically retry a failed Kunaki submission.', 'kunaki-woocommerce' ),
				'id'       => 'kunaki_wc_max_retries',
				'type'     => 'number',
				'default'  => 3,
				'desc_tip' => true,
				'css'      => 'width: 80px;',
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 10,
					'step' => 1,
				),
			),
			array(
				'title'   => __( 'Enable Debug Logging', 'kunaki-woocommerce' ),
				'desc'    => __( 'Log API requests and responses to WooCommerce > Status > Logs.', 'kunaki-woocommerce' ),
				'id'      => 'kunaki_wc_debug_log',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( 'Remove Data on Uninstall', 'kunaki-woocommerce' ),
				'desc'    => __( 'When the plugin is deleted, also delete Kunaki order IDs, tracking details, and product IDs. This cannot be undone. Leave unchecked to keep your order history.', 'kunaki-woocommerce' ),
				'id'      => 'kunaki_wc_delete_data_on_uninstall',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'kunaki_wc_api_settings',
			),
			array(
				'title' => __( 'Circuit Breaker', 'kunaki-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Automatically stops API requests when Kunaki is unresponsive, preventing wasted time and retries.', 'kunaki-woocommerce' ),
				'id'    => 'kunaki_wc_circuit_breaker_settings',
			),
			array(
				'title'    => __( 'Failure Threshold', 'kunaki-woocommerce' ),
				'desc'     => __( 'Number of consecutive API failures before the circuit breaker trips.', 'kunaki-woocommerce' ),
				'id'       => 'kunaki_wc_circuit_threshold',
				'type'     => 'number',
				'default'  => 5,
				'desc_tip' => true,
				'css'      => 'width: 80px;',
				'custom_attributes' => array(
					'min'  => 2,
					'max'  => 20,
					'step' => 1,
				),
			),
			array(
				'title'    => __( 'Cooldown Period (minutes)', 'kunaki-woocommerce' ),
				'desc'     => __( 'How long to wait before allowing a probe request after the circuit trips.', 'kunaki-woocommerce' ),
				'id'       => 'kunaki_wc_circuit_cooldown',
				'type'     => 'number',
				'default'  => 5,
				'desc_tip' => true,
				'css'      => 'width: 80px;',
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 60,
					'step' => 1,
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'kunaki_wc_circuit_breaker_settings',
			),
			array(
				'title' => __( 'Alerts', 'kunaki-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Get an email or Slack message when an order needs you (it failed every retry, can\'t be sent until something is fixed, or Kunaki declined it), when Kunaki\'s API goes down and comes back, and when the saved Kunaki password can\'t be read.', 'kunaki-woocommerce' ),
				'id'    => 'kunaki_wc_alerts_settings',
			),
			array(
				'title'   => __( 'Enable Alerts', 'kunaki-woocommerce' ),
				'desc'    => __( 'Send alerts. When off, nothing is sent.', 'kunaki-woocommerce' ),
				'id'      => 'kunaki_wc_alerts_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( 'Email Alerts', 'kunaki-woocommerce' ),
				'desc'    => __( 'Send alerts by email.', 'kunaki-woocommerce' ),
				'id'      => 'kunaki_wc_alert_email_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'    => __( 'Alert Email Address', 'kunaki-woocommerce' ),
				'desc'     => __( 'Leave blank to use the site admin email.', 'kunaki-woocommerce' ),
				'id'       => 'kunaki_wc_alert_email_address',
				'type'     => 'email',
				'default'  => '',
				'desc_tip' => true,
				'css'      => 'min-width: 350px;',
			),
			array(
				'title'   => __( 'Slack Alerts', 'kunaki-woocommerce' ),
				'desc'    => __( 'Post alerts to a Slack channel.', 'kunaki-woocommerce' ),
				'id'      => 'kunaki_wc_alert_slack_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'    => __( 'Slack Webhook URL', 'kunaki-woocommerce' ),
				'desc'     => __( 'A Slack incoming webhook URL, starting with https://hooks.slack.com/. Other addresses are not saved.', 'kunaki-woocommerce' ),
				'id'       => 'kunaki_wc_alert_slack_webhook',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => true,
				'css'      => 'min-width: 350px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'kunaki_wc_alerts_settings',
			),
		);
	}
}

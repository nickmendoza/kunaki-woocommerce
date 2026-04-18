<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Settings extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'kunaki';
		$this->label = __( 'Kunaki', 'kunaki-woocommerce' );

		parent::__construct();
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
				'desc'     => __( 'Your Kunaki account password.', 'kunaki-woocommerce' ),
				'id'       => 'kunaki_wc_password',
				'type'     => 'password',
				'default'  => '',
				'desc_tip' => true,
				'css'      => 'min-width: 350px;',
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
				'desc'  => __( 'Out-of-band notifications for Kunaki API outages and orders that exhausted their retry budget. These alerts complement the in-admin notices so time-sensitive failures aren\'t missed while the admin is away from WordPress.', 'kunaki-woocommerce' ),
				'id'    => 'kunaki_wc_alerts_settings',
			),
			array(
				'title'   => __( 'Enable Alerts', 'kunaki-woocommerce' ),
				'desc'    => __( 'Master switch. When off, no email or Slack notifications are sent.', 'kunaki-woocommerce' ),
				'id'      => 'kunaki_wc_alerts_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( 'Send Email Alerts', 'kunaki-woocommerce' ),
				'desc'    => __( 'Send notifications via email.', 'kunaki-woocommerce' ),
				'id'      => 'kunaki_wc_alert_email_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'    => __( 'Alert Email Address', 'kunaki-woocommerce' ),
				'desc'     => __( 'Recipient for alert emails. Defaults to the site admin email if left blank.', 'kunaki-woocommerce' ),
				'id'       => 'kunaki_wc_alert_email_address',
				'type'     => 'email',
				'default'  => '',
				'desc_tip' => true,
				'css'      => 'min-width: 350px;',
			),
			array(
				'title'   => __( 'Send Slack Alerts', 'kunaki-woocommerce' ),
				'desc'    => __( 'Post notifications to a Slack incoming webhook.', 'kunaki-woocommerce' ),
				'id'      => 'kunaki_wc_alert_slack_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'    => __( 'Slack Webhook URL', 'kunaki-woocommerce' ),
				'desc'     => __( 'Slack incoming webhook URL (must be on hooks.slack.com).', 'kunaki-woocommerce' ),
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

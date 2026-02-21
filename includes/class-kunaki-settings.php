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
				'desc'  => __( 'Configure your Kunaki publisher account credentials and integration behavior.', 'kunaki-woocommerce' ),
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
		);
	}
}

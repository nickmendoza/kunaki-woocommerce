<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Settings extends WC_Settings_Page {

	const CIPHER_METHOD = 'aes-256-cbc';

	public function __construct() {
		$this->id    = 'kunaki';
		$this->label = __( 'Kunaki', 'kunaki-woocommerce' );

		// Transparently encrypt/decrypt the password in wp_options.
		add_filter( 'pre_update_option_kunaki_wc_password', array( __CLASS__, 'encrypt' ), 10, 1 );
		add_filter( 'option_kunaki_wc_password', array( __CLASS__, 'decrypt' ), 10, 1 );

		parent::__construct();
	}

	/**
	 * Encrypt a value using WordPress auth salts.
	 *
	 * @param string $value Plaintext value.
	 * @return string Base64-encoded IV + ciphertext, or empty string.
	 */
	public static function encrypt( $value ) {
		if ( '' === $value || ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER_METHOD ) );

		$encrypted = openssl_encrypt( $value, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a value encrypted with encrypt().
	 *
	 * @param string $value Base64-encoded IV + ciphertext.
	 * @return string Decrypted plaintext, or original value on failure.
	 */
	public static function decrypt( $value ) {
		if ( '' === $value || ! function_exists( 'openssl_decrypt' ) ) {
			return $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$data = base64_decode( $value, true );
		if ( false === $data ) {
			return $value;
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER_METHOD );
		if ( strlen( $data ) <= $iv_length ) {
			return $value;
		}

		$iv        = substr( $data, 0, $iv_length );
		$encrypted = substr( $data, $iv_length );
		$key       = hash( 'sha256', wp_salt( 'auth' ), true );

		$decrypted = openssl_decrypt( $encrypted, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $decrypted ) {
			// May be a legacy plaintext value — return as-is.
			return $value;
		}

		return $decrypted;
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

<?php
/**
 * PHPUnit bootstrap for Kunaki WooCommerce unit tests.
 *
 * Defines WordPress/WooCommerce stubs so plugin classes can be loaded
 * without a full WordPress installation. Brain Monkey handles function
 * mocking in individual tests via setUp/tearDown.
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ── WordPress constants ──────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'KUNAKI_WC_PLUGIN_DIR' ) ) {
	define( 'KUNAKI_WC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'KUNAKI_WC_PLUGIN_FILE' ) ) {
	define( 'KUNAKI_WC_PLUGIN_FILE', dirname( __DIR__ ) . '/kunaki-woocommerce.php' );
}
if ( ! defined( 'KUNAKI_WC_VERSION' ) ) {
	define( 'KUNAKI_WC_VERSION', '1.3.3' );
}
if ( ! defined( 'KUNAKI_WC_PLUGIN_BASENAME' ) ) {
	define( 'KUNAKI_WC_PLUGIN_BASENAME', 'kunaki-woocommerce/kunaki-woocommerce.php' );
}
if ( ! defined( 'KUNAKI_WC_PLUGIN_URL' ) ) {
	define( 'KUNAKI_WC_PLUGIN_URL', 'https://example.com/wp-content/plugins/kunaki-woocommerce/' );
}

// ── WP_Error stub ────────────────────────────────────────────────────
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		protected $code;
		protected $message;
		protected $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

// ── is_wp_error stub ─────────────────────────────────────────────────
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// ── WordPress text-domain stub ───────────────────────────────────────
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo $text;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return $text;
	}
}

// ── WordPress escaping/sanitizing stubs ──────────────────────────────
if ( ! function_exists( 'esc_xml' ) ) {
	function esc_xml( $text ) {
		return str_replace(
			array( '&', '<', '>', '"', "'" ),
			array( '&amp;', '&lt;', '&gt;', '&quot;', '&apos;' ),
			(string) $text
		);
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return strtolower( preg_replace( '/[^a-z0-9-]/', '-', strtolower( $title ) ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return stripslashes( $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'wp_check_invalid_utf8' ) ) {
	function wp_check_invalid_utf8( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, $decimals, '.', ',' );
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) {
		return array_map( function ( $item ) use ( $field ) {
			return is_object( $item ) ? $item->$field : $item[ $field ];
		}, $list );
	}
}

// ── Plugin helper functions ──────────────────────────────────────────
if ( ! function_exists( 'kunaki_wc_clamp_option' ) ) {
	function kunaki_wc_clamp_option( $value, $min, $max, $default ) {
		$value = absint( $value );
		if ( $value < $min || $value > $max ) {
			return $default;
		}
		return $value;
	}
}

// ── WooCommerce base class stubs ─────────────────────────────────────
if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {
		public $id           = '';
		public $instance_id  = 0;
		public $method_title = '';
		public $method_description = '';
		public $supports     = array();
		public $enabled      = 'yes';
		public $title        = '';
		protected $rates     = array();
		protected $instance_form_fields = array();

		public function init_form_fields() {}
		public function init_settings() {}
		public function process_admin_options() {}

		public function get_option( $key, $default = '' ) {
			return $default;
		}

		public function get_rate_id() {
			return $this->id . ':' . $this->instance_id;
		}

		public function add_rate( $args = array() ) {
			$this->rates[] = $args;
		}

		public function get_rates_added() {
			return $this->rates;
		}
	}
}

if ( ! class_exists( 'WC_Settings_Page' ) ) {
	class WC_Settings_Page {
		public $id    = '';
		public $label = '';

		public function __construct() {}
	}
}

// ── Load plugin source files ─────────────────────────────────────────
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/encryption.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-logger.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-circuit-breaker.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-api-client.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-product-meta.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-delivery-estimator.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-shipping-method.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-order-fulfillment.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-order-status-sync.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-retry-queue.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-dashboard-widget.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-alerts.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-settings.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-woocommerce.php';
require_once KUNAKI_WC_PLUGIN_DIR . 'includes/class-kunaki-cli-command.php';

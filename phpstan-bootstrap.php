<?php
/**
 * PHPStan bootstrap — defines WordPress/WooCommerce stubs for static analysis.
 */

// WordPress constants.
define( 'ABSPATH', __DIR__ . '/' );
define( 'KUNAKI_WC_VERSION', '1.1.0' );
define( 'KUNAKI_WC_PLUGIN_FILE', __DIR__ . '/kunaki-woocommerce.php' );
define( 'KUNAKI_WC_PLUGIN_DIR', __DIR__ . '/' );
define( 'KUNAKI_WC_PLUGIN_URL', 'https://example.com/wp-content/plugins/kunaki-woocommerce/' );
define( 'KUNAKI_WC_PLUGIN_BASENAME', 'kunaki-woocommerce/kunaki-woocommerce.php' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WP_UNINSTALL_PLUGIN', true );
// OPENSSL_RAW_DATA is already defined by PHP's OpenSSL extension.

// WP_Error stub.
class WP_Error {
	/** @var string */
	protected $code;
	/** @var string */
	protected $message;
	/** @var mixed */
	protected $data;

	/**
	 * @param string $code
	 * @param string $message
	 * @param mixed  $data
	 */
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	/** @return mixed */
	public function get_error_data() { return $this->data; }
}

/** @param mixed $thing */
function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }

// WordPress function stubs.
function get_option( string $key, $default = false ) { return $default; }
function update_option( string $key, $value, $autoload = null ): bool { return true; }
function delete_option( string $key ): bool { return true; }
function get_post_meta( int $post_id, string $key = '', bool $single = false ) { return ''; }
function update_post_meta( int $post_id, string $key, $value ): bool { return true; }
function get_transient( string $key ) { return false; }
function set_transient( string $key, $value, int $expiration = 0 ): bool { return true; }
function delete_transient( string $key ): bool { return true; }
function wp_remote_post( string $url, array $args = [] ) { return []; }
function wp_remote_retrieve_response_code( $response ): int { return 200; }
function wp_remote_retrieve_body( $response ): string { return ''; }
function wp_salt( string $scheme = 'auth' ): string { return 'salt'; }
/** @return string|false */
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_check_invalid_utf8( string $text ): string { return $text; }
function wp_kses_post( string $text ): string { return $text; }
function wp_unslash( $value ) { return $value; }
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool { return true; }
function wp_next_scheduled( string $hook ): bool { return false; }
function wp_clear_scheduled_hook( string $hook ): void {}
function wp_get_schedule( string $hook ): string { return ''; }
function add_action( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool { return true; }
function add_filter( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool { return true; }
function register_activation_hook( string $file, $callback ): void {}
function register_deactivation_hook( string $file, $callback ): void {}
function plugin_dir_path( string $file ): string { return __DIR__ . '/'; }
function plugin_dir_url( string $file ): string { return ''; }
function plugin_basename( string $file ): string { return ''; }
function admin_url( string $path = '' ): string { return ''; }
function current_user_can( string $capability, ...$args ): bool { return true; }
function get_current_user_id(): int { return 1; }
function get_current_screen(): ?object { return null; }
function wp_enqueue_style( string $handle, string $src = '', array $deps = [], $ver = false ): void {}
function wp_enqueue_script( string $handle, string $src = '', array $deps = [], $ver = false, bool $in_footer = false ): void {}
function wp_localize_script( string $handle, string $object_name, array $l10n ): bool { return true; }
function wp_create_nonce( string $action = '' ): string { return ''; }
function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, ?callable $callback = null ): void {}
function check_ajax_referer( string $action, string $query_arg = 'nonce' ): bool { return true; }
function wp_send_json_error( $data = null, ?int $status_code = null ): void {}
function wp_send_json_success( $data = null, ?int $status_code = null ): void {}
function esc_html( string $text ): string { return $text; }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function esc_html_e( string $text, string $domain = 'default' ): void { echo $text; }
function esc_attr__( string $text, string $domain = 'default' ): string { return $text; }
function esc_url( string $url ): string { return $url; }
function esc_attr( string $text ): string { return $text; }
function __( string $text, string $domain = 'default' ): string { return $text; }
function absint( $value ): int { return abs( (int) $value ); }
function sanitize_text_field( string $str ): string { return $str; }
function sanitize_title( string $title ): string { return $title; }
function esc_xml( string $text ): string { return $text; }
function woocommerce_wp_text_input( array $args ): void {}

// WooCommerce stubs.
function wc_get_order( int $id ) { return null; }
/** @return \WC_Order[] */
function wc_get_orders( array $args ): array { return []; }
function wc_get_logger(): object { return new class { public function log( string $level, string $message, array $context = [] ): void {} public function error( string $message, array $context = [] ): void {} }; }
function WC(): WooCommerce { return new WooCommerce(); }

class WooCommerce {
	/** @var WC_Countries */
	public $countries;
	public function __construct() { $this->countries = new WC_Countries(); }
}

class WC_Countries {
	/** @return array<string, string> */
	public function get_countries(): array { return []; }
}

class WC_Shipping_Method {
	public string $id = '';
	public int $instance_id = 0;
	public string $method_title = '';
	public string $method_description = '';
	/** @var string[] */
	public array $supports = [];
	public string $enabled = 'yes';
	public string $title = '';
	/** @var array<int, array<string, mixed>> */
	protected array $rates = [];
	/** @var array<string, array<string, mixed>> */
	protected array $instance_form_fields = [];

	public function init_form_fields() {}
	public function init_settings() {}
	public function process_admin_options() {}
	public function get_option( string $key, string $default = '' ): string { return $default; }
	public function get_rate_id(): string { return $this->id . ':' . $this->instance_id; }
	/** @param array<string, mixed> $args */
	public function add_rate( array $args = [] ): void { $this->rates[] = $args; }
	/** @return array<int, array<string, mixed>> */
	public function get_rates_added(): array { return $this->rates; }
}

class WC_Settings_Page {
	public string $id = '';
	public string $label = '';
	public function __construct() {}
}

class WC_DateTime extends \DateTime {
	public function date_i18n( string $format = '' ): string { return $this->format( $format ); }
}

class WC_Order {
	public function get_id(): int { return 0; }
	public function get_meta( string $key ): string { return ''; }
	public function delete_meta_data( string $key ): void {}
	public function update_meta_data( string $key, $value ): void {}
	public function save(): void {}
	public function save_meta_data(): void {}
	public function add_order_note( string $note, bool $is_customer_note = false ): void {}
	/** @return WC_Order_Item_Product[] */
	public function get_items(): array { return []; }
	/** @return WC_Order_Item_Shipping[] */
	public function get_shipping_methods(): array { return []; }
	public function get_shipping_country(): string { return ''; }
	public function get_shipping_state(): string { return ''; }
	public function get_shipping_first_name(): string { return ''; }
	public function get_shipping_last_name(): string { return ''; }
	public function get_shipping_company(): string { return ''; }
	public function get_shipping_address_1(): string { return ''; }
	public function get_shipping_address_2(): string { return ''; }
	public function get_shipping_city(): string { return ''; }
	public function get_shipping_postcode(): string { return ''; }
	public function get_status(): string { return ''; }
	public function set_status( string $status, string $note = '' ): void {}
	public function get_edit_order_url(): string { return ''; }
	public function get_date_created(): ?\WC_DateTime { return null; }
	public function get_billing_first_name(): string { return ''; }
	public function get_billing_last_name(): string { return ''; }
}

class WC_Order_Item_Product {
	public function get_product_id(): int { return 0; }
	public function get_quantity(): int { return 0; }
}

class WC_Order_Item_Shipping {
	public function get_method_id(): string { return ''; }
	public function get_meta( string $key ): string { return ''; }
}

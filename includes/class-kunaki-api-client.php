<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_API_Client {

	const ENDPOINT = 'https://kunaki.com/XMLService.ASP';
	const TIMEOUT  = 30;

	private $user_id;
	private $password;
	private $mode;

	public function __construct( $user_id = '', $password = '', $mode = '' ) {
		$this->user_id  = $user_id ?: get_option( 'kunaki_wc_user_id', '' );
		$this->password = $password ?: get_option( 'kunaki_wc_password', '' );
		$this->mode     = $mode ?: get_option( 'kunaki_wc_mode', 'TEST' );
	}

	/**
	 * Get shipping options for a destination and products.
	 *
	 * @param string $country      Full country name.
	 * @param string $state        Two-letter state/province code.
	 * @param string $postal_code  Postal/ZIP code.
	 * @param array  $products     Array of ['product_id' => string, 'quantity' => int].
	 * @return array|WP_Error      Array of shipping options or WP_Error.
	 */
	public function get_shipping_options( $country, $state, $postal_code, $products ) {
		$xml  = '<ShippingOptions>';
		$xml .= '<Country>' . esc_xml( $country ) . '</Country>';
		$xml .= '<State_Province>' . esc_xml( $state ) . '</State_Province>';
		$xml .= '<PostalCode>' . esc_xml( $postal_code ) . '</PostalCode>';

		foreach ( $products as $product ) {
			$xml .= '<Product>';
			$xml .= '<ProductId>' . esc_xml( $product['product_id'] ) . '</ProductId>';
			$xml .= '<Quantity>' . absint( $product['quantity'] ) . '</Quantity>';
			$xml .= '</Product>';
		}

		$xml .= '</ShippingOptions>';

		$response = $this->send_request( $xml );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$options = array();
		if ( isset( $response->Option ) ) {
			foreach ( $response->Option as $option ) {
				$options[] = array(
					'description'   => (string) $option->Description,
					'delivery_time' => (string) $option->DeliveryTime,
					'price'         => (float) $option->Price,
				);
			}
		}

		return $options;
	}

	/**
	 * Submit an order to Kunaki.
	 *
	 * @param array  $address              Shipping address fields.
	 * @param string $shipping_description Must match a ShippingOptions description.
	 * @param array  $products             Array of ['product_id' => string, 'quantity' => int].
	 * @return string|WP_Error             Kunaki Order ID on success, WP_Error on failure.
	 */
	public function submit_order( $address, $shipping_description, $products ) {
		$xml  = '<Order>';
		$xml .= '<UserId>' . esc_xml( $this->user_id ) . '</UserId>';
		$xml .= '<Password>' . esc_xml( $this->password ) . '</Password>';
		$xml .= '<Mode>' . esc_xml( strtoupper( $this->mode ) ) . '</Mode>';
		$xml .= '<Name>' . esc_xml( $address['name'] ) . '</Name>';
		$xml .= '<Company>' . esc_xml( $address['company'] ?? '' ) . '</Company>';
		$xml .= '<Address1>' . esc_xml( $address['address_1'] ) . '</Address1>';
		$xml .= '<Address2>' . esc_xml( $address['address_2'] ?? '' ) . '</Address2>';
		$xml .= '<City>' . esc_xml( $address['city'] ) . '</City>';
		$xml .= '<State_Province>' . esc_xml( $address['state'] ) . '</State_Province>';
		$xml .= '<PostalCode>' . esc_xml( $address['postcode'] ) . '</PostalCode>';
		$xml .= '<Country>' . esc_xml( $address['country'] ) . '</Country>';
		$xml .= '<ShippingDescription>' . esc_xml( $shipping_description ) . '</ShippingDescription>';

		foreach ( $products as $product ) {
			$xml .= '<Product>';
			$xml .= '<ProductId>' . esc_xml( $product['product_id'] ) . '</ProductId>';
			$xml .= '<Quantity>' . absint( $product['quantity'] ) . '</Quantity>';
			$xml .= '</Product>';
		}

		$xml .= '</Order>';

		$response = $this->send_request( $xml );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return (string) $response->OrderId;
	}

	/**
	 * Get order status from Kunaki.
	 *
	 * @param string $order_id Kunaki order ID.
	 * @return array|WP_Error  Status array or WP_Error.
	 */
	public function get_order_status( $order_id ) {
		$xml  = '<OrderStatus>';
		$xml .= '<UserId>' . esc_xml( $this->user_id ) . '</UserId>';
		$xml .= '<Password>' . esc_xml( $this->password ) . '</Password>';
		$xml .= '<OrderId>' . esc_xml( $order_id ) . '</OrderId>';
		$xml .= '</OrderStatus>';

		$response = $this->send_request( $xml );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'order_id'      => (string) $response->OrderId,
			'status'        => strtoupper( (string) $response->OrderStatus ),
			'tracking_type' => (string) $response->TrackingType,
			'tracking_id'   => (string) $response->TrackingId,
		);
	}

	/**
	 * Get product image URL from Kunaki.
	 *
	 * @param string $product_id Kunaki product ID.
	 * @param string $view       View type: FO, BO, FS, BS, LI, RI, BX.
	 * @return string            Image URL.
	 */
	public static function get_product_image_url( $product_id, $view = 'BX' ) {
		return 'https://kunaki.com/ProductImage.ASP?T=I&ST=' . urlencode( $view ) . '&PID=' . urlencode( $product_id );
	}

	/**
	 * Send raw XML to the Kunaki endpoint and parse the response.
	 *
	 * @param string $xml Raw XML body.
	 * @return SimpleXMLElement|WP_Error
	 */
	private function send_request( $xml ) {
		Kunaki_Logger::debug( 'API request.', array( 'body' => $this->redact_credentials( $xml ) ) );

		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Content-Type' => 'text/xml; charset=utf-8',
			),
			'body' => $xml,
		) );

		if ( is_wp_error( $response ) ) {
			Kunaki_Logger::error( 'HTTP error.', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		Kunaki_Logger::debug( 'API response.', array( 'status_code' => $status_code, 'body' => $body ) );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'kunaki_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Kunaki API returned HTTP %d', 'kunaki-woocommerce' ),
					$status_code
				)
			);
		}

		// Kunaki sometimes wraps responses in HTML — extract the XML Response block.
		if ( preg_match( '/<Response\b[^>]*>.*<\/Response>/s', $body, $matches ) ) {
			$body = $matches[0];
		}
		$body = trim( $body );

		$prev_libxml = libxml_use_internal_errors( true );
		$parsed      = simplexml_load_string( $body );
		libxml_use_internal_errors( $prev_libxml );

		if ( false === $parsed ) {
			Kunaki_Logger::error( 'XML parse error.', array( 'body' => $body ) );
			return new WP_Error(
				'kunaki_xml_error',
				__( 'Failed to parse Kunaki API response.', 'kunaki-woocommerce' )
			);
		}

		$error_code = (int) $parsed->ErrorCode;
		if ( 0 !== $error_code ) {
			$error_text = (string) $parsed->ErrorText;
			Kunaki_Logger::error( 'Kunaki API error.', array( 'error_code' => $error_code, 'error_text' => $error_text ) );
			return new WP_Error( 'kunaki_api_error', $error_text, array( 'error_code' => $error_code ) );
		}

		return $parsed;
	}

	/**
	 * Redact sensitive credentials from XML for logging.
	 *
	 * @param string $xml Raw XML string.
	 * @return string XML with credentials replaced by [REDACTED].
	 */
	private function redact_credentials( $xml ) {
		$xml = preg_replace( '/<UserId>.*?<\/UserId>/', '<UserId>[REDACTED]</UserId>', $xml );
		$xml = preg_replace( '/<Password>.*?<\/Password>/', '<Password>[REDACTED]</Password>', $xml );
		return $xml;
	}
}

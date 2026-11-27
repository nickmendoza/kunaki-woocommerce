<?php
// Disposable test container only: fakes the Kunaki HTTP endpoint.

// Lets run.py prove that web requests really reach debug.log.
if ( isset( $_GET['kunaki_e2e_log_check'] ) ) {
	error_log( 'kunaki-e2e-log-check' );
}

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'kunaki.com' ) ) { return $pre; }
	$body = (string) $args['body'];
	$log  = get_option( 'mock_kunaki_log', array() ); $log[] = $body; update_option( 'mock_kunaki_log', $log, false );
	if ( 'down' === get_option( 'mock_kunaki_mode', 'ok' ) ) { return new WP_Error( 'http_request_failed', 'mock outage' ); }
	if ( 0 === strpos( $body, '<ShippingOptions' ) ) {
		// A dearer option listed first, only when a scenario asks for it: the
		// checkout checks expect exactly one rate.
		$dearer = get_option( 'mock_kunaki_two_rates' ) ? '<Option><Description>UPS Ground</Description><DeliveryTime>1-5 business days</DeliveryTime><Price>9.80</Price></Option>' : '';
		$r = '<Response><ErrorCode>0</ErrorCode><ErrorText>success</ErrorText>' . $dearer . '<Option><Description>USPS First Class Mail</Description><DeliveryTime>2-5 business days</DeliveryTime><Price>6.29</Price></Option></Response>';
	} elseif ( 0 === strpos( $body, '<Order>' ) ) {
		$r = '<Response><ErrorCode>0</ErrorCode><ErrorText>success</ErrorText><OrderId>KUN' . count( $log ) . '</OrderId></Response>';
	} else {
		$r = '<Response><ErrorCode>0</ErrorCode><ErrorText>success</ErrorText><OrderStatus>' . get_option( 'mock_kunaki_status', 'PROCESSING' ) . '</OrderStatus><TrackingType>USPS</TrackingType><TrackingId>9400</TrackingId></Response>';
	}
	return array( 'headers' => array(), 'body' => $r, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );

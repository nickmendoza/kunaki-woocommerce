jQuery( function( $ ) {
	$( document ).on( 'click', '.kunaki-retry-button', function( e ) {
		e.preventDefault();

		var $button  = $( this );
		var $spinner = $button.siblings( '.spinner' );
		var $result  = $button.siblings( '.kunaki-retry-result' );
		var orderId  = $button.data( 'order-id' );

		$button.prop( 'disabled', true );
		$spinner.addClass( 'is-active' );
		$result.text( '' ).removeAttr( 'style' );

		$.post( ajaxurl, {
			action: 'kunaki_retry_order',
			order_id: orderId,
			nonce: kunakiRetry.nonce
		} ).done( function( response ) {
			$spinner.removeClass( 'is-active' );
			if ( response.success ) {
				$result.text( response.data.message ).css( 'color', '#46b450' );
				$button.text( kunakiRetry.i18n.submitted ).prop( 'disabled', true );
				$button.closest( 'tr' ).delay( 1500 ).fadeOut();
			} else {
				$result.text( response.data.message ).css( 'color', '#dc3232' );
				$button.prop( 'disabled', false );
			}
		} ).fail( function() {
			$spinner.removeClass( 'is-active' );
			$result.text( kunakiRetry.i18n.requestFailed ).css( 'color', '#dc3232' );
			$button.prop( 'disabled', false );
		} );
	} );
} );

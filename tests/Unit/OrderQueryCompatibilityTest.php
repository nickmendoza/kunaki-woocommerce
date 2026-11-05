<?php

namespace Kunaki\Tests\Unit;

/**
 * WooCommerce silently ignores meta_query on order queries unless HPOS is
 * enabled, so on legacy stores the query returns every order in the status.
 * wc_get_orders() is mocked in unit tests, so only a source check can catch this.
 */
class OrderQueryCompatibilityTest extends KunakiTestCase {

	public function test_plugin_never_uses_meta_query() {
		foreach ( glob( dirname( __DIR__, 2 ) . '/includes/*.php' ) as $file ) {
			$this->assertStringNotContainsString(
				"'meta_query'",
				(string) file_get_contents( $file ),
				basename( $file ) . ': use meta_key/meta_value/meta_compare instead.'
			);
		}
	}
}

<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

class EncryptionTest extends KunakiTestCase {

	public function test_round_trip() {
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-value-for-unit-tests' );

		$original  = 'MySecretPassword123!';
		$encrypted = kunaki_wc_encrypt_password( $original );

		$this->assertNotSame( $original, $encrypted );
		$this->assertNotEmpty( $encrypted );
		$this->assertSame( $original, kunaki_wc_decrypt_password( $encrypted ) );
	}

	public function test_empty_string_passthrough() {
		$this->assertSame( '', kunaki_wc_encrypt_password( '' ) );
		$this->assertSame( '', kunaki_wc_decrypt_password( '' ) );
	}

	public function test_invalid_base64_returns_original() {
		$result = kunaki_wc_decrypt_password( '!!!not-base64!!!' );
		$this->assertSame( '!!!not-base64!!!', $result );
	}

	public function test_short_data_returns_original() {
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );

		// Valid base64 but decoded data is shorter than AES-256-CBC IV (16 bytes).
		$short = base64_encode( 'short' );
		$this->assertSame( $short, kunaki_wc_decrypt_password( $short ) );
	}

	public function test_wrong_key_returns_empty() {
		Functions\when( 'wp_salt' )->justReturn( 'original-salt' );
		$encrypted = kunaki_wc_encrypt_password( 'MyPassword' );

		// Simulate salt rotation.
		Monkey\tearDown();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( 'different-salt' );
		Functions\when( 'wc_get_logger' )->justReturn(
			\Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);

		$this->assertSame( '', kunaki_wc_decrypt_password( $encrypted ) );
	}

}

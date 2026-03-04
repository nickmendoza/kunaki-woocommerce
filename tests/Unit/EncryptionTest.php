<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

class EncryptionTest extends KunakiTestCase {

	public function test_round_trip() {
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-value-for-unit-tests' );
		Functions\when( 'delete_option' )->justReturn( true );

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
		// Valid base64 but decoded data is too short for either GCM or CBC format.
		$short = base64_encode( 'short' );
		$this->assertSame( $short, kunaki_wc_decrypt_password( $short ) );
	}

	public function test_wrong_key_returns_empty_and_sets_flag() {
		Functions\when( 'wp_salt' )->justReturn( 'original-salt' );
		Functions\when( 'delete_option' )->justReturn( true );
		$encrypted = kunaki_wc_encrypt_password( 'MyPassword' );

		// Simulate salt rotation.
		Monkey\tearDown();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( 'different-salt' );
		Functions\when( 'wc_get_logger' )->justReturn(
			\Mockery::mock( 'WC_Logger' )->shouldIgnoreMissing()
		);
		Functions\expect( 'update_option' )
			->once()
			->with( 'kunaki_wc_decrypt_failed', 'yes', 'no' );

		$this->assertSame( '', kunaki_wc_decrypt_password( $encrypted ) );
	}

	public function test_successful_encrypt_clears_decrypt_flag() {
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\expect( 'delete_option' )
			->once()
			->with( 'kunaki_wc_decrypt_failed' );

		kunaki_wc_encrypt_password( 'NewPassword' );
	}

}

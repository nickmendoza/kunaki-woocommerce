<?php

namespace Kunaki\Tests\Unit;

/**
 * The activation and deactivation hooks record the gap the catch-up scan
 * looks at. Without those two calls the scan never runs, and nothing says so.
 *
 * No unit test can load kunaki-woocommerce.php: it declares functions and
 * constants the test bootstrap already has. The real-install checks cover the
 * hooks, but CI doesn't run those, so only a source check can catch this here.
 */
class PluginHooksTest extends KunakiTestCase {

	public function test_activation_closes_the_inactive_window() {
		$this->assertContains( 'Kunaki_Catch_Up::mark_activated();', $this->statements( 'register_activation_hook' ) );
	}

	public function test_deactivation_records_its_start_before_anything_else() {
		// First, so nothing else in the hook can stop the start of the gap
		// being recorded.
		$statements = $this->statements( 'register_deactivation_hook' );

		$this->assertSame( 'Kunaki_Catch_Up::mark_deactivated();', $statements[0] );
	}

	public function test_the_class_is_loaded_before_the_hooks_that_call_it() {
		// The hooks run without WooCommerce and without the plugin's other
		// classes being loaded, so the main file has to load this one itself.
		$source  = $this->source();
		$require = strpos( $source, "require_once __DIR__ . '/includes/class-kunaki-catch-up.php';" );

		$this->assertNotFalse( $require );
		$this->assertLessThan( strpos( $source, 'register_activation_hook(' ), $require );
		$this->assertLessThan( strpos( $source, 'register_deactivation_hook(' ), $require );
	}

	private function source() {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/kunaki-woocommerce.php' );
	}

	/**
	 * The top-level statements of a hook's closure, without comments, each
	 * with its whitespace collapsed.
	 *
	 * @param string $register register_activation_hook or register_deactivation_hook.
	 * @return string[]
	 */
	private function statements( $register ) {
		$found = preg_match(
			'/^' . $register . '\( __FILE__, function \(\) \{\n(.*?)\n\} \);$/ms',
			$this->source(),
			$match
		);
		$this->assertSame( 1, $found, "$register() was not found in kunaki-woocommerce.php in the form this test reads." );

		$statements = array();
		$current    = '';
		$depth      = 0;
		foreach ( array_slice( token_get_all( '<?php ' . $match[1] ), 1 ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$text = is_array( $token ) ? $token[1] : $token;
			if ( '{' === $text ) {
				$depth++;
			}
			if ( '}' === $text ) {
				$depth--;
			}
			$current .= $text;
			// A statement ends at a semicolon outside any block, or where a block closes.
			if ( 0 === $depth && ( ';' === $text || '}' === $text ) ) {
				$statements[] = trim( (string) preg_replace( '/\s+/', ' ', $current ) );
				$current      = '';
			}
		}

		return $statements;
	}
}

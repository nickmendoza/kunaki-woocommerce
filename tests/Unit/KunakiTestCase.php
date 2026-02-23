<?php

namespace Kunaki\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Base test case that integrates Brain Monkey and Mockery with PHPUnit.
 *
 * Handles setUp/tearDown for Brain Monkey and ensures Mockery expectations
 * are counted as PHPUnit assertions (avoiding risky test warnings).
 */
abstract class KunakiTestCase extends TestCase {

	protected function set_up() {
		parent::set_up();
		Monkey\setUp();
	}

	protected function tear_down() {
		// Count Mockery expectations as PHPUnit assertions.
		if ( $container = Mockery::getContainer() ) {
			$this->addToAssertionCount( $container->mockery_getExpectationCount() );
		}
		Monkey\tearDown();
		parent::tear_down();
	}
}

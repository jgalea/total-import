<?php

namespace Importright\Tests\Unit;

use Importright\Registry;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs/registry-functions.php';

final class RegistryTest extends TestCase {

	protected function setUp(): void {
		Registry::reset();
		$GLOBALS['importright_test_wrong']   = array();
		$GLOBALS['importright_test_filters'] = array();
		$GLOBALS['wp_filter']                = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- stands in for core's hook table.
	}

	protected function tearDown(): void {
		Registry::reset();
		unset( $GLOBALS['wp_filter'] );
	}

	private function add( string $hook, int $priority = 10 ): void {
		if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
			$GLOBALS['wp_filter'][ $hook ] = (object) array( 'callbacks' => array() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- stands in for core's hook table.
		}
		$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][ 'cb' . count( $GLOBALS['wp_filter'][ $hook ]->callbacks, COUNT_RECURSIVE ) ] = array();
	}

	public function test_filters_added_before_the_first_lookup_are_fine(): void {
		$this->add( 'importright_targets' );
		$this->add( 'importright_field_handlers', 5 );

		Registry::targets();
		Registry::handlers();
		Registry::targets();
		Registry::handlers();

		$this->assertSame( array(), $GLOBALS['importright_test_wrong'] );
		$this->assertSame( array( 'post', 'term', 'user' ), array_keys( Registry::targets() ) );
	}

	public function test_a_target_filter_added_after_the_list_was_built_is_reported_once(): void {
		$this->add( 'importright_targets' );
		Registry::targets();
		$this->add( 'importright_targets', 20 );

		Registry::targets();
		Registry::target( 'post' );

		$this->assertCount( 1, $GLOBALS['importright_test_wrong'] );
		$this->assertSame( array( 'importright_targets', '0.2.0' ), array( $GLOBALS['importright_test_wrong'][0][0], $GLOBALS['importright_test_wrong'][0][2] ) );
	}

	public function test_a_late_field_handler_filter_is_reported(): void {
		Registry::handlers();
		$this->add( 'importright_field_handlers' );

		Registry::handlers_for( 'post' );

		$this->assertSame( array( 'importright_field_handlers' ), array_column( $GLOBALS['importright_test_wrong'], 0 ) );
	}

	public function test_removing_a_callback_is_not_reported(): void {
		$this->add( 'importright_targets' );
		Registry::targets();
		$GLOBALS['wp_filter']['importright_targets']->callbacks = array();

		Registry::targets();

		$this->assertSame( array(), $GLOBALS['importright_test_wrong'] );
	}
}

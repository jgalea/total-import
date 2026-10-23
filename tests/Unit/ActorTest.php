<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Runs\Actor;

require_once dirname( __DIR__ ) . '/stubs/runs-functions.php';

final class ActorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['importright_test_users']    = array(
			1 => new \WP_User( 1, 'owner', array( 'manage_options' => true ) ),
			2 => new \WP_User( 2, 'subscriber', array( 'read' => true ) ),
			3 => new \WP_User( 3, 'other-admin', array( 'manage_options' => true ) ),
		);
		$GLOBALS['importright_test_switches'] = array();
		$GLOBALS['importright_test_kses']     = array();
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public function drivers(): array {
		return array(
			'logged out (WP-CLI, WP-Cron)'  => array( 0 ),
			'a subscriber loading wp-admin' => array( 2 ),
			'another administrator'         => array( 3 ),
		);
	}

	/**
	 * @dataProvider drivers
	 */
	public function test_acts_as_the_owner_whoever_drives_the_step( int $driver ): void {
		$GLOBALS['importright_test_current'] = $driver;

		$previous = Actor::become( 1 );
		$this->assertSame( $driver, $previous );
		$this->assertSame( 1, $GLOBALS['importright_test_current'] );
		$this->assertSame( array( 1 ), $GLOBALS['importright_test_kses'], 'kses is re-hooked for the owner.' );

		Actor::restore( $previous );
		$this->assertSame( $driver, $GLOBALS['importright_test_current'] );
		$this->assertSame( array( 1, $driver ), $GLOBALS['importright_test_kses'] );
	}

	public function test_refuses_an_owner_who_was_deleted(): void {
		$GLOBALS['importright_test_current'] = 3;
		$result                              = Actor::become( 9 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'importright_owner', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['importright_test_switches'], 'The current user is left alone.' );
	}

	public function test_refuses_an_owner_who_lost_the_capability(): void {
		$GLOBALS['importright_test_current'] = 3;
		$result                              = Actor::become( 2 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'subscriber', $result->get_error_message() );
		$this->assertSame( array(), $GLOBALS['importright_test_switches'] );
	}

	public function test_refuses_a_run_without_an_owner(): void {
		$GLOBALS['importright_test_current'] = 3;
		$this->assertInstanceOf( \WP_Error::class, Actor::become( 0 ) );
		$this->assertSame( array(), $GLOBALS['importright_test_switches'] );
	}

	public function test_honours_the_capability_filter(): void {
		$GLOBALS['importright_test_filters']['importright_capability'] = static function (): string {
			return 'read';
		};
		try {
			$GLOBALS['importright_test_current'] = 0;
			$this->assertSame( 0, Actor::become( 2 ) );
			$this->assertInstanceOf( \WP_Error::class, Actor::become( 1 ) );
		} finally {
			unset( $GLOBALS['importright_test_filters']['importright_capability'] );
		}
	}
}

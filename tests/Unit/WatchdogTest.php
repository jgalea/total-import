<?php

namespace Importright\Tests\Unit;

use Importright\Runs\Background;
use Importright\Runs\Runner;
use Importright\Runs\Runs;
use PHPUnit\Framework\TestCase;
use WP_User;

require_once dirname( __DIR__ ) . '/stubs/runs-functions.php';
require_once dirname( __DIR__ ) . '/stubs/runner-functions.php';
require_once dirname( __DIR__ ) . '/stubs/background-functions.php';

final class WatchdogTest extends TestCase {

	private FakeRunsDb $db;

	protected function setUp(): void {
		$this->db                            = new FakeRunsDb();
		$GLOBALS['wpdb']                     = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the fake stands in for wpdb.
		$GLOBALS['importright_test_filters'] = array();
		$GLOBALS['importright_test_actions'] = array();
		$GLOBALS['importright_test_cron']    = array();
		$GLOBALS['importright_test_options'] = array();
		$GLOBALS['importright_test_current'] = 1;
		$GLOBALS['importright_test_users']   = array( 1 => new WP_User( 1, 'admin', array( 'manage_options' => true ) ) );
		foreach ( array( 3, 4, 5 ) as $import_id ) {
			$this->db->imports[ $import_id ] = array(
				'id'         => $import_id,
				'name'       => 'Import ' . $import_id,
				'definition' => '{"options":{"batch_size":50}}',
				'created_by' => 1,
				'created_at' => '2026-10-22 10:00:00',
				'updated_at' => '2026-10-22 10:00:00',
			);
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['importright_test_cron']    = array();
		$GLOBALS['importright_test_current'] = 0;
	}

	/**
	 * The worker took the queued step, claimed the lock and died mid-batch:
	 * no pending event, lock expired, heartbeat older than the grace period.
	 */
	private function kill( int $run_id ): void {
		$GLOBALS['importright_test_cron']          = array_values(
			array_filter(
				$GLOBALS['importright_test_cron'],
				static fn( $event ) => array( $run_id ) !== $event[1]
			)
		);
		$this->db->runs[ $run_id ]['status']       = 'running';
		$this->db->runs[ $run_id ]['locked_until'] = time() - 10;
		$this->db->runs[ $run_id ]['heartbeat']    = time() - 600;
	}

	public function test_background_start_keeps_the_callers_origin_and_flags_the_run(): void {
		$cli      = Background::start( 3, array( 'origin' => 'cli' ) );
		$schedule = Background::start( 4, array( 'origin' => 'schedule' ) );
		$default  = Background::start( 5 );

		$this->assertSame( array( 'cli', true ), array( Runs::get( $cli )['origin'], Runs::get( $cli )['background'] ) );
		$this->assertSame( array( 'schedule', true ), array( Runs::get( $schedule )['origin'], Runs::get( $schedule )['background'] ) );
		$this->assertSame( array( 'background', true ), array( Runs::get( $default )['origin'], Runs::get( $default )['background'] ) );
	}

	public function test_cli_and_scheduled_background_runs_killed_mid_batch_are_restarted(): void {
		$cli      = Background::start( 3, array( 'origin' => 'cli' ) );
		$schedule = Background::start( 4, array( 'origin' => 'schedule' ) );
		$this->kill( $cli );
		$this->kill( $schedule );
		$this->assertFalse( Background::has_pending( $cli ) );
		$this->assertFalse( Background::has_pending( $schedule ) );

		$restarted = Background::watchdog();

		$this->assertSame( array( $cli, $schedule ), $restarted );
		$this->assertTrue( Background::has_pending( $cli ) );
		$this->assertTrue( Background::has_pending( $schedule ) );
	}

	public function test_a_browser_driven_run_is_left_to_the_browser(): void {
		$ajax = Runner::start( 5, array( 'origin' => 'ajax' ) );
		$this->kill( $ajax );

		$this->assertFalse( Runs::get( $ajax )['background'] );
		$this->assertSame( array(), Background::watchdog() );
		$this->assertFalse( Background::has_pending( $ajax ) );
	}

	public function test_handing_a_browser_run_to_the_background_puts_it_under_the_watchdog(): void {
		$ajax = Runner::start( 5, array( 'origin' => 'ajax' ) );
		$this->assertTrue( Background::adopt( $ajax ) );
		$this->kill( $ajax );

		$this->assertSame( array( $ajax ), Background::watchdog() );
	}

	public function test_a_run_with_a_live_worker_is_not_restarted(): void {
		$cli = Background::start( 3, array( 'origin' => 'cli' ) );
		$this->kill( $cli );
		$this->db->runs[ $cli ]['heartbeat'] = time();

		$this->assertSame( array(), Background::watchdog() );
	}
}

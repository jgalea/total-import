<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Runs\Background;

require_once dirname( __DIR__ ) . '/stubs/background-functions.php';

final class BackgroundTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['importright_test_cron']     = array();
		$GLOBALS['importright_test_as']       = array();
		$GLOBALS['importright_test_options']  = array();
		$GLOBALS['importright_test_as_ready'] = true;
	}

	private static function load_action_scheduler(): void {
		require_once dirname( __DIR__ ) . '/stubs/action-scheduler.php';
	}

	/**
	 * @return array<string, array{0: bool, 1: bool, 2: string}>
	 */
	public function sites(): array {
		return array(
			'no plugin loads Action Scheduler'           => array( false, false, Background::WP_CRON ),
			'loaded, data store not ready (before init)' => array( true, false, Background::WP_CRON ),
			'loaded and ready'                           => array( true, true, Background::ACTION_SCHEDULER ),
		);
	}

	/**
	 * @dataProvider sites
	 */
	public function test_choose( bool $loaded, bool $ready, string $expected ): void {
		$this->assertSame( $expected, Background::choose( $loaded, $ready ) );
	}

	public function test_without_action_scheduler_steps_go_to_wp_cron(): void {
		$this->assertSame( Background::WP_CRON, Background::backend() );

		Background::enqueue( 7 );

		$this->assertSame( array( array( Background::STEP, array( 7 ), false ) ), array_map( static fn( $e ) => array_slice( $e, 0, 3 ), $GLOBALS['importright_test_cron'] ) );
		$this->assertTrue( Background::has_pending( 7 ) );
		$this->assertFalse( Background::has_pending( 8 ) );
	}

	public function test_wp_cron_watchdog_runs_every_five_minutes(): void {
		Background::ensure_schedules();

		$this->assertContains( array( Background::WATCHDOG, array(), Background::CRON_INTERVAL ), array_map( static fn( $e ) => array_slice( $e, 0, 3 ), $GLOBALS['importright_test_cron'] ) );
		$this->assertContains( array( Background::CLEANUP, array(), 'daily' ), array_map( static fn( $e ) => array_slice( $e, 0, 3 ), $GLOBALS['importright_test_cron'] ) );
		$this->assertSame( 300, Background::cron_schedules( array() )[ Background::CRON_INTERVAL ]['interval'] );
	}

	public function test_backend_change_reschedules_the_watchdog_on_wp_cron_the_same_day(): void {
		// Action Scheduler scheduled the watchdog a minute ago, then its plugin was switched off.
		$GLOBALS['importright_test_options']['importright_schedules_checked'] = array(
			'backend' => Background::ACTION_SCHEDULER,
			'at'      => time() - 60,
		);

		Background::ensure_schedules();

		$this->assertNotFalse( \Importright\Runs\wp_next_scheduled( Background::WATCHDOG ) );
		$this->assertSame( Background::WP_CRON, $GLOBALS['importright_test_options']['importright_schedules_checked']['backend'] );
	}

	public function test_same_backend_is_checked_once_a_day(): void {
		$GLOBALS['importright_test_options']['importright_schedules_checked'] = array(
			'backend' => Background::WP_CRON,
			'at'      => time() - 60,
		);

		Background::ensure_schedules();

		$this->assertSame( array(), $GLOBALS['importright_test_cron'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_action_scheduler_before_init_is_not_used(): void {
		self::load_action_scheduler();
		$GLOBALS['importright_test_as_ready'] = false;

		$this->assertSame( Background::WP_CRON, Background::backend() );
		Background::enqueue( 7 );
		$this->assertSame( array(), $GLOBALS['importright_test_as'] );
		$this->assertCount( 1, $GLOBALS['importright_test_cron'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_steps_go_to_action_scheduler_when_another_plugin_loaded_it(): void {
		self::load_action_scheduler();

		$this->assertSame( Background::ACTION_SCHEDULER, Background::backend() );
		Background::enqueue( 7 );
		Background::enqueue( 8, 30 );

		$this->assertSame( array(), $GLOBALS['importright_test_cron'] );
		$this->assertSame(
			array( array( Background::STEP, array( 7 ), Background::GROUP, 'pending' ), array( Background::STEP, array( 8 ), Background::GROUP, 'pending' ) ),
			array_map( static fn( $a ) => array_slice( $a, 0, 4 ), $GLOBALS['importright_test_as'] )
		);
		$this->assertGreaterThanOrEqual( time() + 30, $GLOBALS['importright_test_as'][1][4] );
		$this->assertTrue( Background::has_pending( 7 ) );

		// The in-progress action may belong to a dead worker, so it doesn't count.
		$GLOBALS['importright_test_as'][0][3] = 'in-progress';
		$this->assertFalse( Background::has_pending( 7 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_cron_step_queued_before_action_scheduler_appeared_still_counts(): void {
		self::load_action_scheduler();
		\Importright\Runs\wp_schedule_single_event( time(), Background::STEP, array( 7 ) );

		$this->assertTrue( Background::has_pending( 7 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_action_scheduler_schedules_replace_wp_cron_ones(): void {
		self::load_action_scheduler();
		\Importright\Runs\wp_schedule_event( time(), Background::CRON_INTERVAL, Background::WATCHDOG );
		$GLOBALS['importright_test_options']['importright_schedules_checked'] = array(
			'backend' => Background::WP_CRON,
			'at'      => time() - 60,
		);

		Background::ensure_schedules();

		$this->assertSame( array(), $GLOBALS['importright_test_cron'] );
		$this->assertSame(
			array( array( Background::WATCHDOG, 300 ), array( Background::CLEANUP, 86400 ) ),
			array_map( static fn( $a ) => array( $a[0], $a[5] ), $GLOBALS['importright_test_as'] )
		);
	}
}

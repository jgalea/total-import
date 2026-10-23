<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table.

namespace Importright\Runs;

use Importright\Database\Schema;
use Importright\Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Runs imports in the background. Total Import doesn't ship Action Scheduler: when
 * another plugin (WooCommerce and many others) has loaded it, steps go through
 * it, otherwise through WP-Cron. The backend is chosen on every call, so a run
 * started on one carries on with the other if that plugin is switched off.
 * Each step works for up to importright_background_time_limit seconds (20 by
 * default), then schedules the next one. A watchdog every five minutes
 * restarts a background run whose worker died: lock expired, no heartbeat for
 * two minutes, and no pending step on either backend.
 */
final class Background {

	public const STEP     = 'importright_run_step';
	public const WATCHDOG = 'importright_watchdog';
	public const CLEANUP  = 'importright_cleanup';
	public const GROUP    = 'importright';
	public const ORIGIN   = 'background';

	public const ACTION_SCHEDULER = 'action-scheduler';
	public const WP_CRON          = 'wp-cron';

	public const CRON_INTERVAL = 'importright_five_minutes';

	private const SCHEDULED_OPTION = 'importright_schedules_checked';

	public static function register(): void {
		add_action( self::STEP, array( self::class, 'handle' ) );
		add_action( self::WATCHDOG, array( self::class, 'watchdog' ) );
		add_action( self::CLEANUP, array( Retention::class, 'prune' ) );
		add_action( self::CLEANUP, array( \Importright\Imports\Imports::class, 'prune_files' ) );
		add_action( 'init', array( self::class, 'ensure_schedules' ), 20 );
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- the watchdog restarts stalled runs, so it can't wait 15 minutes.
	}

	/**
	 * Action Scheduler when another plugin has loaded it and its data store is
	 * ready (from init), WP-Cron otherwise.
	 */
	public static function backend(): string {
		$loaded = function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler', false );
		return self::choose( $loaded, $loaded && \ActionScheduler::is_initialized() );
	}

	public static function choose( bool $loaded, bool $ready ): string {
		return $loaded && $ready ? self::ACTION_SCHEDULER : self::WP_CRON;
	}

	public static function uses_action_scheduler(): bool {
		return self::ACTION_SCHEDULER === self::backend();
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function cron_schedules( $schedules ): array {
		$schedules                        = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'total-import' ),
		);
		return $schedules;
	}

	/**
	 * Starts a run and hands it to the background queue. The caller's origin
	 * (cli, schedule) is kept for display; the background flag is what puts the
	 * run under the watchdog.
	 *
	 * @param array{origin?: string, dry_run?: bool, batch_size?: int} $args
	 * @return int|WP_Error The run ID.
	 */
	public static function start( int $import_id, array $args = array() ) {
		$run_id = Runner::start( $import_id, array( 'background' => true ) + $args + array( 'origin' => self::ORIGIN ) );
		if ( ! is_wp_error( $run_id ) ) {
			self::enqueue( $run_id );
		}
		return $run_id;
	}

	/**
	 * Moves a run that a browser was driving to the background queue.
	 */
	public static function adopt( int $run_id ): bool {
		$run = Runs::get( $run_id );
		if ( null === $run || ! in_array( $run['status'], Runs::ACTIVE, true ) ) {
			return false;
		}
		Runs::save(
			$run_id,
			array(
				'origin'     => self::ORIGIN,
				'background' => 1,
			)
		);
		self::enqueue( $run_id );
		return true;
	}

	public static function enqueue( int $run_id, int $delay = 0 ): void {
		if ( self::uses_action_scheduler() ) {
			if ( 0 === $delay ) {
				as_enqueue_async_action( self::STEP, array( $run_id ), self::GROUP );
			} else {
				as_schedule_single_action( time() + $delay, self::STEP, array( $run_id ), self::GROUP );
			}
			return;
		}
		wp_schedule_single_event( time() + $delay, self::STEP, array( $run_id ) );
	}

	/**
	 * @param int|string $run_id
	 */
	public static function handle( $run_id ): void {
		Schema::maybe_install();
		$run_id = (int) $run_id;
		$limit  = (float) apply_filters( 'importright_background_time_limit', 20.0, $run_id );
		$run    = Runner::run_for( $run_id, max( 1.0, $limit ) );
		if ( is_wp_error( $run ) ) {
			// Another worker holds the run. Retry later unless a step is already queued.
			if ( 'importright_locked' === $run->get_error_code() && ! self::has_pending( $run_id ) ) {
				self::enqueue( $run_id, 30 );
			}
			return;
		}
		if ( in_array( $run['status'], Runs::ACTIVE, true ) ) {
			self::enqueue( $run_id );
		}
	}

	/**
	 * Restarts background runs whose worker died mid-batch, or whose next step
	 * sits in an Action Scheduler queue that nothing runs any more.
	 *
	 * @return int[] Runs that were restarted.
	 */
	public static function watchdog(): array {
		global $wpdb;
		$grace = (int) apply_filters( 'importright_watchdog_grace', 120 );
		$now   = time();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM %i WHERE status IN ('pending','running') AND background = 1 AND locked_until < %d AND heartbeat < %d", Schema::runs(), $now, $now - $grace ), ARRAY_A );

		$restarted = array();
		foreach ( (array) $rows as $row ) {
			$run_id = (int) $row['id'];
			if ( self::has_pending( $run_id ) ) {
				continue;
			}
			self::enqueue( $run_id );
			Runs::save( $run_id, array( 'heartbeat' => $now ) );
			$restarted[] = $run_id;
		}
		return $restarted;
	}

	/**
	 * Whether a step is waiting on a backend that runs on this site. Both are
	 * checked: a WP-Cron event queued before Action Scheduler appeared still runs.
	 */
	public static function has_pending( int $run_id ): bool {
		if ( false !== wp_next_scheduled( self::STEP, array( $run_id ) ) ) {
			return true;
		}
		if ( ! self::uses_action_scheduler() ) {
			return false;
		}
		// Pending only: an in-progress action may be the one whose worker died.
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::STEP,
				'args'     => array( $run_id ),
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);
		return array() !== $ids;
	}

	/**
	 * Schedules the watchdog and the daily cleanup on the current backend,
	 * checking at most once a day unless the backend changed since.
	 */
	public static function ensure_schedules(): void {
		$backend = self::backend();
		$checked = Store::get( self::SCHEDULED_OPTION );
		if ( is_array( $checked ) && ( $checked['backend'] ?? '' ) === $backend && (int) ( $checked['at'] ?? 0 ) > time() - DAY_IN_SECONDS ) {
			return;
		}
		if ( self::ACTION_SCHEDULER === $backend ) {
			if ( ! as_has_scheduled_action( self::WATCHDOG, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 300, 300, self::WATCHDOG, array(), self::GROUP );
			}
			if ( ! as_has_scheduled_action( self::CLEANUP, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::CLEANUP, array(), self::GROUP );
			}
			wp_unschedule_hook( self::WATCHDOG );
			wp_unschedule_hook( self::CLEANUP );
		} else {
			if ( ! wp_next_scheduled( self::WATCHDOG ) ) {
				wp_schedule_event( time() + 300, self::CRON_INTERVAL, self::WATCHDOG );
			}
			if ( ! wp_next_scheduled( self::CLEANUP ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP );
			}
		}
		Store::set(
			self::SCHEDULED_OPTION,
			array(
				'backend' => $backend,
				'at'      => time(),
			)
		);
	}

	public static function unschedule(): void {
		if ( self::uses_action_scheduler() ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		foreach ( array( self::WATCHDOG, self::CLEANUP, self::STEP ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		Store::delete( self::SCHEDULED_OPTION );
	}
}

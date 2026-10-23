<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own tables.

namespace Importright\Runs;

use Importright\Database\Schema;
use Importright\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Daily cleanup. Rollback data is kept for the newest
 * importright_rollback_keep_runs runs of each import (Settings, 5) and only
 * while it is younger than importright_rollback_keep_days (Settings, 30);
 * whichever limit is hit first expires it. Per-row logs and dry-run previews go after
 * importright_log_keep_days (90). Run rows themselves are kept.
 */
final class Retention {

	/**
	 * @return array{expired_runs: int, changes: int, log: int}
	 */
	public static function prune(): array {
		global $wpdb;

		$keep_runs = max( 1, (int) apply_filters( 'importright_rollback_keep_runs', Settings::get( 'rollback_keep_runs' ) ) );
		$keep_days = max( 1, (int) apply_filters( 'importright_rollback_keep_days', Settings::get( 'rollback_keep_days' ) ) );
		$log_days  = max( 1, (int) apply_filters( 'importright_log_keep_days', 90 ) );
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - $keep_days * DAY_IN_SECONDS );

		$runs = $wpdb->get_results( $wpdb->prepare( "SELECT id, import_id, finished_at, rollback_status FROM %i WHERE dry_run = 0 AND status NOT IN ('pending','running') AND rollback_status NOT IN ('expired','running') ORDER BY import_id, id DESC", Schema::runs() ), ARRAY_A );

		$seen    = array();
		$expired = 0;
		$changes = 0;
		foreach ( (array) $runs as $run ) {
			$import          = (int) $run['import_id'];
			$seen[ $import ] = ( $seen[ $import ] ?? 0 ) + 1;
			$old             = null !== $run['finished_at'] && (string) $run['finished_at'] < $cutoff;
			if ( $seen[ $import ] <= $keep_runs && ! $old ) {
				continue;
			}
			$deleted = self::delete_changes( (int) $run['id'] );
			if ( $deleted > 0 ) {
				if ( '' === (string) $run['rollback_status'] ) {
					Runs::save( (int) $run['id'], array( 'rollback_status' => Rollback::EXPIRED ) );
				}
				$changes += $deleted;
				++$expired;
			}
		}

		$log_cutoff = gmdate( 'Y-m-d H:i:s', time() - $log_days * DAY_IN_SECONDS );
		$old_runs   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status NOT IN ('pending','running') AND finished_at < %s", Schema::runs(), $log_cutoff ) );
		$log        = Log::delete_for( array_map( 'intval', (array) $old_runs ) );

		return array(
			'expired_runs' => $expired,
			'changes'      => $changes,
			'log'          => $log,
		);
	}

	private static function delete_changes( int $run_id ): int {
		global $wpdb;
		$total = 0;
		do {
			$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE run_id = %d LIMIT 5000', Schema::changes(), $run_id ) );
			$total  += $deleted;
		} while ( 5000 === $deleted );
		return $total;
	}
}

<?php

namespace Importright\Runs;

defined( 'ABSPATH' ) || exit;

/**
 * The shape the admin UI and WP-CLI show for a run.
 */
final class History {

	/**
	 * @param array<string, mixed> $run
	 * @return array<string, mixed>
	 */
	public static function describe( array $run ): array {
		$user     = $run['user_id'] > 0 ? get_userdata( $run['user_id'] ) : false;
		$log      = Log::counts( (int) $run['id'] );
		$changes  = $run['dry_run'] ? 0 : Journal::count( (int) $run['id'] );
		$finished = null === $run['finished_at'] ? time() : (int) strtotime( $run['finished_at'] . ' UTC' );
		$started  = null === $run['started_at'] ? null : (int) strtotime( $run['started_at'] . ' UTC' );

		$counts = array();
		foreach ( Runs::COUNTERS as $counter ) {
			$counts[ $counter ] = (int) $run[ $counter ];
		}

		$description = array(
			'id'          => (int) $run['id'],
			'import_id'   => (int) $run['import_id'],
			'status'      => (string) $run['status'],
			'mode'        => $run['dry_run'] ? 'dry_run' : 'live',
			'origin'      => (string) $run['origin'],
			'user'        => array(
				'id'   => (int) $run['user_id'],
				'name' => false === $user ? '' : (string) $user->display_name,
			),
			'created_at'  => (string) $run['created_at'],
			'started_at'  => null === $run['started_at'] ? null : (string) $run['started_at'],
			'finished_at' => null === $run['finished_at'] ? null : (string) $run['finished_at'],
			'duration'    => null === $started ? null : max( 0, $finished - $started ),
			'total'       => $run['total'],
			'percent'     => Runner::percent( $run ),
			'counts'      => $counts,
			'message'     => (string) $run['message'],
			'log'         => array(
				'errors'   => $log[ Log::ERROR ],
				'notices'  => $log[ Log::NOTICE ],
				'previews' => $log[ Log::PREVIEW ],
			),
			'rollback'    => array(
				'status'    => (string) $run['rollback_status'],
				'available' => ! $run['dry_run'] && ! in_array( $run['status'], Runs::ACTIVE, true ) && $changes > 0 && in_array( $run['rollback_status'], array( '', Rollback::PARTIAL, Rollback::RUNNING ), true ),
				'changes'   => $changes,
				'at'        => $run['rolled_back_at'] ?? null,
				'by'        => (int) $run['rolled_back_by'],
				'counts'    => (array) $run['rollback'],
			),
		);

		return apply_filters( 'importright_run_description', $description, $run );
	}
}

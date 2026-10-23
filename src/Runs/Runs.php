<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table.

namespace Importright\Runs;

use Importright\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Run rows. A run snapshots the import definition when it starts, so editing
 * an import never changes a run that's under way.
 */
final class Runs {

	public const PENDING   = 'pending';
	public const RUNNING   = 'running';
	public const COMPLETED = 'completed';
	public const FAILED    = 'failed';
	public const CANCELLED = 'cancelled';

	public const ACTIVE   = array( self::PENDING, self::RUNNING );
	public const COUNTERS = array( 'processed', 'created', 'updated', 'unchanged', 'skipped', 'failed' );

	/**
	 * @param array<string, mixed> $fields
	 */
	public static function create( array $fields ): int {
		global $wpdb;
		$row = array_merge(
			array(
				'status'      => self::PENDING,
				'origin'      => '',
				'background'  => 0,
				'dry_run'     => 0,
				'user_id'     => 0,
				'definition'  => '{}',
				'source_file' => '',
				'source_ext'  => '',
				'state'       => '{}',
				'errors'      => '[]',
				'message'     => '',
				'created_at'  => current_time( 'mysql', true ),
			),
			$fields
		);
		$wpdb->insert( Schema::runs(), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::runs(), $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $fields Columns to change; arrays are stored as JSON.
	 */
	public static function save( int $id, array $fields ): void {
		global $wpdb;
		foreach ( $fields as $column => $value ) {
			if ( is_array( $value ) ) {
				$fields[ $column ] = wp_json_encode( $value );
			} elseif ( is_bool( $value ) ) {
				$fields[ $column ] = (int) $value;
			}
		}
		$wpdb->update( Schema::runs(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Takes the run's lock. Returns a token for release(), or null when another process holds it.
	 */
	public static function claim( int $id, int $ttl ): ?string {
		global $wpdb;
		$token = bin2hex( random_bytes( 16 ) );
		$now   = time();
		$taken = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET lock_token = %s, locked_until = %d WHERE id = %d AND locked_until < %d', Schema::runs(), $token, $now + $ttl, $id, $now ) );
		return 1 === $taken ? $token : null;
	}

	public static function release( int $id, string $token ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET lock_token = '', locked_until = 0 WHERE id = %d AND lock_token = %s", Schema::runs(), $id, $token ) );
	}

	/**
	 * Clears a lock left by a process that died mid-batch. Only safe when nothing else is driving the run.
	 */
	public static function break_lock( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET lock_token = '', locked_until = 0 WHERE id = %d", Schema::runs(), $id ) );
	}

	/**
	 * @return array<string, mixed>|null The import's pending or running run.
	 */
	public static function active( int $import_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE import_id = %d AND status IN ('pending','running') ORDER BY id DESC LIMIT 1", Schema::runs(), $import_id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * ID of a run of this import whose rollback is under way, or 0.
	 */
	public static function rolling_back( int $import_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE import_id = %d AND rollback_status = 'running' LIMIT 1", Schema::runs(), $import_id ) );
	}

	/**
	 * @return array<int, array<string, mixed>> Newest first.
	 */
	public static function recent( int $import_id = 0, int $limit = 20 ): array {
		global $wpdb;
		if ( $import_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE import_id = %d ORDER BY id DESC LIMIT %d', Schema::runs(), $import_id, $limit ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Schema::runs(), $limit ), ARRAY_A );
		}
		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	public static function count( int $import_id = 0 ): int {
		global $wpdb;
		if ( $import_id > 0 ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE import_id = %d', Schema::runs(), $import_id ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::runs() ) );
	}

	/**
	 * Live runs of the same import that started after this one; retention counts them.
	 */
	public static function newer_live( int $import_id, int $run_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE import_id = %d AND id > %d AND dry_run = 0', Schema::runs(), $import_id, $run_id ) );
	}

	/**
	 * @return array<int, array<string, mixed>> Newest first.
	 */
	public static function page( int $import_id, int $page, int $per_page ): array {
		global $wpdb;
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		if ( $import_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE import_id = %d ORDER BY id DESC LIMIT %d OFFSET %d', Schema::runs(), $import_id, $per_page, $offset ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', Schema::runs(), $per_page, $offset ), ARRAY_A );
		}
		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * The newest run of each import, keyed by import ID.
	 *
	 * @param int[] $import_ids
	 * @return array<int, array<string, mixed>>
	 */
	public static function latest_for( array $import_ids ): array {
		global $wpdb;
		$import_ids = array_values( array_filter( array_map( 'intval', $import_ids ) ) );
		if ( array() === $import_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $import_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of %d.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE id IN (SELECT MAX(id) FROM %i WHERE import_id IN ($placeholders) GROUP BY import_id)", array_merge( array( Schema::runs(), Schema::runs() ), $import_ids ) ), ARRAY_A );
		$latest = array();
		foreach ( (array) $rows as $row ) {
			$run                         = self::hydrate( $row );
			$latest[ $run['import_id'] ] = $run;
		}
		return $latest;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'import_id', 'user_id', 'locked_until', 'heartbeat', 'rolled_back_by' ) as $column ) {
			$row[ $column ] = (int) ( $row[ $column ] ?? 0 );
		}
		$row['rollback_status'] = (string) ( $row['rollback_status'] ?? '' );
		$rollback               = json_decode( (string) ( $row['rollback'] ?? '' ), true );
		$row['rollback']        = is_array( $rollback ) ? $rollback : array();
		foreach ( self::COUNTERS as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		$row['dry_run']    = (bool) $row['dry_run'];
		$row['background'] = ! empty( $row['background'] );
		$row['total']      = null === $row['total'] ? null : (int) $row['total'];
		foreach ( array( 'definition', 'state', 'errors' ) as $column ) {
			$decoded        = json_decode( (string) $row[ $column ], true );
			$row[ $column ] = is_array( $decoded ) ? $decoded : array();
		}
		return $row;
	}
}

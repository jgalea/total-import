<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table.

namespace Importright\Runs;

use Importright\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The record map: which object each unique key produced, and the hash of the
 * mapped data last written to it.
 */
final class Tracker {

	/**
	 * Keys longer than the column are stored as a hash of themselves.
	 */
	public static function storage_key( string $key ): string {
		return strlen( $key ) > 255 ? 'sha1:' . sha1( $key ) : $key;
	}

	/**
	 * @param string[] $keys
	 * @return array<string, array{id: int, object_type: string, object_id: int, data_hash: string, adopted: bool, first_run_id: int, last_run_id: int, updated_at: string}> Keyed by the original key.
	 */
	public static function lookup( int $import_id, array $keys ): array {
		global $wpdb;

		$by_storage = array();
		foreach ( $keys as $key ) {
			$by_storage[ self::storage_key( (string) $key ) ] = (string) $key;
		}
		if ( array() === $by_storage ) {
			return array();
		}

		$found = array();
		foreach ( array_chunk( array_keys( $by_storage ), 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array.
					"SELECT id, unique_key, object_type, object_id, data_hash, adopted, first_run_id, last_run_id, updated_at FROM %i WHERE import_id = %d AND unique_key IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
					array_merge( array( Schema::records(), $import_id ), array_map( 'strval', $chunk ) )
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$original = $by_storage[ (string) $row['unique_key'] ] ?? null;
				if ( null === $original ) {
					continue;
				}
				$found[ $original ] = array(
					'id'           => (int) $row['id'],
					'object_type'  => (string) $row['object_type'],
					'object_id'    => (int) $row['object_id'],
					'data_hash'    => (string) $row['data_hash'],
					'adopted'      => ! empty( $row['adopted'] ),
					'first_run_id' => (int) $row['first_run_id'],
					'last_run_id'  => (int) $row['last_run_id'],
					'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
				);
			}
		}
		return $found;
	}

	/**
	 * @param bool $adopted The object already existed and Target::find() matched it; the import didn't create it.
	 */
	public static function save( int $import_id, string $key, string $object_type, int $object_id, string $hash, int $run_id, bool $adopted = false ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// adopted is assigned before object_id so the IF() compares against the stored object, and a row that now points at another object takes the new flag.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (import_id, unique_key, object_type, object_id, adopted, data_hash, first_run_id, last_run_id, created_at, updated_at)
				VALUES (%d, %s, %s, %d, %d, %s, %d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE adopted = IF(object_id = VALUES(object_id), adopted, VALUES(adopted)), object_type = VALUES(object_type), object_id = VALUES(object_id), data_hash = VALUES(data_hash), last_run_id = VALUES(last_run_id), updated_at = VALUES(updated_at)',
				Schema::records(),
				$import_id,
				self::storage_key( $key ),
				$object_type,
				$object_id,
				$adopted ? 1 : 0,
				$hash,
				$run_id,
				$run_id,
				$now,
				$now
			)
		);
	}

	/**
	 * Marks rows as seen by a run without changing them, so a later sync can tell which records left the feed.
	 *
	 * @param int[] $row_ids
	 */
	public static function touch( array $row_ids, int $run_id ): void {
		global $wpdb;
		$row_ids = array_values( array_filter( array_map( 'intval', $row_ids ) ) );
		foreach ( array_chunk( $row_ids, 1000 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array.
					"UPDATE %i SET last_run_id = %d WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
					array_merge( array( Schema::records(), $run_id ), $chunk )
				)
			);
		}
	}

	public static function count( int $import_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE import_id = %d', Schema::records(), $import_id ) );
	}
}

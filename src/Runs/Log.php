<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table.

namespace Importright\Runs;

use Importright\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Per-row messages of a run (errors and notices, capped per run by the
 * importright_log_limit filter) and a dry run's preview rows. Row numbers are
 * record numbers starting at 1 (the header isn't counted).
 */
final class Log {

	public const ERROR   = 'error';
	public const NOTICE  = 'notice';
	public const PREVIEW = 'preview';

	public const DEFAULT_LIMIT = 10000;

	/**
	 * @param array<int, array<string, mixed>> $entries {index, key, level, message?, action?, object_id?, data?}
	 */
	public static function add( int $run_id, array $entries ): void {
		global $wpdb;
		if ( array() === $entries ) {
			return;
		}

		$limit = (int) apply_filters( 'importright_log_limit', self::DEFAULT_LIMIT, $run_id );
		$room  = max( 0, $limit - self::count( $run_id, array( self::ERROR, self::NOTICE ) ) );

		$rows = array();
		foreach ( $entries as $entry ) {
			$level = (string) $entry['level'];
			if ( self::PREVIEW !== $level ) {
				if ( $room <= 0 ) {
					continue;
				}
				--$room;
			}
			$rows[] = $entry;
		}

		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$placeholders = array();
			$values       = array( Schema::log() );
			foreach ( $chunk as $entry ) {
				$placeholders[] = '(%d, %d, %s, %s, %s, %d, %s, %s)';
				array_push(
					$values,
					$run_id,
					(int) $entry['index'],
					substr( (string) ( $entry['key'] ?? '' ), 0, 255 ),
					(string) $entry['level'],
					(string) ( $entry['action'] ?? '' ),
					(int) ( $entry['object_id'] ?? 0 ),
					(string) ( $entry['message'] ?? '' ),
					isset( $entry['data'] ) ? (string) wp_json_encode( $entry['data'] ) : ''
				);
			}
			$wpdb->query( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders are fixed format tokens built above.
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array.
					'INSERT INTO %i (run_id, row_index, unique_key, level, action, object_id, message, data) VALUES ' . implode( ',', $placeholders ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
					$values
				)
			);
		}
	}

	/**
	 * @param string[] $levels Empty for all.
	 */
	public static function count( int $run_id, array $levels = array() ): int {
		global $wpdb;
		if ( array() === $levels ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d', Schema::log(), $run_id ) );
		}
		$placeholders = implode( ',', array_fill( 0, count( $levels ), '%s' ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array.
				"SELECT COUNT(*) FROM %i WHERE run_id = %d AND level IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
				array_merge( array( Schema::log(), $run_id ), $levels )
			)
		);
	}

	/**
	 * @return array{error: int, notice: int, preview: int}
	 */
	public static function counts( int $run_id ): array {
		global $wpdb;
		$counts = array(
			self::ERROR   => 0,
			self::NOTICE  => 0,
			self::PREVIEW => 0,
		);
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT level, COUNT(*) AS n FROM %i WHERE run_id = %d GROUP BY level', Schema::log(), $run_id ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['level'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * @param string[] $levels Empty for errors and notices.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function entries( int $run_id, array $levels = array(), int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$levels       = array() === $levels ? array( self::ERROR, self::NOTICE ) : $levels;
		$per_page     = max( 1, min( 500, $per_page ) );
		$offset       = ( max( 1, $page ) - 1 ) * $per_page;
		$placeholders = implode( ',', array_fill( 0, count( $levels ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array.
				"SELECT * FROM %i WHERE run_id = %d AND level IN ({$placeholders}) ORDER BY id LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
				array_merge( array( Schema::log(), $run_id ), $levels, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		return array(
			'items' => array_map( array( self::class, 'shape' ), (array) $rows ),
			'total' => self::count( $run_id, $levels ),
		);
	}

	/**
	 * Writes the run's errors and notices as CSV.
	 *
	 * @param resource $out
	 */
	public static function csv( int $run_id, $out ): void {
		global $wpdb;
		fputcsv( $out, array( 'row', 'key', 'level', 'message' ), ',', '"', '' );
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, row_index, unique_key, level, message FROM %i WHERE run_id = %d AND level IN ('error','notice') AND id > %d ORDER BY id LIMIT 1000", Schema::log(), $run_id, $last ), ARRAY_A );
			$rows = (array) $rows;
			$more = 1000 === count( $rows );
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				fputcsv( $out, array( (int) $row['row_index'] + 1, self::cell( (string) $row['unique_key'] ), $row['level'], self::cell( (string) $row['message'] ) ), ',', '"', '' );
			}
		} while ( $more );
	}

	/**
	 * @param int[] $run_ids
	 */
	public static function delete_for( array $run_ids ): int {
		global $wpdb;
		$deleted = 0;
		foreach ( array_chunk( array_map( 'intval', $run_ids ), 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$deleted     += (int) $wpdb->query(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array.
					"DELETE FROM %i WHERE run_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
					array_merge( array( Schema::log() ), $chunk )
				)
			);
		}
		return $deleted;
	}

	/**
	 * Keeps spreadsheet apps from reading a cell as a formula.
	 */
	public static function cell( string $value ): string {
		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ): array {
		$entry = array(
			'row'       => (int) $row['row_index'] + 1,
			'key'       => (string) $row['unique_key'],
			'level'     => (string) $row['level'],
			'action'    => (string) $row['action'],
			'object_id' => (int) $row['object_id'] > 0 ? (int) $row['object_id'] : null,
			'message'   => (string) $row['message'],
		);
		if ( self::PREVIEW === $row['level'] ) {
			$data               = json_decode( (string) $row['data'], true );
			$data               = is_array( $data ) ? $data : array();
			$entry['changes']   = (array) ( $data['changes'] ?? array() );
			$entry['downloads'] = (array) ( $data['downloads'] ?? array() );
			$entry['messages']  = (array) ( $data['messages'] ?? array() );
			unset( $entry['message'], $entry['level'] );
		}
		return $entry;
	}
}

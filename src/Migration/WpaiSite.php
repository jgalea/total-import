<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads WP All Import's tables and fills the plugin's own record map.

namespace Importright\Migration;

use Importright\Runs\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * WP All Import's tables on this site: its imports ({prefix}pmxi_imports) and
 * the record list ({prefix}pmxi_posts) that ties each unique key to a post.
 * Only read; nothing of WP All Import's is changed.
 */
final class WpaiSite {

	public const CHUNK = 1000;

	/**
	 * @var \wpdb|object
	 */
	private $db;

	/**
	 * @param \wpdb|object|null $db Defaults to the global $wpdb.
	 */
	public function __construct( $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	public function available(): bool {
		$table = $this->imports_table();
		return $table === $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) ) );
	}

	/**
	 * @return array<int, array{id: int, name: string, type: string, feed_type: string, path: string, post_type: string, records: int}>
	 */
	public function imports(): array {
		$counts = array();
		$rows   = (array) $this->db->get_results( $this->db->prepare( 'SELECT import_id, COUNT(*) AS records FROM %i GROUP BY import_id', $this->posts_table() ), ARRAY_A );
		foreach ( $rows as $row ) {
			$counts[ (int) $row['import_id'] ] = (int) $row['records'];
		}

		$imports = array();
		$rows    = (array) $this->db->get_results( $this->db->prepare( 'SELECT id, name, friendly_name, type, feed_type, path, options FROM %i WHERE parent_import_id = 0 ORDER BY id', $this->imports_table() ), ARRAY_A );
		foreach ( $rows as $row ) {
			try {
				$options   = TemplateFile::options( $row['options'] );
				$post_type = (string) ( $options['custom_type'] ?? '' );
				if ( '' === $post_type ) {
					$post_type = (string) ( $options['type'] ?? '' );
				}
				if ( 'taxonomies' === $post_type ) {
					$post_type .= ':' . (string) ( $options['taxonomy_type'] ?? '' );
				}
			} catch ( InvalidTemplate $e ) {
				$post_type = '?';
			}
			$imports[] = array(
				'id'        => (int) $row['id'],
				'name'      => '' !== (string) $row['friendly_name'] ? (string) $row['friendly_name'] : (string) $row['name'],
				'type'      => (string) $row['type'],
				'feed_type' => (string) $row['feed_type'],
				'path'      => (string) $row['path'],
				'post_type' => $post_type,
				'records'   => $counts[ (int) $row['id'] ] ?? 0,
			);
		}
		return $imports;
	}

	/**
	 * @return array{id: int, name: string, type: string, feed_type: string, path: string, xpath: string, root_element: string, options: array<string, mixed>}|null
	 * @throws InvalidTemplate When the import's options are damaged or hold objects.
	 */
	public function import( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->imports_table(), $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'           => (int) $row['id'],
			'name'         => '' !== (string) ( $row['friendly_name'] ?? '' ) ? (string) $row['friendly_name'] : (string) $row['name'],
			'type'         => (string) $row['type'],
			'feed_type'    => (string) $row['feed_type'],
			'path'         => (string) $row['path'],
			'xpath'        => (string) $row['xpath'],
			'root_element' => (string) $row['root_element'],
			'options'      => TemplateFile::options( $row['options'] ),
		);
	}

	public function tracked_count( int $wpai_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE import_id = %d', $this->posts_table(), $wpai_id ) );
	}

	/**
	 * Copies WP All Import's record list into Total Import's record map for a new import, in chunks.
	 * The data hash stays empty, so the first run updates every matched post rather than skipping it.
	 *
	 * @return array{read: int, copied: int, empty: int, duplicates: int}
	 */
	public function copy_tracking( int $wpai_id, int $import_id, string $object_type ): array {
		$totals = array(
			'read'       => 0,
			'copied'     => 0,
			'empty'      => 0,
			'duplicates' => 0,
		);
		$seen   = array();
		$last   = 0;
		$now    = gmdate( 'Y-m-d H:i:s' );

		do {
			$rows = (array) $this->db->get_results(
				$this->db->prepare( 'SELECT id, post_id, unique_key FROM %i WHERE import_id = %d AND id > %d ORDER BY id LIMIT %d', $this->posts_table(), $wpai_id, $last, self::CHUNK ),
				ARRAY_A
			);
			if ( array() === $rows ) {
				break;
			}
			$fetched               = count( $rows );
			$last                  = (int) end( $rows )['id'];
			$totals['read']       += $fetched;
			$built                 = self::tracking_rows( $rows, $seen );
			$totals['empty']      += $built['empty'];
			$totals['duplicates'] += $built['duplicates'];

			if ( array() !== $built['rows'] ) {
				$values = array();
				$args   = array( $this->records_table() );
				foreach ( $built['rows'] as $row ) {
					$values[] = "(%d, %s, %s, %d, '', 0, 0, %s, %s)";
					array_push( $args, $import_id, $row['unique_key'], $object_type, $row['object_id'], $now, $now );
				}
				$sql               = 'INSERT IGNORE INTO %i (import_id, unique_key, object_type, object_id, data_hash, first_run_id, last_run_id, created_at, updated_at) VALUES ' . implode( ', ', $values );
				$totals['copied'] += (int) $this->db->query( $this->db->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
			}
		} while ( self::CHUNK === $fetched );

		return $totals;
	}

	/**
	 * Record-map rows from pmxi_posts rows: keys trimmed the way Total Import trims rendered keys,
	 * long keys hashed like Tracker does, blanks and repeats (first wins) dropped.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<string, bool>              $seen Storage keys already taken, carried across chunks.
	 * @return array{rows: array<int, array{unique_key: string, object_id: int}>, empty: int, duplicates: int}
	 */
	public static function tracking_rows( array $rows, array &$seen ): array {
		$result = array(
			'rows'       => array(),
			'empty'      => 0,
			'duplicates' => 0,
		);
		foreach ( $rows as $row ) {
			$key = trim( (string) ( $row['unique_key'] ?? '' ) );
			$id  = (int) ( $row['post_id'] ?? 0 );
			if ( '' === $key || $id <= 0 ) {
				++$result['empty'];
				continue;
			}
			$storage = Tracker::storage_key( $key );
			if ( isset( $seen[ $storage ] ) ) {
				++$result['duplicates'];
				continue;
			}
			$seen[ $storage ] = true;
			$result['rows'][] = array(
				'unique_key' => $storage,
				'object_id'  => $id,
			);
		}
		return $result;
	}

	private function imports_table(): string {
		return $this->db->prefix . 'pmxi_imports';
	}

	private function posts_table(): string {
		return $this->db->prefix . 'pmxi_posts';
	}

	private function records_table(): string {
		return $this->db->prefix . 'importright_records';
	}
}

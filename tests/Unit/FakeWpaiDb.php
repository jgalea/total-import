<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName -- mirrors wpdb's property names.

namespace Importright\Tests\Unit;

/**
 * Stands in for wpdb: prepare() keeps the query and its arguments, the read methods answer from
 * in-memory pmxi tables, and query() records inserts.
 */
final class FakeWpaiDb {

	public string $prefix = 'wp_';

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $imports = array();

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $posts = array();

	/**
	 * @var array<int, array{sql: string, args: array<int, mixed>}>
	 */
	public array $inserts = array();

	public int $selects = 0;

	public bool $has_tables = true;

	/**
	 * @var array<string, bool>
	 */
	private array $inserted_keys = array();

	/**
	 * @param mixed ...$args
	 * @return array{sql: string, args: array<int, mixed>}
	 */
	public function prepare( string $sql, ...$args ): array {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return array(
			'sql'  => $sql,
			'args' => array_values( $args ),
		);
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * @param array{sql: string, args: array<int, mixed>} $query
	 * @return mixed
	 */
	public function get_var( array $query ) {
		if ( 0 === strpos( $query['sql'], 'SHOW TABLES' ) ) {
			return $this->has_tables ? stripslashes( $query['args'][0] ) : null;
		}
		if ( false !== strpos( $query['sql'], 'COUNT(*)' ) ) {
			return (string) count( $this->rows_for( (int) $query['args'][1] ) );
		}
		return null;
	}

	/**
	 * @param array{sql: string, args: array<int, mixed>} $query
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( array $query, string $output ): array {
		unset( $output );
		$sql = $query['sql'];
		if ( false !== strpos( $sql, 'GROUP BY import_id' ) ) {
			$counts = array();
			foreach ( $this->posts as $row ) {
				$counts[ $row['import_id'] ] = ( $counts[ $row['import_id'] ] ?? 0 ) + 1;
			}
			$rows = array();
			foreach ( $counts as $id => $count ) {
				$rows[] = array(
					'import_id' => (string) $id,
					'records'   => (string) $count,
				);
			}
			return $rows;
		}
		if ( false !== strpos( $sql, 'parent_import_id = 0' ) ) {
			return array_values( $this->imports );
		}
		if ( false !== strpos( $sql, 'id > %d' ) ) {
			++$this->selects;
			list( $table, $import_id, $last, $limit ) = $query['args'];
			if ( 'wp_pmxi_posts' !== $table ) {
				return array();
			}
			$rows = array_filter(
				$this->rows_for( (int) $import_id ),
				static function ( array $row ) use ( $last ): bool {
					return $row['id'] > $last;
				}
			);
			return array_slice( array_values( $rows ), 0, (int) $limit );
		}
		return array();
	}

	/**
	 * @param array{sql: string, args: array<int, mixed>} $query
	 * @return array<string, mixed>|null
	 */
	public function get_row( array $query, string $output ): ?array {
		unset( $output );
		return $this->imports[ (int) $query['args'][1] ] ?? null;
	}

	/**
	 * @param array{sql: string, args: array<int, mixed>} $query
	 */
	public function query( array $query ): int {
		$this->inserts[] = $query;
		$affected        = 0;
		foreach ( array_chunk( array_slice( $query['args'], 1 ), 6 ) as $values ) {
			$key = $values[0] . '|' . $values[1];
			if ( ! isset( $this->inserted_keys[ $key ] ) ) {
				$this->inserted_keys[ $key ] = true;
				++$affected;
			}
		}
		return $affected;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_for( int $import_id ): array {
		return array_values(
			array_filter(
				$this->posts,
				static function ( array $row ) use ( $import_id ): bool {
					return (int) $row['import_id'] === $import_id;
				}
			)
		);
	}
}

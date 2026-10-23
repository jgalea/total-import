<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName -- mirrors wpdb's property names.

namespace Importright\Tests\Unit;

/**
 * Stands in for wpdb for the runs table, the log table and the record map's INSERT. prepare()
 * substitutes its arguments so the other methods can read the final SQL; transactions snapshot
 * the runs and the log so a ROLLBACK really undoes what the step wrote.
 */
final class FakeRunsDb {

	public string $prefix = 'wp_';

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $runs = array();

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $imports = array();

	public int $insert_id = 0;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $log = array();

	/**
	 * @var string[]
	 */
	public array $record_inserts = array();

	/**
	 * @var string[]
	 */
	public array $transactions = array();

	/**
	 * @var array{runs: array<int, array<string, mixed>>, log: array<int, array<string, mixed>>}|null
	 */
	private ?array $snapshot = null;

	/**
	 * @param mixed ...$args
	 */
	public function prepare( string $sql, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$args = array_values( $args );
		return (string) preg_replace_callback(
			'/%([ids])/',
			static function ( array $m ) use ( &$args ): string {
				$value = array_shift( $args );
				if ( 'i' === $m[1] ) {
					return '`' . $value . '`';
				}
				return 'd' === $m[1] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$sql
		);
	}

	/**
	 * @return int|bool
	 */
	public function query( string $sql ) {
		if ( 'START TRANSACTION' === $sql ) {
			$this->transactions[] = 'start';
			$this->snapshot       = array(
				'runs' => $this->runs,
				'log'  => $this->log,
			);
			return true;
		}
		if ( 'COMMIT' === $sql ) {
			$this->transactions[] = 'commit';
			$this->snapshot       = null;
			return true;
		}
		if ( 'ROLLBACK' === $sql ) {
			$this->transactions[] = 'rollback';
			if ( null !== $this->snapshot ) {
				$this->runs = $this->snapshot['runs'];
				$this->log  = $this->snapshot['log'];
			}
			$this->snapshot = null;
			return true;
		}
		if ( preg_match( "/^UPDATE `[^`]+` SET lock_token = '([0-9a-f]+)', locked_until = (\d+) WHERE id = (\d+) AND locked_until < (\d+)$/", $sql, $m ) ) {
			$id = (int) $m[3];
			if ( isset( $this->runs[ $id ] ) && (int) $this->runs[ $id ]['locked_until'] < (int) $m[4] ) {
				$this->runs[ $id ]['lock_token']   = $m[1];
				$this->runs[ $id ]['locked_until'] = (int) $m[2];
				return 1;
			}
			return 0;
		}
		if ( preg_match( "/^UPDATE `[^`]+` SET lock_token = '', locked_until = 0 WHERE id = (\d+)/", $sql, $m ) ) {
			$this->runs[ (int) $m[1] ]['lock_token']   = '';
			$this->runs[ (int) $m[1] ]['locked_until'] = 0;
			return 1;
		}
		if ( 0 === strpos( $sql, 'INSERT INTO `wp_importright_log`' ) ) {
			preg_match_all( "/\((\d+), (-?\d+), '((?:[^'\\\\]|\\\\.)*)', '([^']*)', '([^']*)', (\d+), '((?:[^'\\\\]|\\\\.)*)', '((?:[^'\\\\]|\\\\.)*)'\)/", $sql, $rows, PREG_SET_ORDER );
			foreach ( $rows as $row ) {
				$this->log[] = array(
					'run_id'  => (int) $row[1],
					'key'     => stripslashes( $row[3] ),
					'level'   => $row[4],
					'message' => stripslashes( $row[7] ),
				);
			}
			return count( $rows );
		}
		if ( 0 === strpos( $sql, 'INSERT INTO `wp_importright_records`' ) ) {
			$this->record_inserts[] = $sql;
			return 1;
		}
		return 0;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( string $table, array $data ): int {
		if ( 'wp_importright_runs' !== $table ) {
			return 0;
		}
		$this->insert_id                = max( array_merge( array( 0 ), array_keys( $this->runs ) ) ) + 1;
		$this->runs[ $this->insert_id ] = array_merge(
			array(
				'id'           => $this->insert_id,
				'total'        => null,
				'processed'    => 0,
				'created'      => 0,
				'updated'      => 0,
				'unchanged'    => 0,
				'skipped'      => 0,
				'failed'       => 0,
				'lock_token'   => '',
				'locked_until' => 0,
				'heartbeat'    => 0,
			),
			$data
		);
		return 1;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update( string $table, array $data, array $where ): int {
		unset( $table );
		$id = (int) $where['id'];
		if ( ! isset( $this->runs[ $id ] ) ) {
			return 0;
		}
		$this->runs[ $id ] = array_merge( $this->runs[ $id ], $data );
		return 1;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $sql, $output = null ): ?array {
		unset( $output );
		if ( preg_match( '/^SELECT \* FROM `wp_importright_imports` WHERE id = (\d+)$/', $sql, $m ) ) {
			return $this->imports[ (int) $m[1] ] ?? null;
		}
		if ( preg_match( '/^SELECT \* FROM `[^`]+` WHERE id = (\d+)$/', $sql, $m ) ) {
			return $this->runs[ (int) $m[1] ] ?? null;
		}
		return null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $sql, $output = null ): array {
		unset( $output );
		if ( preg_match( "/^SELECT id FROM `wp_importright_runs` WHERE status IN \\('pending','running'\\) AND background = 1 AND locked_until < (\\d+) AND heartbeat < (\\d+)$/", $sql, $m ) ) {
			$rows = array();
			foreach ( $this->runs as $run ) {
				if ( in_array( $run['status'], array( 'pending', 'running' ), true ) && 1 === (int) ( $run['background'] ?? 0 ) && (int) $run['locked_until'] < (int) $m[1] && (int) $run['heartbeat'] < (int) $m[2] ) {
					$rows[] = array( 'id' => (string) $run['id'] );
				}
			}
			return $rows;
		}
		return array();
	}

	public function get_var( string $sql ): string {
		if ( preg_match( '/^SELECT COUNT\(\*\) FROM `wp_importright_log` WHERE run_id = (\d+)(?: AND level IN \((.*)\))?$/', $sql, $m ) ) {
			$levels = isset( $m[2] ) ? array_map( static fn( $l ) => trim( $l, "'" ), explode( ',', $m[2] ) ) : null;
			$count  = 0;
			foreach ( $this->log as $row ) {
				if ( $row['run_id'] === (int) $m[1] && ( null === $levels || in_array( $row['level'], $levels, true ) ) ) {
					++$count;
				}
			}
			return (string) $count;
		}
		return '0';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function log_for( int $run_id, string $level ): array {
		return array_values( array_filter( $this->log, static fn( $row ) => $row['run_id'] === $run_id && $row['level'] === $level ) );
	}
}

<?php

namespace Importright\Tests\Unit;

use Importright\Runs\Rollback;
use Importright\Runs\Tracker;
use PHPUnit\Framework\TestCase;

final class RollbackRecordTest extends TestCase {

	/**
	 * @var object
	 */
	private $db;

	protected function setUp(): void {
		$this->db        = new class() {
			public string $prefix = 'wp_';
			/**
			 * @var array<int, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
			 */
			public array $updates = array();
			/**
			 * @var string[]
			 */
			public array $queries = array();
			/**
			 * @var array<int, array<string, string>>
			 */
			public array $rows = array();
			public function prepare( string $sql, ...$args ): string {
				unset( $args );
				return $sql;
			}
			public function update( string $table, array $data, array $where ): int {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}
			public function get_results( string $sql, $output = null ): array {
				unset( $output );
				$this->queries[] = $sql;
				return $this->rows;
			}
		};
		$GLOBALS['wpdb'] = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the fake stands in for wpdb.
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * @param array<string, mixed>|null $record
	 */
	private function revert( ?array $record ): void {
		$rollback = ( new \ReflectionClass( Rollback::class ) )->newInstanceWithoutConstructor();
		$entry    = array(
			'unique_key' => 'sku-1',
			'object'     => 41,
			'data'       => array( 'record' => $record ),
		);
		\Closure::bind(
			function () use ( $entry ): void {
				$this->revert_record( array( 'import_id' => 9 ), $entry );
			},
			$rollback,
			Rollback::class
		)();
	}

	/**
	 * @return array<string, array{0: bool, 1: int}>
	 */
	public function flags(): array {
		return array(
			'the import created it' => array( false, 0 ),
			'matched by find()'     => array( true, 1 ),
		);
	}

	/**
	 * @dataProvider flags
	 */
	public function test_revert_puts_back_the_adopted_flag_from_before_the_run( bool $before, int $stored ): void {
		$this->revert(
			array(
				'object_type'  => 'product',
				'object_id'    => 12,
				'data_hash'    => 'abc',
				'adopted'      => $before,
				'first_run_id' => 3,
				'last_run_id'  => 4,
			)
		);

		$this->assertCount( 1, $this->db->updates );
		list( $table, $data, $where ) = $this->db->updates[0];
		$this->assertSame( 'wp_importright_records', $table );
		$this->assertSame( $stored, $data['adopted'] );
		$this->assertSame( 12, $data['object_id'] );
		$this->assertSame(
			array(
				'import_id'  => 9,
				'unique_key' => 'sku-1',
			),
			$where
		);
	}

	public function test_revert_puts_back_when_the_record_was_last_written(): void {
		$this->revert(
			array(
				'object_type'  => 'product',
				'object_id'    => 12,
				'data_hash'    => 'abc',
				'first_run_id' => 3,
				'last_run_id'  => 4,
				'updated_at'   => '2026-10-22 12:00:00',
			)
		);

		$this->assertSame( '2026-10-22 12:00:00', $this->db->updates[0][1]['updated_at'] );
	}

	public function test_a_journal_without_the_timestamp_leaves_updated_at_alone(): void {
		$this->revert(
			array(
				'object_type' => 'post',
				'object_id'   => 12,
				'data_hash'   => 'abc',
			)
		);

		$this->assertArrayNotHasKey( 'updated_at', $this->db->updates[0][1] );
	}

	public function test_a_journal_from_before_the_adopted_column_restores_zero(): void {
		$this->revert(
			array(
				'object_type' => 'post',
				'object_id'   => 12,
				'data_hash'   => 'abc',
			)
		);

		$this->assertSame( 0, $this->db->updates[0][1]['adopted'] );
	}

	public function test_a_key_with_no_earlier_record_is_removed(): void {
		$this->revert( null );

		$this->assertSame( array(), $this->db->updates );
		$this->assertStringStartsWith( 'DELETE FROM', $this->db->queries[0] );
	}

	public function test_lookup_returns_the_adopted_flag_so_the_journal_keeps_it(): void {
		$this->db->rows = array(
			array(
				'id'           => '1',
				'unique_key'   => 'sku-1',
				'object_type'  => 'product',
				'object_id'    => '12',
				'data_hash'    => 'abc',
				'adopted'      => '1',
				'first_run_id' => '3',
				'last_run_id'  => '4',
				'updated_at'   => '2026-10-22 12:00:00',
			),
		);

		$found = Tracker::lookup( 9, array( 'sku-1' ) );

		$this->assertTrue( $found['sku-1']['adopted'] );
		$this->assertSame( '2026-10-22 12:00:00', $found['sku-1']['updated_at'] );
		$this->assertStringContainsString( ' adopted,', $this->db->queries[0] );
	}
}

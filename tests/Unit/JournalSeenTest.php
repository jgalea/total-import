<?php

namespace Importright\Tests\Unit;

use Importright\Runs\Journal;
use PHPUnit\Framework\TestCase;

final class JournalSeenTest extends TestCase {

	/**
	 * @var object
	 */
	private $db;

	protected function setUp(): void {
		$this->db        = new class() {
			public string $prefix = 'wp_';
			/**
			 * @var string[]
			 */
			public array $queries = array();
			/**
			 * @param mixed ...$args
			 */
			public function prepare( string $sql, ...$args ): string {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$args = array_values( $args );
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function () use ( &$args ): string {
						return (string) array_shift( $args );
					},
					$sql
				);
			}
			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}
		};
		$GLOBALS['wpdb'] = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the fake stands in for wpdb.
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $id ): array {
		return array(
			'id'           => $id,
			'object_type'  => 'post',
			'object_id'    => 40 + $id,
			'data_hash'    => 'abc',
			'adopted'      => false,
			'first_run_id' => 1,
			'last_run_id'  => 1,
			'updated_at'   => '2026-10-22 12:00:00',
		);
	}

	public function test_a_row_the_run_re_stamps_is_kept_as_it_was(): void {
		$journal = new Journal( 5, 2 );
		$journal->record_seen( 'sku-1', $this->row( 7 ) );
		$journal->flush();

		$this->assertCount( 1, $this->db->queries );
		$this->assertStringContainsString( 'INSERT IGNORE INTO wp_importright_changes', $this->db->queries[0] );
		$this->assertStringContainsString( '5, 2, record, 7, seen, sku-1,', $this->db->queries[0] );
		$this->assertStringContainsString( '2026-10-22 12:00:00', $this->db->queries[0] );
	}

	public function test_the_first_state_in_a_batch_wins(): void {
		$later                = $this->row( 7 );
		$later['last_run_id'] = 5;
		$journal              = new Journal( 5, 2 );
		$journal->record_seen( 'sku-1', $this->row( 7 ) );
		$journal->record_seen( 'sku-1', $later );
		$journal->flush();

		$this->assertCount( 1, $this->db->queries );
		$this->assertStringContainsString( '"last_run_id":1', $this->db->queries[0] );
	}

	public function test_a_row_without_an_id_is_not_kept(): void {
		$journal = new Journal( 5, 2 );
		$journal->record_seen( 'sku-1', array( 'id' => 0 ) );
		$journal->flush();

		$this->assertSame( array(), $this->db->queries );
	}
}

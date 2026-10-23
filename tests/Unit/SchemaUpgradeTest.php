<?php

namespace Importright\Tests\Unit;

use Importright\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaUpgradeTest extends TestCase {

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
			public function prepare( string $sql, ...$args ): string {
				return str_replace( '%i', '`' . $args[0] . '`', $sql );
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

	public function test_a_v2_site_flags_its_background_runs_for_the_watchdog(): void {
		Schema::upgrade_from( '2' );

		$this->assertSame( array( "UPDATE `wp_importright_runs` SET background = 1 WHERE origin = 'background' AND background = 0" ), $this->db->queries );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function no_backfill(): array {
		return array(
			'fresh install' => array( '' ),
			'already v3'    => array( '3' ),
		);
	}

	/**
	 * @dataProvider no_backfill
	 */
	public function test_fresh_and_current_sites_change_nothing( string $previous ): void {
		Schema::upgrade_from( $previous );

		$this->assertSame( array(), $this->db->queries );
	}
}

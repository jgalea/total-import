<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Runs\Journal;
use Importright\Runs\Rollback;
use Importright\Runs\Snapshot;

final class SnapshotTest extends TestCase {

	private const BEFORE = array(
		'row.post_title'    => 'Old title',
		'row.post_modified' => '2026-01-01 00:00:00',
		'meta.10'           => array( '_price', '10' ),
		'meta.11'           => array( '_gone', 'x' ),
		'terms.category'    => '5:0',
	);

	private const AFTER = array(
		'row.post_title'    => 'New title',
		'row.post_modified' => '2026-10-21 10:00:00',
		'meta.10'           => array( '_price', '12' ),
		'meta.12'           => array( '_new', 'y' ),
		'terms.category'    => '5:0',
		'terms.post_tag'    => '9:0',
	);

	public function test_diff_keeps_old_values_of_changed_fields_only(): void {
		$diff = Snapshot::diff( self::BEFORE, self::AFTER );

		$this->assertSame(
			array(
				'row.post_title'    => 'Old title',
				'row.post_modified' => '2026-01-01 00:00:00',
				'meta.10'           => array( '_price', '10' ),
				'meta.11'           => array( '_gone', 'x' ),
				'meta.12'           => null,
				'terms.post_tag'    => null,
			),
			$diff
		);
		$this->assertArrayNotHasKey( 'terms.category', $diff );
	}

	public function test_diff_of_identical_snapshots_is_empty(): void {
		$this->assertSame( array(), Snapshot::diff( self::AFTER, self::AFTER ) );
	}

	public function test_hash_ignores_fields_outside_the_list(): void {
		$fields = array_keys( Snapshot::diff( self::BEFORE, self::AFTER ) );
		$edited = self::AFTER;

		$edited['terms.category'] = '7:0';
		$this->assertSame( Snapshot::hash( self::AFTER, $fields ), Snapshot::hash( $edited, $fields ) );

		$edited['row.post_title'] = 'Edited by hand';
		$this->assertNotSame( Snapshot::hash( self::AFTER, $fields ), Snapshot::hash( $edited, $fields ) );
	}

	public function test_hash_tells_a_missing_field_from_an_empty_one(): void {
		$fields = array( 'meta.12' );
		$gone   = self::AFTER;
		unset( $gone['meta.12'] );
		$empty            = self::AFTER;
		$empty['meta.12'] = array( '_new', '' );

		$this->assertNotSame( Snapshot::hash( self::AFTER, $fields ), Snapshot::hash( $gone, $fields ) );
		$this->assertNotSame( Snapshot::hash( $gone, $fields ), Snapshot::hash( $empty, $fields ) );
	}

	public function test_full_hash_does_not_depend_on_key_order(): void {
		$this->assertSame( Snapshot::hash( self::AFTER ), Snapshot::hash( array_reverse( self::AFTER, true ) ) );
	}

	public function test_journal_names_fields_readably(): void {
		$names = Journal::names( Snapshot::diff( self::BEFORE, self::AFTER ), self::AFTER );

		$this->assertSame( array( 'post_title', 'post_modified', 'meta:_price', 'meta:_gone', 'meta:_new', 'terms:post_tag' ), $names );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['importright_test_filters']['importright_post_object_types'], $GLOBALS['importright_test_filters']['importright_rollback_remove'] );
	}

	public function test_post_object_types_default(): void {
		$this->assertSame( array( 'post', 'product', 'attachment' ), Snapshot::post_types() );
	}

	public function test_post_object_types_filter_adds_a_type(): void {
		$GLOBALS['importright_test_filters']['importright_post_object_types'] = static function ( array $types ): array {
			$types[] = 'widget';
			return $types;
		};
		$this->assertSame( array( 'post', 'product', 'attachment', 'widget' ), Snapshot::post_types() );
	}

	public function test_post_object_types_filter_returning_junk_keeps_the_default(): void {
		$GLOBALS['importright_test_filters']['importright_post_object_types'] = static function () {
			return 'widget';
		};
		$this->assertSame( array( 'post', 'product', 'attachment' ), Snapshot::post_types() );
	}

	private function remove( string $type ): string {
		$rollback = ( new \ReflectionClass( Rollback::class ) )->newInstanceWithoutConstructor();
		$method   = new \ReflectionMethod( Rollback::class, 'remove' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invoke( $rollback, $type, 5, array() );
	}

	public function test_unknown_type_without_a_listener_fails_as_before(): void {
		$this->assertSame( 'Unknown object type.', $this->remove( 'widget' ) );
	}

	public function test_rollback_remove_listener_handles_an_unknown_type(): void {
		$seen = array();
		$GLOBALS['importright_test_filters']['importright_rollback_remove'] = static function ( $error, string $type, int $id ) use ( &$seen ) {
			$seen = array( $type, $id );
			return 'widget' === $type ? '' : $error;
		};
		$this->assertSame( '', $this->remove( 'widget' ) );
		$this->assertSame( array( 'widget', 5 ), $seen );
		$this->assertSame( 'Unknown object type.', $this->remove( 'gadget' ) );
	}

	private function restore_with_fake_db( string $type, array $before ): void {
		require_once dirname( __DIR__ ) . '/stubs/term-count-functions.php';

		$GLOBALS['importright_test_recounts'] = array();
		$GLOBALS['wpdb']                      = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the fake stands in for wpdb.
			public $posts              = 'wp_posts';
			public $terms              = 'wp_terms';
			public $users              = 'wp_users';
			public $term_relationships = 'wp_term_relationships';
			public $term_taxonomy      = 'wp_term_taxonomy';

			public function update() {
				return 1;
			}

			public function prepare( $query ) {
				return $query;
			}

			public function get_results() {
				return array(
					array(
						'taxonomy'         => 'category',
						'term_taxonomy_id' => '5',
					),
					array(
						'taxonomy'         => 'category',
						'term_taxonomy_id' => '6',
					),
					array(
						'taxonomy'         => 'product_tag',
						'term_taxonomy_id' => '9',
					),
				);
			}
		};
		try {
			Snapshot::restore( $type, 7, $before );
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_restoring_a_status_recounts_the_posts_terms(): void {
		$this->restore_with_fake_db( 'post', array( 'row.post_status' => 'publish' ) );
		$this->assertSame(
			array(
				array( 'category', array( 5, 6 ) ),
				array( 'product_tag', array( 9 ) ),
			),
			$GLOBALS['importright_test_recounts']
		);
	}

	public function test_restoring_other_columns_does_not_recount(): void {
		$this->restore_with_fake_db( 'post', array( 'row.post_title' => 'Old title' ) );
		$this->assertSame( array(), $GLOBALS['importright_test_recounts'] );
	}

	public function test_restoring_a_term_row_does_not_recount_posts(): void {
		$this->restore_with_fake_db( 'term', array( 'row.post_status' => 'publish' ) );
		$this->assertSame( array(), $GLOBALS['importright_test_recounts'] );
	}
}

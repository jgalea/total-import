<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Runs\Decision;
use Importright\Runs\Tracker;

final class DecisionTest extends TestCase {

	public function test_new_records(): void {
		$this->assertSame( Decision::CREATE, Decision::decide( 'create_update', null, null, 'h', true ) );
		$this->assertSame( Decision::CREATE, Decision::decide( 'create', null, null, 'h', true ) );
		$this->assertSame( Decision::SKIP, Decision::decide( 'update', null, null, 'h', true ) );
	}

	public function test_existing_records(): void {
		$this->assertSame( Decision::UPDATE, Decision::decide( 'create_update', 5, 'old', 'new', true ) );
		$this->assertSame( Decision::UPDATE, Decision::decide( 'update', 5, 'old', 'new', true ) );
		$this->assertSame( Decision::SKIP, Decision::decide( 'create', 5, 'old', 'new', true ) );
	}

	public function test_unchanged_only_when_hash_matches_and_skipping_is_on(): void {
		$this->assertSame( Decision::UNCHANGED, Decision::decide( 'create_update', 5, 'same', 'same', true ) );
		$this->assertSame( Decision::UPDATE, Decision::decide( 'create_update', 5, 'same', 'same', false ) );
		$this->assertSame( Decision::UPDATE, Decision::decide( 'create_update', 5, null, 'same', true ), 'Adopted content with no stored hash is updated.' );
		$this->assertSame( Decision::SKIP, Decision::decide( 'create', 5, 'same', 'same', true ) );
	}

	public function test_split_on_create_keeps_everything(): void {
		list( $core, $sections ) = Decision::split( $this->values(), array( 'title' => false ), array( 'meta' ), true );
		$this->assertSame(
			array(
				'title'   => 'T',
				'content' => 'C',
			),
			$core
		);
		$this->assertTrue( $sections['meta']['rule'] );
	}

	public function test_split_on_update_applies_rules(): void {
		list( $core, $sections ) = Decision::split( $this->values(), array( 'title' => false ), array( 'meta' ), false );
		$this->assertSame( array( 'content' => 'C' ), $core );
		$this->assertTrue( $sections['meta']['rule'] );

		list( , $sections ) = Decision::split( $this->values(), array( 'meta' => false ), array( 'meta' ), false );
		$this->assertSame( array(), $sections );

		list( , $sections ) = Decision::split( $this->values(), array( 'meta' => array( 'price' ) ), array( 'meta' ), false );
		$this->assertSame( array( 'price' ), $sections['meta']['rule'] );
	}

	public function test_hash_ignores_fields_updates_cannot_change(): void {
		$values  = $this->values();
		$changed = $values;

		$changed['title']         = 'Other';
		$changed['meta']['stock'] = '9';

		$rules = array(
			'title' => false,
			'meta'  => array( 'price' ),
		);
		$this->assertSame( Decision::hash( $values, $rules, array( 'meta' ) ), Decision::hash( $changed, $rules, array( 'meta' ) ) );

		$changed['meta']['price'] = '2';
		$this->assertNotSame( Decision::hash( $values, $rules, array( 'meta' ) ), Decision::hash( $changed, $rules, array( 'meta' ) ) );
		$this->assertNotSame( Decision::hash( $values, array(), array( 'meta' ) ), Decision::hash( $values, $rules, array( 'meta' ) ), 'Changing the rules changes the hash.' );
	}

	public function test_long_keys_are_hashed_for_storage(): void {
		$this->assertSame( 'SKU-1', Tracker::storage_key( 'SKU-1' ) );
		$long = str_repeat( 'k', 300 );
		$this->assertSame( 'sha1:' . sha1( $long ), Tracker::storage_key( $long ) );
		$this->assertSame( str_repeat( 'k', 255 ), Tracker::storage_key( str_repeat( 'k', 255 ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function values(): array {
		return array(
			'title'   => 'T',
			'content' => 'C',
			'meta'    => array(
				'price' => '1',
				'stock' => '5',
			),
		);
	}
}

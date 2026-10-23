<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Runs\Diff;
use Importright\Runs\Log;

final class DiffTest extends TestCase {

	public function test_only_changed_fields_are_listed(): void {
		$diff = new Diff();
		$diff->add( 'title', 'Same', 'Same' );
		$diff->add( 'menu_order', 3, '3' );
		$diff->add( 'meta._price', '10', '12' );
		$diff->add( 'meta._sku', null, 'A-1' );
		$diff->add( 'manage_stock', false, true );

		$this->assertSame(
			array(
				array(
					'field' => 'meta._price',
					'old'   => '10',
					'new'   => '12',
				),
				array(
					'field' => 'meta._sku',
					'old'   => null,
					'new'   => 'A-1',
				),
				array(
					'field' => 'manage_stock',
					'old'   => 'no',
					'new'   => 'yes',
				),
			),
			$diff->items()
		);
	}

	public function test_a_field_set_to_empty_differs_from_a_missing_one(): void {
		$diff = new Diff();
		$diff->add( 'meta.colour', null, '' );

		$this->assertCount( 1, $diff->items() );
	}

	public function test_long_values_are_cut_on_a_character_boundary(): void {
		$diff = new Diff();
		$diff->add( 'content', null, str_repeat( 'é', Diff::MAX_LENGTH ) );

		$new = $diff->items()[0]['new'];
		$this->assertLessThanOrEqual( Diff::MAX_LENGTH + strlen( '…' ), strlen( $new ) );
		$this->assertTrue( mb_check_encoding( $new, 'UTF-8' ) );
		$this->assertStringEndsWith( '…', $new );
	}

	public function test_csv_cells_never_start_a_formula(): void {
		$this->assertSame( "'=HYPERLINK(\"x\")", Log::cell( '=HYPERLINK("x")' ) );
		$this->assertSame( "'+1", Log::cell( '+1' ) );
		$this->assertSame( "'@SUM(A1)", Log::cell( '@SUM(A1)' ) );
		$this->assertSame( 'Plain text', Log::cell( 'Plain text' ) );
		$this->assertSame( '', Log::cell( '' ) );
	}
}

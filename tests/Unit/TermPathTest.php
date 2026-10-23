<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Field\TermPath;

final class TermPathTest extends TestCase {

	public function test_splits_terms_and_hierarchy(): void {
		$this->assertSame(
			array( array( 'News' ), array( 'Sports', 'Football' ) ),
			TermPath::parse( ' News , Sports > Football ', ',', '>' )
		);
	}

	public function test_deep_paths_and_blank_segments(): void {
		$this->assertSame(
			array( array( 'A', 'B', 'C' ) ),
			TermPath::parse( 'A >> B > C >', ',', '>' )
		);
	}

	public function test_drops_blanks_and_case_insensitive_repeats(): void {
		$this->assertSame(
			array( array( 'Red' ), array( 'blue' ) ),
			TermPath::parse( 'Red||blue|| RED |Blue', '|', '' )
		);
		$this->assertSame(
			array( array( 'Blue' ) ),
			TermPath::parse( 'Blue|blue', '|', '' ),
			'The first spelling wins.'
		);
	}

	public function test_without_hierarchy_the_separator_is_text(): void {
		$this->assertSame( array( array( 'Rock > Pop' ) ), TermPath::parse( 'Rock > Pop', ',', '' ) );
	}

	public function test_without_separator_the_cell_is_one_term(): void {
		$this->assertSame( array( array( 'Salt, Pepper' ) ), TermPath::parse( 'Salt, Pepper', '', '' ) );
		$this->assertSame( array(), TermPath::parse( '  ', ',', '>' ) );
	}

	public function test_multibyte_separators_and_names(): void {
		$this->assertSame(
			array( array( 'Café', 'Crème' ), array( 'Thé' ) ),
			TermPath::parse( 'Café → Crème ・ Thé', '・', '→' )
		);
	}

	public function test_split_lists(): void {
		$this->assertSame( array( 'a.jpg', 'b.jpg' ), TermPath::split( ' a.jpg | b.jpg || a.jpg ', '|' ) );
		$this->assertSame( array( 'a.jpg, b.jpg' ), TermPath::split( 'a.jpg, b.jpg', '' ) );
	}
}

<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Mapping\Template;
use Importright\Migration\Expression;
use Importright\Migration\Report;

final class WpaiExpressionTest extends TestCase {

	public function test_wpai_csv_column_names(): void {
		$map = Expression::wpai_columns( array( 'Product Name', 'SKU', '2nd Price', 'column_4', 'Price', 'price', 'Weight (kg)' ) );
		$this->assertSame(
			array(
				'productname' => 'Product Name',
				'sku'         => 'SKU',
				'el_2ndprice' => '2nd Price',
				'undefined3'  => 'column_4',
				'price'       => 'Price',
				'price_2'     => 'price',
				'weightkg'    => 'Weight (kg)',
			),
			$map
		);
	}

	public function test_numbered_columns_detection(): void {
		$this->assertTrue( Expression::wpai_numbers_columns( array( 'Name', '42' ) ) );
		$this->assertTrue( Expression::wpai_numbers_columns( array( 'Name', 'link https:' ) ) );
		$this->assertFalse( Expression::wpai_numbers_columns( array( 'Name', 'SKU' ) ) );
	}

	public function test_csv_references_resolve_to_the_file_columns(): void {
		$expression = new Expression( true, Expression::wpai_columns( array( 'Product Name', 'SKU' ) ) );
		$result     = $expression->convert( '{productname[1]} ({sku[1]})' );
		$this->assertSame( '{Product Name} ({SKU})', $result['template'] );
		$this->assertSame( Report::MAPPED, $result['status'] );
		$this->assertSame(
			'Shirt (S-1)',
			( new Template( $result['template'] ) )->render(
				array(
					'Product Name' => 'Shirt',
					'SKU'          => 'S-1',
				)
			)
		);
	}

	public function test_csv_reference_with_node_prefix_and_absolute_path(): void {
		$expression = new Expression( true, Expression::wpai_columns( array( 'SKU' ) ), 'node' );
		$result     = $expression->convert( '{/node/sku[1]}' );
		$this->assertSame( '{SKU}', $result['template'] );
		$this->assertSame( Report::APPROXIMATED, $result['status'] );
	}

	public function test_csv_without_the_file_is_approximated(): void {
		$result = ( new Expression( true ) )->convert( '{productname[1]}' );
		$this->assertSame( '{productname}', $result['template'] );
		$this->assertSame( Report::APPROXIMATED, $result['status'] );
		$this->assertStringContainsString( 'lower-cases', $result['notes'][0] );
	}

	public function test_csv_unknown_column_is_flagged(): void {
		$result = ( new Expression( true, Expression::wpai_columns( array( 'SKU' ) ) ) )->convert( '{price[1]}' );
		$this->assertSame( '{price}', $result['template'] );
		$this->assertSame( Report::APPROXIMATED, $result['status'] );
	}

	public function test_csv_column_with_slash_cannot_be_referenced(): void {
		$result = ( new Expression( true, Expression::wpai_columns( array( 'Size/Color' ) ) ) )->convert( '{sizecolor[1]}' );
		$this->assertSame( '', $result['template'] );
		$this->assertSame( Report::UNSUPPORTED, $result['status'] );
	}

	public function test_xml_paths_attributes_and_indexes(): void {
		$expression = new Expression();
		$this->assertSame( '{title}', $expression->convert( '{title[1]}' )['template'] );
		$this->assertSame( '{body}', $expression->convert( '{./body[1]}' )['template'] );
		$this->assertSame( '{dimensions.width}', $expression->convert( '{dimensions[1]/width[1]}' )['template'] );
		$this->assertSame( '{image[2]}', $expression->convert( '{image[2]}' )['template'] );
		$this->assertSame( '{variant[2].sku}', $expression->convert( '{variant[2]/sku[1]}' )['template'] );
		$this->assertSame( '{@id}', $expression->convert( '{@id}' )['template'] );
		$this->assertSame( '{price@currency}', $expression->convert( '{price[1]/@currency}' )['template'] );
		$this->assertSame( '{g:price}', $expression->convert( '{g:price[1]}' )['template'] );
		$this->assertSame( '{title}', $expression->convert( '{title[1]/text()}' )['template'] );
		$this->assertSame( Report::MAPPED, $expression->convert( '{price[1]/@currency}' )['status'] );
	}

	public function test_converted_xml_references_read_flattened_records(): void {
		$record = array(
			'@id'              => '7',
			'price'            => '9.99',
			'price@currency'   => 'EUR',
			'dimensions.width' => '10',
			'image'            => 'a.jpg',
			'image[1]'         => 'a.jpg',
			'image[2]'         => 'b.jpg',
		);
		$result = ( new Expression() )->convert( '{@id}: {price[1]} {price[1]/@currency}, {dimensions[1]/width[1]} cm, {image[2]}' );
		$this->assertSame( '7: 9.99 EUR, 10 cm, b.jpg', ( new Template( $result['template'] ) )->render( $record ) );
	}

	public function test_repeated_xml_element_without_index_is_flagged_when_the_sample_shows_it(): void {
		$expression = new Expression( false, null, '', array( 'tags.tag', 'tags.tag[1]', 'tags.tag[2]' ) );
		$result     = $expression->convert( '{tags[1]/tag}' );
		$this->assertSame( '{tags.tag}', $result['template'] );
		$this->assertSame( Report::APPROXIMATED, $result['status'] );
	}

	public function test_xpath_expressions_are_dropped(): void {
		$expression = new Expression();
		foreach ( array( '{specs/spec[@name="size"]}', '{//title}', '{../parent}', '{*}', '{count(item)}', '{a | b}', '{price[1] * 2}' ) as $xpath ) {
			$result = $expression->convert( 'x ' . $xpath );
			$this->assertSame( 'x ', $result['template'], $xpath );
			$this->assertSame( Report::APPROXIMATED, $result['status'], $xpath );
		}
		$this->assertSame( Report::UNSUPPORTED, $expression->convert( '{specs/spec[@name="size"]}' )['status'] );
	}

	public function test_php_calls_are_removed_never_run(): void {
		$expression = new Expression();
		$result     = $expression->convert( 'Intro: {body[1]} [str_replace("a","b",{title[1]})]' );
		$this->assertSame( 'Intro: {body} ', $result['template'] );
		$this->assertSame( Report::APPROXIMATED, $result['status'] );
		$this->assertStringContainsString( 'str_replace', $result['notes'][0] );

		$only = $expression->convert( '[system("id")]' );
		$this->assertSame( '', $only['template'] );
		$this->assertSame( Report::UNSUPPORTED, $only['status'] );

		$nested = $expression->convert( '[my_fn({a[1]}, "x]y", [inner(1)])] tail' );
		$this->assertSame( ' tail', $nested['template'] );
	}

	public function test_conditions_and_loops_are_removed(): void {
		$result = ( new Expression() )->convert( '[IF({price[1]} > 5)]Pricey[ENDIF] {title[1]}' );
		$this->assertSame( 'Pricey {title}', $result['template'] );
		$this->assertSame( Report::APPROXIMATED, $result['status'] );
		$this->assertCount( 2, $result['notes'] );
	}

	public function test_shortcodes_escapes_and_literal_braces(): void {
		$expression = new Expression();
		$this->assertSame( '[gallery ids="1,2"] {title}', $expression->convert( '[gallery ids="1,2"] {title[1]}' )['template'] );
		$this->assertSame( '[{{literal}}', $expression->convert( '\[\{literal\}' )['template'] );
		$this->assertSame( 'a }} b', $expression->convert( 'a } b' )['template'] );
		$this->assertSame( 'Plain text', $expression->convert( 'Plain text' )['template'] );
		$this->assertSame( Report::MAPPED, $expression->convert( 'Plain text' )['status'] );
	}

	public function test_unclosed_brace_is_text(): void {
		$result = ( new Expression() )->convert( 'a {title' );
		$this->assertSame( 'a {{title', $result['template'] );
		$this->assertSame( 'a {title', ( new Template( $result['template'] ) )->render( array( 'title' => 'X' ) ) );
	}
}

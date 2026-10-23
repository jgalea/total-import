<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Mapping\Mapper;
use Importright\Mapping\Template;

final class TemplateTest extends TestCase {

	private const RECORD = array(
		'title'            => 'Shirt',
		'sku'              => 'S-1',
		'Product Name'     => 'Named',
		'price@currency'   => 'EUR',
		'dimensions.width' => '10',
		'image'            => 'a.jpg',
		'image[2]'         => 'b.jpg',
		'empty'            => '',
	);

	public function test_renders_references_and_literals(): void {
		$this->assertSame( 'Shirt - S-1', ( new Template( '{title} - {sku}' ) )->render( self::RECORD ) );
	}

	public function test_missing_column_is_empty(): void {
		$this->assertSame( 'x--y', ( new Template( 'x-{nope}-y' ) )->render( self::RECORD ) );
	}

	public function test_names_with_spaces_and_case_fallback(): void {
		$this->assertSame( 'Named', ( new Template( '{Product Name}' ) )->render( self::RECORD ) );
		$this->assertSame( 'Named', ( new Template( '{ product name }' ) )->render( self::RECORD ) );
		$this->assertSame( 'Shirt', ( new Template( '{TITLE}' ) )->render( self::RECORD ) );
	}

	public function test_xml_paths(): void {
		$this->assertSame( '10', ( new Template( '{dimensions/width}' ) )->render( self::RECORD ) );
		$this->assertSame( '10', ( new Template( '{/dimensions/width}' ) )->render( self::RECORD ) );
		$this->assertSame( '10', ( new Template( '{./dimensions/width}' ) )->render( self::RECORD ) );
		$this->assertSame( 'EUR', ( new Template( '{price@currency}' ) )->render( self::RECORD ) );
		$this->assertSame( 'a.jpg b.jpg', ( new Template( '{image[1]} {image[2]}' ) )->render( self::RECORD ) );
	}

	public function test_escaping(): void {
		$this->assertSame( '{title} Shirt}', ( new Template( '{{title}} {title}}}' ) )->render( self::RECORD ) );
		$this->assertSame( '{} and { open', ( new Template( '{} and { open' ) )->render( self::RECORD ) );
		$this->assertSame( '{"a": 1}', ( new Template( '{{"a": 1}}' ) )->render( self::RECORD ) );
	}

	public function test_static_template_and_references(): void {
		$this->assertTrue( ( new Template( 'publish' ) )->is_static() );
		$this->assertSame( array( 'title', 'dimensions.width' ), ( new Template( '{title}{dimensions/width}{title}' ) )->references() );
	}

	public function test_values_are_not_reinterpreted(): void {
		$this->assertSame( '{sku}', ( new Template( '{title}' ) )->render( array( 'title' => '{sku}' ) + self::RECORD ) );
	}

	public function test_resolution_cache_survives_records_with_other_keys(): void {
		$template = new Template( '{TITLE}' );
		$this->assertSame( 'Shirt', $template->render( self::RECORD ) );
		$this->assertSame( 'Other', $template->render( array( 'Title' => 'Other' ) ) );
		$this->assertSame( '', $template->render( array() ) );
	}

	public function test_mapper_renders_nested_mapping_and_key(): void {
		$mapper = new Mapper(
			array(
				'title' => '{title}',
				'menu'  => 3,
				'meta'  => array(
					'_sku'  => '{sku}',
					'width' => '{dimensions/width} cm',
				),
			),
			' {sku} '
		);
		$this->assertSame(
			array(
				'title' => 'Shirt',
				'menu'  => '3',
				'meta'  => array(
					'_sku'  => 'S-1',
					'width' => '10 cm',
				),
			),
			$mapper->render( self::RECORD )
		);
		$this->assertSame( 'S-1', $mapper->key( self::RECORD ) );
	}
}

<?php

namespace Importright\Tests\Unit;

use Importright\Reader\ReaderException;
use Importright\Reader\XmlFileReader;

final class XmlFileReaderTest extends FileCase {

	private const PRODUCTS = '<?xml version="1.0" encoding="UTF-8"?>
<catalog xmlns:g="http://base.google.com/ns/1.0">
	<meta><generated>today</generated></meta>
	<products>
		<product id="p1" type="simple">
			<title>First</title>
			<price currency="EUR">9.99</price>
			<g:brand>Acme</g:brand>
			<image>a.jpg</image>
			<image>b.jpg</image>
			<dimensions><width>10</width><height unit="cm">20</height></dimensions>
			<description><![CDATA[<p>Rich & bold</p>]]></description>
			<empty/>
		</product>
		<product id="p2">
			<title>Second</title>
			<image>c.jpg</image>
		</product>
		<product id="p3"><title>Third</title></product>
	</products>
</catalog>';

	public function test_flattens_records(): void {
		$records = $this->read_all( new XmlFileReader( $this->file( self::PRODUCTS ), array( 'record_path' => '/catalog/products/product' ) ) );
		$first   = $records[0];

		$this->assertCount( 3, $records );
		$this->assertSame( 'p1', $first['@id'] );
		$this->assertSame( 'simple', $first['@type'] );
		$this->assertSame( 'First', $first['title'] );
		$this->assertSame( '9.99', $first['price'] );
		$this->assertSame( 'EUR', $first['price@currency'] );
		$this->assertSame( 'Acme', $first['g:brand'] );
		$this->assertSame( 'a.jpg', $first['image'] );
		$this->assertSame( 'a.jpg', $first['image[1]'] );
		$this->assertSame( 'b.jpg', $first['image[2]'] );
		$this->assertSame( '10', $first['dimensions.width'] );
		$this->assertSame( 'cm', $first['dimensions.height@unit'] );
		$this->assertSame( '<p>Rich & bold</p>', $first['description'] );
		$this->assertSame( '', $first['empty'] );
		$this->assertSame( 'c.jpg', $records[1]['image'] );
		$this->assertArrayNotHasKey( 'image[1]', $records[1] );
	}

	public function test_relative_record_path_and_detection(): void {
		$path = $this->file( self::PRODUCTS );
		$this->assertCount( 3, $this->read_all( new XmlFileReader( $path, array( 'record_path' => 'product' ) ) ) );
		$this->assertCount( 3, $this->read_all( new XmlFileReader( $path, array( 'record_path' => 'products/product' ) ) ) );
		$this->assertCount( 0, $this->read_all( new XmlFileReader( $path, array( 'record_path' => '/product' ) ) ) );

		$detected = new XmlFileReader( $path );
		$this->assertSame( '/catalog/products/product', $detected->record_path() );
	}

	public function test_detects_rss_items(): void {
		$rss    = '<rss version="2.0"><channel><title>Feed</title><item><title>A</title></item><item><title>B</title></item></channel></rss>';
		$reader = new XmlFileReader( $this->file( $rss ) );
		$this->assertSame( '/rss/channel/item', $reader->record_path() );
		$this->assertSame( array( 'A', 'B' ), array_column( $this->read_all( $reader ), 'title' ) );
	}

	public function test_text_only_records_get_a_value_key(): void {
		$records = $this->read_all( new XmlFileReader( $this->file( '<tags><tag lang="en">one</tag><tag>two</tag></tags>' ) ) );
		$this->assertSame( 'one', $records[0]['value'] );
		$this->assertSame( 'en', $records[0]['@lang'] );
	}

	public function test_columns_are_sampled(): void {
		$reader = new XmlFileReader( $this->file( self::PRODUCTS ), array( 'record_path' => 'product' ) );
		$this->assertContains( 'dimensions.height@unit', $reader->columns() );
		$this->assertSame( 3, count( $this->read_all( $reader ) ) );
	}

	public function test_resumes_from_cursor(): void {
		$path                   = $this->file( self::PRODUCTS );
		list( $first, $cursor ) = $this->read_some( new XmlFileReader( $path, array( 'record_path' => 'product' ) ), 2 );
		$rest                   = $this->read_all( new XmlFileReader( $path, array( 'record_path' => 'product' ) ), $cursor );
		$this->assertSame( array( 0, 1 ), array_keys( $first ) );
		$this->assertSame( array( 2 ), array_keys( $rest ) );
		$this->assertSame( 'Third', $rest[2]['title'] );
	}

	public function test_malformed_xml_yields_records_before_the_error_then_throws(): void {
		$good   = str_repeat( '<item><a>ok</a></item>', 2000 );
		$reader = new XmlFileReader( $this->file( '<items>' . $good . '<item><a>3</b></item></items>' ), array( 'record_path' => 'item' ) );
		$seen   = array();
		try {
			foreach ( $reader->records() as $record ) {
				$seen[] = $record['a'];
			}
			$this->fail( 'No exception for malformed XML.' );
		} catch ( ReaderException $e ) {
			$this->assertStringContainsString( 'malformed', $e->getMessage() );
		}
		$this->assertNotEmpty( $seen, 'libxml parses in chunks, so records before the damage still arrive.' );
		$this->assertLessThanOrEqual( 2000, count( $seen ) );
		$this->assertSame( array( 'ok' ), array_unique( $seen ) );
	}

	public function test_a_record_over_the_byte_limit_is_an_error(): void {
		$big    = '<item><a>' . str_repeat( 'x', 50 ) . '</a>' . str_repeat( '<b>y</b>', 40 ) . '</item>';
		$reader = new XmlFileReader(
			$this->file( '<items><item><a>ok</a></item>' . $big . '<item><a>never</a></item></items>' ),
			array(
				'record_path'      => 'item',
				'max_record_bytes' => 128,
			)
		);
		$seen   = array();
		try {
			foreach ( $reader->records() as $record ) {
				$seen[] = $record['a'];
			}
			$this->fail( 'No error for an oversized record.' );
		} catch ( ReaderException $e ) {
			$this->assertStringContainsString( 'Record 2', $e->getMessage() );
		}
		$this->assertSame( array( 'ok' ), $seen );
	}

	public function test_records_up_to_the_byte_limit_match_the_full_parse(): void {
		$path  = $this->file( self::PRODUCTS );
		$small = $this->read_all(
			new XmlFileReader(
				$path,
				array(
					'record_path'      => 'product',
					'max_record_bytes' => 400,
				)
			)
		);
		$this->assertSame( $this->read_all( new XmlFileReader( $path, array( 'record_path' => 'product' ) ) ), $small );
		$this->assertSame( '<p>Rich & bold</p>', $small[0]['description'] );
	}

	public function test_external_entities_are_not_loaded(): void {
		$secret = $this->file( 'TOP SECRET' );
		$xml    = '<?xml version="1.0"?><!DOCTYPE items [<!ENTITY xxe SYSTEM "file://' . $secret . '">]><items><item><a>&xxe;</a></item><item><a>x</a></item></items>';
		$values = array();
		try {
			foreach ( ( new XmlFileReader( $this->file( $xml ), array( 'record_path' => 'item' ) ) )->records() as $record ) {
				$values[] = $record['a'];
			}
		} catch ( ReaderException $e ) {
			$values[] = 'error';
		}
		$this->assertNotContains( 'TOP SECRET', $values );
	}

	public function test_empty_file_is_rejected(): void {
		$this->expectException( ReaderException::class );
		new XmlFileReader( $this->file( '' ) );
	}

	public function test_not_xml_is_rejected(): void {
		$this->expectException( ReaderException::class );
		new XmlFileReader( $this->file( 'just text' ) );
	}

	public function test_lists_repeating_elements_shallowest_first(): void {
		$elements = XmlFileReader::elements( $this->file( self::PRODUCTS ) );

		$this->assertSame( array( '/catalog/products/product', '/catalog/products/product/title', '/catalog/products/product/image' ), array_keys( $elements ) );
		$this->assertSame( 3, $elements['/catalog/products/product'] );
		$this->assertSame( array(), XmlFileReader::elements( $this->file( 'just text' ) ) );
	}
}

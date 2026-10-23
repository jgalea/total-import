<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- tests work on temporary files.

namespace Importright\Tests\Unit;

use Importright\Reader\CsvReader;
use Importright\Reader\Formats;
use Importright\Reader\JsonReader;
use Importright\Reader\ReaderException;
use Importright\Reader\Unpacker;
use Importright\Reader\XlsxReader;
use Importright\Reader\XmlFileReader;

final class UnpackerTest extends FileCase {

	public function test_gunzip(): void {
		$packed = $this->file( (string) gzencode( "a,b\n1,2\n" ) );
		$target = $this->path();
		$this->assertSame( 'gzip', Unpacker::container( $packed ) );
		$this->assertSame( 'csv', Unpacker::unpack( $packed, $target, 0, 'feed.csv.gz' ) );
		$this->assertSame( "a,b\n1,2\n", file_get_contents( $target ) );
	}

	public function test_unzip_picks_first_data_file(): void {
		$packed = $this->zip(
			array(
				'__MACOSX/._feed.xml' => 'junk',
				'readme.md'           => 'docs',
				'folder/feed.xml'     => '<a><b>1</b></a>',
			)
		);
		$target = $this->path();
		$this->assertSame( 'zip', Unpacker::container( $packed ) );
		$this->assertSame( 'xml', Unpacker::unpack( $packed, $target, 0 ) );
		$this->assertSame( '<a><b>1</b></a>', file_get_contents( $target ) );
	}

	public function test_zip_entry_names_never_become_paths(): void {
		$packed = $this->zip( array( '../../escape.csv' => "a\n1\n" ) );
		$target = $this->path();
		Unpacker::unpack( $packed, $target, 0 );
		$this->assertFileDoesNotExist( dirname( sys_get_temp_dir(), 2 ) . '/escape.csv' );
		$this->assertSame( "a\n1\n", file_get_contents( $target ) );
	}

	public function test_zip_without_data_file_is_rejected(): void {
		$this->expectException( ReaderException::class );
		Unpacker::unpack( $this->zip( array( 'notes.md' => 'x' ) ), $this->path(), 0 );
	}

	public function test_size_caps(): void {
		$big = str_repeat( 'x', 5000 );
		foreach ( array( $this->file( (string) gzencode( $big ) ), $this->zip( array( 'a.csv' => $big ) ) ) as $packed ) {
			try {
				Unpacker::unpack( $packed, $this->path(), 1000 );
				$this->fail( 'Cap not enforced.' );
			} catch ( ReaderException $e ) {
				$this->assertStringContainsString( 'limit', $e->getMessage() );
			}
		}
	}

	public function test_plain_file_is_not_a_container(): void {
		$this->assertNull( Unpacker::container( $this->file( 'a,b' ) ) );
		$this->expectException( ReaderException::class );
		Unpacker::unpack( $this->file( 'a,b' ), $this->path(), 0 );
	}

	public function test_xlsx_is_recognised(): void {
		$this->assertSame( 'xlsx', Unpacker::container( __DIR__ . '/../fixtures/products.xlsx' ) );
	}

	public function test_format_detection(): void {
		$this->assertSame( 'xlsx', Formats::detect( __DIR__ . '/../fixtures/products.xlsx' ) );
		$this->assertSame( 'xml', Formats::detect( $this->file( "\xEF\xBB\xBF  <?xml version=\"1.0\"?><a/>" ), 'auto', 'csv' ) );
		$this->assertSame( 'json', Formats::detect( $this->file( '[{"a":1}]' ) ) );
		$this->assertSame( 'csv', Formats::detect( $this->file( '[1],x' ), 'auto', 'csv' ) );
		$this->assertSame( 'tsv', Formats::detect( $this->file( "a\tb" ), 'auto', 'tsv' ) );
		$this->assertSame( 'csv', Formats::detect( $this->file( 'a,b' ) ) );
		$this->assertSame( 'json', Formats::detect( $this->file( 'a,b' ), 'json' ) );
	}

	public function test_open_returns_matching_reader(): void {
		$this->assertInstanceOf( XlsxReader::class, Formats::open( __DIR__ . '/../fixtures/products.xlsx', array() ) );
		$this->assertInstanceOf( XmlFileReader::class, Formats::open( $this->file( '<a><b/><b/></a>' ), array() ) );
		$this->assertInstanceOf( JsonReader::class, Formats::open( $this->file( '[]' ), array() ) );
		$this->assertInstanceOf( CsvReader::class, Formats::open( $this->file( "a\n1" ), array() ) );
	}
}

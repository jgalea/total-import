<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- tests work on temporary files.

namespace Importright\Tests\Unit;

use Importright\Reader\CsvReader;
use Importright\Reader\ReaderException;

final class CsvReaderTest extends FileCase {

	public function test_reads_rows_keyed_by_header(): void {
		$reader = new CsvReader( $this->file( "sku,title\nA1,First\nA2,Second\n" ) );
		$this->assertSame( array( 'sku', 'title' ), $reader->columns() );
		$this->assertSame(
			array(
				0 => array(
					'sku'   => 'A1',
					'title' => 'First',
				),
				1 => array(
					'sku'   => 'A2',
					'title' => 'Second',
				),
			),
			$this->read_all( $reader )
		);
	}

	public function test_strips_utf8_bom_and_handles_crlf(): void {
		$reader  = new CsvReader( $this->file( "\xEF\xBB\xBFsku,title\r\nA1,Caf\xC3\xA9\r\n" ) );
		$records = $this->read_all( $reader );
		$this->assertSame( array( 'sku', 'title' ), $reader->columns() );
		$this->assertSame( 'Café', $records[0]['title'] );
	}

	public function test_detects_semicolon_tab_and_pipe(): void {
		$this->assertSame( ';', ( new CsvReader( $this->file( "a;b;c\n1;2,5;3\n4;5;6\n" ) ) )->delimiter() );
		$this->assertSame( "\t", ( new CsvReader( $this->file( "a\tb\n1\t2\n" ) ) )->delimiter() );
		$this->assertSame( '|', ( new CsvReader( $this->file( "a|b\n1|2\n" ) ) )->delimiter() );
		$this->assertSame( ',', ( new CsvReader( $this->file( "a,b\n\"1;2\",3\n" ) ) )->delimiter() );
	}

	public function test_tsv_extension_defaults_to_tab_for_single_column(): void {
		$reader = new CsvReader( $this->file( "title\nOne\n" ), array( 'extension' => 'tsv' ) );
		$this->assertSame( "\t", $reader->delimiter() );
	}

	public function test_explicit_delimiter_wins(): void {
		$reader = new CsvReader( $this->file( "a;b,c\n1;2,3\n" ), array( 'delimiter' => ';' ) );
		$this->assertSame( array( 'a', 'b,c' ), $reader->columns() );
	}

	public function test_quoted_fields_with_newlines_and_quotes(): void {
		$records = $this->read_all( new CsvReader( $this->file( "id,body\n1,\"line one\nline \"\"two\"\"\"\n2,plain\n" ) ) );
		$this->assertSame( "line one\nline \"two\"", $records[0]['body'] );
		$this->assertSame( 'plain', $records[1]['body'] );
	}

	public function test_backslashes_are_not_escapes(): void {
		$records = $this->read_all( new CsvReader( $this->file( "id,path\n1,\"C:\\dir\\\"\n" ) ) );
		$this->assertSame( 'C:\\dir\\', $records[0]['path'] );
	}

	public function test_duplicate_and_empty_header_names(): void {
		$reader = new CsvReader( $this->file( "name,,name, name ,price\n1,2,3,4,5\n" ) );
		$this->assertSame( array( 'name', 'column_2', 'name_2', 'name_3', 'price' ), $reader->columns() );
	}

	public function test_short_and_long_rows(): void {
		$records = $this->read_all( new CsvReader( $this->file( "a,b\n1\n1,2,3\n" ) ) );
		$this->assertSame(
			array(
				'a' => '1',
				'b' => '',
			),
			$records[0]
		);
		$this->assertSame( '3', $records[1]['column_3'] );
	}

	public function test_skips_blank_lines_without_counting_them(): void {
		$records = $this->read_all( new CsvReader( $this->file( "a\n\n1\n\n,\n2\n" ) ) );
		$this->assertSame( array( 0, 1 ), array_keys( $records ) );
		$this->assertSame( '2', $records[1]['a'] );
	}

	public function test_without_header_row(): void {
		$reader  = new CsvReader( $this->file( "1,2\n3,4\n" ), array( 'has_header' => false ) );
		$records = $this->read_all( $reader );
		$this->assertSame(
			array(
				'column_1' => '3',
				'column_2' => '4',
			),
			$records[1]
		);
	}

	public function test_windows_1252_is_detected_and_converted(): void {
		$reader  = new CsvReader( $this->file( "title\nCaf\xE9 cr\xE8me \x80\n" ) );
		$records = $this->read_all( $reader );
		$this->assertSame( 'Windows-1252', $reader->encoding() );
		$this->assertSame( 'Café crème €', $records[0]['title'] );
	}

	public function test_explicit_encoding(): void {
		$records = $this->read_all( new CsvReader( $this->file( "title\nCaf\xE9\n" ), array( 'encoding' => 'latin1' ) ) );
		$this->assertSame( 'Café', $records[0]['title'] );
	}

	public function test_unknown_encoding_is_rejected(): void {
		$this->expectException( ReaderException::class );
		new CsvReader( $this->file( "a\n1\n" ), array( 'encoding' => 'klingon-8' ) );
	}

	public function test_stray_invalid_bytes_in_utf8_file_are_repaired_per_field(): void {
		$records = $this->read_all( new CsvReader( $this->file( "\xEF\xBB\xBFa,b\nCaf\xC3\xA9,Caf\xE9\n" ) ) );
		$this->assertSame( 'Café', $records[0]['a'] );
		$this->assertSame( 'Café', $records[0]['b'] );
	}

	public function test_utf16le_with_bom(): void {
		$csv     = "sku\ttitle\r\nA1\tZo\u{EB} \u{1F600}\r\n";
		$reader  = new CsvReader( $this->file( "\xFF\xFE" . mb_convert_encoding( $csv, 'UTF-16LE', 'UTF-8' ) ) );
		$records = $this->read_all( $reader );
		$this->assertSame( array( 'sku', 'title' ), $reader->columns() );
		$this->assertSame( "Zo\u{EB} \u{1F600}", $records[0]['title'] );
	}

	public function test_utf16be_with_bom_and_cache_dir_reuse(): void {
		$path   = $this->file( "\xFE\xFF" . mb_convert_encoding( "a,b\n1,2\n", 'UTF-16BE', 'UTF-8' ) );
		$dir    = sys_get_temp_dir();
		$first  = $this->read_all( new CsvReader( $path, array( 'cache_dir' => $dir ) ) );
		$second = $this->read_all( new CsvReader( $path, array( 'cache_dir' => $dir ) ) );
		unlink( $dir . '/' . basename( $path ) . '.utf8' );
		$this->assertSame( '2', $first[0]['b'] );
		$this->assertSame( $first, $second );
	}

	public function test_binary_file_is_rejected(): void {
		$this->expectException( ReaderException::class );
		new CsvReader( $this->file( "\x00\x01\x02\x03\xFF\x00\x10binary\x00" ) );
	}

	public function test_missing_file_is_rejected(): void {
		$this->expectException( ReaderException::class );
		new CsvReader( sys_get_temp_dir() . '/importright-missing.csv' );
	}

	public function test_unclosed_quote_reads_to_end_without_error(): void {
		$records = $this->read_all( new CsvReader( $this->file( "a,b\n1,\"open\n2,3\n" ) ) );
		$this->assertCount( 1, $records );
		$this->assertSame( "open\n2,3\n", $records[0]['b'] );
	}

	public function test_rows_up_to_the_byte_limit_are_read(): void {
		$path    = $this->file( "id,text\n1," . str_repeat( 'a', 60 ) . "\n2,\"" . str_repeat( "b\n", 25 ) . "\"\n" );
		$records = $this->read_all( new CsvReader( $path, array( 'max_record_bytes' => 64 ) ) );
		$this->assertCount( 2, $records );
		$this->assertSame( str_repeat( "b\n", 25 ), $records[1]['text'] );
	}

	public function test_a_row_over_the_byte_limit_is_an_error(): void {
		$reader = new CsvReader( $this->file( "id,text\n1,ok\n2," . str_repeat( 'a', 200 ) . "\n3,never\n" ), array( 'max_record_bytes' => 64 ) );
		$seen   = array();
		try {
			foreach ( $reader->records() as $record ) {
				$seen[] = $record['id'];
			}
			$this->fail( 'No error for an oversized row.' );
		} catch ( ReaderException $e ) {
			$this->assertStringContainsString( 'byte 13 is larger than the 64 bytes limit', $e->getMessage() );
		}
		$this->assertSame( array( '1' ), $seen );
	}

	public function test_an_unclosed_quote_stops_at_the_byte_limit(): void {
		$rest   = str_repeat( "x,y\n", 300000 );
		$reader = new CsvReader( $this->file( "a,b\n1,\"open\n" . $rest ), array( 'max_record_bytes' => 65536 ) );
		$before = memory_get_usage();
		try {
			$this->read_all( $reader );
			$this->fail( 'No error for an unclosed quote.' );
		} catch ( ReaderException $e ) {
			$this->assertStringContainsString( 'quote', $e->getMessage() );
		}
		$this->assertLessThan( strlen( $rest ) / 2, memory_get_usage() - $before, 'The rest of the file is never held in memory.' );
	}

	public function test_empty_file_has_no_records(): void {
		$reader = new CsvReader( $this->file( '' ) );
		$this->assertSame( array(), $reader->columns() );
		$this->assertSame( array(), $this->read_all( $reader ) );
	}

	public function test_resumes_from_cursor_in_a_new_reader(): void {
		$path                   = $this->file( "id\n1\n2\n3\n4\n5\n" );
		list( $first, $cursor ) = $this->read_some( new CsvReader( $path ), 2 );
		$rest                   = $this->read_all( new CsvReader( $path ), $cursor );
		$this->assertSame( array( 0, 1 ), array_keys( $first ) );
		$this->assertSame( array( 2, 3, 4 ), array_keys( $rest ) );
		$this->assertSame( '3', $rest[2]['id'] );
	}

	public function test_progress_tracks_bytes(): void {
		$reader = new CsvReader( $this->file( "id\n1\n2\n" ) );
		$this->read_all( $reader );
		$this->assertSame( 1.0, $reader->progress() );
		$this->assertNull( $reader->total() );
	}

	public function test_detect_delimiter_ignores_truncated_last_line(): void {
		$this->assertSame( ';', CsvReader::detect_delimiter( "a;b\n1;2\n3;4\n" ) );
		$this->assertSame( ',', CsvReader::detect_delimiter( "single\nvalue\n" ) );
	}
}

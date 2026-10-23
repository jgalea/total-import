<?php

namespace Importright\Tests\Unit;

use Importright\Reader\ReaderException;
use Importright\Reader\XlsxReader;

final class XlsxReaderTest extends FileCase {

	private const FIXTURE = __DIR__ . '/../fixtures/products.xlsx';

	public function test_reads_openpyxl_workbook(): void {
		$reader  = new XlsxReader( self::FIXTURE );
		$records = $this->read_all( $reader );

		$this->assertSame( array( 'SKU', 'Title', 'Price', 'Released', 'Notes', 'Title_2' ), $reader->columns() );
		$this->assertSame( array( 0, 1 ), array_keys( $records ) );
		$this->assertSame( 'Café crème', $records[0]['Title'] );
		$this->assertSame( '0.3', $records[0]['Price'] );
		$this->assertSame( '2024-03-15', $records[0]['Released'] );
		$this->assertSame( '', $records[0]['Notes'] );
		$this->assertSame( 'dup', $records[0]['Title_2'] );
		$this->assertSame( '2024-03-15 13:30:00', $records[1]['Released'] );
		$this->assertSame( 'extra', $records[1]['column_7'] );
	}

	public function test_total_comes_from_dimension(): void {
		$this->assertSame( 3, ( new XlsxReader( self::FIXTURE ) )->total() );
	}

	public function test_sheet_by_name_and_number(): void {
		$by_name   = $this->read_all( new XlsxReader( self::FIXTURE, array( 'sheet' => 'Other' ) ) );
		$by_number = $this->read_all( new XlsxReader( self::FIXTURE, array( 'sheet' => '2' ) ) );
		$this->assertSame( 'from second sheet', $by_name[0]['name'] );
		$this->assertSame( $by_name, $by_number );
		$this->assertSame( array( 'Products', 'Other' ), XlsxReader::sheets( self::FIXTURE ) );
	}

	public function test_unknown_sheet_is_rejected(): void {
		$this->expectException( ReaderException::class );
		new XlsxReader( self::FIXTURE, array( 'sheet' => 'Nope' ) );
	}

	public function test_resumes_from_cursor(): void {
		list( $first, $cursor ) = $this->read_some( new XlsxReader( self::FIXTURE ), 1 );
		$rest                   = $this->read_all( new XlsxReader( self::FIXTURE ), $cursor );
		$this->assertSame( array( 0 ), array_keys( $first ) );
		$this->assertSame( array( 1 ), array_keys( $rest ) );
		$this->assertSame( 'A-2', $rest[1]['SKU'] );
	}

	public function test_shared_strings_in_memory_and_on_disk(): void {
		$path = $this->workbook( $this->shared_sheet() );
		foreach ( array( 8388608, 0 ) as $limit ) {
			$records = $this->read_all( new XlsxReader( $path, array( 'memory_strings_limit' => $limit ) ) );
			$this->assertSame( 'Bold and plain', $records[0]['name'], "limit $limit" );
			$this->assertSame( '', $records[0]['empty'], "limit $limit" );
			$this->assertSame( 'Zoë', $records[1]['name'], "limit $limit" );
			$this->assertSame( '0.3', $records[1]['value'], "limit $limit" );
			$this->assertSame( '1', $records[0]['value'], "limit $limit" );
		}
	}

	public function test_1904_dates_and_custom_date_format(): void {
		$sheet = '<row r="1"><c r="A1" t="inlineStr"><is><t>when</t></is></c></row><row r="2"><c r="A2" s="1"><v>0</v></c></row>';
		$path  = $this->workbook( $sheet, true, '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy;@"/></numFmts><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs>' );
		$this->assertSame( '1904-01-01', $this->read_all( new XlsxReader( $path ) )[0]['when'] );
	}

	public function test_date_format_detection(): void {
		$this->assertTrue( XlsxReader::is_date_format( 'yyyy-mm-dd' ) );
		$this->assertTrue( XlsxReader::is_date_format( '[$-409]h:mm AM/PM' ) );
		$this->assertFalse( XlsxReader::is_date_format( '"Days: "0' ) );
		$this->assertFalse( XlsxReader::is_date_format( '#,##0.00 [$€-1]' ) );
		$this->assertFalse( XlsxReader::is_date_format( 'General' ) );
	}

	public function test_numbers_and_columns(): void {
		$this->assertSame( '0.3', XlsxReader::number( '0.30000000000000004' ) );
		$this->assertSame( '12', XlsxReader::number( '12' ) );
		$this->assertSame( '1.5E+20', XlsxReader::number( '1.5E+20' ) );
		$this->assertSame( 0, XlsxReader::column_index( 'A1' ) );
		$this->assertSame( 27, XlsxReader::column_index( 'AB12' ) );
	}

	public function test_excel_1900_serials(): void {
		$reader = new XlsxReader( self::FIXTURE );
		$this->assertSame( '1900-01-01', $reader->excel_date( 1 ) );
		$this->assertSame( '1900-03-01', $reader->excel_date( 61 ) );
		$this->assertSame( '12:00:00', $reader->excel_date( 0.5 ) );
	}

	public function test_not_a_zip_is_rejected(): void {
		$this->expectException( ReaderException::class );
		new XlsxReader( $this->file( 'plain text', '.xlsx' ) );
	}

	public function test_zip_without_workbook_is_rejected(): void {
		$this->expectException( ReaderException::class );
		new XlsxReader( $this->zip( array( 'readme.txt' => 'hi' ), '.xlsx' ) );
	}

	public function test_truncated_sheet_xml_is_reported(): void {
		$path = $this->workbook( '<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>1</t>', false, '', false );
		$this->expectException( ReaderException::class );
		$this->read_all( new XlsxReader( $path ) );
	}

	public function test_last_excel_column_is_read(): void {
		$this->assertSame( 16383, XlsxReader::column_index( 'XFD1' ) );
		$records = $this->read_all( new XlsxReader( $this->workbook( '<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row><row r="2"><c r="XFD2" t="inlineStr"><is><t>far</t></is></c></row>' ), array( 'has_header' => false ) ) );
		$this->assertCount( 16384, $records[1] );
		$this->assertSame( 'far', end( $records[1] ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function columns_past_xfd(): array {
		return array(
			'one past XFD'       => array( '<row r="1"><c r="XFE1" t="inlineStr"><is><t>x</t></is></c></row>' ),
			'a huge reference'   => array( '<row r="1"><c r="ZZZZZZZZZZZZZZZZ1" t="inlineStr"><is><t>x</t></is></c></row>' ),
			'unreferenced cells' => array( '<row r="1"><c r="XFD1"><v>1</v></c><c><v>2</v></c></row>' ),
		);
	}

	/**
	 * @dataProvider columns_past_xfd
	 */
	public function test_cells_past_the_last_column_are_refused( string $rows ): void {
		$this->expectException( ReaderException::class );
		$this->expectExceptionMessage( 'XFD' );
		$this->read_all( new XlsxReader( $this->workbook( $rows ), array( 'has_header' => false ) ) );
	}

	private function shared_sheet(): string {
		return '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
			. '<row r="2"><c r="A2" t="s"><v>3</v></c><c r="C2" t="b"><v>1</v></c></row>'
			. '<row r="3"><c r="A3" t="s"><v>4</v></c><c r="C3"><v>0.30000000000000004</v></c></row>';
	}

	private function workbook( string $rows, bool $date1904 = false, string $styles = '', bool $close = true ): string {
		$main    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$rel     = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
		$type    = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
		$shared  = '<sst xmlns="' . $main . '"><si><t>name</t></si><si><t>empty</t></si><si><t>value</t></si>'
			. '<si><r><rPr><b/></rPr><t>Bold</t></r><r><t xml:space="preserve"> and plain</t></r><rPh sb="0" eb="1"><t>ignored</t></rPh></si>'
			. '<si><t>Zoë</t></si></sst>';
		$entries = array(
			'[Content_Types].xml'        => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
			'_rels/.rels'                => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $type . 'officeDocument" Target="xl/workbook.xml"/></Relationships>',
			'xl/workbook.xml'            => '<workbook xmlns="' . $main . '" xmlns:r="' . $rel . '"><workbookPr date1904="' . ( $date1904 ? '1' : '0' ) . '"/><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $type . 'worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $type . 'sharedStrings" Target="/xl/sharedStrings.xml"/><Relationship Id="rId3" Type="' . $type . 'styles" Target="styles.xml"/></Relationships>',
			'xl/sharedStrings.xml'       => $shared,
			'xl/styles.xml'              => '<styleSheet xmlns="' . $main . '">' . $styles . '</styleSheet>',
			'xl/worksheets/sheet1.xml'   => '<worksheet xmlns="' . $main . '"><sheetData>' . $rows . ( $close ? '</sheetData></worksheet>' : '' ),
		);
		return $this->zip( $entries, '.xlsx' );
	}
}

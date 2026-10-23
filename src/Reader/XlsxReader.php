<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reader messages are plain text, escaped where they're displayed.
// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming reads; WP_Filesystem has no streaming API.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XMLReader and DOM properties.

namespace Importright\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Excel .xlsx, read straight from the zip with XMLReader. Shared strings stay
 * in memory when small and go to a temporary file when large.
 */
final class XlsxReader implements Reader {

	private const NS_REL         = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const BUILTIN_DATES  = array( 14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58 );
	private const MEMORY_STRINGS = 8388608;

	/**
	 * Columns Excel allows (A to XFD). A cell reference past it is refused rather than padding a row to that width.
	 */
	public const MAX_COLUMNS = 16384;

	private string $path;
	private string $sheet_entry;
	private bool $has_header;
	private bool $date1904 = false;
	private ?int $total    = null;

	/**
	 * Style indexes whose number format is a date.
	 *
	 * @var array<int, true>
	 */
	private array $date_styles = array();

	/**
	 * @var string[]|null
	 */
	private ?array $strings = null;

	/**
	 * @var resource|null
	 */
	private $strings_file = null;

	private string $strings_index = '';

	/**
	 * @var string[]
	 */
	private array $header = array();

	private int $header_row = 0;

	/**
	 * @var array<string, int>
	 */
	private array $cursor = array(
		'row'   => 0,
		'index' => 0,
	);

	private ?\XMLReader $xml = null;

	/**
	 * @param array<string, mixed> $options sheet (name or 1-based number), has_header, memory_strings_limit.
	 */
	public function __construct( string $path, array $options = array() ) {
		if ( ! class_exists( \ZipArchive::class ) || ! class_exists( \XMLReader::class ) ) {
			throw new ReaderException( __( 'Reading Excel files needs the PHP zip and xmlreader extensions. Save the sheet as CSV instead, or ask your host to enable them.', 'total-import' ) );
		}
		if ( false !== strpos( $path, '#' ) ) {
			throw new ReaderException( __( 'The file path contains a # character, which Excel files can\'t be read from.', 'total-import' ) );
		}

		$this->path       = $path;
		$this->has_header = (bool) ( $options['has_header'] ?? true );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new ReaderException( __( 'The Excel file could not be opened. It may be damaged, or not an .xlsx file.', 'total-import' ) );
		}

		try {
			$workbook_entry = $this->workbook_entry( $zip );
			$workbook       = self::dom( $zip, $workbook_entry );
			$rels           = $this->relationships( $zip, $workbook_entry );

			foreach ( $workbook->getElementsByTagNameNS( '*', 'workbookPr' ) as $pr ) {
				$this->date1904 = in_array( strtolower( (string) $pr->getAttribute( 'date1904' ) ), array( '1', 'true' ), true );
			}

			$this->sheet_entry = $this->sheet_entry( $workbook, $rels, (string) ( $options['sheet'] ?? '' ) );

			foreach ( $rels as $rel ) {
				if ( str_ends_with( $rel['type'], '/styles' ) ) {
					$this->load_styles( $zip, $rel['target'] );
				}
			}
			foreach ( $rels as $rel ) {
				if ( str_ends_with( $rel['type'], '/sharedStrings' ) ) {
					$this->load_strings( $zip, $rel['target'], (int) ( $options['memory_strings_limit'] ?? self::MEMORY_STRINGS ) );
				}
			}
		} finally {
			$zip->close();
		}

		$this->read_header();
	}

	/**
	 * Names of the worksheets in the file, in workbook order.
	 *
	 * @return string[]
	 */
	public static function sheets( string $path ): array {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array();
		}
		$names = array();
		$entry = $zip->locateName( 'xl/workbook.xml' ) !== false ? 'xl/workbook.xml' : null;
		if ( null !== $entry ) {
			foreach ( self::dom( $zip, $entry )->getElementsByTagNameNS( '*', 'sheet' ) as $sheet ) {
				$names[] = (string) $sheet->getAttribute( 'name' );
			}
		}
		$zip->close();
		return $names;
	}

	public function records( ?array $cursor = null ): \Generator {
		$skip_to = max( $this->header_row, (int) ( $cursor['row'] ?? 0 ) );
		$index   = max( 0, (int) ( $cursor['index'] ?? 0 ) );

		$this->open_sheet();
		$this->cursor = array(
			'row'   => $skip_to,
			'index' => $index,
		);
		$position     = 0;

		while ( $this->next_row_element() ) {
			$position = $this->row_number( $position );
			if ( $position <= $skip_to ) {
				continue;
			}
			$cells = $this->read_cells();
			if ( '' === implode( '', $cells ) ) {
				continue;
			}
			$this->cursor = array(
				'row'   => $position,
				'index' => $index + 1,
			);
			yield $index => Columns::combine( $this->header, self::fill( $cells ) );
			++$index;
		}
		$this->check_errors();
	}

	public function cursor(): array {
		return $this->cursor;
	}

	public function columns(): array {
		return $this->header;
	}

	public function total(): ?int {
		return $this->total;
	}

	public function progress(): ?float {
		return null !== $this->total && $this->total > 0 ? min( 1.0, $this->cursor['index'] / $this->total ) : null;
	}

	public function close(): void {
		if ( null !== $this->xml ) {
			$this->xml->close();
			$this->xml = null;
		}
		if ( is_resource( $this->strings_file ) ) {
			fclose( $this->strings_file );
		}
	}

	private function read_header(): void {
		$this->open_sheet();
		$position = 0;
		while ( $this->next_row_element() ) {
			$position = $this->row_number( $position );
			$cells    = $this->read_cells();
			if ( '' === implode( '', $cells ) ) {
				continue;
			}
			if ( $this->has_header ) {
				$this->header     = Columns::normalize( self::fill( $cells ) );
				$this->header_row = $position;
			}
			break;
		}
		$this->check_errors();
		if ( null !== $this->total && $this->has_header && $this->header_row > 0 ) {
			$this->total = max( 0, $this->total - $this->header_row );
		}
	}

	private function open_sheet(): void {
		if ( null !== $this->xml ) {
			$this->xml->close();
		}
		libxml_use_internal_errors( true );
		libxml_clear_errors();
		$xml = new \XMLReader();
		if ( ! $xml->open( 'zip://' . $this->path . '#' . $this->sheet_entry, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
			throw new ReaderException( __( 'The worksheet could not be read.', 'total-import' ) );
		}
		$this->xml = $xml;
	}

	/**
	 * Moves to the next row element, returning false at the end of the sheet data.
	 */
	private function next_row_element(): bool {
		$xml = $this->xml;
		if ( \XMLReader::ELEMENT === $xml->nodeType && 'row' === $xml->localName ) {
			return $xml->next( 'row' ) && \XMLReader::ELEMENT === $xml->nodeType && 'row' === $xml->localName;
		}
		while ( $xml->read() ) {
			if ( \XMLReader::ELEMENT !== $xml->nodeType ) {
				continue;
			}
			if ( 'dimension' === $xml->localName && null === $this->total ) {
				$this->total = self::dimension_rows( (string) $xml->getAttribute( 'ref' ) );
			}
			if ( 'row' === $xml->localName ) {
				return true;
			}
		}
		return false;
	}

	private function row_number( int $previous ): int {
		$r = (int) $this->xml->getAttribute( 'r' );
		return $r > $previous ? $r : $previous + 1;
	}

	/**
	 * Cell values of the current row, keyed by zero-based column.
	 *
	 * @return array<int, string>
	 */
	private function read_cells(): array {
		$node = self::expand( $this->xml );
		if ( ! $node instanceof \DOMElement ) {
			$this->check_errors();
			return array();
		}
		$cells = array();
		$next  = 0;
		foreach ( $node->childNodes as $cell ) {
			if ( ! $cell instanceof \DOMElement || 'c' !== $cell->localName ) {
				continue;
			}
			$ref    = (string) $cell->getAttribute( 'r' );
			$column = '' !== $ref ? self::column_index( $ref ) : $next;
			$next   = $column + 1;
			if ( $column >= self::MAX_COLUMNS ) {
				throw self::past_last_column( '' !== $ref ? $ref : (string) ( $column + 1 ) );
			}

			$cells[ $column ] = $this->cell_value( $cell );
		}
		return $cells;
	}

	private function cell_value( \DOMElement $cell ): string {
		$type = (string) $cell->getAttribute( 't' );

		if ( 'inlineStr' === $type ) {
			foreach ( $cell->childNodes as $child ) {
				if ( $child instanceof \DOMElement && 'is' === $child->localName ) {
					return self::rich_text( $child );
				}
			}
			return '';
		}

		$value = null;
		foreach ( $cell->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'v' === $child->localName ) {
				$value = $child->textContent;
			}
		}
		if ( null === $value ) {
			return '';
		}

		switch ( $type ) {
			case 's':
				return $this->shared_string( (int) $value );
			case 'str':
			case 'b':
			case 'e':
			case 'd':
				return $value;
		}

		if ( ! is_numeric( $value ) ) {
			return $value;
		}
		$style = $cell->getAttribute( 's' );
		if ( '' !== $style && isset( $this->date_styles[ (int) $style ] ) ) {
			return $this->excel_date( (float) $value );
		}
		return self::number( $value );
	}

	/**
	 * Excel stores 0.1 + 0.2 as 0.30000000000000004; show what Excel shows.
	 */
	public static function number( string $value ): string {
		if ( false === strpbrk( $value, '.eE' ) ) {
			return $value;
		}
		$formatted = sprintf( '%.15G', (float) $value );
		if ( false !== strpos( $formatted, 'E' ) ) {
			return $formatted;
		}
		return false !== strpos( $formatted, '.' ) ? rtrim( rtrim( $formatted, '0' ), '.' ) : $formatted;
	}

	public function excel_date( float $serial ): string {
		$days    = (int) floor( $serial );
		$seconds = (int) round( ( $serial - $days ) * 86400 );
		if ( $seconds >= 86400 ) {
			++$days;
			$seconds -= 86400;
		}

		if ( $this->date1904 ) {
			$base = gmmktime( 0, 0, 0, 1, 1, 1904 );
		} else {
			$base = $days < 60 ? gmmktime( 0, 0, 0, 12, 31, 1899 ) : gmmktime( 0, 0, 0, 12, 30, 1899 );
		}
		$stamp = $base + $days * 86400 + $seconds;

		if ( 0 === $days && ! $this->date1904 ) {
			return gmdate( 'H:i:s', $seconds );
		}
		return 0 === $seconds ? gmdate( 'Y-m-d', $stamp ) : gmdate( 'Y-m-d H:i:s', $stamp );
	}

	/**
	 * Zero-based column of a cell reference such as "AB12".
	 *
	 * @throws ReaderException When the column is past XFD.
	 */
	public static function column_index( string $ref ): int {
		$index  = 0;
		$length = strlen( $ref );
		for ( $i = 0; $i < $length; $i++ ) {
			$code = ord( strtoupper( $ref[ $i ] ) );
			if ( $code < 65 || $code > 90 ) {
				break;
			}
			$index = $index * 26 + ( $code - 64 );
			if ( $index > self::MAX_COLUMNS ) {
				throw self::past_last_column( $ref );
			}
		}
		return max( 0, $index - 1 );
	}

	private static function past_last_column( string $ref ): ReaderException {
		/* translators: %s: cell reference or column number */
		return new ReaderException( sprintf( __( 'The worksheet has a cell at %s, past column XFD, the last one Excel allows. The file is damaged or wasn\'t made by a spreadsheet program.', 'total-import' ), substr( $ref, 0, 20 ) ) );
	}

	/**
	 * Whether a number format code formats a date or time.
	 */
	public static function is_date_format( string $code ): bool {
		$code = (string) preg_replace( array( '/"[^"]*"/', '/\\\\./', '/\[[^\]]*\]/', '/_./', '/\*./' ), '', $code );
		if ( '' === $code || 'general' === strtolower( $code ) ) {
			return false;
		}
		$sections = explode( ';', $code );
		return 1 === preg_match( '/[dmyhs]/i', $sections[0] );
	}

	/**
	 * @param array<int, string> $cells
	 * @return array<int, string>
	 */
	private static function fill( array $cells ): array {
		if ( array() === $cells ) {
			return array();
		}
		$filled = array_fill( 0, max( array_keys( $cells ) ) + 1, '' );
		foreach ( $cells as $column => $value ) {
			$filled[ $column ] = $value;
		}
		return $filled;
	}

	private static function dimension_rows( string $ref ): ?int {
		if ( preg_match( '/^[A-Z]+(\d+):[A-Z]+(\d+)$/i', $ref, $m ) ) {
			return (int) $m[2] - (int) $m[1] + 1;
		}
		return null;
	}

	private static function rich_text( \DOMElement $element ): string {
		$text = '';
		foreach ( $element->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			if ( 't' === $child->localName ) {
				$text .= $child->textContent;
			} elseif ( 'r' === $child->localName ) {
				$text .= self::rich_text( $child );
			}
		}
		return $text;
	}

	private function shared_string( int $index ): string {
		if ( null !== $this->strings ) {
			return $this->strings[ $index ] ?? '';
		}
		if ( ! is_resource( $this->strings_file ) || $index < 0 || ( $index + 2 ) * 4 > strlen( $this->strings_index ) ) {
			return '';
		}
		$bounds = unpack( 'V2', $this->strings_index, $index * 4 );
		$length = (int) $bounds[2] - (int) $bounds[1];
		if ( $length <= 0 ) {
			return '';
		}
		fseek( $this->strings_file, (int) $bounds[1] );
		return (string) fread( $this->strings_file, $length );
	}

	private function load_strings( \ZipArchive $zip, string $entry, int $memory_limit ): void {
		$stat      = $zip->statName( $entry );
		$in_memory = false === $stat || (int) $stat['size'] <= $memory_limit;

		libxml_use_internal_errors( true );
		$xml = new \XMLReader();
		if ( ! $xml->open( 'zip://' . $this->path . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
			return;
		}

		if ( $in_memory ) {
			$this->strings = array();
		} else {
			$file = tmpfile();
			if ( false === $file ) {
				throw new ReaderException( __( 'A temporary file for the spreadsheet\'s text could not be created.', 'total-import' ) );
			}
			$this->strings_file  = $file;
			$this->strings_index = pack( 'V', 0 );
		}
		$offset = 0;

		while ( $xml->read() && ! ( \XMLReader::ELEMENT === $xml->nodeType && 'si' === $xml->localName ) ) {
			continue;
		}
		while ( \XMLReader::ELEMENT === $xml->nodeType && 'si' === $xml->localName ) {
			$node = self::expand( $xml );
			$text = $node instanceof \DOMElement ? self::rich_text( $node ) : '';
			if ( $in_memory ) {
				$this->strings[] = $text;
			} else {
				fwrite( $this->strings_file, $text );
				$offset              += strlen( $text );
				$this->strings_index .= pack( 'V', $offset );
			}
			if ( ! $xml->next( 'si' ) ) {
				break;
			}
		}
		$xml->close();
	}

	private function load_styles( \ZipArchive $zip, string $entry ): void {
		if ( false === $zip->locateName( $entry ) ) {
			return;
		}
		$dom = self::dom( $zip, $entry );

		$custom = array();
		foreach ( $dom->getElementsByTagNameNS( '*', 'numFmt' ) as $format ) {
			$custom[ (int) $format->getAttribute( 'numFmtId' ) ] = (string) $format->getAttribute( 'formatCode' );
		}

		foreach ( $dom->getElementsByTagNameNS( '*', 'cellXfs' ) as $xfs ) {
			$index = 0;
			foreach ( $xfs->childNodes as $xf ) {
				if ( ! $xf instanceof \DOMElement || 'xf' !== $xf->localName ) {
					continue;
				}
				$id = (int) $xf->getAttribute( 'numFmtId' );
				if ( isset( $custom[ $id ] ) ? self::is_date_format( $custom[ $id ] ) : in_array( $id, self::BUILTIN_DATES, true ) ) {
					$this->date_styles[ $index ] = true;
				}
				++$index;
			}
		}
	}

	private function workbook_entry( \ZipArchive $zip ): string {
		$root = $zip->getFromName( '_rels/.rels' );
		if ( false !== $root ) {
			foreach ( self::dom( $zip, '_rels/.rels' )->getElementsByTagNameNS( '*', 'Relationship' ) as $rel ) {
				if ( str_ends_with( (string) $rel->getAttribute( 'Type' ), '/officeDocument' ) ) {
					return ltrim( (string) $rel->getAttribute( 'Target' ), '/' );
				}
			}
		}
		if ( false !== $zip->locateName( 'xl/workbook.xml' ) ) {
			return 'xl/workbook.xml';
		}
		throw new ReaderException( __( 'This zip file isn\'t an Excel workbook.', 'total-import' ) );
	}

	/**
	 * @return array<string, array{type: string, target: string}>
	 */
	private function relationships( \ZipArchive $zip, string $workbook_entry ): array {
		$dir   = dirname( $workbook_entry );
		$dir   = '.' === $dir ? '' : $dir . '/';
		$entry = $dir . '_rels/' . basename( $workbook_entry ) . '.rels';
		$rels  = array();
		if ( false === $zip->getFromName( $entry ) ) {
			return $rels;
		}
		foreach ( self::dom( $zip, $entry )->getElementsByTagNameNS( '*', 'Relationship' ) as $rel ) {
			$target = (string) $rel->getAttribute( 'Target' );
			$target = 0 === strpos( $target, '/' ) ? ltrim( $target, '/' ) : $dir . $target;

			$rels[ (string) $rel->getAttribute( 'Id' ) ] = array(
				'type'   => (string) $rel->getAttribute( 'Type' ),
				'target' => self::normalize_entry( $target ),
			);
		}
		return $rels;
	}

	/**
	 * @param array<string, array{type: string, target: string}> $rels
	 */
	private function sheet_entry( \DOMDocument $workbook, array $rels, string $wanted ): string {
		$sheets = array();
		foreach ( $workbook->getElementsByTagNameNS( '*', 'sheet' ) as $sheet ) {
			$id = '';
			foreach ( $sheet->attributes as $attribute ) {
				if ( 'id' === $attribute->localName && '' !== (string) $attribute->namespaceURI ) {
					$id = (string) $attribute->value;
				}
			}
			if ( isset( $rels[ $id ] ) ) {
				$sheets[] = array(
					'name'  => (string) $sheet->getAttribute( 'name' ),
					'entry' => $rels[ $id ]['target'],
				);
			}
		}
		if ( array() === $sheets ) {
			throw new ReaderException( __( 'The workbook has no worksheets.', 'total-import' ) );
		}
		if ( '' === $wanted ) {
			return $sheets[0]['entry'];
		}
		foreach ( $sheets as $sheet ) {
			if ( $sheet['name'] === $wanted ) {
				return $sheet['entry'];
			}
		}
		if ( ctype_digit( $wanted ) && isset( $sheets[ (int) $wanted - 1 ] ) ) {
			return $sheets[ (int) $wanted - 1 ]['entry'];
		}
		/* translators: %s: worksheet name */
		throw new ReaderException( sprintf( __( 'The workbook has no sheet named "%s".', 'total-import' ), $wanted ) );
	}

	private static function normalize_entry( string $entry ): string {
		$parts = array();
		foreach ( explode( '/', $entry ) as $part ) {
			if ( '..' === $part ) {
				array_pop( $parts );
			} elseif ( '' !== $part && '.' !== $part ) {
				$parts[] = $part;
			}
		}
		return implode( '/', $parts );
	}

	private static function dom( \ZipArchive $zip, string $entry ): \DOMDocument {
		$xml = $zip->getFromName( $entry );
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		if ( false === $xml || ! $dom->loadXML( $xml, LIBXML_NONET ) ) {
			libxml_clear_errors();
			/* translators: %s: name of a file inside the Excel workbook */
			throw new ReaderException( sprintf( __( 'The Excel file is damaged: %s could not be read.', 'total-import' ), $entry ) );
		}
		return $dom;
	}

	private function check_errors(): void {
		$error = libxml_get_last_error();
		libxml_clear_errors();
		if ( false !== $error && $error->level >= LIBXML_ERR_ERROR ) {
			/* translators: 1: parser message, 2: line number */
			throw new ReaderException( sprintf( __( 'The worksheet is malformed: %1$s (line %2$d)', 'total-import' ), trim( $error->message ), $error->line ) );
		}
	}

	/**
	 * XMLReader::expand() warns on malformed XML; the libxml error is reported by check_errors() instead.
	 */
	private static function expand( \XMLReader $xml ): ?\DOMNode {
		$node = @$xml->expand(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $node ? null : $node;
	}
}

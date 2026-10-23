<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reader messages are plain text, escaped where they're displayed.
// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming reads; WP_Filesystem has no streaming API.

namespace Importright\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * CSV and TSV, read row by row with fgetcsv. The cursor is a byte offset, so resuming costs one seek.
 *
 * Rows are parsed from an in-memory window of the file that always holds at
 * least max_record_bytes past the current row, so a row that would run past
 * the cap (often an unclosed quote swallowing the rest of the file) is
 * reported instead of being read into memory whole.
 */
final class CsvReader implements Reader {

	public const DELIMITERS = array( ',', ';', "\t", '|' );

	private const SAMPLE_BYTES = 65536;
	private const CHUNK_BYTES  = 1048576;

	/**
	 * @var resource
	 */
	private $handle;

	private int $size;
	private string $delimiter;
	private string $enclosure;
	private string $encoding;
	private int $data_offset = 0;
	private int $max_row_bytes;

	/**
	 * Logical read position in the file; the handle itself runs ahead to fill the window.
	 */
	private int $position = 0;

	/**
	 * @var resource|null
	 */
	private $window = null;

	private int $window_start  = 0;
	private int $window_length = 0;

	/**
	 * @var string[]
	 */
	private array $header = array();

	/**
	 * @var array<string, int>
	 */
	private array $cursor = array(
		'offset' => 0,
		'index'  => 0,
	);

	/**
	 * @param array<string, mixed> $options delimiter, enclosure, encoding, has_header, cache_dir, extension, max_record_bytes.
	 */
	public function __construct( string $path, array $options = array() ) {
		$this->max_row_bytes = max( 1, (int) ( $options['max_record_bytes'] ?? self::DEFAULT_MAX_RECORD_BYTES ) );
		$handle              = is_file( $path ) && is_readable( $path ) ? fopen( $path, 'rb' ) : false;
		if ( false === $handle ) {
			throw new ReaderException( __( 'The file could not be opened.', 'total-import' ) );
		}
		$this->handle = $handle;

		$sample = (string) fread( $this->handle, self::SAMPLE_BYTES );
		$start  = 0;

		$utf16 = self::utf16_variant( $sample );
		if ( null !== $utf16 ) {
			$this->handle = $this->transcode_utf16( $utf16, $path, $options['cache_dir'] ?? null );
			fseek( $this->handle, 0 );
			$sample = (string) fread( $this->handle, self::SAMPLE_BYTES );
		} elseif ( false !== strpos( $sample, "\0" ) ) {
			throw new ReaderException( __( 'This doesn\'t look like a text file. Check the format setting.', 'total-import' ) );
		}

		if ( 0 === strncmp( $sample, "\xEF\xBB\xBF", 3 ) ) {
			$start  = 3;
			$sample = substr( $sample, 3 );
		}

		$this->size      = (int) fstat( $this->handle )['size'];
		$this->enclosure = '' !== (string) ( $options['enclosure'] ?? '' ) ? substr( (string) $options['enclosure'], 0, 1 ) : '"';
		$this->encoding  = $this->pick_encoding( (string) ( $options['encoding'] ?? '' ), $sample, null !== $utf16 || 3 === $start );
		$this->delimiter = $this->pick_delimiter( (string) ( $options['delimiter'] ?? '' ), $sample, (string) ( $options['extension'] ?? '' ) );

		$this->move_to( $start );
		$this->data_offset = $start;
		if ( (bool) ( $options['has_header'] ?? true ) ) {
			$row = $this->next_row();
			if ( null !== $row ) {
				$this->header = Columns::normalize( $row );
			}
			$this->data_offset = $this->position;
		}
		$this->cursor['offset'] = $this->data_offset;
	}

	public function delimiter(): string {
		return $this->delimiter;
	}

	public function encoding(): string {
		return $this->encoding;
	}

	public function records( ?array $cursor = null ): \Generator {
		$offset = isset( $cursor['offset'] ) ? max( $this->data_offset, (int) $cursor['offset'] ) : $this->data_offset;
		$index  = isset( $cursor['index'] ) ? max( 0, (int) $cursor['index'] ) : 0;

		if ( ! $this->move_to( $offset ) ) {
			throw new ReaderException( __( 'The saved position is past the end of the file. The file may have changed since the run started.', 'total-import' ) );
		}
		$this->cursor = array(
			'offset' => $offset,
			'index'  => $index,
		);

		while ( true ) {
			$row = $this->next_row();
			if ( null === $row ) {
				return;
			}
			$this->cursor = array(
				'offset' => $this->position,
				'index'  => $index + 1,
			);
			yield $index => Columns::combine( $this->header, $row );
			++$index;
		}
	}

	public function cursor(): array {
		return $this->cursor;
	}

	public function columns(): array {
		return $this->header;
	}

	public function total(): ?int {
		return null;
	}

	public function progress(): ?float {
		return $this->size > 0 ? min( 1.0, $this->cursor['offset'] / $this->size ) : null;
	}

	public function close(): void {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
		if ( is_resource( $this->window ) ) {
			fclose( $this->window );
		}
		$this->window = null;
	}

	/**
	 * Next non-blank row, converted to UTF-8, or null at the end of the file.
	 *
	 * @return string[]|null
	 */
	private function next_row(): ?array {
		while ( true ) {
			$row = $this->read_row();
			if ( null === $row ) {
				return null;
			}
			if ( array( null ) === $row ) {
				continue;
			}
			$values = array();
			foreach ( $row as $value ) {
				$values[] = Text::to_utf8( (string) $value, $this->encoding );
			}
			if ( '' === implode( '', $values ) ) {
				continue;
			}
			return $values;
		}
	}

	private function move_to( int $offset ): bool {
		if ( 0 !== fseek( $this->handle, $offset ) ) {
			return false;
		}
		if ( is_resource( $this->window ) ) {
			fclose( $this->window );
		}
		$this->window        = null;
		$this->window_start  = $offset;
		$this->window_length = 0;
		$this->position      = $offset;
		return true;
	}

	/**
	 * The next raw row, or null at the end of the file.
	 *
	 * @return array<int, string|null>|null
	 * @throws ReaderException When the row is larger than max_record_bytes.
	 */
	private function read_row(): ?array {
		$ahead = $this->window_start + $this->window_length - $this->position;
		if ( null === $this->window || ( $ahead <= $this->max_row_bytes && ! feof( $this->handle ) ) ) {
			$this->refill();
		}
		fseek( $this->window, $this->position - $this->window_start );
		$row = fgetcsv( $this->window, 0, $this->delimiter, $this->enclosure, '' );
		if ( false === $row ) {
			return null;
		}
		$length = $this->window_start + (int) ftell( $this->window ) - $this->position;
		if ( $length > $this->max_row_bytes ) {
			/* translators: 1: byte offset in the file, 2: size limit, e.g. "1 MB" */
			throw new ReaderException( sprintf( __( 'The row starting at byte %1$d is larger than the %2$s limit for one row. Check for a quote that is never closed, or raise the limit with the importright_max_record_bytes filter.', 'total-import' ), $this->position, Text::size( $this->max_row_bytes ) ) );
		}
		$this->position += $length;
		return $row;
	}

	/**
	 * Drops what's been read from the window and tops it up from the file.
	 */
	private function refill(): void {
		$keep = '';
		if ( null !== $this->window ) {
			$keep = (string) stream_get_contents( $this->window, -1, $this->position - $this->window_start );
			fclose( $this->window );
		}
		$want   = $this->max_row_bytes + self::CHUNK_BYTES - strlen( $keep );
		$more   = $want > 0 ? (string) fread( $this->handle, $want ) : '';
		$window = fopen( 'php://temp/maxmemory:' . ( 2 * self::CHUNK_BYTES + 2 * $this->max_row_bytes ), 'w+b' );
		if ( false === $window ) {
			throw new ReaderException( __( 'The file could not be read.', 'total-import' ) );
		}
		fwrite( $window, $keep . $more );
		$this->window        = $window;
		$this->window_start  = $this->position;
		$this->window_length = strlen( $keep ) + strlen( $more );
	}

	private function pick_encoding( string $requested, string $sample, bool $marked_utf8 ): string {
		if ( '' !== $requested && 'auto' !== $requested ) {
			$name = Text::encoding_name( $requested );
			if ( null === $name ) {
				/* translators: %s: character encoding name */
				throw new ReaderException( sprintf( __( 'Unknown character encoding: %s', 'total-import' ), $requested ) );
			}
			return $name;
		}
		return $marked_utf8 || Text::sample_is_utf8( $sample ) ? 'UTF-8' : 'Windows-1252';
	}

	private function pick_delimiter( string $requested, string $sample, string $extension ): string {
		if ( '' !== $requested && 'auto' !== $requested ) {
			return '\t' === $requested || 'tab' === strtolower( $requested ) ? "\t" : substr( $requested, 0, 1 );
		}
		$fallback = 'tsv' === strtolower( $extension ) ? "\t" : ',';
		return self::detect_delimiter( $sample, $fallback );
	}

	/**
	 * Picks the delimiter that splits the sample's lines into the same number of fields, preferring more fields.
	 */
	public static function detect_delimiter( string $sample, string $fallback = ',' ): string {
		$lines = preg_split( '/\r\n|\n|\r/', $sample );
		$lines = is_array( $lines ) ? $lines : array();
		if ( count( $lines ) > 1 && strlen( $sample ) >= self::SAMPLE_BYTES ) {
			array_pop( $lines );
		}
		$lines = array_slice(
			array_values(
				array_filter(
					$lines,
					static function ( string $line ): bool {
						return '' !== trim( $line );
					}
				)
			),
			0,
			20
		);
		if ( array() === $lines ) {
			return $fallback;
		}

		$best       = $fallback;
		$best_score = array( 0, 0, 0 );
		foreach ( self::DELIMITERS as $delimiter ) {
			$counts = array();
			foreach ( $lines as $line ) {
				$counts[] = count( str_getcsv( $line, $delimiter, '"', '' ) );
			}
			$frequency = array_count_values( $counts );
			arsort( $frequency );
			$mode = (int) array_key_first( $frequency );
			if ( $mode < 2 ) {
				continue;
			}
			$score = array( 1 === count( $frequency ) ? 1 : 0, $frequency[ $mode ], $mode );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $delimiter;
			}
		}
		return $best;
	}

	/**
	 * The UTF-16 byte order of a sample, from its BOM or from the pattern of zero bytes.
	 */
	private static function utf16_variant( string $sample ): ?string {
		if ( 0 === strncmp( $sample, "\xFF\xFE", 2 ) ) {
			return 'UTF-16LE';
		}
		if ( 0 === strncmp( $sample, "\xFE\xFF", 2 ) ) {
			return 'UTF-16BE';
		}
		$length = min( strlen( $sample ), 512 ) & ~1;
		if ( $length < 4 ) {
			return null;
		}
		$even = 0;
		$odd  = 0;
		for ( $i = 0; $i < $length; $i += 2 ) {
			$even += "\0" === $sample[ $i ] ? 1 : 0;
			$odd  += "\0" === $sample[ $i + 1 ] ? 1 : 0;
		}
		$pairs = $length / 2;
		if ( $odd > $pairs * 0.7 && $even < $pairs * 0.1 ) {
			return 'UTF-16LE';
		}
		if ( $even > $pairs * 0.7 && $odd < $pairs * 0.1 ) {
			return 'UTF-16BE';
		}
		return null;
	}

	/**
	 * Rewrites a UTF-16 file as UTF-8, into the cache folder when there is one so later batches reuse it.
	 *
	 * @return resource
	 */
	private function transcode_utf16( string $variant, string $path, ?string $cache_dir ) {
		$target = null;
		if ( null !== $cache_dir && is_dir( $cache_dir ) && is_writable( $cache_dir ) ) {
			$target = rtrim( $cache_dir, '/\\' ) . '/' . basename( $path ) . '.utf8';
			if ( is_file( $target ) && filemtime( $target ) >= filemtime( $path ) ) {
				fclose( $this->handle );
				$reuse = fopen( $target, 'rb' );
				if ( false !== $reuse ) {
					return $reuse;
				}
			}
		}

		$out = null === $target ? tmpfile() : fopen( $target, 'w+b' );
		if ( false === $out ) {
			throw new ReaderException( __( 'A working copy of the file could not be written.', 'total-import' ) );
		}

		fseek( $this->handle, 0 );
		$carry = '';
		$first = true;
		while ( ! feof( $this->handle ) ) {
			$chunk = $carry . (string) fread( $this->handle, self::CHUNK_BYTES );
			$carry = '';
			if ( $first ) {
				$chunk = preg_replace( '/^(\xFF\xFE|\xFE\xFF)/', '', $chunk );
				$first = false;
			}
			if ( strlen( $chunk ) % 2 ) {
				$carry = substr( $chunk, -1 );
				$chunk = substr( $chunk, 0, -1 );
			}
			if ( strlen( $chunk ) >= 2 ) {
				$last = 'UTF-16LE' === $variant ? ord( $chunk[ strlen( $chunk ) - 1 ] ) : ord( $chunk[ strlen( $chunk ) - 2 ] );
				if ( $last >= 0xD8 && $last <= 0xDB ) {
					$carry = substr( $chunk, -2 ) . $carry;
					$chunk = substr( $chunk, 0, -2 );
				}
			}
			fwrite( $out, Text::to_utf8( $chunk, $variant ) );
		}
		fclose( $this->handle );
		fflush( $out );
		return $out;
	}
}

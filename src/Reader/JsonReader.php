<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reader messages are plain text, escaped where they're displayed.
// phpcs:disable WordPress.WP.AlternativeFunctions -- reads a local file; WP_Filesystem adds nothing here.

namespace Importright\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * JSON, decoded in one go, so files are capped at max_bytes (16 MB by
 * default; decoded data takes several times the file size in memory).
 *
 * The records are the array at json_path ("data.items"), or the root when
 * it's an array, or the first array of objects found near the root.
 * Nested keys flatten to "dimensions.width"; lists give "tags", "tags[1]", "tags[2]".
 */
final class JsonReader implements Reader {

	public const DEFAULT_MAX_BYTES = 16777216;

	/**
	 * @var array<int, mixed>
	 */
	private array $items;

	private string $records_path = '';

	/**
	 * @var array<string, int>
	 */
	private array $cursor = array( 'index' => 0 );

	/**
	 * @param array<string, mixed> $options json_path, max_bytes.
	 */
	public function __construct( string $path, array $options = array() ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new ReaderException( __( 'The file could not be opened.', 'total-import' ) );
		}
		$max  = (int) ( $options['max_bytes'] ?? self::DEFAULT_MAX_BYTES );
		$size = (int) filesize( $path );
		if ( $max > 0 && $size > $max ) {
			/* translators: 1: file size in MB, 2: limit in MB */
			throw new ReaderException( sprintf( __( 'This JSON file is %1$s MB, over the %2$s MB limit for JSON. Convert it to CSV or XML, which are read in a stream.', 'total-import' ), number_format( $size / 1048576, 1 ), number_format( $max / 1048576, 1 ) ) );
		}

		$json = (string) file_get_contents( $path );
		if ( 0 === strncmp( $json, "\xEF\xBB\xBF", 3 ) ) {
			$json = substr( $json, 3 );
		}
		$data = json_decode( $json, true, 512, JSON_BIGINT_AS_STRING );
		unset( $json );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			/* translators: %s: JSON parser message */
			throw new ReaderException( sprintf( __( 'The JSON is malformed: %s', 'total-import' ), json_last_error_msg() ) );
		}

		$this->items = $this->locate( $data, trim( (string) ( $options['json_path'] ?? '' ) ) );
	}

	public function records_path(): string {
		return $this->records_path;
	}

	public function records( ?array $cursor = null ): \Generator {
		$index        = max( 0, (int) ( $cursor['index'] ?? 0 ) );
		$count        = count( $this->items );
		$this->cursor = array( 'index' => $index );
		for ( ; $index < $count; $index++ ) {
			$item         = $this->items[ $index ];
			$this->cursor = array( 'index' => $index + 1 );
			yield $index => is_array( $item ) ? self::flatten( $item ) : array( 'value' => self::scalar( $item ) );
		}
	}

	public function cursor(): array {
		return $this->cursor;
	}

	public function columns(): array {
		$keys = array();
		foreach ( array_slice( $this->items, 0, 50 ) as $item ) {
			$keys += array_fill_keys( array_keys( is_array( $item ) ? self::flatten( $item ) : array( 'value' => '' ) ), true );
		}
		return array_map( 'strval', array_keys( $keys ) );
	}

	public function total(): ?int {
		return count( $this->items );
	}

	public function progress(): ?float {
		$total = count( $this->items );
		return $total > 0 ? min( 1.0, $this->cursor['index'] / $total ) : null;
	}

	public function close(): void {
		$this->items = array();
	}

	/**
	 * @param array<mixed> $data
	 * @return array<string, string>
	 */
	public static function flatten( array $data, string $prefix = '' ): array {
		$record = array();
		foreach ( $data as $key => $value ) {
			$name = $prefix . $key;
			if ( ! is_array( $value ) ) {
				$record[ $name ] = self::scalar( $value );
				continue;
			}
			if ( array() === $value ) {
				$record[ $name ] = '';
				continue;
			}
			if ( self::is_list( $value ) ) {
				foreach ( array_values( $value ) as $i => $item ) {
					$keys = 0 === $i ? array( $name, $name . '[1]' ) : array( $name . '[' . ( $i + 1 ) . ']' );
					foreach ( $keys as $item_key ) {
						if ( is_array( $item ) ) {
							$record += self::flatten( $item, $item_key . '.' );
						} else {
							$record[ $item_key ] = self::scalar( $item );
						}
					}
				}
				continue;
			}
			$record += self::flatten( $value, $name . '.' );
		}
		return $record;
	}

	/**
	 * @param mixed $value
	 */
	private static function scalar( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param array<mixed> $value
	 */
	private static function is_list( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * @param mixed $data
	 * @return array<int, mixed>
	 */
	private function locate( $data, string $path ): array {
		if ( ! is_array( $data ) ) {
			throw new ReaderException( __( 'The JSON holds a single value, not records.', 'total-import' ) );
		}

		$path = (string) preg_replace( '/^\$\.?|^\//', '', $path );
		if ( '' !== $path ) {
			foreach ( preg_split( '/[.\/]/', $path ) as $segment ) {
				if ( ! is_array( $data ) || ! array_key_exists( $segment, $data ) ) {
					/* translators: %s: path such as data.items */
					throw new ReaderException( sprintf( __( 'The JSON has nothing at "%s".', 'total-import' ), $path ) );
				}
				$data = $data[ $segment ];
			}
			if ( ! is_array( $data ) ) {
				/* translators: %s: path such as data.items */
				throw new ReaderException( sprintf( __( 'The value at "%s" isn\'t a list of records.', 'total-import' ), $path ) );
			}
			$this->records_path = $path;
			return self::is_list( $data ) ? $data : array( $data );
		}

		if ( array() === $data || self::is_list( $data ) ) {
			return array_values( $data );
		}

		$queue = array( array( '', $data, 0 ) );
		while ( array() !== $queue ) {
			list( $prefix, $node, $depth ) = array_shift( $queue );
			foreach ( $node as $key => $value ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				$here = '' === $prefix ? (string) $key : $prefix . '.' . $key;
				if ( array() !== $value && self::is_list( $value ) && is_array( $value[0] ) ) {
					$this->records_path = $here;
					return $value;
				}
				if ( $depth < 3 && ! self::is_list( $value ) ) {
					$queue[] = array( $here, $value, $depth + 1 );
				}
			}
		}

		return array( $data );
	}
}

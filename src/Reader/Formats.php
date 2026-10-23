<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- reads a few bytes to sniff the format.

namespace Importright\Reader;

defined( 'ABSPATH' ) || exit;

final class Formats {

	public const TYPES = array( 'csv', 'tsv', 'xlsx', 'xml', 'json' );

	/**
	 * Decides the format from the setting, then the file's content and name.
	 *
	 * @param string $extension Extension of the original file name, if any.
	 */
	public static function detect( string $path, string $requested = 'auto', string $extension = '' ): string {
		if ( in_array( $requested, self::TYPES, true ) ) {
			return $requested;
		}
		if ( 'xlsx' === Unpacker::container( $path ) ) {
			return 'xlsx';
		}

		$handle = fopen( $path, 'rb' );
		$head   = false === $handle ? '' : (string) fread( $handle, 1024 );
		if ( false !== $handle ) {
			fclose( $handle );
		}
		$head  = ltrim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', $head ) );
		$first = substr( $head, 0, 1 );

		if ( '<' === $first ) {
			return 'xml';
		}
		$extension = strtolower( $extension );
		if ( in_array( $extension, array( 'csv', 'tsv', 'xml', 'json' ), true ) ) {
			return $extension;
		}
		if ( '{' === $first || '[' === $first ) {
			return 'json';
		}
		return 'tsv' === $extension ? 'tsv' : 'csv';
	}

	/**
	 * Opens a reader for a plain (already unpacked) file.
	 *
	 * @param array<string, mixed> $options The import's format settings plus cache_dir and max_json_bytes.
	 */
	public static function open( string $path, array $options, string $extension = '' ): Reader {
		$type = self::detect( $path, (string) ( $options['type'] ?? 'auto' ), $extension );
		switch ( $type ) {
			case 'xlsx':
				return new XlsxReader( $path, $options );
			case 'xml':
				return new XmlFileReader( $path, $options );
			case 'json':
				$options['max_bytes'] = $options['max_json_bytes'] ?? JsonReader::DEFAULT_MAX_BYTES;
				return new JsonReader( $path, $options );
			case 'tsv':
				$options['extension'] = 'tsv';
				return new CsvReader( $path, $options );
			default:
				$options['extension'] = $extension;
				return new CsvReader( $path, $options );
		}
	}
}

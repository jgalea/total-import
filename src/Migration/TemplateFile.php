<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are plain text, escaped where they're displayed.
// phpcs:disable WordPress.WP.AlternativeFunctions -- reads a local export file and streams one zip entry.

namespace Importright\Migration;

use Importright\Reader\Unpacker;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WP All Import template exports: the .txt file from Settings > Templates
 * (json_encode of pmxi_templates rows) or a WP All Export bundle zip holding
 * such a file and the data file. The template is read into memory, never
 * written to disk; zip entry names are never used as paths.
 */
final class TemplateFile {

	public const DEFAULT_MAX_BYTES = 8388608;

	/**
	 * @return array<int, array{name: string, options: array<string, mixed>, import_type: string, import_url: string, source_file_name: string}>
	 * @throws InvalidTemplate When the file isn't a readable template export.
	 */
	public static function read( string $path, int $max_bytes = self::DEFAULT_MAX_BYTES ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new InvalidTemplate( __( 'The template file can\'t be read.', 'total-import' ) );
		}
		if ( self::is_zip( $path ) ) {
			$entry = self::bundle_entries( $path );
			if ( null === $entry['template'] ) {
				throw new InvalidTemplate( __( 'The zip has no WP All Import template in it. Bundles name it "WP All Import Template…" or "templates_….txt".', 'total-import' ) );
			}
			$json = self::entry_contents( $path, $entry['template'], $max_bytes );
		} else {
			if ( filesize( $path ) > $max_bytes ) {
				throw new InvalidTemplate( self::too_big( $max_bytes ) );
			}
			$json = (string) file_get_contents( $path );
		}
		return self::parse( $json );
	}

	public static function is_zip( string $path ): bool {
		$handle = is_file( $path ) ? fopen( $path, 'rb' ) : false;
		if ( false === $handle ) {
			return false;
		}
		$magic = (string) fread( $handle, 4 );
		fclose( $handle );
		return "PK\x03\x04" === $magic || "PK\x05\x06" === $magic;
	}

	/**
	 * @return array<int, array{name: string, options: array<string, mixed>, import_type: string, import_url: string, source_file_name: string}>
	 * @throws InvalidTemplate When the JSON doesn't hold template rows.
	 */
	public static function parse( string $json ): array {
		$json = (string) preg_replace( '/^\xEF\xBB\xBF/', '', trim( $json ) );
		$data = json_decode( $json, true, 64 );
		if ( ! is_array( $data ) ) {
			throw new InvalidTemplate( __( 'The template file isn\'t a WP All Import template export.', 'total-import' ) );
		}

		$rows = array();
		foreach ( self::row_candidates( $data ) as $row ) {
			$options = self::options( $row['options'] );
			if ( isset( $options['is_user_export'] ) ) {
				throw new InvalidTemplate( __( 'This is a WP All Export template, not an import template.', 'total-import' ) );
			}
			$rows[] = array(
				'name'             => is_scalar( $row['name'] ?? null ) ? (string) $row['name'] : '',
				'options'          => $options,
				'import_type'      => is_scalar( $row['_import_type'] ?? null ) ? (string) $row['_import_type'] : '',
				'import_url'       => is_scalar( $row['_import_url'] ?? null ) ? (string) $row['_import_url'] : '',
				'source_file_name' => is_scalar( $row['source_file_name'] ?? null ) ? (string) $row['source_file_name'] : '',
			);
		}
		if ( array() === $rows ) {
			throw new InvalidTemplate( __( 'The template file has no templates in it.', 'total-import' ) );
		}
		return $rows;
	}

	/**
	 * Decodes a template's options: a PHP-serialized array, or an array already. Objects are refused.
	 *
	 * @param mixed $raw
	 * @return array<string, mixed>
	 * @throws InvalidTemplate When the options aren't a plain array.
	 */
	public static function options( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( ! preg_match( '/^a:\d+:\{/', $raw ) ) {
				throw new InvalidTemplate( __( 'The template\'s options aren\'t a serialized list of settings.', 'total-import' ) );
			}
			set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- unserialize warns on damaged input; the result is checked below.
				static function (): bool {
					return true;
				}
			);
			try {
				$raw = unserialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WP All Import stores options serialized; classes are disallowed and objects refused.
					$raw,
					array(
						'allowed_classes' => false,
						'max_depth'       => 32,
					)
				);
			} finally {
				restore_error_handler();
			}
		}
		if ( ! is_array( $raw ) ) {
			throw new InvalidTemplate( __( 'The template\'s options are damaged.', 'total-import' ) );
		}
		if ( self::has_object( $raw ) ) {
			throw new InvalidTemplate( __( 'The template\'s options contain PHP objects, which are never loaded. The file may have been tampered with.', 'total-import' ) );
		}
		return $raw;
	}

	/**
	 * Indexes of the template entry and the first data file in a bundle zip.
	 *
	 * @return array{template: int|null, data: int|null, data_name: string}
	 * @throws InvalidTemplate When the zip can't be opened.
	 */
	public static function bundle_entries( string $path ): array {
		$zip = self::open_zip( $path );

		$found = array(
			'template'  => null,
			'data'      => null,
			'data_name' => '',
		);
		$count = $zip->count();
		for ( $i = 0; $i < $count; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			$base = basename( str_replace( '\\', '/', $name ) );
			if ( '/' === substr( $name, -1 ) || 0 === strpos( $name, '__MACOSX/' ) || '' === $base || '.' === $base[0] ) {
				continue;
			}
			if ( null === $found['template'] && ( 0 === strpos( $base, 'WP All Import Template' ) || 0 === strpos( $base, 'templates_' ) ) && preg_match( '/\.(txt|json)$/i', $base ) ) {
				$found['template'] = $i;
				continue;
			}
			if ( null === $found['data'] && 'readme.txt' !== strtolower( $base ) && '' !== Unpacker::extension( $base ) ) {
				$found['data']      = $i;
				$found['data_name'] = $base;
			}
		}
		$zip->close();
		return $found;
	}

	/**
	 * Streams the bundle's data file to $target. Returns its extension.
	 *
	 * @throws InvalidTemplate When the bundle has no data file or it's too big.
	 */
	public static function extract_data( string $path, string $target, int $max_bytes ): string {
		$entry = self::bundle_entries( $path );
		if ( null === $entry['data'] ) {
			throw new InvalidTemplate( __( 'The bundle has no data file in it.', 'total-import' ) );
		}
		$zip  = self::open_zip( $path );
		$stat = $zip->statIndex( $entry['data'] );
		if ( false === $stat || ( $max_bytes > 0 && (int) $stat['size'] > $max_bytes ) ) {
			$zip->close();
			throw new InvalidTemplate( self::too_big( $max_bytes ) );
		}
		$in  = $zip->getStream( (string) $stat['name'] );
		$out = fopen( $target, 'wb' );
		if ( false === $in || false === $out ) {
			$zip->close();
			throw new InvalidTemplate( __( 'The data file inside the bundle could not be extracted.', 'total-import' ) );
		}
		$written = 0;
		try {
			while ( ! feof( $in ) ) {
				$chunk    = (string) fread( $in, 1048576 );
				$written += strlen( $chunk );
				if ( $max_bytes > 0 && $written > $max_bytes ) {
					throw new InvalidTemplate( self::too_big( $max_bytes ) );
				}
				fwrite( $out, $chunk );
			}
		} finally {
			fclose( $in );
			fclose( $out );
			$zip->close();
		}
		return Unpacker::extension( $entry['data_name'] );
	}

	private static function entry_contents( string $path, int $index, int $max_bytes ): string {
		$zip  = self::open_zip( $path );
		$stat = $zip->statIndex( $index );
		if ( false === $stat || (int) $stat['size'] > $max_bytes ) {
			$zip->close();
			throw new InvalidTemplate( self::too_big( $max_bytes ) );
		}
		$stream = $zip->getStream( (string) $stat['name'] );
		if ( false === $stream ) {
			$zip->close();
			throw new InvalidTemplate( __( 'The template inside the zip could not be read.', 'total-import' ) );
		}
		$contents = (string) stream_get_contents( $stream, $max_bytes + 1 );
		fclose( $stream );
		$zip->close();
		if ( strlen( $contents ) > $max_bytes ) {
			throw new InvalidTemplate( self::too_big( $max_bytes ) );
		}
		return $contents;
	}

	private static function open_zip( string $path ): \ZipArchive {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new InvalidTemplate( __( 'Opening zip files needs the PHP zip extension.', 'total-import' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new InvalidTemplate( __( 'The zip file could not be opened.', 'total-import' ) );
		}
		return $zip;
	}

	/**
	 * Template rows from the shapes WP All Import writes: a list of rows, one row, or rows grouped by post type.
	 *
	 * @param array<mixed> $data
	 * @return array<int, array<string, mixed>>
	 */
	private static function row_candidates( array $data ): array {
		if ( array_key_exists( 'options', $data ) ) {
			return array( $data );
		}
		$rows = array();
		foreach ( $data as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( array_key_exists( 'options', $item ) ) {
				$rows[] = $item;
				continue;
			}
			foreach ( $item as $nested ) {
				if ( is_array( $nested ) && array_key_exists( 'options', $nested ) ) {
					$rows[] = $nested;
				}
			}
		}
		return $rows;
	}

	/**
	 * @param array<mixed> $value
	 */
	private static function has_object( array $value ): bool {
		foreach ( $value as $item ) {
			if ( is_object( $item ) || ( is_array( $item ) && self::has_object( $item ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function too_big( int $max_bytes ): string {
		/* translators: %s: size limit in MB */
		return sprintf( __( 'The file is larger than the %s MB limit.', 'total-import' ), number_format( $max_bytes / 1048576, 1 ) );
	}
}

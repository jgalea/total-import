<?php

namespace Importright\Admin;

use Importright\Reader\Formats;
use Importright\Reader\JsonReader;
use Importright\Reader\Reader;
use Importright\Reader\ReaderException;
use Importright\Reader\Unpacker;
use Importright\Reader\XlsxReader;
use Importright\Reader\XmlFileReader;
use Importright\Source\PathGuard;
use Importright\Source\Sources;
use Importright\Source\Storage;
use Importright\Source\UrlFetcher;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the first records of a source for the wizard's preview, along with
 * what the format pickers need: columns, sheets, repeating XML elements.
 *
 * A URL source is downloaded once per user and kept as a run-0 work file for
 * a while, so changing a format option doesn't fetch it again.
 */
final class Inspector {

	public const ROWS = 5;

	private const CACHE_TTL = 900;

	/**
	 * @param array<string, mixed> $source
	 * @param array<string, mixed> $format
	 * @return array<string, mixed>|WP_Error
	 */
	public static function inspect( array $source, array $format, bool $refresh = false ) {
		$source = Sources::normalize( $source );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( 'path' === $source['type'] && ! self::can_use_paths() ) {
			return new WP_Error( 'importright_source', __( 'Only network administrators can import files from a server path.', 'total-import' ) );
		}

		$file = self::local_file( $source, $refresh );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$plain     = $file['path'];
		$temporary = null;
		$extension = Unpacker::extension( $file['name'] );
		$container = Unpacker::container( $plain );
		if ( 'gzip' === $container || 'zip' === $container ) {
			$temporary = Storage::work_path( 0, 'data' );
			if ( is_wp_error( $temporary ) ) {
				return $temporary;
			}
			try {
				$extension = Unpacker::unpack( $plain, $temporary, (int) apply_filters( 'importright_max_unpacked_bytes', Sources::DEFAULT_MAX_UNPACKED_BYTES ), $file['name'] );
			} catch ( ReaderException $e ) {
				self::delete( $temporary );
				return new WP_Error( 'importright_unpack', $e->getMessage() );
			}
			$plain = $temporary;
		} elseif ( 'xlsx' === $container ) {
			$extension = 'xlsx';
		}

		$options                     = $format;
		$options['max_json_bytes']   = (int) apply_filters( 'importright_max_json_bytes', JsonReader::DEFAULT_MAX_BYTES );
		$options['max_record_bytes'] = (int) apply_filters( 'importright_max_record_bytes', Reader::DEFAULT_MAX_RECORD_BYTES );
		$options['cache_dir']        = Storage::is_work_file( $plain ) || 'upload' === $source['type'] ? dirname( $plain ) : null;
		$detected                    = Formats::detect( $plain, (string) ( $format['type'] ?? 'auto' ), $extension );

		$result = array(
			'name'     => $file['name'],
			'bytes'    => $file['bytes'],
			'type'     => $file['type'],
			'packed'   => null !== $temporary,
			'format'   => $detected,
			'columns'  => array(),
			'records'  => array(),
			'total'    => null,
			'sheets'   => 'xlsx' === $detected ? XlsxReader::sheets( $plain ) : array(),
			'elements' => 'xml' === $detected ? XmlFileReader::elements( $plain ) : array(),
			'detected' => array(),
		);

		try {
			$reader = Formats::open( $plain, $options, $extension );
			foreach ( $reader->records() as $record ) {
				$result['records'][] = $record;
				if ( count( $result['records'] ) >= self::ROWS ) {
					break;
				}
			}
			$result['columns'] = array_values( array_map( 'strval', $reader->columns() ) );
			$result['total']   = $reader->total();
			if ( $reader instanceof \Importright\Reader\CsvReader ) {
				$result['detected'] = array(
					'delimiter' => $reader->delimiter(),
					'encoding'  => $reader->encoding(),
				);
			} elseif ( $reader instanceof XmlFileReader ) {
				$result['detected'] = array( 'record_path' => $reader->record_path() );
			} elseif ( $reader instanceof JsonReader ) {
				$result['detected'] = array( 'json_path' => $reader->records_path() );
			}
			$reader->close();
		} catch ( ReaderException $e ) {
			$result['error'] = $e->getMessage();
		}

		if ( null !== $temporary ) {
			self::delete( $temporary );
		}
		return $result;
	}

	/**
	 * Server paths read any readable file under ABSPATH or uploads, so on a network only super admins get them.
	 */
	public static function can_use_paths(): bool {
		return ! is_multisite() || is_super_admin();
	}

	/**
	 * @param array<string, string> $source Normalized source.
	 * @return array{path: string, name: string, bytes: int, type: string}|WP_Error
	 */
	private static function local_file( array $source, bool $refresh ) {
		switch ( $source['type'] ) {
			case 'upload':
				$path = (string) Storage::upload_path( $source['file'] );
				return array(
					'path'  => $path,
					'name'  => $source['original_name'],
					'bytes' => (int) filesize( $path ),
					'type'  => '',
				);
			case 'path':
				$path = PathGuard::resolve( $source['path'] );
				if ( is_wp_error( $path ) ) {
					return $path;
				}
				return array(
					'path'  => $path,
					'name'  => basename( $path ),
					'bytes' => (int) filesize( $path ),
					'type'  => '',
				);
		}
		return self::download( $source, $refresh );
	}

	/**
	 * @param array<string, string> $source
	 * @return array{path: string, name: string, bytes: int, type: string}|WP_Error
	 */
	private static function download( array $source, bool $refresh ) {
		$transient = 'importright_inspect_' . get_current_user_id();
		$key       = 'url' === $source['type'] ? md5( $source['url'] . "\n" . $source['username'] . "\n" . $source['password'] ) : md5( (string) wp_json_encode( $source ) );
		$cached    = get_transient( $transient );
		if ( is_array( $cached ) && Storage::is_work_file( (string) ( $cached['path'] ?? '' ) ) && is_file( (string) $cached['path'] ) ) {
			if ( ! $refresh && ( $cached['key'] ?? '' ) === $key ) {
				return array(
					'path'  => (string) $cached['path'],
					'name'  => (string) $cached['name'],
					'bytes' => (int) $cached['bytes'],
					'type'  => (string) $cached['type'],
				);
			}
			self::delete( (string) $cached['path'] );
		}

		$path = Storage::work_path( 0, 'download' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( 'url' === $source['type'] ) {
			$result = UrlFetcher::download( $source, $path );
		} else {
			$result = apply_filters( 'importright_fetch_source', null, $source, $path, 0 );
			if ( is_array( $result ) && is_file( $path ) ) {
				$result = array(
					'name'  => (string) ( $result['name'] ?? '' ),
					'bytes' => (int) filesize( $path ),
					'type'  => '',
				);
			} elseif ( ! is_wp_error( $result ) ) {
				$result = new WP_Error( 'importright_source', __( 'The import has no valid source.', 'total-import' ) );
			}
		}
		if ( is_wp_error( $result ) ) {
			self::delete( $path );
			return $result;
		}
		$file = array(
			'path'  => $path,
			'name'  => '' !== $result['name'] ? $result['name'] : 'download',
			'bytes' => $result['bytes'],
			'type'  => $result['type'],
		);
		set_transient( $transient, $file + array( 'key' => $key ), self::CACHE_TTL );
		return $file;
	}

	private static function delete( string $path ): void {
		if ( ! Storage::is_work_file( $path ) ) {
			return;
		}
		foreach ( array( $path, $path . '.utf8' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file_from_directory( $file, dirname( $file ) );
			}
		}
	}
}

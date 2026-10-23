<?php

namespace Importright\Source;

use Importright\Reader\ReaderException;
use Importright\Reader\Unpacker;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Source settings and how a run gets a local, unpacked file from them.
 *
 * upload: {"type": "upload", "file": "upload-….csv", "original_name": "products.csv"}
 * url:    {"type": "url", "url": "https://…", "username": "", "password": ""}
 * path:   {"type": "path", "path": "wp-content/uploads/feed.xml"}
 *
 * Other types come from the importright_source_types, importright_normalize_source
 * and importright_fetch_source filters.
 */
final class Sources {

	public const DEFAULT_MAX_UNPACKED_BYTES = 1073741824;

	/**
	 * Source types by label. Other plugins add their own through importright_source_types.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		$types = apply_filters(
			'importright_source_types',
			array(
				'upload' => __( 'Upload', 'total-import' ),
				'url'    => __( 'URL', 'total-import' ),
				'path'   => __( 'Server path', 'total-import' ),
			)
		);
		return is_array( $types ) ? array_map( 'strval', $types ) : array();
	}

	/**
	 * A source with its secrets blanked, for the browser and for exported files.
	 *
	 * @param array<string, mixed> $source
	 * @return array<string, mixed>
	 */
	public static function redact( array $source ): array {
		$source = (array) apply_filters( 'importright_mask_source', $source );
		if ( isset( $source['password'] ) ) {
			$source['password'] = '';
		}
		return $source;
	}

	/**
	 * @param array<string, mixed>      $source
	 * @param array<string, mixed>|null $stored The saved source of the import being edited, passed to listeners only when its type matches.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function normalize( array $source, ?array $stored = null ) {
		$type = (string) ( $source['type'] ?? '' );
		switch ( $type ) {
			case 'upload':
				$file = (string) ( $source['file'] ?? '' );
				$path = Storage::upload_path( $file );
				if ( null === $path || ! is_file( $path ) ) {
					return new WP_Error( 'importright_source', __( 'The uploaded file is missing. Upload it again.', 'total-import' ) );
				}
				return array(
					'type'          => 'upload',
					'file'          => $file,
					'original_name' => sanitize_file_name( (string) ( $source['original_name'] ?? $file ) ),
				);
			case 'url':
				$url = esc_url_raw( UrlFetcher::normalize( (string) ( $source['url'] ?? '' ) ), array( 'http', 'https' ) );
				if ( '' === $url ) {
					return new WP_Error( 'importright_source', __( 'Enter an http or https URL.', 'total-import' ) );
				}
				return array(
					'type'     => 'url',
					'url'      => $url,
					'username' => (string) ( $source['username'] ?? '' ),
					'password' => (string) ( $source['password'] ?? '' ),
				);
			case 'path':
				$real = PathGuard::resolve( (string) ( $source['path'] ?? '' ) );
				if ( is_wp_error( $real ) ) {
					return $real;
				}
				return array(
					'type' => 'path',
					'path' => (string) $source['path'],
				);
		}
		// A listener reusing a stored secret must also check the endpoint is unchanged, as Credentials::can_reuse() does for URLs.
		$normalized = apply_filters( 'importright_normalize_source', null, $source, ( $stored['type'] ?? null ) === $type ? $stored : null );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		if ( is_array( $normalized ) ) {
			$normalized['type'] = $type;
			return $normalized;
		}
		return new WP_Error( 'importright_source', __( 'Choose an upload, a URL or a server path as the source.', 'total-import' ) );
	}

	/**
	 * Fetches and unpacks the source for a run.
	 *
	 * @param array<string, string> $source
	 * @return array{path: string, ext: string}|WP_Error
	 */
	public static function prepare( array $source, int $run_id ) {
		$name     = '';
		$download = null;

		switch ( $source['type'] ?? '' ) {
			case 'upload':
				$path = Storage::upload_path( (string) $source['file'] );
				if ( null === $path || ! is_file( $path ) ) {
					return new WP_Error( 'importright_source', __( 'The uploaded file is missing. Upload it again.', 'total-import' ) );
				}
				$name = (string) ( $source['original_name'] ?? $source['file'] );
				break;
			case 'path':
				$path = PathGuard::resolve( (string) $source['path'] );
				if ( is_wp_error( $path ) ) {
					return $path;
				}
				$name = basename( $path );
				break;
			case 'url':
				$path = Storage::work_path( $run_id, 'download' );
				if ( is_wp_error( $path ) ) {
					return $path;
				}
				$result = UrlFetcher::download( $source, $path );
				if ( is_wp_error( $result ) ) {
					Storage::clean_run( $run_id );
					return $result;
				}
				$download = $path;
				$name     = $result['name'];
				break;
			default:
				$path = Storage::work_path( $run_id, 'download' );
				if ( is_wp_error( $path ) ) {
					return $path;
				}
				$fetched = apply_filters( 'importright_fetch_source', null, $source, $path, $run_id );
				if ( is_wp_error( $fetched ) ) {
					Storage::clean_run( $run_id );
					return $fetched;
				}
				if ( ! is_array( $fetched ) || ! is_file( $path ) ) {
					Storage::clean_run( $run_id );
					return new WP_Error( 'importright_source', __( 'The import has no valid source.', 'total-import' ) );
				}
				$download = $path;
				$name     = (string) ( $fetched['name'] ?? '' );
				break;
		}

		$extension = Unpacker::extension( $name );
		$container = Unpacker::container( $path );
		if ( 'gzip' !== $container && 'zip' !== $container ) {
			return array(
				'path' => $path,
				'ext'  => 'xlsx' === $container ? 'xlsx' : $extension,
			);
		}

		$target = Storage::work_path( $run_id, 'data' );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		try {
			$extension = Unpacker::unpack( $path, $target, (int) apply_filters( 'importright_max_unpacked_bytes', self::DEFAULT_MAX_UNPACKED_BYTES ), $name );
		} catch ( ReaderException $e ) {
			Storage::clean_run( $run_id );
			return new WP_Error( 'importright_unpack', $e->getMessage() );
		}
		if ( null !== $download ) {
			wp_delete_file( $download );
		}
		return array(
			'path' => $target,
			'ext'  => $extension,
		);
	}
}

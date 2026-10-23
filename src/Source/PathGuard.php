<?php

namespace Importright\Source;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Server-path sources must resolve, after symlinks, to a data file inside
 * the WordPress folder or the uploads folder.
 */
final class PathGuard {

	public const EXTENSIONS = array( 'csv', 'tsv', 'txt', 'xml', 'json', 'xlsx', 'gz', 'zip' );

	/**
	 * @param string[] $roots Allowed folders; defaults to ABSPATH and uploads.
	 * @return string|WP_Error The real path.
	 */
	public static function resolve( string $path, ?array $roots = null ) {
		$roots = $roots ?? self::roots();
		$path  = trim( $path );
		if ( '' === $path || false !== strpos( $path, "\0" ) || preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path ) ) {
			return new WP_Error( 'importright_path', __( 'Enter a path to a file on this server.', 'total-import' ) );
		}
		if ( ! path_is_absolute( $path ) ) {
			$path = trailingslashit( ABSPATH ) . ltrim( $path, '/\\' );
		}

		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) ) {
			return new WP_Error( 'importright_path', __( 'No file exists at that path.', 'total-import' ) );
		}

		$inside = false;
		foreach ( $roots as $root ) {
			$root_real = realpath( $root );
			if ( false !== $root_real && 0 === strpos( $real, trailingslashit( $root_real ) ) ) {
				$inside = true;
				break;
			}
		}
		if ( ! $inside ) {
			return new WP_Error( 'importright_path', __( 'The file must be inside the WordPress folder or the uploads folder.', 'total-import' ) );
		}

		if ( ! in_array( strtolower( (string) pathinfo( $real, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true ) ) {
			return new WP_Error( 'importright_path', __( 'Only CSV, TSV, TXT, XLSX, XML, JSON, GZ and ZIP files can be imported.', 'total-import' ) );
		}
		if ( ! is_readable( $real ) ) {
			return new WP_Error( 'importright_path', __( 'The file exists but can\'t be read. Check its permissions.', 'total-import' ) );
		}
		return $real;
	}

	/**
	 * @return string[]
	 */
	public static function roots(): array {
		$uploads = wp_upload_dir( null, false );
		$roots   = array( ABSPATH, (string) $uploads['basedir'] );
		return array_values( array_filter( (array) apply_filters( 'importright_server_path_roots', $roots ), 'is_string' ) );
	}
}

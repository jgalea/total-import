<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- files in the plugin's own private folder; WP_Filesystem has no streaming API.

namespace Importright\Source;

use Importright\Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Uploaded source files and per-run working files live in a randomly named
 * folder under uploads, closed to the web by .htaccess and web.config.
 */
final class Storage {

	public const DIR_OPTION = 'importright_storage_dir';

	private const DIR_PATTERN    = '/^importright-[a-f0-9]{20}$/';
	private const UPLOAD_PATTERN = '/^upload-[a-f0-9]{16}\.(csv|tsv|txt|xml|json|xlsx|gz|zip)$/';
	private const RUN_PATTERN    = '/^run-(\d+)-[a-f0-9]{8}\.[a-z0-9]{1,10}(\.utf8)?$/';

	private const HTACCESS   = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
	private const WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<authorization>\n\t\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n\t\t\t</authorization>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n";

	/**
	 * @return string|WP_Error Absolute path without a trailing slash.
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'importright_storage', (string) $uploads['error'] );
		}

		$name = (string) Store::get( self::DIR_OPTION, '' );
		if ( ! preg_match( self::DIR_PATTERN, $name ) ) {
			$name = 'importright-' . bin2hex( random_bytes( 10 ) );
			Store::set( self::DIR_OPTION, $name );
		}

		$dir = untrailingslashit( (string) $uploads['basedir'] ) . '/' . $name;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'importright_storage', __( 'The import folder could not be created. Check that the uploads folder is writable.', 'total-import' ) );
		}

		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
			'.htaccess'  => self::HTACCESS,
			'web.config' => self::WEB_CONFIG,
		);
		foreach ( $files as $file => $contents ) {
			if ( ! file_exists( $dir . '/' . $file ) && false === file_put_contents( $dir . '/' . $file, $contents ) ) {
				return new WP_Error( 'importright_storage', __( 'The import folder could not be protected. Check that the uploads folder is writable.', 'total-import' ) );
			}
		}

		return $dir;
	}

	/**
	 * Copies (or moves) a file into storage as an import's uploaded source.
	 *
	 * @return string|WP_Error The stored file name.
	 */
	public static function store_upload( string $from, string $original_name, bool $move = false ) {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$extension = self::upload_extension( $original_name );
		if ( null === $extension ) {
			return new WP_Error( 'importright_file_type', __( 'Upload a CSV, TSV, TXT, XLSX, XML, JSON, GZ or ZIP file.', 'total-import' ) );
		}
		$name   = 'upload-' . bin2hex( random_bytes( 8 ) ) . '.' . $extension;
		$target = $dir . '/' . $name;
		$done   = $move ? rename( $from, $target ) : copy( $from, $target );
		if ( ! $done ) {
			return new WP_Error( 'importright_storage', __( 'The file could not be saved to the import folder.', 'total-import' ) );
		}
		return $name;
	}

	public static function upload_extension( string $name ): ?string {
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		return in_array( $extension, array( 'csv', 'tsv', 'txt', 'xml', 'json', 'xlsx', 'gz', 'zip' ), true ) ? $extension : null;
	}

	/**
	 * Absolute path of a stored upload, or null when the name isn't one the plugin creates.
	 */
	public static function upload_path( string $name ): ?string {
		if ( ! preg_match( self::UPLOAD_PATTERN, $name ) ) {
			return null;
		}
		$dir = self::dir();
		return is_wp_error( $dir ) ? null : $dir . '/' . $name;
	}

	/**
	 * A fresh path for a run's working file.
	 *
	 * @return string|WP_Error
	 */
	public static function work_path( int $run_id, string $extension ) {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$extension = preg_match( '/^[a-z0-9]{1,10}$/', $extension ) ? $extension : 'data';
		return $dir . '/run-' . $run_id . '-' . bin2hex( random_bytes( 4 ) ) . '.' . $extension;
	}

	public static function is_work_file( string $path ): bool {
		$dir = self::dir();
		return ! is_wp_error( $dir ) && dirname( $path ) === $dir && 1 === preg_match( self::RUN_PATTERN, basename( $path ) );
	}

	/**
	 * Deletes a run's working files, including caches the readers wrote next to them.
	 */
	public static function clean_run( int $run_id ): void {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( preg_match( self::RUN_PATTERN, (string) $name, $m ) && (int) $m[1] === $run_id ) {
				wp_delete_file_from_directory( $dir . '/' . $name, $dir );
			}
		}
	}

	/**
	 * Deletes admin preview files (run 0) older than an hour, and uploads older
	 * than a day that no import uses: a wizard closed before saving leaves them.
	 *
	 * @param string[] $keep Upload names still in use.
	 * @return int Files deleted.
	 */
	public static function prune( array $keep ): int {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return 0;
		}
		$keep    = array_flip( $keep );
		$deleted = 0;
		foreach ( (array) scandir( $dir ) as $name ) {
			$name = (string) $name;
			$path = $dir . '/' . $name;
			$age  = is_file( $path ) ? time() - (int) filemtime( $path ) : 0;
			$base = preg_replace( '/\.utf8$/', '', $name );
			if ( preg_match( self::RUN_PATTERN, $name, $m ) && 0 === (int) $m[1] && $age > HOUR_IN_SECONDS ) {
				wp_delete_file_from_directory( $path, $dir );
				++$deleted;
			} elseif ( preg_match( self::UPLOAD_PATTERN, (string) $base ) && ! isset( $keep[ $base ] ) && $age > DAY_IN_SECONDS ) {
				wp_delete_file_from_directory( $path, $dir );
				++$deleted;
			}
		}
		return $deleted;
	}

	public static function delete_upload( string $name ): void {
		$path = self::upload_path( $name );
		if ( null !== $path && is_file( $path ) ) {
			wp_delete_file_from_directory( $path, dirname( $path ) );
			if ( is_file( $path . '.utf8' ) ) {
				wp_delete_file_from_directory( $path . '.utf8', dirname( $path ) );
			}
		}
	}
}

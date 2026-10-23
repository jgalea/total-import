<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reader messages are plain text, escaped where they're displayed.
// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming copies; WP_Filesystem has no streaming API.

namespace Importright\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a gzip file, or a zip holding a data file, into a plain working file.
 * Entry names are never used as paths, and output is capped against zip bombs.
 */
final class Unpacker {

	public const DATA_EXTENSIONS = array( 'csv', 'tsv', 'txt', 'xml', 'json', 'xlsx' );

	/**
	 * The kind of container: gzip, zip or xlsx (a zip that is a workbook), or null for anything else.
	 */
	public static function container( string $path ): ?string {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return null;
		}
		$magic = (string) fread( $handle, 4 );
		fclose( $handle );

		if ( 0 === strncmp( $magic, "\x1F\x8B", 2 ) ) {
			return 'gzip';
		}
		if ( "PK\x03\x04" !== $magic && "PK\x05\x06" !== $magic ) {
			return null;
		}
		if ( ! class_exists( \ZipArchive::class ) ) {
			return 'zip';
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return 'zip';
		}
		$xlsx = false !== $zip->locateName( '[Content_Types].xml' ) && false !== $zip->locateName( 'xl/workbook.xml' );
		$zip->close();
		return $xlsx ? 'xlsx' : 'zip';
	}

	/**
	 * Unpacks into $target. Returns the extension of the unpacked file when the name says it, else ''.
	 *
	 * @param string $original_name Name of the packed file, used to guess the format of a .gz.
	 */
	public static function unpack( string $path, string $target, int $max_bytes, string $original_name = '' ): string {
		$kind = self::container( $path );
		if ( 'gzip' === $kind ) {
			self::gunzip( $path, $target, $max_bytes );
			return self::extension( (string) preg_replace( '/\.gz$/i', '', $original_name ) );
		}
		if ( 'zip' === $kind ) {
			return self::unzip( $path, $target, $max_bytes );
		}
		throw new ReaderException( __( 'This isn\'t a gzip or zip file.', 'total-import' ) );
	}

	public static function extension( string $name ): string {
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		return in_array( $extension, self::DATA_EXTENSIONS, true ) ? $extension : '';
	}

	private static function gunzip( string $path, string $target, int $max_bytes ): void {
		$in = gzopen( $path, 'rb' );
		if ( false === $in ) {
			throw new ReaderException( __( 'The gzip file could not be opened.', 'total-import' ) );
		}
		$out = fopen( $target, 'wb' );
		if ( false === $out ) {
			gzclose( $in );
			throw new ReaderException( __( 'A working copy of the file could not be written.', 'total-import' ) );
		}
		$written = 0;
		try {
			while ( ! gzeof( $in ) ) {
				$chunk = gzread( $in, 1048576 );
				if ( false === $chunk ) {
					throw new ReaderException( __( 'The gzip file is damaged.', 'total-import' ) );
				}
				$written += strlen( $chunk );
				if ( $max_bytes > 0 && $written > $max_bytes ) {
					throw new ReaderException( self::too_big( $max_bytes ) );
				}
				fwrite( $out, $chunk );
			}
		} finally {
			gzclose( $in );
			fclose( $out );
		}
	}

	private static function unzip( string $path, string $target, int $max_bytes ): string {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new ReaderException( __( 'Opening zip files needs the PHP zip extension. Upload the file unzipped instead.', 'total-import' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new ReaderException( __( 'The zip file could not be opened.', 'total-import' ) );
		}

		$entry     = null;
		$extension = '';
		$count     = $zip->count();
		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}
			$name = (string) $stat['name'];
			$base = basename( $name );
			if ( '/' === substr( $name, -1 ) || 0 === strpos( $name, '__MACOSX/' ) || 0 === strpos( $base, '.' ) ) {
				continue;
			}
			$ext = self::extension( $base );
			if ( '' !== $ext ) {
				$entry     = $i;
				$extension = $ext;
				break;
			}
		}
		if ( null === $entry ) {
			$zip->close();
			throw new ReaderException( __( 'The zip file has no CSV, Excel, XML or JSON file in it.', 'total-import' ) );
		}

		$stat = $zip->statIndex( $entry );
		if ( $max_bytes > 0 && (int) $stat['size'] > $max_bytes ) {
			$zip->close();
			throw new ReaderException( self::too_big( $max_bytes ) );
		}

		$in  = $zip->getStream( (string) $stat['name'] );
		$out = fopen( $target, 'wb' );
		if ( false === $in || false === $out ) {
			$zip->close();
			throw new ReaderException( __( 'The file inside the zip could not be extracted.', 'total-import' ) );
		}
		$written = 0;
		try {
			while ( ! feof( $in ) ) {
				$chunk    = (string) fread( $in, 1048576 );
				$written += strlen( $chunk );
				if ( $max_bytes > 0 && $written > $max_bytes ) {
					throw new ReaderException( self::too_big( $max_bytes ) );
				}
				fwrite( $out, $chunk );
			}
		} finally {
			fclose( $in );
			fclose( $out );
			$zip->close();
		}
		return $extension;
	}

	private static function too_big( int $max_bytes ): string {
		/* translators: %s: size limit in MB */
		return sprintf( __( 'The unpacked file is larger than the %s MB limit.', 'total-import' ), number_format( $max_bytes / 1048576 ) );
	}
}

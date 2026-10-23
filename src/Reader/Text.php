<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reader messages are plain text, escaped where they're displayed.

namespace Importright\Reader;

defined( 'ABSPATH' ) || exit;

final class Text {

	/**
	 * A byte count for reader messages: "1 MB", "2.5 MB", or "512 bytes" below a megabyte.
	 */
	public static function size( int $bytes ): string {
		if ( $bytes < 1048576 ) {
			/* translators: %d: number of bytes */
			return sprintf( __( '%d bytes', 'total-import' ), $bytes );
		}
		/* translators: %s: number of megabytes */
		return sprintf( __( '%s MB', 'total-import' ), rtrim( rtrim( number_format( $bytes / 1048576, 1, '.', '' ), '0' ), '.' ) );
	}

	public static function is_utf8( string $text ): bool {
		return 1 === preg_match( '//u', $text );
	}

	/**
	 * Whether a sample cut from a longer stream is UTF-8, ignoring a multibyte character split at the end.
	 */
	public static function sample_is_utf8( string $sample ): bool {
		$limit = min( 4, strlen( $sample ) );
		for ( $trim = 0; $trim < $limit; $trim++ ) {
			if ( self::is_utf8( 0 === $trim ? $sample : substr( $sample, 0, -$trim ) ) ) {
				return true;
			}
		}
		return '' === $sample;
	}

	public static function to_utf8( string $text, string $from ): string {
		if ( '' === $text || ( 'UTF-8' === $from && self::is_utf8( $text ) ) ) {
			return $text;
		}
		if ( 'UTF-8' === $from ) {
			$from = 'Windows-1252';
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $text, 'UTF-8', $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unknown encoding name is reported below.
			if ( is_string( $converted ) ) {
				return $converted;
			}
		}
		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( $from, 'UTF-8//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
			if ( is_string( $converted ) ) {
				return $converted;
			}
		}
		/* translators: %s: character encoding name */
		throw new ReaderException( sprintf( __( 'The text could not be converted from %s to UTF-8.', 'total-import' ), $from ) );
	}

	/**
	 * Canonical name for an encoding given by a user, or null when it's unknown.
	 */
	public static function encoding_name( string $name ): ?string {
		$key     = strtoupper( str_replace( array( '_', ' ' ), '-', trim( $name ) ) );
		$aliases = array(
			'UTF8'        => 'UTF-8',
			'LATIN1'      => 'ISO-8859-1',
			'LATIN-1'     => 'ISO-8859-1',
			'CP1252'      => 'Windows-1252',
			'WINDOWS1252' => 'Windows-1252',
			'ANSI'        => 'Windows-1252',
		);
		$key     = $aliases[ $key ] ?? $key;
		if ( 'WINDOWS-1252' === $key ) {
			return 'Windows-1252';
		}
		if ( function_exists( 'mb_list_encodings' ) ) {
			foreach ( mb_list_encodings() as $known ) {
				if ( strtoupper( $known ) === $key ) {
					return $known;
				}
			}
			return null;
		}
		return $key;
	}
}

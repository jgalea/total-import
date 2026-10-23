<?php

namespace Importright\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Reads one image reference from a cell: an attachment ID, an http(s) URL,
 * or a file name to look up in the Media Library.
 */
final class ImageSource {

	public const ID   = 'id';
	public const URL  = 'url';
	public const FILE = 'file';

	/**
	 * @return array{type: string, value: string, key: string, filename: string}|null
	 */
	public static function classify( string $value ): ?array {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		if ( ctype_digit( $value ) ) {
			return array(
				'type'     => self::ID,
				'value'    => $value,
				'key'      => $value,
				'filename' => '',
			);
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			$key = self::url_key( $value );
			if ( null === $key ) {
				return null;
			}
			return array(
				'type'     => self::URL,
				'value'    => $value,
				'key'      => $key,
				'filename' => self::url_filename( $key ),
			);
		}
		$name = self::basename( $value );
		if ( '' === $name ) {
			return null;
		}
		return array(
			'type'     => self::FILE,
			'value'    => $name,
			'key'      => $name,
			'filename' => $name,
		);
	}

	/**
	 * The form a URL is stored and compared in: scheme and host lower-cased, default port and fragment dropped.
	 */
	public static function url_key( string $url ): ?string {
		$parts = parse_url( trim( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, tested without WordPress.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || isset( $parts['user'] ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) && ! ( ( 'http' === $scheme && 80 === $parts['port'] ) || ( 'https' === $scheme && 443 === $parts['port'] ) ) ? ':' . $parts['port'] : '';
		$path = $parts['path'] ?? '/';
		$path = '' === $path ? '/' : str_replace( ' ', '%20', $path );
		$qs   = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		return $scheme . '://' . strtolower( $parts['host'] ) . $port . $path . $qs;
	}

	/**
	 * File name at the end of a URL's path, decoded; empty when the path ends in a slash.
	 */
	public static function url_filename( string $url ): string {
		$path = (string) parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper.
		return self::basename( rawurldecode( $path ) );
	}

	private static function basename( string $value ): string {
		$value = str_replace( array( '\\', "\0" ), array( '/', '' ), $value );
		$value = substr( $value, (int) strrpos( '/' . $value, '/' ) );
		return '' === trim( $value, " \t\n\r." ) ? '' : trim( $value );
	}
}

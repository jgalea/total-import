<?php

namespace Importright\Source;

defined( 'ABSPATH' ) || exit;

/**
 * When a saved URL password may be kept for an edited URL source. The browser never
 * sees the password, so an edit that points the URL elsewhere must not carry it along.
 */
final class Credentials {

	/**
	 * True when the new source goes to the same scheme, host and port with the same username.
	 *
	 * @param array<string, mixed> $stored Saved URL source.
	 * @param array<string, mixed> $edited URL source from the form.
	 */
	public static function can_reuse( array $stored, array $edited ): bool {
		$before = self::origin( (string) ( $stored['url'] ?? '' ) );
		$after  = self::origin( (string) ( $edited['url'] ?? '' ) );
		return null !== $before && $before === $after && (string) ( $stored['username'] ?? '' ) === (string) ( $edited['username'] ?? '' );
	}

	/**
	 * "scheme://host:port" with the default port filled in, or null for anything but http(s).
	 */
	public static function origin( string $url ): ?string {
		$parts  = parse_url( UrlFetcher::normalize( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, tested without WordPress.
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return null;
		}
		$port = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		return $scheme . '://' . $host . ':' . $port;
	}
}

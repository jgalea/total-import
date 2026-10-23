<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- stands in for WordPress.

class WP_Http {

	public static function make_absolute_url( string $location, string $base ): string {
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		$parts = parse_url( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $location;
	}
}

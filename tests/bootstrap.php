<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- stands in for WordPress.

define( 'ABSPATH', __DIR__ . '/' );

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore,Universal.NamingConventions.NoReservedKeywordParameterNames
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Test filters: set $GLOBALS['importright_test_filters'][ $hook ] to a callable.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	function apply_filters( string $hook, $value, ...$args ) {
		$callback = $GLOBALS['importright_test_filters'][ $hook ] ?? null;
		return null === $callback ? $value : $callback( $value, ...$args );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'path_is_absolute' ) ) {
	function path_is_absolute( string $path ): bool {
		return '' !== $path && ( '/' === $path[0] || (bool) preg_match( '#^[a-zA-Z]:[\\\\/]#', $path ) );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	require __DIR__ . '/stubs/class-wp-error.php';
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Universal.NamingConventions.NoReservedKeywordParameterNames,WordPress.WP.AlternativeFunctions -- stands in for WordPress inside the source namespace.

namespace Importright\Source;

if ( ! defined( 'IMPORTRIGHT_VERSION' ) ) {
	define( 'IMPORTRIGHT_VERSION', 'test' );
}

if ( ! class_exists( 'WP_Http' ) ) {
	require __DIR__ . '/class-wp-http.php';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function add_action( $hook, $callback ) {
	$GLOBALS['importright_test_hooks'][ $hook ] = $callback;
}

function add_filter( $hook, $callback ) {
	$GLOBALS['importright_test_hooks'][ $hook ] = $callback;
}

function remove_action( $hook ) {
	unset( $GLOBALS['importright_test_hooks'][ $hook ] );
}

function remove_filter( $hook ) {
	unset( $GLOBALS['importright_test_hooks'][ $hook ] );
}

/**
 * Records each request and answers with the next of $GLOBALS['importright_test_responses'].
 */
function wp_safe_remote_get( $url, $args ) {
	$GLOBALS['importright_test_requests'][] = array(
		'url'     => $url,
		'headers' => $args['headers'],
	);
	if ( isset( $GLOBALS['importright_test_hooks']['http_api_curl'] ) ) {
		$GLOBALS['importright_test_hooks']['http_api_curl']( curl_init() );
	}
	$response = array_shift( $GLOBALS['importright_test_responses'] );
	if ( 200 === $response['code'] ) {
		file_put_contents( $args['filename'], 'id' );
	}
	return $response;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['code'];
}

function wp_remote_retrieve_header( $response, $header ) {
	return $response['headers'][ $header ] ?? '';
}

function wp_delete_file( $file ) {
	unlink( $file );
}

function home_url( $path = '' ) {
	return 'https://example.com' . $path;
}

function sanitize_file_name( $name ) {
	return $name;
}

function size_format( $bytes ) {
	return $bytes . ' B';
}

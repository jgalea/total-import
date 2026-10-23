<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Universal.NamingConventions.NoReservedKeywordParameterNames -- stands in for WordPress inside the admin namespace.

namespace Importright\Admin;

function admin_url( $path = '' ) {
	return 'https://example.com/wp-admin/' . $path;
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function wp_nonce_field( $action, $name, $referer ) {
	unset( $referer );
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce-for-' . esc_attr( $action ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
}

function add_action( $hook, $callback ) {
	$GLOBALS['importright_test_actions'][ $hook ] = $callback;
}

function sanitize_html_class( $value ) {
	return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
}

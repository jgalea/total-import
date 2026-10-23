<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,WordPress.NamingConventions.ValidFunctionName -- stands in for WordPress inside the plugin's root namespace.

namespace Importright;

function _doing_it_wrong( $function_name, $message, $version ) {
	$GLOBALS['importright_test_wrong'][] = array( $function_name, $message, $version );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html__( $text, $domain = 'default' ) {
	unset( $domain );
	return esc_html( $text );
}

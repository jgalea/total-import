<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Universal.NamingConventions.NoReservedKeywordParameterNames -- stands in for WordPress inside the store's namespace.

namespace Importright;

function get_option( $key, $fallback = false ) {
	return $GLOBALS['importright_test_options'][ $key ] ?? $fallback;
}

function update_option( $key, $value, $autoload = null ) {
	unset( $autoload );
	$GLOBALS['importright_test_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['importright_test_options'][ $key ] );
	return true;
}

<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Universal.NamingConventions.NoReservedKeywordParameterNames -- stands in for WordPress inside the imports namespace.

namespace Importright\Imports;

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function current_time( $type, $gmt = 0 ) {
	unset( $type, $gmt );
	return '2026-01-01 00:00:00';
}

function do_action( $hook, ...$args ) {
	unset( $hook, $args );
}

function get_current_user_id() {
	return 7;
}

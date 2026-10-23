<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- stands in for WordPress inside the runner's namespace.

namespace Importright\Runs;

if ( ! class_exists( 'WP_User' ) ) {
	require __DIR__ . '/class-wp-user.php';
}

/**
 * Users by ID: $GLOBALS['importright_test_users'][ $id ] = WP_User. The current user is $GLOBALS['importright_test_current'].
 */
function get_userdata( $id ) {
	return $GLOBALS['importright_test_users'][ (int) $id ] ?? false;
}

function user_can( $user, $capability ) {
	return ! empty( $user->allcaps[ $capability ] );
}

function current_user_can( $capability ) {
	$user = get_userdata( get_current_user_id() );
	return false !== $user && user_can( $user, $capability );
}

function get_current_user_id() {
	return (int) ( $GLOBALS['importright_test_current'] ?? 0 );
}

function wp_set_current_user( $id ) {
	$GLOBALS['importright_test_current']    = (int) $id;
	$GLOBALS['importright_test_switches'][] = (int) $id;
}

function kses_init() {
	$GLOBALS['importright_test_kses'][] = get_current_user_id();
}

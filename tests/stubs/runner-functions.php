<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- stands in for WordPress inside the runner's namespace.

namespace Importright\Runs;

if ( ! class_exists( 'WP_User' ) ) {
	require __DIR__ . '/class-wp-user.php';
}

/**
 * Fired actions land in $GLOBALS['importright_test_actions'] as [hook, args]; a callable in
 * $GLOBALS['importright_test_action_callbacks'][ $hook ] is run too.
 */
function do_action( string $hook, ...$args ) {
	$GLOBALS['importright_test_actions'][] = array( $hook, $args );
	$callback                              = $GLOBALS['importright_test_action_callbacks'][ $hook ] ?? null;
	if ( null !== $callback ) {
		$callback( ...$args );
	}
}

function current_time( string $type, bool $gmt = false ) {
	unset( $type, $gmt );
	return gmdate( 'Y-m-d H:i:s' );
}

function wp_defer_term_counting( $defer = null ) {
	unset( $defer );
	return false;
}

function wp_defer_comment_counting( $defer = null ) {
	unset( $defer );
	return false;
}

function wp_cache_supports( string $feature ): bool {
	unset( $feature );
	return false;
}

function wp_using_ext_object_cache(): bool {
	return false;
}

function wp_cache_flush(): bool {
	return true;
}

function sanitize_key( string $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

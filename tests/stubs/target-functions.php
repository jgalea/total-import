<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Universal.NamingConventions.NoReservedKeywordParameterNames -- stands in for WordPress inside the target namespace.

namespace Importright\Target;

if ( ! class_exists( 'WP_Taxonomy' ) ) {
	require __DIR__ . '/class-wp-taxonomy.php';
}

/**
 * Capabilities: $GLOBALS['importright_test_can'] = callable( $cap, ...$args ). Unset means allowed.
 */
function current_user_can( $capability, ...$args ) {
	$callback = $GLOBALS['importright_test_can'] ?? null;
	return null === $callback ? true : (bool) $callback( $capability, ...$args );
}

function get_current_user_id() {
	return 7;
}

function get_post_type_object( $post_type ) {
	if ( ! in_array( $post_type, array( 'post', 'page', 'product' ), true ) ) {
		return null;
	}
	$plural = 'post' === $post_type ? 'posts' : $post_type . 's';
	return (object) array(
		'labels' => (object) array( 'name' => ucfirst( $plural ) ),
		'cap'    => (object) array(
			'create_posts'      => 'edit_' . $plural,
			'publish_posts'     => 'publish_' . $plural,
			'edit_others_posts' => 'edit_others_' . $plural,
		),
	);
}

function get_taxonomy( $taxonomy ) {
	return 'category' === $taxonomy ? new \WP_Taxonomy( 'Categories', 'manage_categories' ) : false;
}

function get_post_stati() {
	return array_fill_keys( array( 'publish', 'future', 'draft', 'pending', 'private' ), true );
}

function sanitize_key( $key ) {
	return strtolower( (string) $key );
}

function wp_slash( $value ) {
	return $value;
}

function get_user_by( $field, $value ) {
	unset( $field );
	return in_array( (int) $value, array( 7, 8 ), true ) ? new \WP_User( (int) $value, 'user' . $value ) : false;
}

function is_email( $value ) {
	return false !== strpos( (string) $value, '@' );
}

function wp_insert_post( $data, $wp_error ) {
	unset( $wp_error );
	$GLOBALS['importright_test_saved'][] = $data;
	return 500;
}

function wp_update_post( $data, $wp_error ) {
	unset( $wp_error );
	$GLOBALS['importright_test_saved'][] = $data;
	return (int) $data['ID'];
}

function wp_insert_term( $name, $taxonomy, $args ) {
	$GLOBALS['importright_test_saved'][] = array( $name, $taxonomy, $args );
	return array( 'term_id' => 600 );
}

function wp_update_term( $id, $taxonomy, $args ) {
	$GLOBALS['importright_test_saved'][] = array( $id, $taxonomy, $args );
	return array( 'term_id' => $id );
}

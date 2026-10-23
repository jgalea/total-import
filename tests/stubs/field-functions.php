<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Universal.NamingConventions.NoReservedKeywordParameterNames -- stands in for WordPress inside the handler's namespace.

namespace Importright\Field;

function get_post_type( $post ) {
	unset( $post );
	return 'post';
}

function is_object_in_taxonomy( $object_type, $taxonomy ) {
	unset( $object_type, $taxonomy );
	return true;
}

function term_exists( $name, $taxonomy, $parent_id ) {
	unset( $taxonomy, $parent_id );
	$id = $GLOBALS['importright_test_terms'][ $name ] ?? null;
	return null === $id ? null : array( 'term_id' => (string) $id );
}

function wp_insert_term( $name, $taxonomy, $args ) {
	unset( $taxonomy, $args );
	$GLOBALS['importright_test_terms'][ $name ] = 100 + count( $GLOBALS['importright_test_terms'] );
	return array( 'term_id' => $GLOBALS['importright_test_terms'][ $name ] );
}

function wp_set_object_terms( $object_id, $terms, $taxonomy, $append ) {
	$GLOBALS['importright_test_set'][] = array( $object_id, $terms, $taxonomy, $append );
	return $terms;
}

function get_option( $name ) {
	return 'default_category' === $name ? 1 : false;
}

function is_wp_error( $thing ) {
	return $thing instanceof \WP_Error;
}

/**
 * Capabilities: set $GLOBALS['importright_test_can'] to a callable( $cap, ...$args ). Unset means allowed.
 */
function current_user_can( $capability, ...$args ) {
	$callback = $GLOBALS['importright_test_can'] ?? null;
	return null === $callback ? true : (bool) $callback( $capability, ...$args );
}

function get_current_user_id() {
	return 7;
}

function is_protected_meta( $meta_key, $meta_type = '' ) {
	unset( $meta_type );
	return '_' === substr( (string) $meta_key, 0, 1 );
}

function update_metadata( $meta_type, $object_id, $meta_key, $meta_value ) {
	$GLOBALS['importright_test_meta'][] = array( $meta_type, $object_id, $meta_key, $meta_value );
	return true;
}

function metadata_exists( $meta_type, $object_id, $meta_key ) {
	unset( $meta_type, $object_id, $meta_key );
	return false;
}

function wp_slash( $value ) {
	return $value;
}

function get_taxonomy( $taxonomy ) {
	return (object) array(
		'cap' => (object) array(
			'assign_terms' => 'assign_' . $taxonomy,
			'edit_terms'   => 'edit_' . $taxonomy,
		),
	);
}

<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- stands in for WordPress.

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		unset( $taxonomy );
		return true;
	}
}

if ( ! function_exists( 'wp_update_term_count_now' ) ) {
	function wp_update_term_count_now( $terms, $taxonomy ) {
		$GLOBALS['importright_test_recounts'][] = array( $taxonomy, array_values( $terms ) );
		return true;
	}
}

if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( $id ) {
		unset( $id );
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		unset( $key, $group );
		return true;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id ) {
		unset( $id );
		return 'post';
	}
}

if ( ! function_exists( 'clean_object_term_cache' ) ) {
	function clean_object_term_cache( $id, $type ) {
		unset( $id, $type );
	}
}

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $id ) {
		unset( $id );
		return null;
	}
}

if ( ! function_exists( 'clean_term_cache' ) ) {
	function clean_term_cache( $id, $taxonomy = '' ) {
		unset( $id, $taxonomy );
	}
}

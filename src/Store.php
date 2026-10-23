<?php

namespace Importright;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's few options, never autoloaded.
 */
final class Store {

	/**
	 * @param mixed $fallback
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		return get_option( $key, $fallback );
	}

	/**
	 * @param mixed $value
	 */
	public static function set( string $key, $value ): void {
		update_option( $key, $value, false );
	}

	public static function delete( string $key ): void {
		delete_option( $key );
	}
}

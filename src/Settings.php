<?php

namespace Importright;

defined( 'ABSPATH' ) || exit;

/**
 * Site settings from the Settings screen, stored in one non-autoloaded option.
 * They are the defaults of the matching filters, so a filter still wins.
 */
final class Settings {

	public const OPTION = 'importright_settings';

	public const DEFAULTS = array(
		'rollback_keep_runs' => 5,
		'rollback_keep_days' => 30,
		'batch_size'         => 100,
		'max_image_mb'       => 20,
		'delete_data'        => false,
	);

	public const LIMITS = array(
		'rollback_keep_runs' => array( 1, 100 ),
		'rollback_keep_days' => array( 1, 365 ),
		'batch_size'         => array( 1, 5000 ),
		'max_image_mb'       => array( 1, 512 ),
	);

	/**
	 * @return array{rollback_keep_runs: int, rollback_keep_days: int, batch_size: int, max_image_mb: int, delete_data: bool}
	 */
	public static function all(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * @return int|bool
	 */
	public static function get( string $key ) {
		return self::all()[ $key ];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function save( array $input ): void {
		Store::set( self::OPTION, self::sanitize( $input ) );
	}

	/**
	 * Fills missing keys with defaults, clamps numbers to their limits and drops unknown keys.
	 *
	 * @param array<string, mixed> $input
	 * @return array{rollback_keep_runs: int, rollback_keep_days: int, batch_size: int, max_image_mb: int, delete_data: bool}
	 */
	public static function sanitize( array $input ): array {
		$clean = self::DEFAULTS;
		foreach ( self::LIMITS as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$clean[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}
		$clean['delete_data'] = ! empty( $input['delete_data'] );
		return $clean;
	}
}

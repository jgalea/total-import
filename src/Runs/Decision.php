<?php

namespace Importright\Runs;

defined( 'ABSPATH' ) || exit;

/**
 * What happens to one record, decided from the matching mode, whether an
 * existing object was found, and the data hashes. No side effects.
 */
final class Decision {

	public const CREATE    = 'create';
	public const UPDATE    = 'update';
	public const UNCHANGED = 'unchanged';
	public const SKIP      = 'skip';

	/**
	 * @param string      $mode         create_update, create or update.
	 * @param int|null    $existing_id  Object found for the record, if any.
	 * @param string|null $tracked_hash Hash stored in the record map for this key and object, if any.
	 */
	public static function decide( string $mode, ?int $existing_id, ?string $tracked_hash, string $hash, bool $skip_unchanged ): string {
		if ( null === $existing_id ) {
			return 'update' === $mode ? self::SKIP : self::CREATE;
		}
		if ( 'create' === $mode ) {
			return self::SKIP;
		}
		if ( $skip_unchanged && null !== $tracked_hash && hash_equals( $tracked_hash, $hash ) ) {
			return self::UNCHANGED;
		}
		return self::UPDATE;
	}

	/**
	 * Hash of what an update would write: the values its rules let through, and the rules
	 * themselves. A change in a field the rules exclude leaves the hash alone.
	 *
	 * @param array<string, mixed> $values
	 * @param array<string, mixed> $update
	 * @param string[]             $handler_keys
	 */
	public static function hash( array $values, array $update, array $handler_keys ): string {
		list( $core, $sections ) = self::split( $values, $update, $handler_keys, false );
		foreach ( $sections as $key => $section ) {
			if ( is_array( $section['rule'] ) ) {
				$sections[ $key ]['values'] = array_intersect_key( $section['values'], array_flip( $section['rule'] ) );
			}
		}
		return md5( (string) wp_json_encode( array( $core, $sections, $update ) ) );
	}

	/**
	 * Splits rendered values into the parts an update may write.
	 *
	 * @param array<string, mixed> $values
	 * @param array<string, mixed> $update  Field => bool or list of sub-keys.
	 * @param string[]             $handler_keys
	 * @return array{0: array<string, string>, 1: array<string, array{values: array<mixed>, rule: bool|string[]}>}
	 */
	public static function split( array $values, array $update, array $handler_keys, bool $is_new ): array {
		$core     = array();
		$sections = array();
		foreach ( $values as $field => $value ) {
			$rule = $update[ $field ] ?? true;
			if ( in_array( $field, $handler_keys, true ) ) {
				if ( $is_new || false !== $rule ) {
					$sections[ $field ] = array(
						'values' => is_array( $value ) ? $value : array(),
						'rule'   => $is_new ? true : $rule,
					);
				}
				continue;
			}
			if ( $is_new || true === $rule ) {
				$core[ $field ] = (string) $value;
			}
		}
		return array( $core, $sections );
	}
}

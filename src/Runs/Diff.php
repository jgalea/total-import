<?php

namespace Importright\Runs;

defined( 'ABSPATH' ) || exit;

/**
 * Field-level changes for dry-run previews: {field, old, new}. Old is null
 * for a new object or a field that isn't set; long values are cut so a
 * preview row stays small.
 */
final class Diff {

	public const MAX_LENGTH = 1000;

	/**
	 * @var array<int, array{field: string, old: string|null, new: string|null}>
	 */
	private array $items = array();

	/**
	 * Adds the field when the values differ.
	 *
	 * @param mixed $before
	 * @param mixed $after
	 */
	public function add( string $field, $before, $after ): void {
		$old = null === $before ? null : self::text( $before );
		$new = null === $after ? null : self::text( $after );
		if ( $old === $new ) {
			return;
		}
		$this->items[] = array(
			'field' => $field,
			'old'   => null === $old ? null : self::cut( $old ),
			'new'   => null === $new ? null : self::cut( $new ),
		);
	}

	/**
	 * @return array<int, array{field: string, old: string|null, new: string|null}>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * @param mixed $value
	 */
	private static function text( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}
		return (string) $value;
	}

	private static function cut( string $value ): string {
		if ( strlen( $value ) <= self::MAX_LENGTH ) {
			return $value;
		}
		$cut = function_exists( 'mb_strcut' ) ? mb_strcut( $value, 0, self::MAX_LENGTH, 'UTF-8' ) : substr( $value, 0, self::MAX_LENGTH );
		return $cut . '…';
	}
}

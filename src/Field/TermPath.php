<?php

namespace Importright\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Splits a cell such as "News, Sports > Football" into term paths:
 * [["News"], ["Sports", "Football"]].
 */
final class TermPath {

	/**
	 * @return array<int, string[]> One list of names per term, outermost parent first.
	 */
	public static function parse( string $value, string $separator, string $hierarchy ): array {
		$items = '' === $separator ? array( $value ) : explode( $separator, $value );
		$paths = array();
		$seen  = array();

		foreach ( $items as $item ) {
			$segments = '' === $hierarchy ? array( $item ) : explode( $hierarchy, $item );
			$segments = array_values( array_filter( array_map( 'trim', $segments ), 'strlen' ) );
			if ( array() === $segments ) {
				continue;
			}
			$id = mb_strtolower( implode( "\0", $segments ) );
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$paths[]     = $segments;
		}
		return $paths;
	}

	/**
	 * Splits a list cell (image URLs, attribute values) on a separator, dropping blanks and repeats.
	 *
	 * @return string[]
	 */
	public static function split( string $value, string $separator ): array {
		$items = '' === $separator ? array( $value ) : explode( $separator, $value );
		return array_values( array_unique( array_filter( array_map( 'trim', $items ), 'strlen' ) ) );
	}
}

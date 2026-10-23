<?php

namespace Importright\Reader;

defined( 'ABSPATH' ) || exit;

final class Columns {

	/**
	 * Header names made usable as keys: trimmed, blanks named column_N, duplicates suffixed _2, _3.
	 *
	 * @param array<int, string|null> $names
	 * @return string[]
	 */
	public static function normalize( array $names ): array {
		$seen   = array();
		$result = array();
		foreach ( array_values( $names ) as $i => $name ) {
			$name = trim( str_replace( "\xEF\xBB\xBF", '', (string) $name ) );
			if ( '' === $name ) {
				$name = 'column_' . ( $i + 1 );
			}
			$base   = $name;
			$suffix = 2;
			while ( isset( $seen[ $name ] ) ) {
				$name = $base . '_' . $suffix;
				++$suffix;
			}
			$seen[ $name ] = true;
			$result[]      = $name;
		}
		return $result;
	}

	/**
	 * Pairs values with header names. Missing values become empty strings; extra values get column_N keys.
	 *
	 * @param string[]                $header
	 * @param array<int, string|null> $values
	 * @return array<string, string>
	 */
	public static function combine( array $header, array $values ): array {
		$record = array();
		foreach ( $header as $i => $name ) {
			$record[ $name ] = (string) ( $values[ $i ] ?? '' );
		}
		$count = count( $values );
		for ( $i = count( $header ); $i < $count; $i++ ) {
			$key = 'column_' . ( $i + 1 );
			if ( ! isset( $record[ $key ] ) ) {
				$record[ $key ] = (string) $values[ $i ];
			}
		}
		return $record;
	}
}

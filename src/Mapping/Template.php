<?php

namespace Importright\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * A field template such as "{title} - {sku}".
 *
 * {name} inserts the record's value for that column; a missing column is an
 * empty string. XML-style paths work too: {product/price} is the same as
 * {product.price}, and a leading slash is ignored. {name[1]} falls back to
 * {name} when the file has a single value. Column names match exactly first,
 * then ignoring case. Write {{ and }} for literal braces. A { with no
 * closing } is literal.
 */
final class Template {

	/**
	 * Literal text at even indexes, references at odd ones.
	 *
	 * @var string[]
	 */
	private array $parts = array();

	/**
	 * @var array<string, string>
	 */
	private array $resolved = array();

	public function __construct( string $template ) {
		$length  = strlen( $template );
		$literal = '';
		$i       = 0;
		while ( $i < $length ) {
			$char = $template[ $i ];
			$next = $template[ $i + 1 ] ?? '';
			if ( ( '{' === $char || '}' === $char ) && $next === $char ) {
				$literal .= $char;
				$i       += 2;
				continue;
			}
			if ( '{' === $char ) {
				$close = strpos( $template, '}', $i + 1 );
				if ( false !== $close ) {
					$reference = trim( substr( $template, $i + 1, $close - $i - 1 ) );
					if ( '' !== $reference ) {
						$this->parts[] = $literal;
						$this->parts[] = self::normalize( $reference );
						$literal       = '';
						$i             = $close + 1;
						continue;
					}
				}
			}
			$literal .= $char;
			++$i;
		}
		$this->parts[] = $literal;
	}

	/**
	 * @return string[] The column references used, normalized.
	 */
	public function references(): array {
		$references = array();
		foreach ( $this->parts as $i => $part ) {
			if ( 1 === $i % 2 ) {
				$references[] = $part;
			}
		}
		return array_values( array_unique( $references ) );
	}

	public function is_static(): bool {
		return 1 === count( $this->parts );
	}

	/**
	 * @param array<string, string> $record
	 */
	public function render( array $record ): string {
		$output = '';
		foreach ( $this->parts as $i => $part ) {
			$output .= 0 === $i % 2 ? $part : $this->lookup( $part, $record );
		}
		return $output;
	}

	public static function normalize( string $reference ): string {
		$reference = (string) preg_replace( '#^\.?/+#', '', $reference );
		return str_replace( '/', '.', $reference );
	}

	/**
	 * @param array<string, string> $record
	 */
	private function lookup( string $reference, array $record ): string {
		if ( isset( $record[ $reference ] ) ) {
			return $record[ $reference ];
		}
		$key = $this->resolved[ $reference ] ?? null;
		if ( null === $key || ! isset( $record[ $key ] ) ) {
			$key = self::resolve( $reference, $record );
			if ( null === $key ) {
				return '';
			}
			$this->resolved[ $reference ] = $key;
		}
		return $record[ $key ];
	}

	/**
	 * @param array<string, string> $record
	 */
	private static function resolve( string $reference, array $record ): ?string {
		$candidates = array( $reference );
		$without    = str_replace( '[1]', '', $reference );
		if ( $without !== $reference ) {
			$candidates[] = $without;
		}
		foreach ( $candidates as $candidate ) {
			if ( isset( $record[ $candidate ] ) ) {
				return $candidate;
			}
		}
		foreach ( $candidates as $candidate ) {
			foreach ( array_keys( $record ) as $key ) {
				if ( 0 === strcasecmp( (string) $key, $candidate ) ) {
					return (string) $key;
				}
			}
		}
		return null;
	}
}

<?php

namespace Importright\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Compiles an import's mapping once and renders it per record. The mapping
 * is nested arrays whose string leaves are templates; the output has the
 * same shape with rendered strings.
 */
final class Mapper {

	/**
	 * @var array<string, mixed>
	 */
	private array $compiled;

	private Template $key;

	/**
	 * @param array<string, mixed> $mapping
	 */
	public function __construct( array $mapping, string $unique_key ) {
		$this->compiled = self::compile( $mapping );
		$this->key      = new Template( $unique_key );
	}

	/**
	 * @param array<string, string> $record
	 * @return array<string, mixed>
	 */
	public function render( array $record ): array {
		return self::render_tree( $this->compiled, $record );
	}

	/**
	 * @param array<string, string> $record
	 */
	public function key( array $record ): string {
		return trim( $this->key->render( $record ) );
	}

	/**
	 * @param array<mixed> $mapping
	 * @return array<mixed>
	 */
	private static function compile( array $mapping ): array {
		$compiled = array();
		foreach ( $mapping as $name => $value ) {
			if ( is_array( $value ) ) {
				$compiled[ $name ] = self::compile( $value );
			} elseif ( is_scalar( $value ) ) {
				$compiled[ $name ] = new Template( (string) $value );
			}
		}
		return $compiled;
	}

	/**
	 * @param array<mixed>          $tree
	 * @param array<string, string> $record
	 * @return array<mixed>
	 */
	private static function render_tree( array $tree, array $record ): array {
		$output = array();
		foreach ( $tree as $name => $node ) {
			$output[ $name ] = $node instanceof Template ? $node->render( $record ) : self::render_tree( $node, $record );
		}
		return $output;
	}
}

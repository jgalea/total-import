<?php

namespace Importright\Imports;

use Importright\Reader\Formats;
use Importright\Registry;
use Importright\Settings;
use Importright\Source\Sources;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and fills in an import definition. The stored JSON shape is:
 *
 * {
 *   "name": "Products",
 *   "source":   {"type": "upload|url|path", …},
 *   "format":   {"type": "auto|csv|tsv|xlsx|xml|json", "delimiter": "", "enclosure": "", "encoding": "",
 *                "has_header": true, "sheet": "", "record_path": "", "json_path": ""},
 *   "target":   {"type": "post", "post_type": "post", "default_status": "publish"},
 *   "mapping":  {"title": "{title}", …, "meta": {"_sku": "{sku}"}},
 *   "matching": {"unique_key": "{sku}", "mode": "create_update|create|update", "match_existing": ""},
 *   "update":   {"content": false, "meta": ["_price"]},
 *   "options":  {"batch_size": 100, "skip_unchanged": true},
 *   "extensions": {}
 * }
 *
 * Mapping keys are the target's core fields plus one section per field
 * handler. Update rules default to true; a handler's rule may be a list of
 * sub-keys it may change.
 */
final class Definition {

	public const MODES = array( 'create_update', 'create', 'update' );

	public const DEFAULT_BATCH_SIZE = 100;
	public const MAX_BATCH_SIZE     = 5000;

	/**
	 * @param array<string, mixed>      $input
	 * @param array<string, mixed>|null $stored_source The saved source of the import being edited, if any.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function normalize( array $input, ?array $stored_source = null ) {
		$source = Sources::normalize( is_array( $input['source'] ?? null ) ? $input['source'] : array(), $stored_source );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$format = self::format( is_array( $input['format'] ?? null ) ? $input['format'] : array() );
		if ( is_wp_error( $format ) ) {
			return $format;
		}

		$target_input = is_array( $input['target'] ?? null ) ? $input['target'] : array();
		$target_type  = (string) ( $target_input['type'] ?? 'post' );
		$target       = Registry::target( $target_type );
		if ( null === $target ) {
			/* translators: %s: target type such as post */
			return new WP_Error( 'importright_target', sprintf( __( 'Unknown import target: %s', 'total-import' ), $target_type ) );
		}
		$settings = $target->validate( $target_input );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$settings['type'] = $target_type;

		$mapping = self::mapping( is_array( $input['mapping'] ?? null ) ? $input['mapping'] : array(), $target_type );
		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}

		$matching = is_array( $input['matching'] ?? null ) ? $input['matching'] : array();
		$mode     = (string) ( $matching['mode'] ?? 'create_update' );
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new WP_Error( 'importright_matching', __( 'The matching mode must be create_update, create or update.', 'total-import' ) );
		}
		$unique_key = trim( (string) ( $matching['unique_key'] ?? '' ) );
		if ( '' === $unique_key ) {
			$unique_key = is_string( $mapping['title'] ?? null ) ? trim( $mapping['title'] ) : '';
		}
		if ( '' === $unique_key || false === strpos( $unique_key, '{' ) ) {
			return new WP_Error( 'importright_matching', __( 'Set a unique key that uses at least one column, such as {sku} or {id}. It is how a later run finds the posts this import created.', 'total-import' ) );
		}
		$match_existing = (string) ( $matching['match_existing'] ?? '' );
		if ( '' !== $match_existing && ! in_array( $match_existing, $target->match_strategies(), true ) && 0 !== strpos( $match_existing, 'meta:' ) ) {
			/* translators: %s: matching strategy name */
			return new WP_Error( 'importright_matching', sprintf( __( 'Unknown way to match existing content: %s', 'total-import' ), $match_existing ) );
		}

		$update = array();
		foreach ( is_array( $input['update'] ?? null ) ? $input['update'] : array() as $field => $rule ) {
			$update[ (string) $field ] = is_array( $rule ) ? array_values( array_map( 'strval', $rule ) ) : (bool) $rule;
		}

		$options = is_array( $input['options'] ?? null ) ? $input['options'] : array();
		$batch   = (int) ( $options['batch_size'] ?? Settings::get( 'batch_size' ) );

		$definition = array(
			'name'       => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
			'source'     => $source,
			'format'     => $format,
			'target'     => $settings,
			'mapping'    => $mapping,
			'matching'   => array(
				'unique_key'     => $unique_key,
				'mode'           => $mode,
				'match_existing' => $match_existing,
			),
			'update'     => $update,
			'options'    => array(
				'batch_size'     => max( 1, min( self::MAX_BATCH_SIZE, $batch ) ),
				'skip_unchanged' => (bool) ( $options['skip_unchanged'] ?? true ),
			),
			'extensions' => array(),
		);

		$extensions = apply_filters( 'importright_definition_extensions', array(), is_array( $input['extensions'] ?? null ) ? $input['extensions'] : array(), $definition );
		if ( is_wp_error( $extensions ) ) {
			return $extensions;
		}
		$definition['extensions'] = is_array( $extensions ) ? $extensions : array();
		return $definition;
	}

	/**
	 * @param array<string, mixed> $format
	 * @return array<string, mixed>|WP_Error
	 */
	private static function format( array $format ) {
		$type = (string) ( $format['type'] ?? 'auto' );
		if ( 'auto' !== $type && ! in_array( $type, Formats::TYPES, true ) ) {
			/* translators: %s: format name */
			return new WP_Error( 'importright_format', sprintf( __( 'Unknown file format: %s', 'total-import' ), $type ) );
		}
		return array(
			'type'        => $type,
			'delimiter'   => substr( (string) ( $format['delimiter'] ?? '' ), 0, 3 ),
			'enclosure'   => substr( (string) ( $format['enclosure'] ?? '' ), 0, 1 ),
			'encoding'    => sanitize_text_field( (string) ( $format['encoding'] ?? '' ) ),
			'has_header'  => (bool) ( $format['has_header'] ?? true ),
			'sheet'       => sanitize_text_field( (string) ( $format['sheet'] ?? '' ) ),
			'record_path' => trim( (string) ( $format['record_path'] ?? '' ) ),
			'json_path'   => trim( (string) ( $format['json_path'] ?? '' ) ),
		);
	}

	/**
	 * @param array<string, mixed> $mapping
	 * @return array<string, mixed>|WP_Error
	 */
	private static function mapping( array $mapping, string $target_type ) {
		$target   = Registry::target( $target_type );
		$fields   = $target->fields();
		$handlers = Registry::handlers_for( $target_type );
		$clean    = array();

		foreach ( $mapping as $key => $value ) {
			$key = (string) $key;
			if ( isset( $handlers[ $key ] ) ) {
				$section = $handlers[ $key ]->validate( is_array( $value ) ? $value : array() );
				if ( is_wp_error( $section ) ) {
					return $section;
				}
				$clean[ $key ] = $section;
				continue;
			}
			if ( ! isset( $fields[ $key ] ) ) {
				/* translators: %s: field name */
				return new WP_Error( 'importright_mapping', sprintf( __( 'Unknown field in the mapping: %s', 'total-import' ), $key ) );
			}
			if ( ! is_scalar( $value ) ) {
				/* translators: %s: field name */
				return new WP_Error( 'importright_mapping', sprintf( __( 'The mapping for %s must be a template string.', 'total-import' ), $key ) );
			}
			$clean[ $key ] = (string) $value;
		}
		return $clean;
	}
}

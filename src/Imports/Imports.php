<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own tables.

namespace Importright\Imports;

use Importright\Database\Schema;
use Importright\Source\Storage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Saved import definitions.
 */
final class Imports {

	/**
	 * @param array<string, mixed> $input
	 * @return int|WP_Error The new import's ID.
	 */
	public static function create( array $input ) {
		global $wpdb;

		$definition = Definition::normalize( $input );
		if ( is_wp_error( $definition ) ) {
			return $definition;
		}
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			Schema::imports(),
			array(
				'name'       => $definition['name'],
				'definition' => wp_json_encode( $definition ),
				'created_by' => get_current_user_id(),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'importright_db', __( 'The import could not be saved.', 'total-import' ) );
		}
		$id = (int) $wpdb->insert_id;
		do_action( 'importright_import_saved', $id, $definition );
		return $id;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $input ) {
		global $wpdb;

		$stored     = self::get( $id );
		$definition = Definition::normalize( $input, is_array( $stored['definition']['source'] ?? null ) ? $stored['definition']['source'] : null );
		if ( is_wp_error( $definition ) ) {
			return $definition;
		}
		if ( is_array( $stored['definition']['extensions'] ?? null ) ) {
			$definition['extensions'] += $stored['definition']['extensions'];
		}
		$wpdb->update(
			Schema::imports(),
			array(
				'name'       => $definition['name'],
				'definition' => wp_json_encode( $definition ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		do_action( 'importright_import_saved', $id, $definition );
		return true;
	}

	/**
	 * @return array{id: int, name: string, definition: array<string, mixed>, created_by: int, created_at: string, updated_at: string}|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::imports(), $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', Schema::imports() ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Deletes the definition, its runs and its record map. Imported content stays.
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$import = self::get( $id );
		if ( null === $import ) {
			return;
		}
		$wpdb->delete( Schema::records(), array( 'import_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::runs(), array( 'import_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::imports(), array( 'id' => $id ), array( '%d' ) );
		if ( 'upload' === ( $import['definition']['source']['type'] ?? '' ) ) {
			Storage::delete_upload( (string) $import['definition']['source']['file'] );
		}
		do_action( 'importright_import_deleted', $id );
	}

	/**
	 * Daily: removes stored uploads no import or active run refers to, and stale admin previews.
	 */
	public static function prune_files(): int {
		global $wpdb;
		$keep = array();
		foreach ( self::all() as $import ) {
			$keep[] = (string) ( $import['definition']['source']['file'] ?? '' );
		}
		$active = $wpdb->get_col( $wpdb->prepare( "SELECT definition FROM %i WHERE status IN ('pending','running')", Schema::runs() ) );
		foreach ( (array) $active as $json ) {
			$definition = json_decode( (string) $json, true );
			$keep[]     = (string) ( $definition['source']['file'] ?? '' );
		}
		return Storage::prune( array_filter( $keep ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id: int, name: string, definition: array<string, mixed>, created_by: int, created_at: string, updated_at: string}
	 */
	private static function hydrate( array $row ): array {
		$definition = json_decode( (string) $row['definition'], true );
		return array(
			'id'         => (int) $row['id'],
			'name'       => (string) $row['name'],
			'definition' => is_array( $definition ) ? $definition : array(),
			'created_by' => (int) $row['created_by'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		);
	}
}

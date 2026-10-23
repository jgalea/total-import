<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads and restores raw rows so a rollback puts back exactly what was there, meta IDs included.

namespace Importright\Runs;

defined( 'ABSPATH' ) || exit;

/**
 * The stored state of one object as a flat map, so a run can keep the
 * previous value of whatever it changed and a rollback can put it back
 * byte for byte:
 *
 *   row.<column>       posts, users or terms row (term_taxonomy as tt.<column>)
 *   meta.<meta_id>     [meta_key, meta_value]
 *   terms.<taxonomy>   "tt_id:term_order,…" for posts
 *
 * Passwords, activation keys and session tokens are never read. Counts that
 * follow from relationships (term_taxonomy.count, WooCommerce's
 * product_count_* term meta) and the editor's _edit_lock are left out, so
 * they neither get restored nor count as an edit (filter
 * importright_snapshot_skip_meta).
 */
final class Snapshot {

	private const POST_TYPES = array( 'post', 'product', 'attachment' );

	private const USER_SECRETS = array( 'user_pass', 'user_activation_key' );

	private const USER_SECRET_META = array( 'session_tokens' );

	/**
	 * Object types stored as rows in the posts table.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = apply_filters( 'importright_post_object_types', self::POST_TYPES );
		return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ), 'strlen' ) ) : self::POST_TYPES;
	}

	/**
	 * @return array<string, mixed>|null Null when the object no longer exists.
	 */
	public static function take( string $type, int $id ): ?array {
		global $wpdb;

		$map = array();
		if ( in_array( $type, self::post_types(), true ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
			if ( ! is_array( $row ) ) {
				return null;
			}
			unset( $row['ID'] );
			foreach ( $row as $column => $value ) {
				$map[ 'row.' . $column ] = $value;
			}
			$map   += self::meta( 'post', $id );
			$terms  = $wpdb->get_results( $wpdb->prepare( "SELECT tt.taxonomy, tr.term_taxonomy_id, tr.term_order FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d ORDER BY tt.taxonomy, tr.term_taxonomy_id", $id ), ARRAY_A );
			$by_tax = array();
			foreach ( (array) $terms as $term ) {
				$by_tax[ $term['taxonomy'] ][] = $term['term_taxonomy_id'] . ':' . $term['term_order'];
			}
			foreach ( $by_tax as $taxonomy => $pairs ) {
				$map[ 'terms.' . $taxonomy ] = implode( ',', $pairs );
			}
			return $map;
		}

		if ( 'term' === $type ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->terms} WHERE term_id = %d", $id ), ARRAY_A );
			if ( ! is_array( $row ) ) {
				return null;
			}
			unset( $row['term_id'] );
			foreach ( $row as $column => $value ) {
				$map[ 'row.' . $column ] = $value;
			}
			$tt = $wpdb->get_row( $wpdb->prepare( "SELECT taxonomy, description, parent FROM {$wpdb->term_taxonomy} WHERE term_id = %d ORDER BY term_taxonomy_id LIMIT 1", $id ), ARRAY_A );
			foreach ( (array) $tt as $column => $value ) {
				$map[ 'tt.' . $column ] = $value;
			}
			return $map + self::meta( 'term', $id );
		}

		if ( 'user' === $type ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $id ), ARRAY_A );
			if ( ! is_array( $row ) ) {
				return null;
			}
			unset( $row['ID'] );
			foreach ( self::USER_SECRETS as $secret ) {
				unset( $row[ $secret ] );
			}
			foreach ( $row as $column => $value ) {
				$map[ 'row.' . $column ] = $value;
			}
			return $map + self::meta( 'user', $id );
		}

		return null;
	}

	/**
	 * Fields whose values differ, with the value before (null when the field didn't exist).
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, mixed>
	 */
	public static function diff( array $before, array $after ): array {
		$changed = array();
		foreach ( array_keys( $before + $after ) as $field ) {
			$old = $before[ $field ] ?? null;
			$new = $after[ $field ] ?? null;
			if ( $old !== $new ) {
				$changed[ $field ] = $old;
			}
		}
		return $changed;
	}

	/**
	 * Fingerprint of a snapshot, or of only the given fields of it.
	 *
	 * @param array<string, mixed> $map
	 * @param string[]|null        $fields
	 */
	public static function hash( array $map, ?array $fields = null ): string {
		if ( null !== $fields ) {
			$picked = array();
			foreach ( $fields as $field ) {
				$picked[ $field ] = $map[ $field ] ?? null;
			}
			$map = $picked;
		}
		ksort( $map, SORT_STRING );
		return md5( (string) wp_json_encode( $map ) );
	}

	/**
	 * Writes the stored values back.
	 *
	 * @param array<string, mixed> $before Field => value before the run (null: the field didn't exist).
	 */
	public static function restore( string $type, int $id, array $before ): void {
		global $wpdb;

		$columns = array();
		$tt      = array();
		$meta    = array();
		$terms   = array();
		foreach ( $before as $field => $value ) {
			list( $kind, $name ) = explode( '.', (string) $field, 2 );
			if ( 'row' === $kind ) {
				$columns[ $name ] = $value;
			} elseif ( 'tt' === $kind ) {
				$tt[ $name ] = $value;
			} elseif ( 'meta' === $kind ) {
				$meta[ (int) $name ] = $value;
			} elseif ( 'terms' === $kind ) {
				$terms[ $name ] = (string) $value;
			}
		}

		$meta_type = in_array( $type, self::post_types(), true ) ? 'post' : $type;

		if ( array() !== $columns ) {
			if ( 'term' === $type ) {
				$wpdb->update( $wpdb->terms, $columns, array( 'term_id' => $id ) );
			} elseif ( 'user' === $type ) {
				$wpdb->update( $wpdb->users, array_diff_key( $columns, array_flip( self::USER_SECRETS ) ), array( 'ID' => $id ) );
			} else {
				$wpdb->update( $wpdb->posts, $columns, array( 'ID' => $id ) );
			}
		}
		if ( array() !== $tt && 'term' === $type ) {
			$taxonomy = (string) $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1", $id ) );
			unset( $tt['taxonomy'] );
			if ( array() !== $tt ) {
				$wpdb->update(
					$wpdb->term_taxonomy,
					$tt,
					array(
						'term_id'  => $id,
						'taxonomy' => $taxonomy,
					)
				);
			}
		}

		foreach ( $meta as $meta_id => $value ) {
			self::restore_meta( $meta_type, $id, $meta_id, $value );
		}

		foreach ( $terms as $taxonomy => $pairs ) {
			self::restore_terms( $id, $taxonomy, $pairs );
		}

		if ( array_key_exists( 'post_status', $columns ) && in_array( $type, self::post_types(), true ) ) {
			self::recount_terms( $id );
		}

		self::clean( $type, $id );
	}

	private static function recount_terms( int $id ): void {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.taxonomy, tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d", $id ), ARRAY_A );
		$by   = array();
		foreach ( (array) $rows as $row ) {
			$by[ (string) $row['taxonomy'] ][] = (int) $row['term_taxonomy_id'];
		}
		foreach ( $by as $taxonomy => $tt_ids ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				wp_update_term_count_now( $tt_ids, $taxonomy );
			}
		}
	}

	public static function clean( string $type, int $id ): void {
		if ( 'term' === $type ) {
			$term = get_term( $id );
			clean_term_cache( $id, $term instanceof \WP_Term ? $term->taxonomy : '' );
			wp_cache_delete( $id, 'term_meta' );
			return;
		}
		if ( 'user' === $type ) {
			clean_user_cache( $id );
			wp_cache_delete( $id, 'user_meta' );
			return;
		}
		clean_post_cache( $id );
		wp_cache_delete( $id, 'post_meta' );
		$post_type = get_post_type( $id );
		if ( is_string( $post_type ) ) {
			clean_object_term_cache( $id, $post_type );
		}
		if ( 'product' === $type && function_exists( 'wc_delete_product_transients' ) ) {
			wp_cache_delete( 'lookup_table', 'object_' . $id );
			$store = \WC_Data_Store::load( 'product' );
			if ( $store->has_callable( 'refresh_product_lookup_table' ) ) {
				$store->refresh_product_lookup_table( $id );
			}
			wc_delete_product_transients( $id );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function meta( string $type, int $id ): array {
		global $wpdb;
		list( $table, $object_column, $id_column ) = self::meta_table( $type );

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT %i AS mid, meta_key, meta_value FROM %i WHERE %i = %d ORDER BY %i', $id_column, $table, $object_column, $id, $id_column ), ARRAY_A );
		$map  = array();
		$skip = self::skipped_meta( $type );
		foreach ( (array) $rows as $row ) {
			if ( isset( $skip[ $row['meta_key'] ] ) || 0 === strpos( (string) $row['meta_key'], 'product_count_' ) ) {
				continue;
			}
			$map[ 'meta.' . $row['mid'] ] = array( (string) $row['meta_key'], (string) $row['meta_value'] );
		}
		return $map;
	}

	/**
	 * @return array<string, true>
	 */
	private static function skipped_meta( string $type ): array {
		static $cache = array();
		if ( ! isset( $cache[ $type ] ) ) {
			$keys           = 'user' === $type ? self::USER_SECRET_META : array( '_edit_lock' );
			$keys           = (array) apply_filters( 'importright_snapshot_skip_meta', $keys, $type );
			$cache[ $type ] = array_fill_keys( array_map( 'strval', $keys ), true );
			if ( 'user' === $type ) {
				$cache[ $type ] += array_fill_keys( self::USER_SECRET_META, true );
			}
		}
		return $cache[ $type ];
	}

	/**
	 * @param mixed $value
	 */
	private static function restore_meta( string $type, int $id, int $meta_id, $value ): void {
		global $wpdb;
		list( $table, $object_column, $id_column ) = self::meta_table( $type );

		if ( null === $value ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE %i = %d AND %i = %d', $table, $id_column, $meta_id, $object_column, $id ) );
			return;
		}
		$value = (array) $value;
		$found = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i = %d', $table, $id_column, $meta_id ) );
		if ( $found > 0 ) {
			$wpdb->update(
				$table,
				array(
					'meta_key'   => (string) $value[0], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => (string) $value[1], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				),
				array( $id_column => $meta_id )
			);
			return;
		}
		$wpdb->insert(
			$table,
			array(
				$id_column     => $meta_id,
				$object_column => $id,
				'meta_key'     => (string) $value[0], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => (string) $value[1], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	private static function restore_terms( int $id, string $taxonomy, string $pairs ): void {
		global $wpdb;

		$current  = $wpdb->get_col( $wpdb->prepare( "SELECT tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s", $id, $taxonomy ) );
		$affected = array_map( 'intval', (array) $current );
		foreach ( $affected as $tt_id ) {
			$wpdb->delete(
				$wpdb->term_relationships,
				array(
					'object_id'        => $id,
					'term_taxonomy_id' => $tt_id,
				)
			);
		}
		foreach ( '' === $pairs ? array() : explode( ',', $pairs ) as $pair ) {
			list( $tt_id, $order ) = array_map( 'intval', explode( ':', $pair ) );
			$wpdb->insert(
				$wpdb->term_relationships,
				array(
					'object_id'        => $id,
					'term_taxonomy_id' => $tt_id,
					'term_order'       => $order,
				)
			);
			$affected[] = $tt_id;
		}
		$affected = array_values( array_unique( $affected ) );
		if ( array() !== $affected && taxonomy_exists( $taxonomy ) ) {
			wp_update_term_count_now( $affected, $taxonomy );
		}
	}

	/**
	 * @return array{0: string, 1: string, 2: string} Table, object column, ID column.
	 */
	private static function meta_table( string $type ): array {
		global $wpdb;
		if ( 'term' === $type ) {
			return array( $wpdb->termmeta, 'term_id', 'meta_id' );
		}
		if ( 'user' === $type ) {
			return array( $wpdb->usermeta, 'user_id', 'umeta_id' );
		}
		return array( $wpdb->postmeta, 'post_id', 'meta_id' );
	}
}

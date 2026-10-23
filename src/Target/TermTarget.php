<?php

namespace Importright\Target;

use Importright\Runs\Context;
use Importright\Runs\Diff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Terms of one taxonomy, saved through wp_insert_term and wp_update_term.
 * Parent: a term ID, a unique key of this import, then a name or slug.
 */
final class TermTarget implements Target, Previewable {

	public function key(): string {
		return 'term';
	}

	public function label(): string {
		return __( 'Categories, tags and other taxonomy terms', 'total-import' );
	}

	public function object_type(): string {
		return 'term';
	}

	public function fields(): array {
		return array(
			'name'        => __( 'Name', 'total-import' ),
			'slug'        => __( 'Slug', 'total-import' ),
			'description' => __( 'Description', 'total-import' ),
			'parent'      => __( 'Parent (ID, name, slug or unique key)', 'total-import' ),
		);
	}

	public function match_strategies(): array {
		return array( 'id', 'slug', 'name' );
	}

	public function validate( array $settings ) {
		$taxonomy = sanitize_key( (string) ( $settings['taxonomy'] ?? 'category' ) );
		if ( ! taxonomy_exists( $taxonomy ) || in_array( $taxonomy, array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' ), true ) ) {
			/* translators: %s: taxonomy */
			return new WP_Error( 'importright_target', sprintf( __( 'Terms can\'t be imported into the taxonomy "%s".', 'total-import' ), $taxonomy ) );
		}
		return array( 'taxonomy' => $taxonomy );
	}

	public function prime( array $ids ): void {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( array() !== $ids ) {
			_prime_term_caches( $ids, false );
		}
	}

	public function exists( int $id, array $settings ): bool {
		$term = get_term( $id );
		return $term instanceof \WP_Term && $term->taxonomy === $settings['taxonomy'];
	}

	public function find( string $strategy, array $values, string $unique_key, array $settings ): ?int {
		global $wpdb;

		$taxonomy = (string) $settings['taxonomy'];
		$id       = null;
		if ( 'id' === $strategy && ctype_digit( $unique_key ) ) {
			$id = (int) $unique_key;
		} elseif ( 'slug' === $strategy && '' !== (string) ( $values['slug'] ?? '' ) ) {
			$term = get_term_by( 'slug', sanitize_title( (string) $values['slug'] ), $taxonomy );
			$id   = $term instanceof \WP_Term ? (int) $term->term_id : null;
		} elseif ( 'name' === $strategy && '' !== (string) ( $values['name'] ?? '' ) ) {
			$term = get_term_by( 'name', (string) $values['name'], $taxonomy );
			$id   = $term instanceof \WP_Term ? (int) $term->term_id : null;
		} elseif ( 0 === strpos( $strategy, 'meta:' ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT t.term_id FROM {$wpdb->term_taxonomy} t INNER JOIN {$wpdb->termmeta} m ON m.term_id = t.term_id WHERE m.meta_key = %s AND m.meta_value = %s AND t.taxonomy = %s ORDER BY t.term_id LIMIT 1", substr( $strategy, 5 ), $unique_key, $taxonomy ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return null !== $id && $id > 0 && $this->exists( $id, $settings ) ? $id : null;
	}

	public function save( ?int $id, array $fields, array $settings, Context $context ) {
		$taxonomy = (string) $settings['taxonomy'];
		$denied   = Permissions::term( $id, $taxonomy );
		if ( null !== $denied ) {
			return $denied;
		}
		$args = $this->args( $id, $fields, $settings, $context );

		$name = array_key_exists( 'name', $fields ) ? trim( (string) $fields['name'] ) : '';
		if ( null === $id ) {
			if ( '' === $name ) {
				return new WP_Error( 'importright_save', __( 'A new term needs a name.', 'total-import' ) );
			}
			$result = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $args ) );
			if ( is_wp_error( $result ) && 'term_exists' === $result->get_error_code() ) {
				return self::duplicate( $name );
			}
		} else {
			if ( '' !== $name ) {
				$args['name'] = $name;
			}
			if ( array() === $args ) {
				return $id;
			}
			$result = wp_update_term( $id, $taxonomy, wp_slash( $args ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (int) $result['term_id'];
	}

	public function preview( ?int $id, array $fields, array $settings, Context $context, Diff $diff ) {
		$taxonomy = (string) $settings['taxonomy'];
		$denied   = Permissions::term( $id, $taxonomy );
		if ( null !== $denied ) {
			return $denied;
		}
		$args = $this->args( $id, $fields, $settings, $context );
		$name = array_key_exists( 'name', $fields ) ? trim( (string) $fields['name'] ) : '';
		$term = null === $id ? null : get_term( $id, $taxonomy );

		if ( null === $id ) {
			if ( '' === $name ) {
				return new WP_Error( 'importright_save', __( 'A new term needs a name.', 'total-import' ) );
			}
			if ( term_exists( $name, $taxonomy, (int) ( $args['parent'] ?? 0 ) ) ) {
				return self::duplicate( $name );
			}
		}
		if ( '' !== $name ) {
			$diff->add( 'name', $term instanceof \WP_Term ? $term->name : null, $name );
		}
		foreach ( array( 'slug', 'description', 'parent' ) as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$diff->add( $field, $term instanceof \WP_Term ? $term->{$field} : null, $args[ $field ] );
			}
		}
		return true;
	}

	private static function duplicate( string $name ): WP_Error {
		/* translators: %s: term name */
		return new WP_Error( 'importright_save', sprintf( __( 'A term named "%s" already exists here. Set matching to name or slug to update it instead.', 'total-import' ), $name ) );
	}

	/**
	 * @param array<string, string> $fields
	 * @param array<string, mixed>  $settings
	 * @return array<string, mixed>
	 */
	private function args( ?int $id, array $fields, array $settings, Context $context ): array {
		$taxonomy = (string) $settings['taxonomy'];
		$args     = array();

		if ( array_key_exists( 'slug', $fields ) && '' !== trim( (string) $fields['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $fields['slug'] );
		}
		if ( array_key_exists( 'description', $fields ) ) {
			$args['description'] = (string) $fields['description'];
		}
		if ( array_key_exists( 'parent', $fields ) && is_taxonomy_hierarchical( $taxonomy ) ) {
			$parent = trim( (string) $fields['parent'] );
			if ( '' === $parent ) {
				$args['parent'] = 0;
			} else {
				$parent_id = $this->parent( $parent, $settings, $context );
				if ( null === $parent_id || $parent_id === $id ) {
					/* translators: %s: parent text from the file */
					$context->notice( sprintf( __( 'Parent "%s" was not found.', 'total-import' ), $parent ) );
				} else {
					$args['parent'] = $parent_id;
				}
			}
		}

		return $args;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function parent( string $value, array $settings, Context $context ): ?int {
		$taxonomy = (string) $settings['taxonomy'];
		return $context->remember(
			'term_parent',
			$value,
			function () use ( $value, $taxonomy, $settings, $context ): ?int {
				if ( ctype_digit( $value ) && $this->exists( (int) $value, $settings ) ) {
					return (int) $value;
				}
				$tracked = $context->tracked( $value );
				if ( null !== $tracked && $this->exists( $tracked, $settings ) ) {
					return $tracked;
				}
				foreach ( array( 'name', 'slug' ) as $field ) {
					$term = get_term_by( $field, 'slug' === $field ? sanitize_title( $value ) : $value, $taxonomy );
					if ( $term instanceof \WP_Term ) {
						return (int) $term->term_id;
					}
				}
				return null;
			},
			false
		);
	}
}

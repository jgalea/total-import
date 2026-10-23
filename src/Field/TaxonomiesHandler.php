<?php

namespace Importright\Field;

use Importright\Runs\Context;
use Importright\Runs\Diff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Terms on posts and products:
 * {"taxonomies": {"post_tag": "{tags}",
 *                 "category": {"terms": "{cats}", "separator": ",", "hierarchy": ">", "create": "1", "mode": "replace"}}}
 *
 * A cell holds several terms split by the separator; "Parent > Child" walks
 * the hierarchy. Terms are matched by name or slug under their parent and
 * created when missing unless create is "0". Mode "append" adds to the
 * post's terms, "replace" sets them. On update, a genuinely empty cell clears
 * them in replace mode; a cell that names terms none of which resolve (create
 * off, or every insert failed) leaves them unchanged with a notice, so a typo
 * or a missing term never wipes a post's terms. Update rules may list the
 * taxonomies an update may change.
 */
final class TaxonomiesHandler implements FieldHandler, Previewable {

	public function key(): string {
		return 'taxonomies';
	}

	public function supports( string $target ): bool {
		return in_array( $target, array( 'post', 'product' ), true );
	}

	public function validate( array $config ) {
		$clean = array();
		foreach ( $config as $taxonomy => $settings ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				/* translators: %s: taxonomy name */
				return new WP_Error( 'importright_mapping', sprintf( __( 'Unknown taxonomy in the mapping: %s', 'total-import' ), $taxonomy ) );
			}
			if ( is_scalar( $settings ) ) {
				$settings = array( 'terms' => (string) $settings );
			}
			if ( ! is_array( $settings ) || ! is_scalar( $settings['terms'] ?? null ) ) {
				/* translators: %s: taxonomy name */
				return new WP_Error( 'importright_mapping', sprintf( __( 'The %s mapping needs a terms template.', 'total-import' ), $taxonomy ) );
			}
			$mode = (string) ( $settings['mode'] ?? 'replace' );
			if ( ! in_array( $mode, array( 'replace', 'append' ), true ) ) {
				return new WP_Error( 'importright_mapping', __( 'A taxonomy mode must be replace or append.', 'total-import' ) );
			}
			$hierarchical       = is_taxonomy_hierarchical( $taxonomy );
			$clean[ $taxonomy ] = array(
				'terms'     => (string) $settings['terms'],
				'separator' => (string) ( $settings['separator'] ?? ',' ),
				'hierarchy' => $hierarchical ? (string) ( $settings['hierarchy'] ?? '>' ) : '',
				'create'    => self::flag( $settings['create'] ?? true ),
				'mode'      => $mode,
			);
		}
		return $clean;
	}

	public function apply( string $target, int $object_id, array $values, bool $is_new, $rule, Context $context ) {
		$post_type = (string) get_post_type( $object_id );
		foreach ( $values as $taxonomy => $settings ) {
			$taxonomy = (string) $taxonomy;
			if ( ! $is_new && ( false === $rule || ( is_array( $rule ) && ! in_array( $taxonomy, $rule, true ) ) ) ) {
				continue;
			}
			if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				/* translators: 1: taxonomy, 2: post type */
				$context->notice( sprintf( __( 'The taxonomy %1$s isn\'t registered for %2$s, so its terms were skipped.', 'total-import' ), $taxonomy, $post_type ) );
				continue;
			}
			$settings = (array) $settings;
			$append   = 'append' === ( $settings['mode'] ?? 'replace' );
			$paths    = TermPath::parse( (string) ( $settings['terms'] ?? '' ), (string) ( $settings['separator'] ?? '' ), (string) ( $settings['hierarchy'] ?? '' ) );
			if ( array() === $paths && ( $is_new || $append ) ) {
				continue;
			}
			if ( ! self::can( 'assign_terms', $taxonomy, $context ) ) {
				continue;
			}

			$create = '0' !== ( $settings['create'] ?? '1' );
			$ids    = array();
			foreach ( $paths as $path ) {
				$id = self::resolve( $taxonomy, $path, $create, $context );
				if ( null !== $id ) {
					$ids[] = $id;
				}
			}
			if ( array() === $ids && array() !== $paths ) {
				/* translators: %s: taxonomy name */
				$context->notice( sprintf( __( 'None of the %s terms in this record could be used, so the existing terms were left unchanged.', 'total-import' ), $taxonomy ) );
				continue;
			}
			if ( array() === $ids && ! $append && 'category' === $taxonomy ) {
				// Same as wp_set_post_categories(): a post is never left without a category.
				$ids = array( (int) get_option( 'default_category' ) );
			}
			$set = wp_set_object_terms( $object_id, array_values( array_unique( $ids ) ), $taxonomy, $append );
			if ( is_wp_error( $set ) ) {
				$context->notice( $set->get_error_message() );
			}
		}
		return true;
	}

	/**
	 * Finds (and optionally creates) the term at the end of a path. Used by the term target for parents too.
	 *
	 * @param string[] $path
	 */
	public static function resolve( string $taxonomy, array $path, bool $create, Context $context ): ?int {
		$parent = 0;
		foreach ( $path as $name ) {
			$found = $context->remember(
				'term',
				$taxonomy . '|' . $parent . '|' . mb_strtolower( $name ),
				static function () use ( $taxonomy, $name, $parent, $create, $context ): ?int {
					$existing = term_exists( $name, $taxonomy, $parent );
					if ( is_array( $existing ) ) {
						return (int) $existing['term_id'];
					}
					if ( ! $create ) {
						/* translators: 1: term name, 2: taxonomy */
						$context->notice( sprintf( __( 'Term "%1$s" doesn\'t exist in %2$s and creating terms is off.', 'total-import' ), $name, $taxonomy ) );
						return null;
					}
					if ( ! self::can( 'edit_terms', $taxonomy, $context ) ) {
						return null;
					}
					$made = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );
					if ( is_wp_error( $made ) ) {
						$id = (int) $made->get_error_data( 'term_exists' );
						if ( $id > 0 ) {
							return $id;
						}
						$context->notice( $made->get_error_message() );
						return null;
					}
					$context->added( 'term', (int) $made['term_id'] );
					return (int) $made['term_id'];
				},
				false
			);
			if ( null === $found ) {
				return null;
			}
			$parent = $found;
		}
		return $parent > 0 ? $parent : null;
	}

	/**
	 * Whether the acting user holds a taxonomy capability (assign_terms, edit_terms), leaving a notice when not.
	 */
	private static function can( string $cap, string $taxonomy, Context $context ): bool {
		$tax = get_taxonomy( $taxonomy );
		if ( $tax && current_user_can( $tax->cap->{$cap} ) ) {
			return true;
		}
		$context->notice(
			'assign_terms' === $cap
				/* translators: %s: taxonomy */
				? sprintf( __( 'The user running the import isn\'t allowed to assign %s terms, so they were left unchanged.', 'total-import' ), $taxonomy )
				/* translators: %s: taxonomy */
				: sprintf( __( 'The user running the import isn\'t allowed to create %s terms, so missing ones were skipped.', 'total-import' ), $taxonomy )
		);
		return false;
	}

	/**
	 * @param mixed $value
	 */
	public static function flag( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true ) ? '1' : '0';
	}

	public function preview( string $target, ?int $object_id, array $values, bool $is_new, $rule, Context $context, Diff $diff ): void {
		if ( null !== $object_id ) {
			$post_type = (string) get_post_type( $object_id );
		} else {
			$post_type = 'product' === $target ? 'product' : (string) ( $context->settings['post_type'] ?? 'post' );
		}
		foreach ( $values as $taxonomy => $settings ) {
			$taxonomy = (string) $taxonomy;
			if ( ! $is_new && ( false === $rule || ( is_array( $rule ) && ! in_array( $taxonomy, $rule, true ) ) ) ) {
				continue;
			}
			if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				/* translators: 1: taxonomy, 2: post type */
				$context->notice( sprintf( __( 'The taxonomy %1$s isn\'t registered for %2$s, so its terms were skipped.', 'total-import' ), $taxonomy, $post_type ) );
				continue;
			}
			$settings = (array) $settings;
			$append   = 'append' === ( $settings['mode'] ?? 'replace' );
			$paths    = TermPath::parse( (string) ( $settings['terms'] ?? '' ), (string) ( $settings['separator'] ?? '' ), (string) ( $settings['hierarchy'] ?? '' ) );
			if ( array() === $paths && ( $is_new || $append ) ) {
				continue;
			}
			if ( ! self::can( 'assign_terms', $taxonomy, $context ) ) {
				continue;
			}
			$create = '0' !== ( $settings['create'] ?? '1' );
			$names  = array();
			foreach ( $paths as $path ) {
				$name = self::lookup( $taxonomy, $path, $create, $context );
				if ( null !== $name ) {
					$names[] = $name;
				}
			}
			if ( array() === $names && array() !== $paths ) {
				/* translators: %s: taxonomy name */
				$context->notice( sprintf( __( 'None of the %s terms in this record could be used, so the existing terms were left unchanged.', 'total-import' ), $taxonomy ) );
				continue;
			}
			$current = null === $object_id ? array() : wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'names' ) );
			$current = is_array( $current ) ? $current : array();
			if ( $append ) {
				$names = array_merge( $current, $names );
			} elseif ( array() === $names && 'category' === $taxonomy ) {
				$default = get_term( (int) get_option( 'default_category' ), 'category' );
				$names   = $default instanceof \WP_Term ? array( $default->name ) : array();
			}
			$names = array_values( array_unique( $names ) );
			sort( $names );
			sort( $current );
			$diff->add( 'taxonomies.' . $taxonomy, null === $object_id ? null : implode( ', ', $current ), implode( ', ', $names ) );
		}
	}

	/**
	 * Name of the term at the end of a path, without creating anything. New terms are marked "(new)".
	 *
	 * @param string[] $path
	 */
	private static function lookup( string $taxonomy, array $path, bool $create, Context $context ): ?string {
		$parent = 0;
		foreach ( $path as $name ) {
			$existing = term_exists( $name, $taxonomy, $parent );
			if ( ! is_array( $existing ) ) {
				if ( ! $create ) {
					/* translators: 1: term name, 2: taxonomy */
					$context->notice( sprintf( __( 'Term "%1$s" doesn\'t exist in %2$s and creating terms is off.', 'total-import' ), $name, $taxonomy ) );
					return null;
				}
				if ( ! self::can( 'edit_terms', $taxonomy, $context ) ) {
					return null;
				}
				/* translators: %s: term name, or a path such as "Parent > Child" */
				return sprintf( __( '%s (new)', 'total-import' ), implode( ' > ', $path ) );
			}
			$parent = (int) $existing['term_id'];
		}
		$term = get_term( $parent, $taxonomy );
		return $term instanceof \WP_Term ? $term->name : null;
	}
}

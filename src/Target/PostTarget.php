<?php

namespace Importright\Target;

use Importright\Runs\Context;
use Importright\Runs\Diff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Posts, pages and any registered post type, saved through wp_insert_post
 * and wp_update_post. Content passes through the usual kses filters unless
 * the user running the import has unfiltered_html, and Permissions applies
 * the editor's create, edit, publish and author checks.
 */
final class PostTarget implements Target, Previewable {

	private const COLUMNS = array(
		'title'          => 'post_title',
		'content'        => 'post_content',
		'excerpt'        => 'post_excerpt',
		'slug'           => 'post_name',
		'menu_order'     => 'menu_order',
		'comment_status' => 'comment_status',
		'ping_status'    => 'ping_status',
	);

	private const PREVIEW = array(
		'post_title'     => 'title',
		'post_content'   => 'content',
		'post_excerpt'   => 'excerpt',
		'post_name'      => 'slug',
		'post_status'    => 'status',
		'post_date'      => 'date',
		'post_author'    => 'author',
		'post_parent'    => 'parent',
		'menu_order'     => 'menu_order',
		'comment_status' => 'comment_status',
		'ping_status'    => 'ping_status',
	);

	public function key(): string {
		return 'post';
	}

	public function label(): string {
		return __( 'Posts, pages and custom post types', 'total-import' );
	}

	public function object_type(): string {
		return 'post';
	}

	public function fields(): array {
		return array(
			'title'          => __( 'Title', 'total-import' ),
			'content'        => __( 'Content', 'total-import' ),
			'excerpt'        => __( 'Excerpt', 'total-import' ),
			'slug'           => __( 'Slug', 'total-import' ),
			'status'         => __( 'Status', 'total-import' ),
			'date'           => __( 'Date', 'total-import' ),
			'author'         => __( 'Author (ID, login or email)', 'total-import' ),
			'parent'         => __( 'Parent (ID, slug or unique key)', 'total-import' ),
			'menu_order'     => __( 'Menu order', 'total-import' ),
			'comment_status' => __( 'Comments open', 'total-import' ),
			'ping_status'    => __( 'Pings open', 'total-import' ),
		);
	}

	public function match_strategies(): array {
		return array( 'id', 'slug', 'title' );
	}

	public function validate( array $settings ) {
		$post_type = sanitize_key( (string) ( $settings['post_type'] ?? 'post' ) );
		if ( ! post_type_exists( $post_type ) || in_array( $post_type, array( 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' ), true ) ) {
			/* translators: %s: post type */
			return new WP_Error( 'importright_target', sprintf( __( 'Posts can\'t be imported into the post type "%s".', 'total-import' ), $post_type ) );
		}
		$status = sanitize_key( (string) ( $settings['default_status'] ?? 'publish' ) );
		if ( ! in_array( $status, array_keys( get_post_stati() ), true ) ) {
			$status = 'publish';
		}
		return array(
			'post_type'      => $post_type,
			'default_status' => $status,
		);
	}

	public function prime( array $ids ): void {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( array() !== $ids ) {
			_prime_post_caches( $ids, false, false );
		}
	}

	public function exists( int $id, array $settings ): bool {
		$post = get_post( $id );
		return $post instanceof \WP_Post && $post->post_type === $settings['post_type'] && 'trash' !== $post->post_status;
	}

	public function find( string $strategy, array $values, string $unique_key, array $settings ): ?int {
		global $wpdb;

		$post_type = (string) $settings['post_type'];
		$id        = null;

		if ( 'id' === $strategy && ctype_digit( $unique_key ) ) {
			$id = (int) $unique_key;
		} elseif ( 'slug' === $strategy && '' !== (string) ( $values['slug'] ?? '' ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status <> 'trash' ORDER BY ID LIMIT 1", sanitize_title( (string) $values['slug'] ), $post_type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( 'title' === $strategy && '' !== (string) ( $values['title'] ?? '' ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s AND post_status <> 'trash' ORDER BY ID LIMIT 1", (string) $values['title'], $post_type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( 0 === strpos( $strategy, 'meta:' ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = %s AND p.post_status <> 'trash' ORDER BY p.ID LIMIT 1", substr( $strategy, 5 ), $unique_key, $post_type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return null !== $id && $id > 0 && $this->exists( $id, $settings ) ? $id : null;
	}

	public function save( ?int $id, array $fields, array $settings, Context $context ) {
		$denied = Permissions::post( $id, (string) $settings['post_type'] );
		if ( null !== $denied ) {
			return $denied;
		}
		$data = $this->data( $id, $fields, $settings, $context );

		if ( null === $id ) {
			$data['post_type'] = (string) $settings['post_type'];
			if ( ! isset( $data['post_status'] ) ) {
				$data['post_status'] = (string) Permissions::status( (string) $settings['post_type'], (string) $settings['default_status'], null, $context );
			}
			$result = wp_insert_post( wp_slash( $data ), true );
		} else {
			if ( array() === $data ) {
				return $id;
			}
			$data['ID'] = $id;
			$result     = wp_update_post( wp_slash( $data ), true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 0 === (int) $result ) {
			return new WP_Error( 'importright_save', __( 'WordPress did not save the post.', 'total-import' ) );
		}
		return (int) $result;
	}

	public function preview( ?int $id, array $fields, array $settings, Context $context, Diff $diff ) {
		$denied = Permissions::post( $id, (string) $settings['post_type'] );
		if ( null !== $denied ) {
			return $denied;
		}
		$data = $this->data( $id, $fields, $settings, $context );
		if ( null === $id && ! isset( $data['post_status'] ) ) {
			$data['post_status'] = (string) Permissions::status( (string) $settings['post_type'], (string) $settings['default_status'], null, $context );
		}
		$post = null === $id ? null : get_post( $id );
		foreach ( self::PREVIEW as $column => $field ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}
			$new = $data[ $column ];
			if ( 'post_name' === $column ) {
				$new = sanitize_title( (string) $new );
			}
			$diff->add( $field, $post instanceof \WP_Post ? $post->{$column} : null, $new );
		}
		return true;
	}

	/**
	 * The wp_insert_post / wp_update_post fields for a record, leaving out values it can't use (with a notice).
	 *
	 * @param array<string, string> $fields
	 * @param array<string, mixed>  $settings
	 * @return array<string, mixed>
	 */
	private function data( ?int $id, array $fields, array $settings, Context $context ): array {
		$data = array();

		foreach ( self::COLUMNS as $field => $column ) {
			if ( array_key_exists( $field, $fields ) ) {
				$data[ $column ] = (string) $fields[ $field ];
			}
		}
		if ( isset( $data['menu_order'] ) ) {
			$data['menu_order'] = (int) $data['menu_order'];
		}
		foreach ( array( 'comment_status', 'ping_status' ) as $column ) {
			if ( isset( $data[ $column ] ) ) {
				$data[ $column ] = self::open_or_closed( $data[ $column ] );
				if ( '' === $data[ $column ] ) {
					unset( $data[ $column ] );
				}
			}
		}

		if ( array_key_exists( 'status', $fields ) ) {
			$status = sanitize_key( (string) $fields['status'] );
			$status = '' === $status ? null : Permissions::status( (string) $settings['post_type'], $status, $id, $context );
			if ( null !== $status ) {
				$data['post_status'] = $status;
			}
		}

		if ( array_key_exists( 'date', $fields ) && '' !== trim( (string) $fields['date'] ) ) {
			$date = self::parse_date( (string) $fields['date'] );
			if ( null === $date ) {
				/* translators: %s: date text from the file */
				$context->notice( sprintf( __( 'Date "%s" could not be read and was ignored.', 'total-import' ), $fields['date'] ) );
			} else {
				$data['post_date']     = $date;
				$data['post_date_gmt'] = get_gmt_from_date( $date );
				$data['edit_date']     = true;
			}
		}

		if ( array_key_exists( 'author', $fields ) && '' !== trim( (string) $fields['author'] ) ) {
			$author = $this->author( trim( (string) $fields['author'] ), $context );
			if ( null === $author ) {
				/* translators: %s: author text from the file */
				$context->notice( sprintf( __( 'No user matches author "%s"; the author was left unchanged.', 'total-import' ), $fields['author'] ) );
			} elseif ( Permissions::author( (string) $settings['post_type'], $author, $context ) ) {
				$data['post_author'] = $author;
			}
		}

		if ( array_key_exists( 'parent', $fields ) ) {
			$parent = trim( (string) $fields['parent'] );
			if ( '' === $parent ) {
				$data['post_parent'] = 0;
			} else {
				$parent_id = $this->parent( $parent, $settings, $context );
				if ( null === $parent_id || $parent_id === $id ) {
					/* translators: %s: parent text from the file */
					$context->notice( sprintf( __( 'Parent "%s" was not found.', 'total-import' ), $parent ) );
				} else {
					$data['post_parent'] = $parent_id;
				}
			}
		}

		return $data;
	}

	/**
	 * Reads a date in the site's timezone. Returns local MySQL datetime, or null.
	 */
	public static function parse_date( string $value ): ?string {
		$value = trim( $value );
		if ( ctype_digit( $value ) && strlen( $value ) >= 9 ) {
			return wp_date( 'Y-m-d H:i:s', (int) $value );
		}
		try {
			$date = new \DateTimeImmutable( $value, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
		return $date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	}

	private static function open_or_closed( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( in_array( $value, array( 'open', '1', 'yes', 'true', 'on' ), true ) ) {
			return 'open';
		}
		if ( in_array( $value, array( 'closed', '0', 'no', 'false', 'off' ), true ) ) {
			return 'closed';
		}
		return '';
	}

	private function author( string $value, Context $context ): ?int {
		return $context->remember(
			'author',
			$value,
			static function () use ( $value ): ?int {
				if ( ctype_digit( $value ) ) {
					$user = get_user_by( 'id', (int) $value );
				} elseif ( is_email( $value ) ) {
					$user = get_user_by( 'email', $value );
				} else {
					$user = get_user_by( 'login', $value );
				}
				return $user instanceof \WP_User ? (int) $user->ID : null;
			}
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function parent( string $value, array $settings, Context $context ): ?int {
		$post_type = (string) $settings['post_type'];
		return $context->remember(
			'parent',
			$value,
			function () use ( $value, $post_type, $settings, $context ): ?int {
				if ( ctype_digit( $value ) && $this->exists( (int) $value, $settings ) ) {
					return (int) $value;
				}
				$tracked = $context->tracked( $value );
				if ( null !== $tracked ) {
					return $tracked;
				}
				$post = get_page_by_path( $value, OBJECT, $post_type );
				return $post instanceof \WP_Post ? (int) $post->ID : null;
			},
			false
		);
	}
}

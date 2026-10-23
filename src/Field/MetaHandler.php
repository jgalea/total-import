<?php

namespace Importright\Field;

use Importright\Registry;
use Importright\Runs\Context;
use Importright\Runs\Diff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Custom fields: {"meta": {"_sku": "{sku}", "colour": "{colour}"}}, written as
 * post, term or user meta depending on the target. Empty values are skipped
 * on create and written as empty on update. User meta that holds roles,
 * capabilities or sessions is refused, so meta can't get around the role guard.
 *
 * Protected (underscore) keys are written for importers with unfiltered_html.
 * For anyone else a key needs edit_{type}_meta on the object (core denies it
 * unless a plugin registers an auth callback), or must be one a target or
 * handler owns (OwnsMeta); other keys are skipped with a notice.
 */
final class MetaHandler implements FieldHandler, Previewable {

	private const META_TYPES = array(
		'post'    => 'post',
		'product' => 'post',
		'term'    => 'term',
		'user'    => 'user',
	);

	public function key(): string {
		return 'meta';
	}

	public function supports( string $target ): bool {
		return isset( self::META_TYPES[ $target ] );
	}

	public function validate( array $config ) {
		$clean = array();
		foreach ( $config as $meta_key => $template ) {
			$meta_key = trim( (string) $meta_key );
			if ( '' === $meta_key || ! is_scalar( $template ) ) {
				return new WP_Error( 'importright_mapping', __( 'Each custom field needs a key and a template.', 'total-import' ) );
			}
			$clean[ $meta_key ] = (string) $template;
		}
		return $clean;
	}

	public function apply( string $target, int $object_id, array $values, bool $is_new, $rule, Context $context ) {
		$type = self::META_TYPES[ $target ] ?? 'post';
		foreach ( $values as $meta_key => $value ) {
			$meta_key = (string) $meta_key;
			if ( ! $is_new && ( false === $rule || ( is_array( $rule ) && ! in_array( $meta_key, $rule, true ) ) ) ) {
				continue;
			}
			if ( $is_new && '' === $value ) {
				continue;
			}
			if ( 'user' === $type && self::is_protected_user_meta( $meta_key ) ) {
				/* translators: %s: meta key */
				$context->notice( sprintf( __( 'The user field "%s" controls roles or sessions and can\'t be imported.', 'total-import' ), $meta_key ) );
				continue;
			}
			if ( ! self::may_write( $type, $target, $object_id, $meta_key ) ) {
				$context->notice( self::protected_notice( $meta_key ) );
				continue;
			}
			update_metadata( $type, $object_id, wp_slash( $meta_key ), wp_slash( (string) $value ) );
		}
		return true;
	}

	/**
	 * Whether the acting user may write a protected meta key. A new object in a dry run has no ID yet,
	 * so only a registered auth callback can allow it there, as core would for an existing one.
	 */
	public static function may_write( string $type, string $target, ?int $object_id, string $meta_key ): bool {
		if ( ! is_protected_meta( $meta_key, $type ) || current_user_can( 'unfiltered_html' ) ) {
			return true;
		}
		if ( in_array( $meta_key, self::owned( $target ), true ) ) {
			return true;
		}
		if ( null === $object_id ) {
			return (bool) apply_filters( "auth_{$type}_meta_{$meta_key}", false, $meta_key, 0, get_current_user_id(), "edit_{$type}_meta", array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's meta auth filter.
		}
		return current_user_can( "edit_{$type}_meta", $object_id, $meta_key );
	}

	/**
	 * Protected keys the target and its handlers write on purpose.
	 *
	 * @return string[]
	 */
	private static function owned( string $target ): array {
		$owned = array();
		foreach ( array_merge( array( Registry::target( $target ) ), array_values( Registry::handlers_for( $target ) ) ) as $owner ) {
			if ( $owner instanceof OwnsMeta ) {
				$owned = array_merge( $owned, $owner->owned_meta( $target ) );
			}
		}
		return $owned;
	}

	private static function protected_notice( string $meta_key ): string {
		/* translators: %s: meta key */
		return sprintf( __( 'The custom field "%s" is protected, and the user running the import isn\'t allowed to edit it, so it was skipped.', 'total-import' ), $meta_key );
	}

	public static function is_protected_user_meta( string $meta_key ): bool {
		$meta_key = strtolower( $meta_key );
		foreach ( array( 'capabilities', 'user_level' ) as $suffix ) {
			if ( substr( $meta_key, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return in_array( $meta_key, array( 'session_tokens', 'primary_blog', 'source_domain', 'use_ssl' ), true );
	}

	public function preview( string $target, ?int $object_id, array $values, bool $is_new, $rule, Context $context, Diff $diff ): void {
		$type = self::META_TYPES[ $target ] ?? 'post';
		foreach ( $values as $meta_key => $value ) {
			$meta_key = (string) $meta_key;
			if ( ! $is_new && ( false === $rule || ( is_array( $rule ) && ! in_array( $meta_key, $rule, true ) ) ) ) {
				continue;
			}
			if ( $is_new && '' === $value ) {
				continue;
			}
			if ( 'user' === $type && self::is_protected_user_meta( $meta_key ) ) {
				/* translators: %s: meta key */
				$context->notice( sprintf( __( 'The user field "%s" controls roles or sessions and can\'t be imported.', 'total-import' ), $meta_key ) );
				continue;
			}
			if ( ! self::may_write( $type, $target, $object_id, $meta_key ) ) {
				$context->notice( self::protected_notice( $meta_key ) );
				continue;
			}
			$old = null;
			if ( null !== $object_id && metadata_exists( $type, $object_id, $meta_key ) ) {
				$old = maybe_serialize( get_metadata( $type, $object_id, $meta_key, true ) );
			}
			$diff->add( 'meta.' . $meta_key, $old, (string) $value );
		}
	}
}

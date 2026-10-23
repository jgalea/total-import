<?php

namespace Importright\Target;

use Importright\Runs\Context;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The per-object checks WordPress's own editors make, applied to the user a
 * run acts as. Plugin::capability() decides who may run imports at all; these
 * decide what that user may write, which matters once importright_capability
 * is lowered from manage_options.
 *
 * Posts and products: creating needs the type's create_posts, updating needs
 * edit_post on the object, the status must be one of the editor's (status()),
 * publishing needs publish_posts or the post goes in as pending, and giving a post to another
 * author needs edit_others_posts. Terms: creating needs the taxonomy's
 * edit_terms, updating needs edit_term on the term, assigning needs assign_terms.
 */
final class Permissions {

	private const PUBLISHED = array( 'publish', 'future', 'private' );

	public static function post( ?int $id, string $post_type ): ?WP_Error {
		$type = get_post_type_object( $post_type );
		if ( null === $type ) {
			/* translators: %s: post type */
			return new WP_Error( 'importright_permission', sprintf( __( 'The post type "%s" isn\'t registered.', 'total-import' ), $post_type ) );
		}
		if ( null === $id ) {
			if ( ! current_user_can( $type->cap->create_posts ) ) {
				/* translators: %s: post type label, e.g. "Pages" */
				return new WP_Error( 'importright_permission', sprintf( __( 'The user running the import isn\'t allowed to create %s.', 'total-import' ), $type->labels->name ) );
			}
		} elseif ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'importright_permission', __( 'The user running the import isn\'t allowed to edit this item.', 'total-import' ) );
		}
		return null;
	}

	/**
	 * The status a record may set, or null to leave an existing post's status alone.
	 *
	 * Only the editor's statuses are accepted (filter importright_post_statuses for custom ones);
	 * trash only on update and with delete_post. Anything else becomes draft on create and is
	 * ignored on update. A published status (publish, future, private) without publish_posts becomes pending.
	 */
	public static function status( string $post_type, string $status, ?int $id, Context $context ): ?string {
		if ( 'trash' === $status ) {
			if ( null !== $id && current_user_can( 'delete_post', $id ) ) {
				return 'trash';
			}
			$context->notice( null === $id ? __( 'A new item can\'t be created in the trash, so it was saved as a draft.', 'total-import' ) : __( 'The user running the import isn\'t allowed to trash this item, so its status was left unchanged.', 'total-import' ) );
			return null === $id ? 'draft' : null;
		}
		$allowed = (array) apply_filters( 'importright_post_statuses', array( 'draft', 'pending', 'publish', 'future', 'private' ), $post_type );
		if ( ! in_array( $status, $allowed, true ) ) {
			$context->notice(
				null === $id
					/* translators: %s: post status */
					? sprintf( __( 'The status "%s" can\'t be set by an import, so the item was saved as a draft.', 'total-import' ), $status )
					/* translators: %s: post status */
					: sprintf( __( 'The status "%s" can\'t be set by an import, so the status was left unchanged.', 'total-import' ), $status )
			);
			return null === $id ? 'draft' : null;
		}
		$type = get_post_type_object( $post_type );
		if ( null === $type || ! in_array( $status, self::PUBLISHED, true ) || current_user_can( $type->cap->publish_posts ) ) {
			return $status;
		}
		/* translators: %s: post status */
		$context->notice( sprintf( __( 'The user running the import isn\'t allowed to publish, so the status "%s" was saved as pending.', 'total-import' ), $status ) );
		return 'pending';
	}

	public static function author( string $post_type, int $author, Context $context ): bool {
		$type = get_post_type_object( $post_type );
		if ( get_current_user_id() === $author || ( null !== $type && current_user_can( $type->cap->edit_others_posts ) ) ) {
			return true;
		}
		$context->notice( __( 'The user running the import isn\'t allowed to assign items to other authors, so the author was left unchanged.', 'total-import' ) );
		return false;
	}

	public static function term( ?int $id, string $taxonomy ): ?WP_Error {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax instanceof \WP_Taxonomy ) {
			/* translators: %s: taxonomy */
			return new WP_Error( 'importright_permission', sprintf( __( 'The taxonomy "%s" isn\'t registered.', 'total-import' ), $taxonomy ) );
		}
		if ( null === $id ? ! current_user_can( $tax->cap->edit_terms ) : ! current_user_can( 'edit_term', $id ) ) {
			/* translators: %s: taxonomy label, e.g. "Categories" */
			return new WP_Error( 'importright_permission', sprintf( __( 'The user running the import isn\'t allowed to edit %s.', 'total-import' ), $tax->labels->name ) );
		}
		return null;
	}
}

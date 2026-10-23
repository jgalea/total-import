<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own tables, and usage checks over indexed columns.

namespace Importright\Runs;

use Importright\Database\Schema;
use Importright\Field\ImagesHandler;
use Importright\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Undoes a run from its journal. Updated objects get their old values back,
 * unless they changed after the run (then they're skipped and reported).
 * Objects the run created are removed when unchanged since: posts and
 * products to the trash, terms and users deleted (users only when they have
 * no content and haven't signed in). Attachments and terms created along the
 * way go last, and only when nothing else uses them.
 *
 * Entries are processed newest first in steps, so the AJAX driver and WP-CLI
 * can both drive a large rollback, and a step that dies is simply redone.
 */
final class Rollback {

	public const RUNNING = 'running';
	public const DONE    = 'done';
	public const PARTIAL = 'partial';
	public const EXPIRED = 'expired';

	public const RESTORED = 'restored';
	public const DELETED  = 'deleted';
	public const KEPT     = 'kept';
	public const SKIPPED  = 'skipped';
	public const GONE     = 'gone';
	public const FAILED   = 'failed';

	public const OUTCOMES = array( self::RESTORED, self::DELETED, self::KEPT, self::SKIPPED, self::GONE, self::FAILED );

	public const STEP_SIZE = 200;

	/**
	 * Objects removed (or that a preview would remove) and fields restored, so later usage checks can ignore them.
	 *
	 * @var array{post: array<int, true>, term: array<int, true>, meta: array<int, array<int, true>>, terms: array<int, array<string, true>>}
	 */
	private array $state = array(
		'post'  => array(),
		'term'  => array(),
		'meta'  => array(),
		'terms' => array(),
	);

	private bool $dry;

	private bool $loaded = false;

	/**
	 * @var string[]
	 */
	private array $gallery_keys;

	/**
	 * @param array<string, mixed> $run
	 */
	private function __construct( array $run, bool $dry ) {
		$this->dry          = $dry;
		$gallery            = (string) ( $run['definition']['mapping']['images']['gallery']['meta_key'] ?? '' );
		$this->gallery_keys = array_values( array_unique( array_filter( array( '_product_image_gallery', ImagesHandler::DEFAULT_GALLERY_META, $gallery ) ) ) );
	}

	/**
	 * Why a run can't be rolled back, or null when it can.
	 *
	 * @param array<string, mixed> $run
	 */
	public static function refuse( array $run, bool $starting = true ): ?WP_Error {
		if ( $run['dry_run'] ) {
			return new WP_Error( 'importright_rollback', __( 'A dry run changed nothing, so there is nothing to roll back.', 'total-import' ) );
		}
		if ( in_array( $run['status'], Runs::ACTIVE, true ) ) {
			return new WP_Error( 'importright_busy', __( 'This run hasn\'t finished. Cancel it before rolling it back.', 'total-import' ) );
		}
		$active = Runs::active( (int) $run['import_id'] );
		if ( null !== $active ) {
			/* translators: %d: run ID */
			return new WP_Error( 'importright_busy', sprintf( __( 'Run %d of this import is in progress. Wait for it to finish first.', 'total-import' ), $active['id'] ) );
		}
		if ( self::EXPIRED === $run['rollback_status'] ) {
			return new WP_Error( 'importright_rollback', __( 'The rollback data for this run has been cleaned up.', 'total-import' ) );
		}
		if ( $starting && self::DONE === $run['rollback_status'] ) {
			return new WP_Error( 'importright_rollback', __( 'This run has already been rolled back.', 'total-import' ) );
		}
		if ( 0 === Journal::count( (int) $run['id'] ) ) {
			return new WP_Error( 'importright_rollback', __( 'This run didn\'t change anything that can be rolled back.', 'total-import' ) );
		}
		return null;
	}

	/**
	 * What a rollback would do, without changing anything.
	 *
	 * @return array{counts: array<string, int>, items: array<int, array<string, mixed>>, total: int}|WP_Error
	 */
	public static function preview( int $run_id, int $max_items = 200 ) {
		$run = Runs::get( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'importright_missing', __( 'No run has that ID.', 'total-import' ) );
		}
		$refused = self::refuse( $run, false );
		if ( null !== $refused ) {
			return $refused;
		}

		$rollback = new self( $run, true );
		$counts   = array_fill_keys( self::OUTCOMES, 0 );
		$items    = array();
		$routine  = array();
		$total    = 0;
		foreach ( array( 0, 1 ) as $phase ) {
			$before = PHP_INT_MAX;
			do {
				$entries = self::entries( $run_id, $phase, $before, 500, false );
				$more    = 500 === count( $entries );
				foreach ( $entries as $entry ) {
					$before                = (int) $entry['id'];
					list( $status, $note ) = $rollback->undo( $run, $entry );
					++$counts[ $status ];
					++$total;
					// Items undo would leave alone come first, so a long run still lists every one of them.
					if ( in_array( $status, array( self::RESTORED, self::DELETED, self::GONE ), true ) ) {
						if ( count( $routine ) < $max_items ) {
							$routine[] = self::shape( $entry, $status, $note );
						}
					} elseif ( count( $items ) < $max_items ) {
						$items[] = self::shape( $entry, $status, $note );
					}
				}
			} while ( $more );
		}
		return array(
			'counts' => $counts,
			'items'  => array_slice( array_merge( $items, $routine ), 0, $max_items ),
			'total'  => $total,
		);
	}

	/**
	 * @return array<string, mixed>|WP_Error The run.
	 */
	public static function start( int $run_id ) {
		global $wpdb;
		$run = Runs::get( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'importright_missing', __( 'No run has that ID.', 'total-import' ) );
		}
		if ( self::RUNNING === $run['rollback_status'] ) {
			return $run;
		}
		if ( ! current_user_can( Plugin::capability() ) ) {
			return new WP_Error( 'importright_permission', __( 'Rollbacks need a user who can run imports. In WP-CLI, pass --user.', 'total-import' ) );
		}
		$refused = self::refuse( $run );
		if ( null !== $refused ) {
			return $refused;
		}
		// A second attempt retries what was skipped or failed before (someone may have undone their edit).
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = '', note = NULL WHERE run_id = %d AND status IN ('skipped','failed','kept')", Schema::changes(), $run_id ) );
		$fields = array(
			'rollback_status' => self::RUNNING,
			'rolled_back_by'  => get_current_user_id(),
		);
		Runs::save( $run_id, $fields );
		do_action( 'importright_rollback_started', $run_id );
		return array_merge( $run, $fields );
	}

	/**
	 * Undoes up to $limit entries.
	 *
	 * @return array<string, mixed>|WP_Error The run.
	 */
	public static function step( int $run_id, int $limit = self::STEP_SIZE ) {
		global $wpdb;

		$run = Runs::get( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'importright_missing', __( 'No run has that ID.', 'total-import' ) );
		}
		if ( self::RUNNING !== $run['rollback_status'] ) {
			return $run;
		}
		$token = Runs::claim( $run_id, Runner::lock_ttl() );
		if ( null === $token ) {
			return new WP_Error( 'importright_locked', __( 'Another process is working on this run.', 'total-import' ) );
		}

		$previous = null;
		try {
			$acting = Actor::become( (int) $run['rolled_back_by'] );
			if ( is_wp_error( $acting ) ) {
				// Stopped, not stuck: someone who can run imports can start the rollback again as themselves.
				Runs::save(
					$run_id,
					array(
						'rollback_status' => self::PARTIAL,
						'rollback'        => self::counts( $run_id ),
					)
				);
				return $acting;
			}
			$previous = $acting;
			$rollback = new self( $run, false );
			$entries  = self::entries( $run_id, 0, PHP_INT_MAX, $limit, true );
			if ( count( $entries ) < $limit ) {
				$entries = array_merge( $entries, self::entries( $run_id, 1, PHP_INT_MAX, $limit - count( $entries ), true ) );
			}
			$seen = count( $entries ) < $limit ? self::seen_entries( $run_id, $limit - count( $entries ) ) : array();
			if ( array() === $entries && array() === $seen ) {
				return self::finish( $run );
			}

			$wpdb->query( 'START TRANSACTION' );
			try {
				foreach ( $entries as $entry ) {
					if ( Journal::ADDED === $entry['action'] && ! $rollback->loaded ) {
						$rollback->load_deleted( $run_id );
					}
					list( $status, $note ) = $rollback->undo( $run, $entry );
					$wpdb->update(
						Schema::changes(),
						array(
							'status' => $status,
							'note'   => $note,
						),
						array( 'id' => (int) $entry['id'] )
					);
				}
				foreach ( $seen as $entry ) {
					$rollback->revert_record( $run, $entry );
					$wpdb->update( Schema::changes(), array( 'status' => self::RESTORED ), array( 'id' => (int) $entry['id'] ) );
				}
				Runs::save( $run_id, array( 'heartbeat' => time() ) );
				$wpdb->query( 'COMMIT' );
			} catch ( \Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				throw $e;
			}
			return Runs::get( $run_id ) ?? $run;
		} finally {
			if ( null !== $previous ) {
				Actor::restore( $previous );
			}
			Runs::release( $run_id, $token );
		}
	}

	/**
	 * Steps until done or $seconds pass.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run_for( int $run_id, float $seconds ) {
		$until = microtime( true ) + $seconds;
		do {
			$run = self::step( $run_id );
			if ( is_wp_error( $run ) || self::RUNNING !== $run['rollback_status'] ) {
				return $run;
			}
		} while ( microtime( true ) < $until );
		return $run;
	}

	/**
	 * @return array<string, int>
	 */
	public static function counts( int $run_id ): array {
		global $wpdb;
		$counts = array_fill_keys( array_merge( array( 'pending' ), self::OUTCOMES ), 0 );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE run_id = %d AND action <> %s GROUP BY status', Schema::changes(), $run_id, Journal::SEEN ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$counts[ '' === $row['status'] ? 'pending' : (string) $row['status'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Entries of a rollback, with what happened to each.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function report( int $run_id, string $status = '', int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		if ( '' === $status ) {
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d AND action <> %s ORDER BY id DESC LIMIT %d OFFSET %d', Schema::changes(), $run_id, Journal::SEEN, $per_page, $offset ), ARRAY_A );
			$total = Journal::count( $run_id );
		} else {
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d AND action <> %s AND status = %s ORDER BY id DESC LIMIT %d OFFSET %d', Schema::changes(), $run_id, Journal::SEEN, $status, $per_page, $offset ), ARRAY_A );
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d AND action <> %s AND status = %s', Schema::changes(), $run_id, Journal::SEEN, $status ) );
		}
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::shape( $row, '' === $row['status'] ? 'pending' : (string) $row['status'], (string) $row['note'] );
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * @param array<string, mixed> $run
	 * @return array<string, mixed>
	 */
	private static function finish( array $run ): array {
		$counts = self::counts( (int) $run['id'] );
		$fields = array(
			'rollback_status' => $counts[ self::SKIPPED ] + $counts[ self::FAILED ] > 0 ? self::PARTIAL : self::DONE,
			'rollback'        => $counts,
			'rolled_back_at'  => current_time( 'mysql', true ),
		);
		Runs::save( (int) $run['id'], $fields );
		$run = array_merge( $run, $fields );
		do_action( 'importright_rollback_finished', $run );
		return $run;
	}

	/**
	 * Pending entries of one phase (0: the records' own objects, 1: objects added along the way), newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function entries( int $run_id, int $phase, int $before_id, int $limit, bool $pending_only ): array {
		global $wpdb;
		$operator = 1 === $phase ? '=' : '<>';
		$status   = $pending_only ? " AND status = ''" : '';
		$rows     = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- operator is one of two fixed literals.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE run_id = %d AND action {$operator} %s AND action <> %s AND id < %d{$status} ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fragments chosen above.
				Schema::changes(),
				$run_id,
				Journal::ADDED,
				Journal::SEEN,
				$before_id,
				max( 1, $limit )
			),
			ARRAY_A
		);
		$entries  = array();
		foreach ( (array) $rows as $row ) {
			$data          = json_decode( (string) $row['data'], true );
			$row['data']   = is_array( $data ) ? $data : array();
			$row['id']     = (int) $row['id'];
			$row['object'] = (int) $row['object_id'];
			$entries[]     = $row;
		}
		return $entries;
	}

	/**
	 * Record map rows the run touched without a changed object, still waiting to be put back.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function seen_entries( int $run_id, int $limit ): array {
		global $wpdb;
		$rows    = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE run_id = %d AND action = %s AND status = '' ORDER BY id DESC LIMIT %d", Schema::changes(), $run_id, Journal::SEEN, max( 1, $limit ) ),
			ARRAY_A
		);
		$entries = array();
		foreach ( (array) $rows as $row ) {
			$data          = json_decode( (string) $row['data'], true );
			$row['data']   = is_array( $data ) ? $data : array();
			$row['id']     = (int) $row['id'];
			$row['object'] = (int) $row['object_id'];
			$entries[]     = $row;
		}
		return $entries;
	}

	private function load_deleted( int $run_id ): void {
		global $wpdb;
		$types = array_values( array_diff( Snapshot::post_types(), array( 'attachment' ) ) );
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM %i WHERE run_id = %d AND status = \'deleted\' AND object_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', array_merge( array( Schema::changes(), $run_id ), $types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
		foreach ( (array) $ids as $id ) {
			$this->state['post'][ (int) $id ] = true;
		}
		$this->loaded = true;
	}

	/**
	 * Decides (and unless this is a preview, does) what happens to one entry.
	 *
	 * @param array<string, mixed> $run
	 * @param array<string, mixed> $entry
	 * @return array{0: string, 1: string} Status and note.
	 */
	private function undo( array $run, array $entry ): array {
		$type = (string) $entry['object_type'];
		$id   = (int) $entry['object'];
		$data = (array) $entry['data'];

		try {
			$current = Snapshot::take( $type, $id );

			if ( Journal::UPDATED === $entry['action'] ) {
				$before = (array) ( $data['before'] ?? array() );
				if ( null === $current ) {
					return array( self::SKIPPED, __( 'It was deleted after this run.', 'total-import' ) );
				}
				if ( ! hash_equals( (string) ( $data['after'] ?? '' ), Snapshot::hash( $current, array_keys( $before ) ) ) ) {
					return array( self::SKIPPED, __( 'It was changed after this run, so it was left as it is.', 'total-import' ) );
				}
				if ( 'user' === $type && ! current_user_can( 'edit_users' ) ) {
					return array( self::SKIPPED, __( 'Restoring users needs a user who can edit users (in WP-CLI, pass --user).', 'total-import' ) );
				}
				if ( $this->dry ) {
					$this->note_restored( $id, $before );
				} else {
					Snapshot::restore( $type, $id, $before );
					$this->revert_record( $run, $entry );
				}
				return array( self::RESTORED, '' );
			}

			if ( null === $current || ( isset( $current['row.post_status'] ) && 'trash' === $current['row.post_status'] ) ) {
				if ( ! $this->dry && Journal::CREATED === $entry['action'] ) {
					$this->revert_record( $run, $entry );
				}
				return array( self::GONE, __( 'It had already been deleted.', 'total-import' ) );
			}
			$changed = ! hash_equals( (string) ( $data['after'] ?? '' ), Snapshot::hash( $current ) );
			$added   = Journal::ADDED === $entry['action'];
			if ( $changed ) {
				return array( $added ? self::KEPT : self::SKIPPED, __( 'It was changed after this run, so it was kept.', 'total-import' ) );
			}

			$in_use = $this->in_use( $type, $id );
			if ( '' !== $in_use ) {
				return array( $added ? self::KEPT : self::SKIPPED, $in_use );
			}
			if ( 'user' === $type ) {
				$refused = self::refuse_user( $id, $current );
				if ( '' !== $refused ) {
					return array( self::SKIPPED, $refused );
				}
			}

			if ( ! $this->dry ) {
				$removed = $this->remove( $type, $id, $current );
				if ( '' !== $removed ) {
					return array( self::FAILED, $removed );
				}
				if ( ! $added ) {
					$this->revert_record( $run, $entry );
				}
			}
			if ( in_array( $type, array_diff( Snapshot::post_types(), array( 'attachment' ) ), true ) ) {
				$this->state['post'][ $id ] = true;
			} elseif ( 'term' === $type ) {
				$this->state['term'][ $id ] = true;
			}
			return array( self::DELETED, '' );
		} catch ( \Throwable $e ) {
			return array( self::FAILED, $e->getMessage() );
		}
	}

	/**
	 * @param array<string, mixed> $before
	 */
	private function note_restored( int $id, array $before ): void {
		foreach ( array_keys( $before ) as $field ) {
			list( $kind, $name ) = explode( '.', (string) $field, 2 );
			if ( 'meta' === $kind ) {
				$this->state['meta'][ $id ][ (int) $name ] = true;
			} elseif ( 'terms' === $kind ) {
				$this->state['terms'][ $id ][ $name ] = true;
			}
		}
	}

	/**
	 * Why an object must stay because something outside this rollback uses it, or ''.
	 */
	private function in_use( string $type, int $id ): string {
		global $wpdb;

		if ( 'attachment' === $type ) {
			$placeholders = implode( ',', array_fill( 0, count( $this->gallery_keys ), '%s' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array.
					"SELECT post_id, meta_id FROM {$wpdb->postmeta} WHERE ( meta_key = '_thumbnail_id' AND meta_value = %s ) OR ( meta_key IN ({$placeholders}) AND FIND_IN_SET( %s, meta_value ) ) LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
					array_merge( array( (string) $id ), $this->gallery_keys, array( (string) $id ) )
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$post = (int) $row['post_id'];
				if ( isset( $this->state['post'][ $post ] ) || isset( $this->state['meta'][ $post ][ (int) $row['meta_id'] ] ) ) {
					continue;
				}
				/* translators: %d: post ID */
				return sprintf( __( 'Post %d still uses it.', 'total-import' ), $post );
			}
			return '';
		}

		if ( 'term' === $type ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tr.object_id, tt.taxonomy FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d LIMIT 200", $id ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$object = (int) $row['object_id'];
				if ( isset( $this->state['post'][ $object ] ) || isset( $this->state['terms'][ $object ][ (string) $row['taxonomy'] ] ) ) {
					continue;
				}
				/* translators: %d: post ID */
				return sprintf( __( 'Post %d still has this term.', 'total-import' ), $object );
			}
			$children = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE parent = %d", $id ) );
			foreach ( (array) $children as $child ) {
				if ( ! isset( $this->state['term'][ (int) $child ] ) ) {
					/* translators: %d: term ID */
					return sprintf( __( 'Term %d is a child of it.', 'total-import' ), (int) $child );
				}
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $current
	 */
	private static function refuse_user( int $id, array $current ): string {
		global $wpdb;
		if ( ! current_user_can( 'delete_users' ) ) {
			return __( 'Deleting users needs a user who can delete users (in WP-CLI, pass --user).', 'total-import' );
		}
		if ( get_current_user_id() === $id ) {
			return __( 'This is the user running the rollback.', 'total-import' );
		}
		$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d", $id ) );
		if ( $posts > 0 ) {
			return __( 'The user has content of their own now, so they were kept.', 'total-import' );
		}
		if ( '' !== (string) get_user_meta( $id, 'session_tokens', true ) ) {
			return __( 'The user has signed in since the import, so they were kept.', 'total-import' );
		}
		unset( $current );
		return '';
	}

	/**
	 * @param array<string, mixed> $current
	 * @return string An error message, or '' on success.
	 */
	private function remove( string $type, int $id, array $current ): string {
		if ( ! in_array( $type, array( 'post', 'product', 'attachment', 'term', 'user' ), true ) ) {
			$error = apply_filters( 'importright_rollback_remove', null, $type, $id, $current );
			if ( is_string( $error ) ) {
				return $error;
			}
		}
		if ( 'attachment' === $type ) {
			return false === wp_delete_attachment( $id, true ) ? __( 'WordPress could not delete the attachment.', 'total-import' ) : '';
		}
		if ( in_array( $type, Snapshot::post_types(), true ) ) {
			// Trashing goes through wp_update_post, which would otherwise leave a revision behind for every post.
			$revisions = has_action( 'post_updated', 'wp_save_post_revision' );
			if ( false !== $revisions ) {
				remove_action( 'post_updated', 'wp_save_post_revision', $revisions );
			}
			$trashed = wp_trash_post( $id );
			if ( false !== $revisions ) {
				add_action( 'post_updated', 'wp_save_post_revision', $revisions );
			}
			return false === $trashed || null === $trashed ? __( 'WordPress could not move the post to the trash.', 'total-import' ) : '';
		}
		if ( 'term' === $type ) {
			$deleted = wp_delete_term( $id, (string) ( $current['tt.taxonomy'] ?? '' ) );
			return true === $deleted ? '' : __( 'WordPress could not delete the term.', 'total-import' );
		}
		if ( 'user' === $type ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			if ( is_multisite() ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
				$blogs = get_blogs_of_user( $id );
				if ( count( $blogs ) > 1 ) {
					return true === remove_user_from_blog( $id, get_current_blog_id() ) ? '' : __( 'WordPress could not remove the user from this site.', 'total-import' );
				}
				return wpmu_delete_user( $id ) ? '' : __( 'WordPress could not delete the user.', 'total-import' );
			}
			return wp_delete_user( $id ) ? '' : __( 'WordPress could not delete the user.', 'total-import' );
		}
		return __( 'Unknown object type.', 'total-import' );
	}

	/**
	 * Puts the record map row back the way it was before the run.
	 *
	 * @param array<string, mixed> $run
	 * @param array<string, mixed> $entry
	 */
	private function revert_record( array $run, array $entry ): void {
		global $wpdb;
		$key = (string) $entry['unique_key'];
		if ( '' === $key ) {
			return;
		}
		$record = $entry['data']['record'] ?? null;
		if ( ! is_array( $record ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE import_id = %d AND unique_key = %s AND object_id = %d', Schema::records(), (int) $run['import_id'], $key, (int) $entry['object'] ) );
			return;
		}
		$values = array(
			'object_type'  => (string) $record['object_type'],
			'object_id'    => (int) $record['object_id'],
			'data_hash'    => (string) $record['data_hash'],
			'adopted'      => empty( $record['adopted'] ) ? 0 : 1,
			'first_run_id' => (int) ( $record['first_run_id'] ?? 0 ),
			'last_run_id'  => (int) ( $record['last_run_id'] ?? 0 ),
		);
		// Journals written before the timestamp was kept leave updated_at as it is.
		if ( ! empty( $record['updated_at'] ) ) {
			$values['updated_at'] = (string) $record['updated_at'];
		}
		$wpdb->update(
			Schema::records(),
			$values,
			array(
				'import_id'  => (int) $run['import_id'],
				'unique_key' => $key,
			)
		);
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function shape( array $entry, string $status, string $note ): array {
		$type  = (string) $entry['object_type'];
		$id    = (int) $entry['object_id'];
		$title = '';
		if ( in_array( $type, Snapshot::post_types(), true ) ) {
			$title = (string) get_the_title( $id );
		} elseif ( 'term' === $type ) {
			$term  = get_term( $id );
			$title = $term instanceof \WP_Term ? $term->name : '';
		} elseif ( 'user' === $type ) {
			$user  = get_userdata( $id );
			$title = false === $user ? '' : $user->user_login;
		}
		return array(
			'object_type' => $type,
			'object_id'   => $id,
			'title'       => $title,
			'action'      => (string) $entry['action'],
			'key'         => (string) $entry['unique_key'],
			'fields'      => (array) ( ( is_array( $entry['data'] ) ? $entry['data'] : (array) json_decode( (string) $entry['data'], true ) )['fields'] ?? array() ),
			'status'      => $status,
			'note'        => $note,
		);
	}
}

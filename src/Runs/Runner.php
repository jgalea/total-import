<?php

namespace Importright\Runs;

use Importright\Imports\Definition;
use Importright\Imports\Imports;
use Importright\Plugin;
use Importright\Reader\ReaderException;
use Importright\Source\Sources;
use Importright\Source\Storage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every step acts as the user who started the run (see Actor), so content is
 * filtered by kses unless that user has unfiltered_html. kses_init() is
 * called on every step because WP-CLI loads without the kses filters.
 *
 * Drives a run as a sequence of batches. Every step reads its state from the
 * runs table and writes it back, so any driver (WP-CLI, AJAX, Action
 * Scheduler) can take the next step, and a step that dies mid-batch is redone
 * from the last saved cursor.
 *
 * pending  -> first step fetches and unpacks the source -> running
 * running  -> each step imports one batch and saves the cursor and counters
 *          -> completed when the reader runs out, or, when a listener adds phases
 *          to importright_run_phases, one phase per step (state.phase) before completing
 * any active state -> failed (unreadable source, fatal error) or cancelled
 */
final class Runner {

	public const MAX_ERRORS = 100;
	public const LOCK_TTL   = 600;

	/**
	 * @param array{origin?: string, background?: bool, dry_run?: bool, batch_size?: int} $args
	 * @return int|WP_Error The run ID.
	 */
	public static function start( int $import_id, array $args = array() ) {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return new WP_Error( 'importright_permission', __( 'Runs need a user who can run imports. In WP-CLI, pass --user.', 'total-import' ) );
		}
		$import = Imports::get( $import_id );
		if ( null === $import ) {
			return new WP_Error( 'importright_missing', __( 'No import has that ID.', 'total-import' ) );
		}
		$active = Runs::active( $import_id );
		if ( null !== $active ) {
			/* translators: %d: run ID */
			return new WP_Error( 'importright_busy', sprintf( __( 'Run %d of this import hasn\'t finished. Resume or cancel it first.', 'total-import' ), $active['id'] ), array( 'run_id' => $active['id'] ) );
		}

		$rolling = Runs::rolling_back( $import_id );
		if ( $rolling > 0 ) {
			/* translators: %d: run ID */
			return new WP_Error( 'importright_busy', sprintf( __( 'Run %d of this import is being rolled back. Wait for it to finish first.', 'total-import' ), $rolling ), array( 'run_id' => $rolling ) );
		}

		$definition = $import['definition'];
		if ( isset( $args['batch_size'] ) && (int) $args['batch_size'] > 0 ) {
			$definition['options']['batch_size'] = min( Definition::MAX_BATCH_SIZE, (int) $args['batch_size'] );
		}

		$run_id = Runs::create(
			array(
				'import_id'  => $import_id,
				'origin'     => sanitize_key( (string) ( $args['origin'] ?? '' ) ),
				'background' => empty( $args['background'] ) ? 0 : 1,
				'dry_run'    => empty( $args['dry_run'] ) ? 0 : 1,
				'user_id'    => get_current_user_id(),
				'definition' => wp_json_encode( $definition ),
			)
		);
		if ( 0 === $run_id ) {
			return new WP_Error( 'importright_db', __( 'The run could not be created.', 'total-import' ) );
		}
		do_action( 'importright_run_created', $run_id, $import_id );
		return $run_id;
	}

	/**
	 * Takes one step. Pass the same Session variable on every call to keep the reader open between batches.
	 *
	 * @return array<string, mixed>|WP_Error The run after the step.
	 */
	public static function step( int $run_id, ?Session &$session = null ) {
		$run = Runs::get( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'importright_missing', __( 'No run has that ID.', 'total-import' ) );
		}
		if ( ! in_array( $run['status'], Runs::ACTIVE, true ) ) {
			return $run;
		}

		$token = Runs::claim( $run_id, self::lock_ttl() );
		if ( null === $token ) {
			return new WP_Error( 'importright_locked', __( 'Another process is working on this run.', 'total-import' ) );
		}

		$previous = null;
		try {
			$acting = Actor::become( (int) $run['user_id'] );
			if ( is_wp_error( $acting ) ) {
				return self::finish( $run, Runs::FAILED, $acting->get_error_message(), $session );
			}
			$previous = $acting;

			if ( Runs::PENDING === $run['status'] ) {
				$prepared = Sources::prepare( (array) ( $run['definition']['source'] ?? array() ), $run_id );
				if ( is_wp_error( $prepared ) ) {
					return self::finish( $run, Runs::FAILED, $prepared->get_error_message(), $session );
				}
				$fields = array(
					'status'      => Runs::RUNNING,
					'source_file' => $prepared['path'],
					'source_ext'  => $prepared['ext'],
					'started_at'  => current_time( 'mysql', true ),
					'heartbeat'   => time(),
				);
				Runs::save( $run_id, $fields );
				$run = array_merge( $run, $fields );
			}

			if ( isset( $run['state']['phase'] ) ) {
				if ( null !== $session ) {
					$session->close();
					$session = null;
				}
				return self::phase( $run, $session );
			}

			if ( null === $session || ! $session->continues( $run ) ) {
				if ( null !== $session ) {
					$session->close();
				}
				try {
					$session = new Session( $run );
				} catch ( ReaderException $e ) {
					$session = null;
					return self::finish( $run, Runs::FAILED, $e->getMessage(), $session );
				}
				if ( null === $run['total'] && null !== $session->reader->total() ) {
					$run['total'] = $session->reader->total();
					Runs::save( $run_id, array( 'total' => $run['total'] ) );
				}
			}

			$run = self::batch( $run, $session );
			if ( isset( $run['state']['phase'] ) ) {
				$session->close();
				$session = null;
			}
			return $run;
		} catch ( \Throwable $e ) {
			return self::finish( Runs::get( $run_id ) ?? $run, Runs::FAILED, $e->getMessage(), $session );
		} finally {
			if ( null !== $previous ) {
				Actor::restore( $previous );
			}
			Runs::release( $run_id, $token );
		}
	}

	/**
	 * Runs every remaining step, for drivers that can afford to block.
	 *
	 * @param callable|null $tick Called with the run after each step.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run( int $run_id, ?callable $tick = null ) {
		$session = null;
		do {
			$run = self::step( $run_id, $session );
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			if ( null !== $tick ) {
				$tick( $run );
			}
		} while ( in_array( $run['status'], Runs::ACTIVE, true ) );
		return $run;
	}

	/**
	 * Runs steps for up to $seconds in one request, keeping the reader open between them (AJAX and background drivers).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run_for( int $run_id, float $seconds ) {
		$session = null;
		$until   = microtime( true ) + $seconds;
		$memory  = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		do {
			$run = self::step( $run_id, $session );
			if ( is_wp_error( $run ) || ! in_array( $run['status'], Runs::ACTIVE, true ) ) {
				break;
			}
			if ( $memory > 0 && memory_get_usage() > $memory * 0.8 ) {
				break;
			}
		} while ( microtime( true ) < $until );
		if ( null !== $session ) {
			$session->close();
		}
		return $run;
	}

	public static function lock_ttl(): int {
		return max( 30, (int) apply_filters( 'importright_lock_ttl', self::LOCK_TTL ) );
	}

	public static function cancel( int $run_id ): bool {
		$run = Runs::get( $run_id );
		if ( null === $run || ! in_array( $run['status'], Runs::ACTIVE, true ) ) {
			return false;
		}
		$session = null;
		self::finish( $run, Runs::CANCELLED, __( 'Cancelled.', 'total-import' ), $session );
		return true;
	}

	/**
	 * Fraction done, when the reader can tell.
	 *
	 * @param array<string, mixed> $run
	 */
	public static function percent( array $run, ?Session $session = null ): ?float {
		if ( Runs::COMPLETED === $run['status'] ) {
			return 100.0;
		}
		if ( isset( $run['state']['phase'] ) ) {
			return null;
		}
		if ( null !== $session && null !== $session->reader->progress() ) {
			return round( $session->reader->progress() * 100, 1 );
		}
		if ( null !== $run['total'] && $run['total'] > 0 ) {
			return round( min( 1, $run['processed'] / $run['total'] ) * 100, 1 );
		}
		$offset = $run['state']['cursor']['offset'] ?? null;
		if ( is_int( $offset ) && $offset > 0 ) {
			$size = self::text_size( (string) $run['source_file'] );
			if ( $size > 0 ) {
				return round( min( 1, $offset / $size ) * 100, 1 );
			}
		}
		return null;
	}

	/**
	 * Size of the file a CSV cursor's byte offset points into: the UTF-8 copy of a UTF-16
	 * file when there is one next to it, else the file itself. 0 when unknown (a UTF-16
	 * file read through a temporary copy).
	 */
	private static function text_size( string $path ): int {
		if ( '' === $path ) {
			return 0;
		}
		if ( is_file( $path . '.utf8' ) ) {
			return (int) filesize( $path . '.utf8' );
		}
		if ( ! is_file( $path ) ) {
			return 0;
		}
		$head = (string) file_get_contents( $path, false, null, 0, 512 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file's first bytes.
		return false === strpos( $head, "\0" ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * @param array<string, mixed> $run
	 * @return array<string, mixed>
	 */
	private static function batch( array $run, Session $session ): array {
		global $wpdb;

		$definition = $session->definition;
		$use_tx     = (bool) apply_filters( 'importright_batch_transaction', true, $run );

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's flag for importers; skips pings and other per-post side effects.
		}
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		if ( $use_tx ) {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		try {
			$items  = $session->take( (int) ( $definition['options']['batch_size'] ?? Definition::DEFAULT_BATCH_SIZE ) );
			$result = Batch::process( $items, $session );
			// $keys maps unique key => action. PHP stores numeric-string keys ("123") as int array keys, so cast to string before hashing or comparing.
			do_action( 'importright_batch_processed', $run, $result['keys'], $result, $session );

			// Deferred term counts are written before the journal reads the objects back.
			wp_defer_term_counting( false );
			if ( null !== $session->journal ) {
				$session->journal->flush();
			}
			Log::add( (int) $run['id'], array_merge( $result['errors'], $result['previews'] ) );

			$fields = array(
				'state'     => array( 'cursor' => $session->cursor ),
				'heartbeat' => time(),
			);
			foreach ( Runs::COUNTERS as $counter ) {
				$run[ $counter ]   += $result['counts'][ $counter ];
				$fields[ $counter ] = $run[ $counter ];
			}
			if ( array() !== $result['errors'] && count( $run['errors'] ) < self::MAX_ERRORS ) {
				$run['errors']    = array_slice( array_merge( $run['errors'], $result['errors'] ), 0, self::MAX_ERRORS );
				$fields['errors'] = $run['errors'];
			}
			$phases = array();
			if ( $session->exhausted ) {
				$phases = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'importright_run_phases', array(), $run ) ), 'strlen' ) );
				if ( array() !== $phases ) {
					$fields['state'] += array(
						'phase'        => $phases[0],
						'phases'       => $phases,
						'phase_state'  => array(),
						'phase_counts' => array(),
					);
				}
			}
			Runs::save( (int) $run['id'], $fields );
			$run['state'] = $fields['state'];

			if ( $use_tx ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		} catch ( \Throwable $e ) {
			if ( $use_tx ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( null !== $session->journal ) {
				$session->journal->discard();
			}
			throw $e;
		} finally {
			wp_defer_term_counting( false );
			wp_defer_comment_counting( false );
			self::flush_caches();
		}

		if ( $session->exhausted && array() === $phases ) {
			return self::finish( $run, Runs::COMPLETED, '', $session );
		}
		return $run;
	}

	/**
	 * Takes one step of the run's current phase, in the same transaction a batch gets.
	 * A phase answers through its importright_run_phase_{name} filter; with no
	 * answer it's skipped and the run carries on.
	 *
	 * @param array<string, mixed> $run
	 * @return array<string, mixed>
	 */
	private static function phase( array $run, ?Session &$session ): array {
		global $wpdb;

		$name   = (string) $run['state']['phase'];
		$use_tx = (bool) apply_filters( 'importright_batch_transaction', true, $run );

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		if ( $use_tx ) {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		try {
			$result = apply_filters( "importright_run_phase_{$name}", null, $run, (array) ( $run['state']['phase_state'] ?? array() ) );
			$state  = $run['state'];

			if ( is_array( $result ) ) {
				$done                 = (bool) ( $result['done'] ?? true );
				$errors               = (array) ( $result['errors'] ?? array() );
				$entries              = array_merge( $errors, (array) ( $result['previews'] ?? array() ) );
				$state['phase_state'] = (array) ( $result['state'] ?? array() );
				foreach ( (array) ( $result['counts'] ?? array() ) as $counter => $amount ) {
					$state['phase_counts'][ $name ][ $counter ] = (int) ( $state['phase_counts'][ $name ][ $counter ] ?? 0 ) + (int) $amount;
				}
			} else {
				$done = true;
				/* translators: %s: name of a run phase */
				$message = sprintf( __( 'The "%s" step was skipped because the plugin that handles it isn\'t active.', 'total-import' ), $name );
				$errors  = array(
					array(
						'index'   => 0,
						'key'     => '',
						'level'   => Log::NOTICE,
						'message' => $message,
					),
				);
				$entries = $errors;
			}
			Log::add( (int) $run['id'], $entries );

			$next = null;
			if ( $done ) {
				$phases = (array) ( $state['phases'] ?? array() );
				$at     = array_search( $name, $phases, true );
				$next   = false === $at ? null : ( $phases[ $at + 1 ] ?? null );
				if ( null === $next ) {
					unset( $state['phase'], $state['phases'], $state['phase_state'] );
				} else {
					$state['phase']       = $next;
					$state['phase_state'] = array();
				}
			}

			$fields = array(
				'state'     => $state,
				'heartbeat' => time(),
			);
			if ( array() !== $errors && count( $run['errors'] ) < self::MAX_ERRORS ) {
				$run['errors']    = array_slice( array_merge( $run['errors'], array_values( $errors ) ), 0, self::MAX_ERRORS );
				$fields['errors'] = $run['errors'];
			}
			Runs::save( (int) $run['id'], $fields );
			$run['state'] = $state;

			if ( $use_tx ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		} catch ( \Throwable $e ) {
			if ( $use_tx ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			throw $e;
		} finally {
			wp_defer_term_counting( false );
			wp_defer_comment_counting( false );
			self::flush_caches();
		}

		if ( $done && null === $next ) {
			return self::finish( $run, Runs::COMPLETED, '', $session );
		}
		return $run;
	}

	/**
	 * @param array<string, mixed> $run
	 * @return array<string, mixed>
	 */
	private static function finish( array $run, string $status, string $message, ?Session &$session ): array {
		if ( null !== $session ) {
			$session->close();
			$session = null;
		}
		$fields = array(
			'status'      => $status,
			'message'     => $message,
			'finished_at' => current_time( 'mysql', true ),
		);
		Runs::save( (int) $run['id'], $fields );
		Storage::clean_run( (int) $run['id'] );

		$run = array_merge( $run, $fields );
		do_action( 'importright_run_finished', $run );
		return $run;
	}

	/**
	 * Keeps memory flat over long runs: the non-persistent object cache would otherwise hold every post written.
	 */
	private static function flush_caches(): void {
		global $wpdb;
		if ( function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		} elseif ( ! wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$wpdb->queries = array();
		}
	}
}

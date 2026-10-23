<?php

namespace Importright\Runs;

use Importright\Field\Previewable as PreviewableField;
use Importright\Target\NormalizesKey;
use Importright\Target\Previewable as PreviewableTarget;

defined( 'ABSPATH' ) || exit;

/**
 * Imports one batch of records: render, match against the record map in one
 * query, decide, write, track. A dry run stops before writing and instead
 * asks the target and handlers what they would change.
 */
final class Batch {

	/**
	 * @param array<int, array<string, string>> $items Records keyed by index.
	 * @return array{counts: array<string, int>, keys: array<string|int, string>, errors: array<int, array{index: int, key: string, level: string, message: string}>, previews: array<int, array<string, mixed>>}
	 */
	public static function process( array $items, Session $session ): array {
		$definition = $session->definition;
		$settings   = (array) $definition['target'];
		$matching   = (array) $definition['matching'];
		$update     = (array) ( $definition['update'] ?? array() );
		$skip_same  = (bool) ( $definition['options']['skip_unchanged'] ?? true );
		$target     = $session->target;
		$context    = $session->context;
		$handlers   = $session->handlers;
		$journal    = $session->journal;
		$dry_run    = $context->dry_run;

		$counts   = array_fill_keys( Runs::COUNTERS, 0 );
		$errors   = array();
		$previews = array();
		$outcomes = array();

		$rendered = array();
		foreach ( $items as $index => $record ) {
			$key = $session->mapper->key( $record );
			if ( $target instanceof NormalizesKey ) {
				$key = $target->normalize_key( $key );
			}
			$rendered[ $index ] = array(
				'values' => $session->mapper->render( $record ),
				'key'    => $key,
			);
		}

		$keys    = array_filter( array_column( $rendered, 'key' ), 'strlen' );
		$tracked = Tracker::lookup( $context->import_id, $keys );
		$target->prime( array_column( $tracked, 'object_id' ) );

		$touched = array();
		foreach ( $rendered as $index => $item ) {
			++$counts['processed'];
			$context->index = $index;
			$key            = $item['key'];
			$values         = $item['values'];
			$preview        = null;

			try {
				if ( '' === $key ) {
					++$counts['failed'];
					$message  = __( 'The unique key is empty for this record, so it was skipped.', 'total-import' );
					$errors[] = self::error( $index, '', 'error', $message );
					$preview  = self::preview_row( $index, '', 'fail', null, array(), array( $message ) );
					continue;
				}

				$row      = $tracked[ $key ] ?? null;
				$existing = null;
				$adopted  = false;
				if ( null !== $row && $row['object_type'] === $target->object_type() && $target->exists( $row['object_id'], $settings ) ) {
					$existing = $row['object_id'];
				}
				if ( null === $existing && '' !== (string) ( $matching['match_existing'] ?? '' ) ) {
					$existing = $target->find( (string) $matching['match_existing'], $values, $key, $settings );
					$adopted  = null !== $existing;
				}

				$hash        = Decision::hash( $values, $update, array_keys( $handlers ) );
				$stored_hash = null !== $row && $existing === $row['object_id'] ? $row['data_hash'] : null;
				$action      = Decision::decide( (string) ( $matching['mode'] ?? 'create_update' ), $existing, $stored_hash, $hash, $skip_same );

				if ( Decision::SKIP === $action ) {
					++$counts['skipped'];
					$outcomes[ $key ] = Decision::SKIP;
					$preview          = self::preview_row( $index, $key, 'skip', $existing );
					continue;
				}
				if ( Decision::UNCHANGED === $action ) {
					++$counts['unchanged'];
					$outcomes[ $key ] = Decision::UNCHANGED;
					$touched[]        = $row['id'];
					if ( null !== $journal ) {
						$journal->record_seen( $key, $row );
					}
					$preview = self::preview_row( $index, $key, 'unchanged', $existing );
					continue;
				}
				$is_new = Decision::CREATE === $action;

				list( $core, $sections ) = Decision::split( $values, $update, array_keys( $handlers ), $is_new );

				if ( $dry_run ) {
					$diff  = new Diff();
					$check = self::preview( $session, $is_new ? null : $existing, $core, $sections, $is_new, $diff );
					if ( is_wp_error( $check ) ) {
						++$counts['failed'];
						$outcomes[ $key ] = 'fail';
						$errors[]         = self::error( $index, $key, 'error', $check->get_error_message() );
						$preview          = self::preview_row( $index, $key, 'fail', $existing, array(), array( $check->get_error_message() ) );
						continue;
					}
					++$counts[ $is_new ? 'created' : 'updated' ];
					$outcomes[ $key ] = $action;
					$changes          = $diff->items();
					if ( $is_new ) {
						// An empty value is never written to a new object.
						$changes = array_values(
							array_filter(
								$changes,
								static function ( array $change ): bool {
									return '' !== $change['new'];
								}
							)
						);
					}
					$preview = self::preview_row( $index, $key, $is_new ? 'create' : 'update', $existing, $changes, array(), $context->take_downloads() );
					continue;
				}

				if ( null !== $journal && ! $is_new ) {
					$journal->before_update( $target->object_type(), (int) $existing, $key, $row );
				}

				$object_id = $target->save( $is_new ? null : $existing, $core, $settings, $context );
				if ( is_wp_error( $object_id ) ) {
					++$counts['failed'];
					$outcomes[ $key ] = 'fail';
					$errors[]         = self::error( $index, $key, 'error', $object_id->get_error_message() );
					continue;
				}
				if ( null !== $journal && $is_new ) {
					$journal->created( $target->object_type(), $object_id, $key, $row );
				}

				try {
					foreach ( $sections as $section => $part ) {
						$applied = $handlers[ $section ]->apply( $target->key(), $object_id, $part['values'], $is_new, $part['rule'], $context );
						if ( is_wp_error( $applied ) ) {
							$context->notice( $applied->get_error_message() );
						}
					}
				} finally {
					foreach ( $context->take_added() as $added ) {
						if ( null !== $journal ) {
							$journal->created( $added[0], $added[1], '', null, true );
						}
					}
				}

				if ( null !== $journal && null !== $row ) {
					$journal->record_seen( $key, $row );
				}
				Tracker::save( $context->import_id, $key, $target->object_type(), $object_id, $hash, $context->run_id, $adopted );
				$tracked[ $key ] = array(
					'id'           => 0,
					'object_type'  => $target->object_type(),
					'object_id'    => $object_id,
					'data_hash'    => $hash,
					'adopted'      => null !== $row && $row['object_id'] === $object_id ? ! empty( $row['adopted'] ) : $adopted,
					'first_run_id' => null === $row ? $context->run_id : $row['first_run_id'],
					'last_run_id'  => $context->run_id,
				);
				++$counts[ $is_new ? 'created' : 'updated' ];
				$outcomes[ $key ] = $action;
			} catch ( \Throwable $e ) {
				++$counts['failed'];
				if ( '' !== $key ) {
					$outcomes[ $key ] = 'fail';
				}
				$errors[] = self::error( $index, $key, 'error', $e->getMessage() );
				if ( $dry_run ) {
					$preview = self::preview_row( $index, $key, 'fail', null, array(), array( $e->getMessage() ) );
				}
			} finally {
				$notices = $context->take_notices();
				foreach ( $notices as $notice ) {
					$errors[] = self::error( $index, $key, 'notice', $notice );
				}
				if ( $dry_run && null !== $preview && $context->preview_room > 0 ) {
					--$context->preview_room;
					$preview['data']['messages'] = array_merge( $preview['data']['messages'], $notices );
					$previews[]                  = $preview;
				}
			}
		}

		if ( ! $dry_run && array() !== $touched ) {
			Tracker::touch( $touched, $context->run_id );
		}

		return array(
			'counts'   => $counts,
			'keys'     => $outcomes,
			'errors'   => $errors,
			'previews' => $previews,
		);
	}

	/**
	 * Asks the target and handlers what they'd change. Nothing is written.
	 *
	 * @param array<string, string>                                         $core
	 * @param array<string, array{values: array<mixed>, rule: bool|string[]}> $sections
	 * @return true|\WP_Error
	 */
	private static function preview( Session $session, ?int $id, array $core, array $sections, bool $is_new, Diff $diff ) {
		$target  = $session->target;
		$context = $session->context;
		if ( $target instanceof PreviewableTarget ) {
			$check = $target->preview( $id, $core, (array) $session->definition['target'], $context, $diff );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		} else {
			foreach ( $core as $field => $value ) {
				$diff->add( $field, null, $value );
			}
		}
		foreach ( $sections as $section => $part ) {
			$handler = $session->handlers[ $section ];
			if ( $handler instanceof PreviewableField ) {
				$handler->preview( $target->key(), $id, $part['values'], $is_new, $part['rule'], $context, $diff );
			}
		}
		return true;
	}

	/**
	 * @param array<int, array{field: string, old: string|null, new: string|null}> $changes
	 * @param string[]                                                            $messages
	 * @param string[]                                                            $downloads
	 * @return array<string, mixed>
	 */
	private static function preview_row( int $index, string $key, string $action, ?int $object_id, array $changes = array(), array $messages = array(), array $downloads = array() ): array {
		return array(
			'index'     => $index,
			'key'       => $key,
			'level'     => Log::PREVIEW,
			'action'    => $action,
			'object_id' => (int) $object_id,
			'data'      => array(
				'changes'   => $changes,
				'downloads' => $downloads,
				'messages'  => $messages,
			),
		);
	}

	/**
	 * @return array{index: int, key: string, level: string, message: string}
	 */
	private static function error( int $index, string $key, string $level, string $message ): array {
		return array(
			'index'   => $index,
			'key'     => $key,
			'level'   => $level,
			'message' => $message,
		);
	}
}

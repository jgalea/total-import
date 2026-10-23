<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table.

namespace Importright\Runs;

use Importright\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers what a run changed so it can be undone. Before an update the
 * object's state is snapshotted; when the batch ends (after deferred term
 * counts are written, inside the batch transaction) the state is read again
 * and only the fields that differ are stored with their old values, plus a
 * fingerprint of the new values so a rollback can tell whether anyone has
 * edited the object since. Objects the run created are stored with a
 * fingerprint of their whole state.
 *
 * Actions: "created" (the record's object), "updated", and "added" for
 * objects created along the way (downloaded attachments, new terms), which a
 * rollback removes last and only when nothing else uses them.
 *
 * "seen" entries keep the record map row as it was before the run touched it,
 * for rows the run only re-stamped or whose object didn't change. They aren't
 * changes: counts and reports leave them out, and a rollback puts the rows back.
 */
final class Journal {

	public const CREATED = 'created';
	public const UPDATED = 'updated';
	public const ADDED   = 'added';
	public const SEEN    = 'seen';

	private int $run_id;
	private int $import_id;

	/**
	 * @var array<string, array{type: string, id: int, action: string, key: string, before: array<string, mixed>|null, record: array<string, mixed>|null}>
	 */
	private array $pending = array();

	/**
	 * @var array<string, array{key: string, record: array<string, mixed>}>
	 */
	private array $seen = array();

	public function __construct( int $run_id, int $import_id ) {
		$this->run_id    = $run_id;
		$this->import_id = $import_id;
	}

	public static function enabled( int $import_id ): bool {
		return (bool) apply_filters( 'importright_keep_rollback', true, $import_id );
	}

	/**
	 * Call before updating an object. Only the first call per object in a batch counts.
	 *
	 * @param array<string, mixed>|null $record The record map row for the key before this run touched it.
	 */
	public function before_update( string $type, int $id, string $key, ?array $record ): void {
		$slot = $type . ':' . $id;
		if ( isset( $this->pending[ $slot ] ) ) {
			return;
		}
		$this->pending[ $slot ] = array(
			'type'   => $type,
			'id'     => $id,
			'action' => self::UPDATED,
			'key'    => $key,
			'before' => Snapshot::take( $type, $id ) ?? array(),
			'record' => $record,
		);
	}

	/**
	 * @param array<string, mixed>|null $record
	 */
	public function created( string $type, int $id, string $key, ?array $record, bool $added = false ): void {
		$slot = $type . ':' . $id;
		if ( isset( $this->pending[ $slot ] ) ) {
			return;
		}
		$this->pending[ $slot ] = array(
			'type'   => $type,
			'id'     => $id,
			'action' => $added ? self::ADDED : self::CREATED,
			'key'    => $key,
			'before' => null,
			'record' => $record,
		);
	}

	/**
	 * Call before the run changes a record map row. Rows created in this run have no earlier state to keep.
	 *
	 * @param array<string, mixed> $record The row as it was before the run.
	 */
	public function record_seen( string $key, array $record ): void {
		if ( empty( $record['id'] ) ) {
			return;
		}
		$slot = (string) $record['id'];
		if ( ! isset( $this->seen[ $slot ] ) ) {
			$this->seen[ $slot ] = array(
				'key'    => $key,
				'record' => $record,
			);
		}
	}

	/**
	 * Stores the batch's entries. Run inside the batch transaction.
	 */
	public function flush(): void {
		global $wpdb;
		foreach ( $this->seen as $id => $seen ) {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (run_id, import_id, object_type, object_id, action, unique_key, data) VALUES (%d, %d, %s, %d, %s, %s, %s)',
					Schema::changes(),
					$this->run_id,
					$this->import_id,
					'record',
					(int) $id,
					self::SEEN,
					Tracker::storage_key( $seen['key'] ),
					(string) wp_json_encode( array( 'record' => $seen['record'] ) )
				)
			);
		}
		$this->seen = array();
		foreach ( $this->pending as $entry ) {
			$after = Snapshot::take( $entry['type'], $entry['id'] );
			if ( null === $after ) {
				continue;
			}
			if ( null === $entry['before'] ) {
				$data = array(
					'after'  => Snapshot::hash( $after ),
					'record' => $entry['record'],
				);
			} else {
				$changed = Snapshot::diff( $entry['before'], $after );
				if ( array() === $changed ) {
					continue;
				}
				$data = array(
					'before' => $changed,
					'after'  => Snapshot::hash( $after, array_keys( $changed ) ),
					'record' => $entry['record'],
					'fields' => self::names( $changed, $after ),
				);
			}
			$this->store( $entry, $data, $after );
		}
		$this->pending = array();
	}

	public function discard(): void {
		$this->pending = array();
		$this->seen    = array();
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $after
	 */
	private function store( array $entry, array $data, array $after ): void {
		global $wpdb;

		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (run_id, import_id, object_type, object_id, action, unique_key, data) VALUES (%d, %d, %s, %d, %s, %s, %s)',
				Schema::changes(),
				$this->run_id,
				$this->import_id,
				$entry['type'],
				$entry['id'],
				$entry['action'],
				Tracker::storage_key( $entry['key'] ),
				(string) wp_json_encode( $data )
			)
		);
		if ( 1 === $inserted ) {
			return;
		}

		// An earlier batch of this run already touched the object: keep its first "before" and refresh the fingerprint.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, action, data FROM %i WHERE run_id = %d AND object_type = %s AND object_id = %d', Schema::changes(), $this->run_id, $entry['type'], $entry['id'] ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return;
		}
		$old = json_decode( (string) $row['data'], true );
		$old = is_array( $old ) ? $old : array();
		if ( self::UPDATED === $row['action'] ) {
			$before = (array) ( $old['before'] ?? array() );
			foreach ( (array) ( $data['before'] ?? array() ) as $field => $value ) {
				if ( ! array_key_exists( $field, $before ) ) {
					$before[ $field ] = $value;
				}
			}
			$old['before'] = $before;
			$old['after']  = Snapshot::hash( $after, array_keys( $before ) );
			$old['fields'] = array_values( array_unique( array_merge( (array) ( $old['fields'] ?? array() ), (array) ( $data['fields'] ?? array() ) ) ) );
		} else {
			$old['after'] = Snapshot::hash( $after );
		}
		$wpdb->update( Schema::changes(), array( 'data' => (string) wp_json_encode( $old ) ), array( 'id' => (int) $row['id'] ) );
	}

	/**
	 * Readable names of changed fields for reports: post_title, meta:_price, terms:product_cat.
	 *
	 * @param array<string, mixed> $changed
	 * @param array<string, mixed> $after
	 * @return string[]
	 */
	public static function names( array $changed, array $after ): array {
		$names = array();
		foreach ( $changed as $field => $old ) {
			list( $kind, $name ) = explode( '.', (string) $field, 2 );
			if ( 'meta' === $kind ) {
				$row     = is_array( $old ) ? $old : ( $after[ $field ] ?? array( '' ) );
				$names[] = 'meta:' . (string) $row[0];
			} elseif ( 'terms' === $kind ) {
				$names[] = 'terms:' . $name;
			} else {
				$names[] = $name;
			}
		}
		return array_values( array_unique( $names ) );
	}

	public static function count( int $run_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d AND action <> %s', Schema::changes(), $run_id, self::SEEN ) );
	}
}

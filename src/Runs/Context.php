<?php

namespace Importright\Runs;

defined( 'ABSPATH' ) || exit;

/**
 * What targets and field handlers may know about the run they're in, plus a
 * per-run cache and a place to leave notices about a record.
 */
final class Context {

	public int $run_id;
	public int $import_id;
	public bool $dry_run;

	/**
	 * Index of the record being processed.
	 */
	public int $index = 0;

	/**
	 * Detailed preview rows a dry run may still store.
	 */
	public int $preview_room = 0;

	/**
	 * The definition's target section, for handlers that need the post type.
	 *
	 * @var array<string, mixed>
	 */
	public array $settings = array();

	/**
	 * Objects created along the way for the current record (attachments, terms): [type, id].
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private array $added = array();

	/**
	 * URLs a dry run would have downloaded for the current record.
	 *
	 * @var string[]
	 */
	private array $downloads = array();

	/**
	 * @var string[]
	 */
	private array $notices = array();

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $cache = array();

	public function __construct( int $run_id, int $import_id, bool $dry_run ) {
		$this->run_id    = $run_id;
		$this->import_id = $import_id;
		$this->dry_run   = $dry_run;
	}

	public function notice( string $message ): void {
		$this->notices[] = $message;
	}

	/**
	 * Returns and clears the notices left for the current record.
	 *
	 * @return string[]
	 */
	public function take_notices(): array {
		$notices       = $this->notices;
		$this->notices = array();
		return $notices;
	}

	public function added( string $type, int $id ): void {
		$this->added[] = array( $type, $id );
	}

	/**
	 * @return array<int, array{0: string, 1: int}>
	 */
	public function take_added(): array {
		$added       = $this->added;
		$this->added = array();
		return $added;
	}

	public function would_download( string $url ): void {
		$this->downloads[] = $url;
	}

	/**
	 * @return string[]
	 */
	public function take_downloads(): array {
		$downloads       = array_values( array_unique( $this->downloads ) );
		$this->downloads = array();
		return $downloads;
	}

	/**
	 * Memoizes a lookup for the rest of the run (author logins, parent slugs).
	 * Misses are only remembered when $remember_null is set, since a later
	 * record may create what an earlier one looked for.
	 *
	 * @return mixed
	 */
	public function remember( string $group, string $key, callable $compute, bool $remember_null = true ) {
		if ( isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] ) ) {
			return $this->cache[ $group ][ $key ];
		}
		$value = $compute();
		if ( null !== $value || $remember_null ) {
			if ( isset( $this->cache[ $group ] ) && count( $this->cache[ $group ] ) >= 5000 ) {
				$this->cache[ $group ] = array();
			}
			$this->cache[ $group ][ $key ] = $value;
		}
		return $value;
	}

	/**
	 * Object this import created for a unique key, used to resolve parents.
	 */
	public function tracked( string $unique_key ): ?int {
		$row = Tracker::lookup( $this->import_id, array( $unique_key ) )[ $unique_key ] ?? null;
		return null === $row ? null : (int) $row['object_id'];
	}
}

<?php

namespace Importright\Runs;

use Importright\Field\FieldHandler;
use Importright\Mapping\Mapper;
use Importright\Reader\Formats;
use Importright\Reader\JsonReader;
use Importright\Reader\Reader;
use Importright\Registry;
use Importright\Source\Storage;
use Importright\Target\Target;

defined( 'ABSPATH' ) || exit;

/**
 * The open reader and compiled mapping for a run. A driver that runs many
 * batches in one process (WP-CLI) keeps the session between steps, so the
 * reader carries on instead of seeking from the top; a driver that runs one
 * batch per request (AJAX, Action Scheduler) gets a fresh session each time.
 */
final class Session {

	public const PREVIEW_ROWS = 200;

	public int $run_id;

	/**
	 * @var array<string, mixed>
	 */
	public array $definition;

	public Reader $reader;
	public Mapper $mapper;
	public Target $target;
	public Context $context;

	/**
	 * Null for dry runs, or when rollback data is switched off.
	 */
	public ?Journal $journal;

	/**
	 * @var array<string, FieldHandler>
	 */
	public array $handlers;

	/**
	 * Position after the last record handed out.
	 *
	 * @var array<string, int|string>
	 */
	public array $cursor;

	public bool $exhausted = false;

	private ?\Generator $records = null;
	private bool $advance        = false;

	/**
	 * @param array<string, mixed> $run
	 * @throws \Importright\Reader\ReaderException When the file can't be read.
	 */
	public function __construct( array $run ) {
		$definition = $run['definition'];
		$target     = Registry::target( (string) ( $definition['target']['type'] ?? '' ) );
		if ( null === $target ) {
			throw new \Importright\Reader\ReaderException( __( 'The import\'s target type is no longer available.', 'total-import' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where shown.
		}

		$path    = (string) $run['source_file'];
		$options = (array) $definition['format'];

		$options['max_json_bytes']   = (int) apply_filters( 'importright_max_json_bytes', JsonReader::DEFAULT_MAX_BYTES );
		$options['max_record_bytes'] = (int) apply_filters( 'importright_max_record_bytes', Reader::DEFAULT_MAX_RECORD_BYTES );
		$options['cache_dir']        = Storage::is_work_file( $path ) || 'upload' === ( $definition['source']['type'] ?? '' ) ? dirname( $path ) : null;

		$this->run_id     = (int) $run['id'];
		$this->definition = $definition;
		$this->target     = $target;
		$this->handlers   = Registry::handlers_for( $target->key() );
		$this->reader     = Formats::open( $path, $options, (string) $run['source_ext'] );
		$this->mapper     = new Mapper( (array) $definition['mapping'], (string) $definition['matching']['unique_key'] );
		$this->context    = new Context( (int) $run['id'], (int) $run['import_id'], (bool) $run['dry_run'] );
		$this->journal    = ! $run['dry_run'] && Journal::enabled( (int) $run['import_id'] ) ? new Journal( (int) $run['id'], (int) $run['import_id'] ) : null;

		$this->context->settings = (array) $definition['target'];
		if ( $run['dry_run'] ) {
			$this->context->preview_room = max( 0, (int) apply_filters( 'importright_preview_rows', self::PREVIEW_ROWS, $run ) - Log::count( (int) $run['id'], array( Log::PREVIEW ) ) );
		}
		$this->cursor = (array) ( $run['state']['cursor'] ?? array() );
	}

	/**
	 * Whether this session is positioned where the stored run left off.
	 *
	 * @param array<string, mixed> $run
	 */
	public function continues( array $run ): bool {
		return (int) $run['id'] === $this->run_id && (array) ( $run['state']['cursor'] ?? array() ) == $this->cursor; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- JSON round trips turn ints into strings in some drivers.
	}

	/**
	 * Up to $limit records, keyed by record index.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function take( int $limit ): array {
		if ( null === $this->records ) {
			$this->records = $this->reader->records( array() === $this->cursor ? null : $this->cursor );
			$this->advance = false;
		}
		$items = array();
		$taken = 0;
		while ( $taken < $limit ) {
			if ( $this->advance ) {
				$this->records->next();
				$this->advance = false;
			}
			if ( ! $this->records->valid() ) {
				$this->exhausted = true;
				break;
			}
			$items[ (int) $this->records->key() ] = $this->records->current();
			$this->cursor                         = $this->reader->cursor();
			$this->advance                        = true;
			++$taken;
		}
		return $items;
	}

	public function close(): void {
		$this->records = null;
		$this->reader->close();
	}
}

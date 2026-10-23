<?php

namespace Importright\Cli;

use Importright\Database\Schema;
use Importright\Imports\Imports;
use Importright\Runs\Background;
use Importright\Runs\History;
use Importright\Runs\Log;
use Importright\Runs\Retention;
use Importright\Runs\Rollback;
use Importright\Runs\Runner;
use Importright\Runs\Runs;
use Importright\Runs\Tracker;
use Importright\Source\Storage;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Create, list and run Total Import imports.
 */
final class Command {

	public function __construct() {
		Schema::maybe_install();
	}

	/**
	 * Creates an import from a JSON definition file.
	 *
	 * ## OPTIONS
	 *
	 * <definition>
	 * : Path to the JSON definition.
	 *
	 * [--file=<path>]
	 * : A data file to copy into the import folder and use as the import's uploaded source. Overrides the definition's source.
	 *
	 * [--porcelain]
	 * : Print only the new import's ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp importright create products.json --file=products.csv
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function create( array $args, array $assoc_args ): void {
		$json = is_readable( $args[0] ) ? file_get_contents( $args[0] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file named on the command line.
		if ( false === $json ) {
			WP_CLI::error( sprintf( 'Cannot read %s.', $args[0] ) );
		}
		$input = json_decode( (string) $json, true );
		if ( ! is_array( $input ) ) {
			WP_CLI::error( 'The definition is not valid JSON: ' . json_last_error_msg() );
		}

		if ( isset( $assoc_args['file'] ) ) {
			$data = realpath( $assoc_args['file'] );
			if ( false === $data || ! is_file( $data ) ) {
				WP_CLI::error( sprintf( 'Cannot read %s.', $assoc_args['file'] ) );
			}
			$name = Storage::store_upload( $data, basename( $data ) );
			if ( is_wp_error( $name ) ) {
				WP_CLI::error( $name->get_error_message() );
			}
			$input['source'] = array(
				'type'          => 'upload',
				'file'          => $name,
				'original_name' => basename( $data ),
			);
		}

		$id = Imports::create( $input );
		if ( is_wp_error( $id ) ) {
			if ( isset( $name ) && is_string( $name ) ) {
				Storage::delete_upload( $name );
			}
			WP_CLI::error( $id->get_error_message() );
		}

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( (string) $id );
			return;
		}
		WP_CLI::success( sprintf( 'Created import %d.', $id ) );
	}

	/**
	 * Lists imports.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( Imports::all() as $import ) {
			$definition = $import['definition'];
			$last       = Runs::recent( $import['id'], 1 )[0] ?? null;
			$rows[]     = array(
				'id'       => $import['id'],
				'name'     => $import['name'],
				'source'   => self::describe_source( (array) ( $definition['source'] ?? array() ) ),
				'target'   => ( $definition['target']['type'] ?? '' ) . ':' . ( $definition['target']['post_type'] ?? '' ),
				'records'  => Tracker::count( $import['id'] ),
				'last_run' => null === $last ? '' : sprintf( '%d %s', $last['id'], $last['status'] ),
			);
		}
		if ( 'ids' === $assoc_args['format'] ) {
			WP_CLI::line( implode( ' ', array_column( $rows, 'id' ) ) );
			return;
		}
		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array( 'id', 'name', 'source', 'target', 'records', 'last_run' ) );
	}

	/**
	 * Prints an import's definition as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <import-id>
	 * : The import.
	 *
	 * @param string[] $args
	 */
	public function get( array $args ): void {
		$import = Imports::get( (int) $args[0] );
		if ( null === $import ) {
			WP_CLI::error( 'No import has that ID.' );
		}
		$definition = $import['definition'];
		if ( isset( $definition['source']['password'] ) && '' !== $definition['source']['password'] ) {
			$definition['source']['password'] = '********';
		}
		WP_CLI::line( (string) wp_json_encode( $definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Runs an import to the end.
	 *
	 * Needs --user with a user who can run imports. Every batch is written as
	 * that user, whoever drives the run later.
	 *
	 * ## OPTIONS
	 *
	 * <import-id>
	 * : The import to run.
	 *
	 * [--dry-run]
	 * : Read and match every record and report what would happen, without writing anything. Prints the stored preview (the first 200 records, filter importright_preview_rows).
	 *
	 * [--batch-size=<records>]
	 * : Records per batch. Defaults to the import's setting.
	 *
	 * [--background]
	 * : Queue the run for Action Scheduler (or WP-Cron) and return its ID instead of running it here.
	 *
	 * [--rows=<n>]
	 * : Preview records to print after a dry run.
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp importright run 3 --user=admin
	 *     wp importright run 3 --user=admin --dry-run
	 *     wp importright run 3 --user=admin --background
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function run( array $args, array $assoc_args ): void {
		$run_args = array(
			'origin'  => 'cli',
			'dry_run' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ),
		);
		if ( isset( $assoc_args['batch-size'] ) ) {
			$run_args['batch_size'] = (int) $assoc_args['batch-size'];
		}

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'background', false ) ) {
			$run_id = Background::start( (int) $args[0], $run_args );
			if ( is_wp_error( $run_id ) ) {
				WP_CLI::error( $run_id->get_error_message() );
			}
			WP_CLI::success( sprintf( 'Queued run %d (%s).', $run_id, Background::uses_action_scheduler() ? 'Action Scheduler' : 'WP-Cron' ) );
			return;
		}

		$run_id = Runner::start( (int) $args[0], $run_args );
		if ( is_wp_error( $run_id ) ) {
			WP_CLI::error( $run_id->get_error_message() );
		}
		$this->drive( $run_id );
		if ( $run_args['dry_run'] ) {
			self::print_preview( $run_id, (int) ( $assoc_args['rows'] ?? 50 ) );
		}
	}

	/**
	 * Prints a dry run's stored preview: what each record would create or change, field by field.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : A dry run.
	 *
	 * [--rows=<n>]
	 * : Records to print.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : table prints one line per changed field; json prints the stored rows.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function preview( array $args, array $assoc_args ): void {
		$run = Runs::get( (int) $args[0] );
		if ( null === $run ) {
			WP_CLI::error( 'No run has that ID.' );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( Log::entries( $run['id'], array( Log::PREVIEW ), 1, (int) ( $assoc_args['rows'] ?? 50 ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		self::print_preview( $run['id'], (int) ( $assoc_args['rows'] ?? 50 ) );
	}

	/**
	 * Rolls back a run: restores what it updated and removes what it created.
	 *
	 * Objects changed after the run are skipped and reported. Posts and products
	 * go to the trash; terms and users the run created are deleted; attachments
	 * and terms it created along the way are deleted only when nothing else uses
	 * them. Use --user=<admin> when the run touched users.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : The run to undo.
	 *
	 * [--dry-run]
	 * : Report what would happen without changing anything.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * [--format=<format>]
	 * : How to print the per-object report.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp importright rollback 12 --dry-run
	 *     wp importright rollback 12 --user=admin --yes
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function rollback( array $args, array $assoc_args ): void {
		$run_id = (int) $args[0];
		$format = $assoc_args['format'] ?? 'table';
		$fields = array( 'object_type', 'object_id', 'title', 'action', 'status', 'fields', 'note' );

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			$preview = Rollback::preview( $run_id, PHP_INT_MAX );
			if ( is_wp_error( $preview ) ) {
				WP_CLI::error( $preview->get_error_message() );
			}
			WP_CLI\Utils\format_items( $format, self::flatten( $preview['items'] ), $fields );
			WP_CLI::success( 'Dry run: ' . self::outcomes( $preview['counts'] ) . '.' );
			return;
		}

		WP_CLI::confirm( sprintf( 'Roll back run %d?', $run_id ), $assoc_args );
		$run = Rollback::start( $run_id );
		if ( is_wp_error( $run ) ) {
			WP_CLI::error( $run->get_error_message() );
		}
		do {
			$run = Rollback::step( $run_id );
			if ( is_wp_error( $run ) ) {
				WP_CLI::error( $run->get_error_message() );
			}
		} while ( Rollback::RUNNING === $run['rollback_status'] );

		$report = Rollback::report( $run_id, '', 1, 500 );
		WP_CLI\Utils\format_items( $format, self::flatten( $report['items'] ), $fields );
		if ( $report['total'] > 500 ) {
			WP_CLI::log( sprintf( 'Showing 500 of %d objects.', $report['total'] ) );
		}
		$message = sprintf( 'Run %d rolled back (%s): %s.', $run_id, $run['rollback_status'], self::outcomes( Rollback::counts( $run_id ) ) );
		if ( Rollback::DONE === $run['rollback_status'] ) {
			WP_CLI::success( $message );
		} else {
			WP_CLI::warning( $message );
		}
	}

	/**
	 * Prints a run's errors and notices.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : The run.
	 *
	 * [--level=<level>]
	 * : Only errors or only notices.
	 * ---
	 * options:
	 *   - error
	 *   - notice
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format. csv prints every entry; table and json the first 1000.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function log( array $args, array $assoc_args ): void {
		$run_id = (int) $args[0];
		if ( null === Runs::get( $run_id ) ) {
			WP_CLI::error( 'No run has that ID.' );
		}
		if ( 'csv' === ( $assoc_args['format'] ?? 'table' ) && empty( $assoc_args['level'] ) ) {
			$out = fopen( 'php://stdout', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI output.
			Log::csv( $run_id, $out );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return;
		}
		$levels  = empty( $assoc_args['level'] ) ? array() : array( (string) $assoc_args['level'] );
		$entries = Log::entries( $run_id, $levels, 1, 500 )['items'];
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $entries, array( 'row', 'key', 'level', 'message' ) );
	}

	/**
	 * Expires old rollback data and logs now instead of waiting for the daily cleanup.
	 *
	 * @subcommand cleanup
	 */
	public function cleanup(): void {
		$result = Retention::prune();
		$files  = Imports::prune_files();
		WP_CLI::success( sprintf( 'Expired rollback data of %d runs (%d entries), removed %d log rows and %d unused files.', $result['expired_runs'], $result['changes'], $result['log'], $files ) );
	}

	/**
	 * Continues a run that stopped part way.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : The run.
	 *
	 * [--force]
	 * : Take over the run even if its lock hasn't expired, after the process that held it died. Never use it while another process is still running the import.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function resume( array $args, array $assoc_args ): void {
		$run = Runs::get( (int) $args[0] );
		if ( null === $run ) {
			WP_CLI::error( 'No run has that ID.' );
		}
		if ( ! in_array( $run['status'], Runs::ACTIVE, true ) ) {
			WP_CLI::error( sprintf( 'Run %d is %s.', $run['id'], $run['status'] ) );
		}
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false ) ) {
			Runs::break_lock( $run['id'] );
		}
		$this->drive( $run['id'] );
	}

	/**
	 * Takes a single batch of a run, the way a background job or an admin request would.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : The run.
	 *
	 * @param string[] $args
	 */
	public function step( array $args ): void {
		$run = Runner::step( (int) $args[0] );
		if ( is_wp_error( $run ) ) {
			WP_CLI::error( $run->get_error_message() );
		}
		WP_CLI::line( self::summary( $run ) );
	}

	/**
	 * Starts a run without processing it, printing the run ID. Use step or resume to drive it.
	 *
	 * ## OPTIONS
	 *
	 * <import-id>
	 * : The import.
	 *
	 * [--dry-run]
	 * : Don't write anything.
	 *
	 * [--batch-size=<records>]
	 * : Records per batch.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function start( array $args, array $assoc_args ): void {
		$run_args = array(
			'origin'  => 'cli',
			'dry_run' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ),
		);
		if ( isset( $assoc_args['batch-size'] ) ) {
			$run_args['batch_size'] = (int) $assoc_args['batch-size'];
		}
		$run_id = Runner::start( (int) $args[0], $run_args );
		if ( is_wp_error( $run_id ) ) {
			WP_CLI::error( $run_id->get_error_message() );
		}
		WP_CLI::line( (string) $run_id );
	}

	/**
	 * Cancels a pending or running run.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : The run.
	 *
	 * @param string[] $args
	 */
	public function cancel( array $args ): void {
		if ( ! Runner::cancel( (int) $args[0] ) ) {
			WP_CLI::error( 'That run is not active.' );
		}
		WP_CLI::success( 'Cancelled.' );
	}

	/**
	 * Lists recent runs.
	 *
	 * ## OPTIONS
	 *
	 * [<import-id>]
	 * : Only this import's runs.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function runs( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( Runs::recent( (int) ( $args[0] ?? 0 ) ) as $run ) {
			$history = History::describe( $run );
			if ( 'json' === $assoc_args['format'] ) {
				$rows[] = $history;
				continue;
			}
			$rows[] = array(
				'id'        => $run['id'],
				'import'    => $run['import_id'],
				'status'    => $run['status'],
				'mode'      => $history['mode'],
				'origin'    => $run['origin'],
				'user'      => $history['user']['name'],
				'processed' => $run['processed'],
				'created'   => $run['created'],
				'updated'   => $run['updated'],
				'unchanged' => $run['unchanged'],
				'skipped'   => $run['skipped'],
				'failed'    => $run['failed'],
				'errors'    => $history['log']['errors'],
				'started'   => (string) $run['started_at'],
				'finished'  => (string) $run['finished_at'],
				'rollback'  => '' !== $history['rollback']['status'] ? $history['rollback']['status'] : ( $history['rollback']['available'] ? 'available' : '' ),
			);
		}
		if ( 'json' === $assoc_args['format'] ) {
			WP_CLI::line( (string) wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array( 'id', 'import', 'status', 'mode', 'origin', 'user', 'processed', 'created', 'updated', 'unchanged', 'skipped', 'failed', 'errors', 'started', 'finished', 'rollback' ) );
	}

	/**
	 * Deletes an import, its runs and its record map. Imported posts stay.
	 *
	 * ## OPTIONS
	 *
	 * <import-id>
	 * : The import.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function delete( array $args, array $assoc_args ): void {
		if ( null === Imports::get( (int) $args[0] ) ) {
			WP_CLI::error( 'No import has that ID.' );
		}
		WP_CLI::confirm( 'Delete this import and its record map? Imported posts are kept, but a new import would no longer update them.', $assoc_args );
		Imports::delete( (int) $args[0] );
		WP_CLI::success( 'Deleted.' );
	}

	private function drive( int $run_id ): void {
		$started = microtime( true );
		$last    = $started;
		$run     = Runner::run(
			$run_id,
			static function ( array $run ) use ( &$last ): void {
				if ( microtime( true ) - $last >= 10 && in_array( $run['status'], Runs::ACTIVE, true ) ) {
					$last = microtime( true );
					WP_CLI::log( self::summary( $run ) );
				}
			}
		);
		if ( is_wp_error( $run ) ) {
			WP_CLI::error( $run->get_error_message() );
		}

		foreach ( array_slice( $run['errors'], 0, 20 ) as $error ) {
			WP_CLI::warning( sprintf( 'Record %d%s: %s', $error['index'] + 1, '' === $error['key'] ? '' : ' (' . $error['key'] . ')', $error['message'] ) );
		}
		if ( count( $run['errors'] ) > 20 ) {
			WP_CLI::warning( sprintf( '%d more messages are stored with run %d.', count( $run['errors'] ) - 20, $run['id'] ) );
		}

		$timing = sprintf( '%.1f s, peak memory %.1f MB', microtime( true ) - $started, memory_get_peak_usage() / 1048576 );
		if ( Runs::COMPLETED !== $run['status'] ) {
			WP_CLI::error( sprintf( 'Run %d %s: %s (%s)', $run['id'], $run['status'], $run['message'], $timing ) );
		}
		WP_CLI::success( self::summary( $run ) . ' ' . $timing . '.' );
	}

	/**
	 * @param array<string, mixed> $run
	 */
	private static function summary( array $run ): string {
		$percent = Runner::percent( $run );
		return sprintf(
			'Run %d %s%s: %d processed, %d %s, %d %s, %d unchanged, %d skipped, %d failed%s.',
			$run['id'],
			$run['status'],
			$run['dry_run'] ? ' (dry run)' : '',
			$run['processed'],
			$run['created'],
			$run['dry_run'] ? 'to create' : 'created',
			$run['updated'],
			$run['dry_run'] ? 'to update' : 'updated',
			$run['unchanged'],
			$run['skipped'],
			$run['failed'],
			null === $percent || Runs::COMPLETED === $run['status'] ? '' : sprintf( ' (%s%%)', $percent )
		);
	}

	private static function print_preview( int $run_id, int $rows ): void {
		$entries = Log::entries( $run_id, array( Log::PREVIEW ), 1, max( 1, $rows ) );
		$lines   = array();
		foreach ( $entries['items'] as $entry ) {
			$base  = array(
				'row'    => $entry['row'],
				'key'    => $entry['key'],
				'action' => $entry['action'] . ( null === $entry['object_id'] ? '' : ' #' . $entry['object_id'] ),
			);
			$items = array();
			foreach ( $entry['changes'] as $change ) {
				$items[] = $base + array(
					'field' => $change['field'],
					'old'   => self::short( $change['old'] ),
					'new'   => self::short( $change['new'] ),
				);
			}
			foreach ( $entry['downloads'] as $url ) {
				$items[] = $base + array(
					'field' => 'would download',
					'old'   => '',
					'new'   => self::short( $url ),
				);
			}
			foreach ( $entry['messages'] as $message ) {
				$items[] = $base + array(
					'field' => 'message',
					'old'   => '',
					'new'   => self::short( $message ),
				);
			}
			if ( array() === $items ) {
				$items[] = $base + array(
					'field' => '',
					'old'   => '',
					'new'   => '',
				);
			}
			array_push( $lines, ...$items );
		}
		if ( array() === $lines ) {
			WP_CLI::log( 'No preview rows were stored for this run.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $lines, array( 'row', 'key', 'action', 'field', 'old', 'new' ) );
		WP_CLI::log( sprintf( 'Showing %d of %d stored preview records.', count( $entries['items'] ), $entries['total'] ) );
	}

	private static function short( ?string $value ): string {
		if ( null === $value ) {
			return '(none)';
		}
		$value = preg_replace( '/\s+/', ' ', $value );
		return strlen( (string) $value ) > 60 ? substr( (string) $value, 0, 57 ) . '...' : (string) $value;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten( array $items ): array {
		foreach ( $items as $i => $item ) {
			$items[ $i ]['fields'] = implode( ', ', (array) $item['fields'] );
		}
		return $items;
	}

	/**
	 * @param array<string, int> $counts
	 */
	private static function outcomes( array $counts ): string {
		$parts = array();
		foreach ( $counts as $status => $count ) {
			if ( $count > 0 ) {
				$parts[] = $count . ' ' . $status;
			}
		}
		return array() === $parts ? 'nothing to do' : implode( ', ', $parts );
	}

	/**
	 * @param array<string, string> $source
	 */
	private static function describe_source( array $source ): string {
		switch ( $source['type'] ?? '' ) {
			case 'upload':
				return 'upload: ' . ( $source['original_name'] ?? '' );
			case 'url':
				return 'url: ' . ( $source['url'] ?? '' );
			case 'path':
				return 'path: ' . ( $source['path'] ?? '' );
		}
		return '';
	}
}

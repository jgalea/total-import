<?php

namespace Importright\Cli;

use Importright\Database\Schema;
use Importright\Migration\Migrator;
use Importright\Migration\Report;
use Importright\Migration\WpaiSite;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Moves WP All Import imports and templates over to Total Import.
 */
final class MigrateCommand {

	public function __construct() {
		Schema::maybe_install();
	}

	/**
	 * Lists WP All Import's imports on this site.
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
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function list_( array $args, array $assoc_args ): void {
		$site = new WpaiSite();
		if ( ! $site->available() ) {
			WP_CLI::error( 'WP All Import\'s tables aren\'t on this site.' );
		}
		$migrated = Migrator::migrated();
		$rows     = array();
		foreach ( $site->imports() as $import ) {
			$rows[] = array(
				'id'          => $import['id'],
				'name'        => $import['name'],
				'source'      => $import['type'] . ( '' === $import['feed_type'] ? '' : ' (' . $import['feed_type'] . ')' ) . ': ' . $import['path'],
				'post_type'   => $import['post_type'],
				'records'     => $import['records'],
				'migrated_to' => $migrated[ $import['id'] ] ?? '',
			);
		}
		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array( 'id', 'name', 'source', 'post_type', 'records', 'migrated_to' ) );
	}

	/**
	 * Converts a WP All Import import on this site into a Total Import import, and copies its record list so the first run updates the posts it created.
	 *
	 * ## OPTIONS
	 *
	 * <wpai-id>
	 * : The WP All Import import ID (see `wp importright migrate list`).
	 *
	 * [--dry-run]
	 * : Print the definition and report without saving anything.
	 *
	 * [--file=<path>]
	 * : Read this data file instead of the import's own source.
	 *
	 * [--format=<format>]
	 * : Report format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp importright migrate import 4 --dry-run
	 *     wp importright migrate import 4
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function import( array $args, array $assoc_args ): void {
		$result = Migrator::from_site(
			(int) $args[0],
			array(
				'dry_run'   => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ),
				'data_file' => (string) ( $assoc_args['file'] ?? '' ),
			)
		);
		$this->output( $result, $assoc_args );
	}

	/**
	 * Converts a WP All Import template export (.txt) or a WP All Export bundle (.zip) into a Total Import import.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The template export or bundle.
	 *
	 * [--dry-run]
	 * : Print the definition and report without saving anything.
	 *
	 * [--file=<path>]
	 * : The data file the import should read. Needed for a plain template export unless it names a URL.
	 *
	 * [--index=<n>]
	 * : Which template to convert when the file holds several, starting at 0.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--format=<format>]
	 * : Report format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp importright migrate template templates_6512.txt --file=products.csv --dry-run
	 *     wp importright migrate template bundle.zip
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function template( array $args, array $assoc_args ): void {
		$result = Migrator::from_template(
			$args[0],
			array(
				'dry_run'   => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ),
				'data_file' => (string) ( $assoc_args['file'] ?? '' ),
				'index'     => (int) ( $assoc_args['index'] ?? 0 ),
			)
		);
		$this->output( $result, $assoc_args );
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result
	 * @param array<string, string>          $assoc_args
	 */
	private function output( $result, array $assoc_args ): void {
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$report = $result['report'];

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line(
				(string) wp_json_encode(
					array(
						'import_id'  => $result['import_id'],
						'definition' => $result['definition'],
						'report'     => $report->to_array(),
						'tracking'   => is_array( $result['tracking'] ) ? $result['tracking'] : null,
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			);
			return;
		}

		$rows = array();
		foreach ( $report->entries() as $entry ) {
			$rows[] = array(
				'status'      => $entry['status'],
				'wpai'        => $entry['wpai_key'],
				'wpai_value'  => self::text( $entry['wpai_value'] ),
				'importright' => $entry['field'],
				'value'       => self::text( $entry['value'] ),
				'note'        => $entry['note'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'wpai', 'wpai_value', 'importright', 'value', 'note' ) );

		$counts = $report->counts();
		WP_CLI::log( sprintf( '%d mapped, %d approximated, %d unsupported.', $counts[ Report::MAPPED ], $counts[ Report::APPROXIMATED ], $counts[ Report::UNSUPPORTED ] ) );

		if ( null === $result['definition'] ) {
			WP_CLI::error( 'This import can\'t be migrated.' );
		}
		if ( is_array( $result['tracking'] ) ) {
			$tracking = $result['tracking'];
			if ( '' !== $tracking['skipped_reason'] ) {
				WP_CLI::warning( $tracking['skipped_reason'] );
			} elseif ( null === $result['import_id'] ) {
				WP_CLI::log( sprintf( '%d WP All Import records would be carried over.', $tracking['available'] ) );
			} else {
				WP_CLI::log( sprintf( 'Carried over %d of %d WP All Import records (%d without a key, %d repeated keys).', $tracking['copied'], $tracking['read'], $tracking['empty'], $tracking['duplicates'] ) );
			}
		}

		if ( null === $result['import_id'] ) {
			WP_CLI::line( (string) wp_json_encode( $result['definition'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			WP_CLI::success( 'Dry run: nothing was saved.' );
			return;
		}
		WP_CLI::success( sprintf( 'Created import %d. Check it with a dry run first: wp importright run %d --dry-run', $result['import_id'], $result['import_id'] ) );
	}

	/**
	 * @param mixed $value
	 */
	private static function text( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$text = str_replace( array( "\r\n", "\n" ), ' / ', $text );
		return strlen( $text ) > 80 ? substr( $text, 0, 77 ) . '...' : $text;
	}
}

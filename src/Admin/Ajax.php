<?php

namespace Importright\Admin;

use Importright\Imports\Imports;
use Importright\Mapping\Mapper;
use Importright\Migration\Migrator;
use Importright\Plugin;
use Importright\Runs\Background;
use Importright\Runs\History;
use Importright\Runs\Log;
use Importright\Runs\Rollback;
use Importright\Runs\Runner;
use Importright\Runs\Runs;
use Importright\Source\Credentials;
use Importright\Source\Storage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * admin-ajax endpoints the admin screens call. Every request needs the
 * importright_ajax nonce in "nonce" and Plugin::capability(). Responses are
 * wp_send_json_success( data ) or wp_send_json_error( {code, message}, status ).
 *
 * Anyone with the capability may drive, cancel or roll back any run, but each
 * step writes as the user who started the run or rollback (Runs\Actor), so a
 * second admin resuming a colleague's run can't change what it does.
 */
final class Ajax {

	public const NONCE = 'importright_ajax';

	private const ACTIONS = array(
		'run_start',
		'run_step',
		'run_status',
		'run_cancel',
		'run_background',
		'run_unlock',
		'runs',
		'run_preview',
		'run_log',
		'rollback_preview',
		'rollback_start',
		'rollback_step',
		'rollback_report',
		'upload',
		'inspect',
		'render',
		'save',
		'migrate_site',
		'migrate_upload',
		'migrate_template',
	);

	public static function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'wp_ajax_importright_' . $action, array( self::class, $action ) );
		}
	}

	public static function nonce(): string {
		return wp_create_nonce( self::NONCE );
	}

	public static function run_start(): void {
		self::guard();
		$import_id = self::int( 'import_id' );
		$args      = array( 'dry_run' => 1 === self::int( 'dry_run' ) );
		if ( self::int( 'batch_size' ) > 0 ) {
			$args['batch_size'] = self::int( 'batch_size' );
		}
		if ( 1 === self::int( 'background' ) ) {
			$run_id = Background::start( $import_id, $args );
		} else {
			$run_id = Runner::start( $import_id, array( 'origin' => 'ajax' ) + $args );
		}
		if ( is_wp_error( $run_id ) ) {
			self::fail( $run_id, 409 );
		}
		self::respond_run( (int) $run_id );
	}

	public static function run_step(): void {
		self::guard();
		$run_id = self::run_id();
		$limit  = (float) apply_filters( 'importright_ajax_time_limit', 10.0 );
		$run    = Runner::run_for( $run_id, max( 1.0, $limit ) );
		if ( is_wp_error( $run ) ) {
			self::fail( $run, 'importright_locked' === $run->get_error_code() ? 409 : 400 );
		}
		self::respond_run( $run_id );
	}

	public static function run_status(): void {
		self::guard();
		self::respond_run( self::run_id() );
	}

	public static function run_cancel(): void {
		self::guard();
		$run_id = self::run_id();
		if ( ! Runner::cancel( $run_id ) ) {
			self::fail( new WP_Error( 'importright_inactive', __( 'That run is not active.', 'total-import' ) ), 409 );
		}
		self::respond_run( $run_id );
	}

	public static function run_background(): void {
		self::guard();
		$run_id = self::run_id();
		if ( ! Background::adopt( $run_id ) ) {
			self::fail( new WP_Error( 'importright_inactive', __( 'That run is not active.', 'total-import' ) ), 409 );
		}
		self::respond_run( $run_id );
	}

	/**
	 * Takes over a run whose driver went away (closed tab, killed request) before its lock expired.
	 */
	public static function run_unlock(): void {
		self::guard();
		$run_id = self::run_id();
		$run    = Runs::get( $run_id );
		$grace  = (int) apply_filters( 'importright_watchdog_grace', 120 );
		if ( null === $run || $run['heartbeat'] > time() - $grace ) {
			self::fail( new WP_Error( 'importright_locked', __( 'This run was active in the last two minutes. Wait before taking it over.', 'total-import' ) ), 409 );
		}
		Runs::break_lock( $run_id );
		self::respond_run( $run_id );
	}

	public static function runs(): void {
		self::guard();
		$import_id = self::int( 'import_id' );
		$page      = max( 1, self::int( 'page' ) );
		$per_page  = self::int( 'per_page' ) > 0 ? self::int( 'per_page' ) : 20;
		wp_send_json_success(
			array(
				'runs'  => array_map( array( History::class, 'describe' ), Runs::page( $import_id, $page, $per_page ) ),
				'total' => Runs::count( $import_id ),
			)
		);
	}

	public static function run_preview(): void {
		self::guard();
		$run_id  = self::run_id();
		$entries = Log::entries( $run_id, array( Log::PREVIEW ), max( 1, self::int( 'page' ) ), self::int( 'per_page' ) > 0 ? self::int( 'per_page' ) : 50 );
		$run     = Runs::get( $run_id );
		wp_send_json_success(
			array(
				'run'   => History::describe( (array) $run ),
				'rows'  => $entries['items'],
				'total' => $entries['total'],
			)
		);
	}

	public static function run_log(): void {
		self::guard();
		$run_id = self::run_id();
		$level  = sanitize_key( self::text( 'level' ) );
		$levels = in_array( $level, array( Log::ERROR, Log::NOTICE ), true ) ? array( $level ) : array();
		$found  = Log::entries( $run_id, $levels, max( 1, self::int( 'page' ) ), self::int( 'per_page' ) > 0 ? self::int( 'per_page' ) : 50 );
		wp_send_json_success(
			array(
				'entries' => $found['items'],
				'total'   => $found['total'],
			)
		);
	}

	public static function rollback_preview(): void {
		self::guard();
		$preview = Rollback::preview( self::run_id(), 200 );
		if ( is_wp_error( $preview ) ) {
			self::fail( $preview, 409 );
		}
		wp_send_json_success( $preview );
	}

	public static function rollback_start(): void {
		self::guard();
		$run = Rollback::start( self::run_id() );
		if ( is_wp_error( $run ) ) {
			self::fail( $run, 409 );
		}
		self::respond_rollback( (int) $run['id'] );
	}

	public static function rollback_step(): void {
		self::guard();
		$run_id = self::run_id();
		$limit  = (float) apply_filters( 'importright_ajax_time_limit', 10.0 );
		$run    = Rollback::run_for( $run_id, max( 1.0, $limit ) );
		if ( is_wp_error( $run ) ) {
			self::fail( $run, 409 );
		}
		self::respond_rollback( $run_id );
	}

	public static function rollback_report(): void {
		self::guard();
		$run_id = self::run_id();
		$status = sanitize_key( self::text( 'status' ) );
		$status = in_array( $status, Rollback::OUTCOMES, true ) ? $status : '';
		$report = Rollback::report( $run_id, $status, max( 1, self::int( 'page' ) ), self::int( 'per_page' ) > 0 ? self::int( 'per_page' ) : 50 );
		wp_send_json_success( $report + array( 'counts' => Rollback::counts( $run_id ) ) );
	}

	/**
	 * Stores a source file for the wizard. It belongs to no import until the import is saved;
	 * the daily cleanup deletes it after a day if it never is.
	 */
	public static function upload(): void {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checks the nonce; the file is checked below.
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;
		if ( null === $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$too_big = null !== $file && in_array( (int) ( $file['error'] ?? 0 ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			self::fail(
				new WP_Error(
					'importright_upload',
					$too_big
						/* translators: %s: maximum upload size, e.g. "64 MB" */
						? sprintf( __( 'The file is larger than this site accepts (%s). Use a URL or a server path instead.', 'total-import' ), size_format( wp_max_upload_size() ) )
						: __( 'The file could not be uploaded. Try again.', 'total-import' )
				),
				400
			);
		}
		$name   = sanitize_file_name( wp_unslash( (string) $file['name'] ) );
		$stored = Storage::store_upload( (string) $file['tmp_name'], $name, true );
		if ( is_wp_error( $stored ) ) {
			self::fail( $stored, 400 );
		}
		wp_send_json_success(
			array(
				'file'          => $stored,
				'original_name' => $name,
				'bytes'         => (int) filesize( (string) Storage::upload_path( $stored ) ),
			)
		);
	}

	public static function inspect(): void {
		self::guard();
		$result = Inspector::inspect( self::json( 'source' ), self::json( 'format' ), 1 === self::int( 'refresh' ) );
		if ( is_wp_error( $result ) ) {
			self::fail( $result, 400 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Renders a mapping against one record, for the wizard's live preview.
	 */
	public static function render(): void {
		self::guard();
		$record = array();
		foreach ( self::json( 'record' ) as $column => $value ) {
			$record[ (string) $column ] = is_scalar( $value ) ? (string) $value : '';
		}
		$mapper = new Mapper( self::json( 'mapping' ), self::raw( 'unique_key' ) );
		wp_send_json_success(
			array(
				'values' => $mapper->render( $record ),
				'key'    => $mapper->key( $record ),
			)
		);
	}

	public static function save(): void {
		self::guard();
		$import_id  = self::int( 'import_id' );
		$definition = self::json( 'definition' );
		$existing   = $import_id > 0 ? Imports::get( $import_id ) : null;
		if ( $import_id > 0 && null === $existing ) {
			self::fail( new WP_Error( 'importright_missing', __( 'No import has that ID.', 'total-import' ) ), 404 );
		}
		if ( 'path' === ( $definition['source']['type'] ?? '' ) && ! Inspector::can_use_paths() ) {
			self::fail( new WP_Error( 'importright_source', __( 'Only network administrators can import files from a server path.', 'total-import' ) ), 403 );
		}
		if ( 'url' === ( $definition['source']['type'] ?? '' ) && '' === (string) ( $definition['source']['password'] ?? '' ) && ! empty( $definition['source']['keep_password'] ) ) {
			$stored = (array) ( $existing['definition']['source'] ?? array() );
			if ( 'url' !== ( $stored['type'] ?? '' ) || '' === (string) ( $stored['password'] ?? '' ) ) {
				$definition['source']['password'] = '';
			} elseif ( Credentials::can_reuse( $stored, $definition['source'] ) ) {
				$definition['source']['password'] = (string) $stored['password'];
			} elseif ( '' !== (string) ( $definition['source']['username'] ?? '' ) ) {
				self::fail( new WP_Error( 'importright_password', __( 'The URL or username changed, so the saved password wasn\'t kept. Type the password again.', 'total-import' ) ), 400 );
			}
		}
		unset( $definition['source']['keep_password'] );

		$result = null === $existing ? Imports::create( $definition ) : Imports::update( $import_id, $definition );
		if ( is_wp_error( $result ) ) {
			self::fail( $result, 400 );
		}
		$import_id = null === $existing ? (int) $result : $import_id;
		$saved     = Imports::get( $import_id );

		$old_file = (string) ( $existing['definition']['source']['file'] ?? '' );
		$new_file = (string) ( $saved['definition']['source']['file'] ?? '' );
		if ( '' !== $old_file && $new_file !== $old_file && null === Runs::active( $import_id ) ) {
			Storage::delete_upload( $old_file );
		}

		wp_send_json_success(
			array(
				'import_id' => $import_id,
				'edit_url'  => Screens::url( Screens::EDIT, array( 'import' => $import_id ) ),
				'name'      => (string) ( $saved['name'] ?? '' ),
			)
		);
	}

	/**
	 * Converts one of WP All Import's imports on this site. dry_run=1 only reports.
	 */
	public static function migrate_site(): void {
		self::guard();
		self::respond_migration( Migrator::from_site( self::int( 'wpai_id' ), array( 'dry_run' => 1 === self::int( 'dry_run' ) ) ) );
	}

	/**
	 * Stores a WP All Import template (.txt/.json) or WP All Export bundle (.zip), and
	 * optionally the data file, for migrate_template. Unused files go with the daily cleanup.
	 */
	public static function migrate_upload(): void {
		self::guard();
		$template = self::store_file( 'template', array( 'txt', 'json', 'zip' ) );
		if ( null === $template ) {
			self::fail( new WP_Error( 'importright_upload', __( 'Choose a WP All Import template (.txt) or a WP All Export bundle (.zip).', 'total-import' ) ), 400 );
		}
		$data = self::store_file( 'data', array( 'csv', 'tsv', 'txt', 'xml', 'json', 'xlsx', 'gz', 'zip' ) );
		wp_send_json_success(
			array(
				'template' => $template,
				'data'     => $data,
			)
		);
	}

	/**
	 * Converts a stored template. Only files migrate_upload stored are accepted as the template or data file.
	 */
	public static function migrate_template(): void {
		self::guard();
		$template = Storage::upload_path( self::text( 'template' ) );
		$data     = '' === self::text( 'data' ) ? null : Storage::upload_path( self::text( 'data' ) );
		if ( null === $template || ! is_file( $template ) || ( '' !== self::text( 'data' ) && ( null === $data || ! is_file( $data ) ) ) ) {
			self::fail( new WP_Error( 'importright_missing', __( 'The uploaded file is gone. Upload it again.', 'total-import' ) ), 404 );
		}
		$dry_run = 1 === self::int( 'dry_run' );
		$result  = Migrator::from_template(
			$template,
			array(
				'dry_run'   => $dry_run,
				'index'     => self::int( 'index' ),
				'data_file' => null === $data ? '' : $data,
			)
		);
		if ( ! $dry_run && ! is_wp_error( $result ) && null !== $result['import_id'] ) {
			Storage::delete_upload( self::text( 'template' ) );
			if ( null !== $data ) {
				Storage::delete_upload( self::text( 'data' ) );
			}
		}
		self::respond_migration( $result );
	}

	/**
	 * @param array<string, mixed>|WP_Error $result
	 */
	private static function respond_migration( $result ): void {
		if ( is_wp_error( $result ) ) {
			self::fail( $result, 400 );
		}
		$import_id  = null === $result['import_id'] ? 0 : (int) $result['import_id'];
		$definition = (array) $result['definition'];
		wp_send_json_success(
			array(
				'report'      => $result['report']->to_array(),
				'convertible' => null !== $result['definition'],
				'target'      => (string) ( $definition['target']['type'] ?? '' ),
				'name'        => (string) ( $definition['name'] ?? '' ),
				'tracking'    => is_array( $result['tracking'] ) ? $result['tracking'] : null,
				'import_id'   => $import_id,
				'edit_url'    => $import_id > 0 ? Screens::url( Screens::EDIT, array( 'import' => $import_id ) ) : '',
				'dry_run_url' => $import_id > 0 ? Screens::run_url( $import_id, true ) : '',
			)
		);
	}

	/**
	 * Moves an uploaded file into the protected storage folder.
	 *
	 * @param string[] $extensions
	 * @return array{file: string, name: string}|null
	 */
	private static function store_file( string $field, array $extensions ): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checks the nonce; the file is checked below.
		$file = isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] ) ? $_FILES[ $field ] : null;
		if ( null === $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return null;
		}
		$name = sanitize_file_name( wp_unslash( (string) $file['name'] ) );
		if ( ! in_array( Storage::upload_extension( $name ), $extensions, true ) ) {
			return null;
		}
		$stored = Storage::store_upload( (string) $file['tmp_name'], $name, true );
		if ( is_wp_error( $stored ) ) {
			self::fail( $stored, 400 );
		}
		return array(
			'file' => $stored,
			'name' => $name,
		);
	}

	private static function guard(): void {
		if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			self::fail( new WP_Error( 'importright_nonce', __( 'Your session has expired. Reload the page and try again.', 'total-import' ) ), 403 );
		}
		if ( ! current_user_can( Plugin::capability() ) ) {
			self::fail( new WP_Error( 'importright_permission', __( 'You are not allowed to run imports.', 'total-import' ) ), 403 );
		}
	}

	private static function run_id(): int {
		$run_id = self::int( 'run_id' );
		if ( null === Runs::get( $run_id ) ) {
			self::fail( new WP_Error( 'importright_missing', __( 'No run has that ID.', 'total-import' ) ), 404 );
		}
		return $run_id;
	}

	private static function respond_run( int $run_id ): void {
		$run = Runs::get( $run_id );
		if ( null === $run ) {
			self::fail( new WP_Error( 'importright_missing', __( 'No run has that ID.', 'total-import' ) ), 404 );
		}
		wp_send_json_success( array( 'run' => History::describe( $run ) ) );
	}

	private static function respond_rollback( int $run_id ): void {
		$run = Runs::get( $run_id );
		wp_send_json_success(
			array(
				'run'    => History::describe( (array) $run ),
				'counts' => Rollback::counts( $run_id ),
				'done'   => null !== $run && Rollback::RUNNING !== $run['rollback_status'],
			)
		);
	}

	/**
	 * @return never
	 */
	private static function fail( WP_Error $error, int $status ): void {
		wp_send_json_error(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			$status
		);
	}

	private static function int( string $name ): int {
		return isset( $_REQUEST[ $name ] ) ? absint( wp_unslash( $_REQUEST[ $name ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() checks the nonce first.
	}

	/**
	 * A JSON-encoded parameter as an array. Callers validate the contents (Definition::normalize, Sources::normalize).
	 *
	 * @return array<mixed>
	 */
	private static function json( string $name ): array {
		$decoded = json_decode( self::raw( $name ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function raw( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checks the nonce first; templates and JSON are validated by the caller.
		return isset( $_REQUEST[ $name ] ) && is_string( $_REQUEST[ $name ] ) ? wp_unslash( $_REQUEST[ $name ] ) : '';
	}

	private static function text( string $name ): string {
		return isset( $_REQUEST[ $name ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() checks the nonce first.
	}
}

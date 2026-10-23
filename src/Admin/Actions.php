<?php

namespace Importright\Admin;

use Importright\Imports\Imports;
use Importright\Plugin;
use Importright\Runs\Log;
use Importright\Runs\Runs;
use Importright\Source\Sources;
use Importright\Source\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * admin-post.php handlers for the imports list: duplicate, export, delete and
 * importing a definition file, plus the run log download. Each checks the
 * capability and its own nonce.
 */
final class Actions {

	private const MAX_DEFINITION_BYTES = 1048576;

	public const LOG_CSV = 'importright_run_log_csv';

	public static function register(): void {
		foreach ( array( 'duplicate', 'export', 'delete', 'import_definition', 'run_log_csv' ) as $action ) {
			add_action( 'admin_post_importright_' . $action, array( self::class, $action ) );
		}
	}

	public static function url( string $action, int $import_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'importright_' . $action,
					'import' => $import_id,
				),
				admin_url( 'admin-post.php' )
			),
			'importright_' . $action . '_' . $import_id
		);
	}

	public static function duplicate(): void {
		$import = self::guarded_import( 'duplicate' );
		$copy   = $import['definition'];
		/* translators: %s: import name */
		$copy['name'] = sprintf( __( '%s (copy)', 'total-import' ), $import['name'] );

		if ( 'upload' === ( $copy['source']['type'] ?? '' ) ) {
			$path   = Storage::upload_path( (string) $copy['source']['file'] );
			$stored = null === $path ? null : Storage::store_upload( $path, (string) $copy['source']['original_name'] );
			if ( null === $stored || is_wp_error( $stored ) ) {
				self::finish( 'error', null === $stored ? __( 'The uploaded file of that import is missing.', 'total-import' ) : $stored->get_error_message() );
			}
			$copy['source']['file'] = $stored;
		}
		if ( 'path' === ( $copy['source']['type'] ?? '' ) && ! Inspector::can_use_paths() ) {
			self::finish( 'error', __( 'Only network administrators can import files from a server path.', 'total-import' ) );
		}

		$id = Imports::create( $copy );
		if ( is_wp_error( $id ) ) {
			if ( 'upload' === $copy['source']['type'] ) {
				Storage::delete_upload( (string) $copy['source']['file'] );
			}
			self::finish( 'error', $id->get_error_message() );
		}
		/* translators: %s: import name */
		self::finish( 'success', sprintf( __( 'Created "%s".', 'total-import' ), $copy['name'] ) );
	}

	/**
	 * Downloads the definition as JSON. Source secrets are left out.
	 */
	public static function export(): void {
		$import     = self::guarded_import( 'export' );
		$definition = $import['definition'];
		if ( is_array( $definition['source'] ?? null ) ) {
			$definition['source'] = Sources::redact( $definition['source'] );
		}
		$name = sanitize_file_name( 'importright-' . ( '' !== $import['name'] ? sanitize_title( $import['name'] ) : 'import-' . $import['id'] ) . '.json' );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download, not HTML.
		exit;
	}

	/**
	 * A form that downloads a run's log. It posts, so the nonce stays out of the URL
	 * (browser history, Referer headers, server logs), and the nonce only works for this download.
	 */
	public static function log_csv_form( int $run_id, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="importright-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::LOG_CSV ); ?>">
			<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $run_id ); ?>">
			<?php wp_nonce_field( self::LOG_CSV, '_wpnonce', false ); ?>
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	public static function run_log_csv(): void {
		self::guard();
		check_admin_referer( self::LOG_CSV );
		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		if ( null === Runs::get( $run_id ) ) {
			wp_die( esc_html__( 'No run has that ID.', 'total-import' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="importright-run-' . $run_id . '-log.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming the download.
		Log::csv( $run_id, $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public static function delete(): void {
		$import = self::guarded_import( 'delete' );
		Imports::delete( $import['id'] );
		/* translators: %s: import name */
		self::finish( 'success', sprintf( __( 'Deleted "%s". The content it imported was kept.', 'total-import' ), $import['name'] ) );
	}

	public static function import_definition(): void {
		self::guard();
		check_admin_referer( 'importright_import_definition' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- uploaded files are checked below.
		$file = isset( $_FILES['definition'] ) && is_array( $_FILES['definition'] ) ? $_FILES['definition'] : null;
		$data = isset( $_FILES['data'] ) && is_array( $_FILES['data'] ) ? $_FILES['data'] : null;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! self::uploaded( $file ) || (int) $file['size'] > self::MAX_DEFINITION_BYTES ) {
			self::finish( 'error', __( 'Choose a definition file (JSON, up to 1 MB).', 'total-import' ) );
		}
		$definition = json_decode( (string) file_get_contents( (string) $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the uploaded temp file.
		if ( ! is_array( $definition ) || ! is_array( $definition['source'] ?? null ) ) {
			self::finish( 'error', __( 'That file isn\'t a Total Import definition.', 'total-import' ) );
		}

		$stored = '';
		if ( self::uploaded( $data ) ) {
			$name   = sanitize_file_name( wp_unslash( (string) $data['name'] ) );
			$result = Storage::store_upload( (string) $data['tmp_name'], $name, true );
			if ( is_wp_error( $result ) ) {
				self::finish( 'error', $result->get_error_message() );
			}
			$stored               = $result;
			$definition['source'] = array(
				'type'          => 'upload',
				'file'          => $stored,
				'original_name' => $name,
			);
		} elseif ( 'upload' === ( $definition['source']['type'] ?? '' ) ) {
			// Never point a second import at another import's stored file: deleting either would remove it.
			self::finish( 'error', __( 'This import read an uploaded file. Choose the data file as well.', 'total-import' ) );
		}
		if ( 'path' === ( $definition['source']['type'] ?? '' ) && ! Inspector::can_use_paths() ) {
			self::finish( 'error', __( 'Only network administrators can import files from a server path.', 'total-import' ) );
		}

		$id = Imports::create( $definition );
		if ( is_wp_error( $id ) ) {
			if ( '' !== $stored ) {
				Storage::delete_upload( $stored );
			}
			self::finish( 'error', $id->get_error_message() );
		}
		self::flash( 'success', __( 'Import created from the definition. Check the mapping, then run it.', 'total-import' ) );
		wp_safe_redirect( Screens::url( Screens::EDIT, array( 'import' => (int) $id ) ) );
		exit;
	}

	/**
	 * Keeps a notice for the current user's next Total Import screen.
	 */
	public static function flash( string $type, string $message ): void {
		set_transient( 'importright_notice_' . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
	}

	public static function notices(): void {
		$key    = 'importright_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || 2 !== count( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice[0], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice[0] : 'info';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( (string) $notice[1] ) );
	}

	private static function guard(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage imports.', 'total-import' ), 403 );
		}
	}

	/**
	 * @return array{id: int, name: string, definition: array<string, mixed>, created_by: int, created_at: string, updated_at: string}
	 */
	private static function guarded_import( string $action ): array {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked right after, against this ID.
		$id = isset( $_GET['import'] ) ? absint( wp_unslash( $_GET['import'] ) ) : 0;
		check_admin_referer( 'importright_' . $action . '_' . $id );
		$import = Imports::get( $id );
		if ( null === $import ) {
			self::finish( 'error', __( 'That import no longer exists.', 'total-import' ) );
		}
		return $import;
	}

	/**
	 * @param array<string, mixed>|null $file
	 */
	private static function uploaded( ?array $file ): bool {
		return null !== $file && UPLOAD_ERR_OK === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) && is_uploaded_file( (string) $file['tmp_name'] );
	}

	/**
	 * @return never
	 */
	private static function finish( string $type, string $message ): void {
		self::flash( $type, $message );
		wp_safe_redirect( Screens::url( Screens::LIST ) );
		exit;
	}
}

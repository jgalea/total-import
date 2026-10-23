<?php

namespace Importright\Admin;

use Importright\Imports\Imports;
use Importright\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The Total Import admin menu and its screens. Imports, the wizard and the run
 * screen are built here; a run's detail page, Migrate and Settings render
 * through RunScreen, Migrate and SettingsScreen.
 */
final class Screens {

	public const LIST     = 'importright';
	public const EDIT     = 'importright-import';
	public const RUN      = 'importright-run';
	public const HISTORY  = 'importright-history';
	public const MIGRATE  = 'importright-migrate';
	public const SETTINGS = 'importright-settings';

	/**
	 * @var array<string, string> Page slug => hook suffix.
	 */
	private static array $hooks = array();

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ), 10, 2 );
		Actions::register();
		SettingsScreen::register();
	}

	public static function add_menu(): void {
		$capability = Plugin::capability();

		self::$hooks[ self::LIST ] = (string) add_menu_page(
			__( 'Total Import', 'total-import' ),
			__( 'Total Import', 'total-import' ),
			$capability,
			self::LIST,
			array( self::class, 'render_list' ),
			'dashicons-database-import',
			76
		);

		$pages = array(
			self::LIST     => array( __( 'Imports', 'total-import' ), __( 'Imports', 'total-import' ), array( self::class, 'render_list' ) ),
			self::EDIT     => array( __( 'New import', 'total-import' ), __( 'New import', 'total-import' ), array( self::class, 'render_wizard' ) ),
			self::HISTORY  => array( __( 'History', 'total-import' ), __( 'History', 'total-import' ), array( self::class, 'render_history' ) ),
			self::MIGRATE  => array( __( 'Migrate from WP All Import', 'total-import' ), __( 'Migrate from WP All Import', 'total-import' ), array( self::class, 'render_migrate' ) ),
			self::SETTINGS => array( __( 'Total Import settings', 'total-import' ), __( 'Settings', 'total-import' ), array( self::class, 'render_settings' ) ),
		);
		foreach ( $pages as $slug => $page ) {
			self::$hooks[ $slug ] = (string) add_submenu_page( self::LIST, $page[0], $page[1], $capability, $slug, $page[2] );
		}

		// Reached from the Run and Dry run links. Registered under Total Import so the menu stays open, then taken out of the list.
		self::$hooks[ self::RUN ] = (string) add_submenu_page( self::LIST, __( 'Run import', 'total-import' ), __( 'Run import', 'total-import' ), $capability, self::RUN, array( self::class, 'render_run' ) );
		add_action( 'admin_head', array( self::class, 'hide_run_item' ) );

		add_action( 'load-' . self::$hooks[ self::LIST ], array( ImportsTable::class, 'load' ) );
	}

	/**
	 * Keeps the menu on Imports while editing an import or watching a run.
	 *
	 * @param string|null $submenu_file
	 * @param string      $parent_file
	 * @return string|null
	 */
	public static function submenu_file( $submenu_file, $parent_file ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature.
		$screen = self::current();
		if ( self::RUN === $screen || ( self::EDIT === $screen && self::import_id() > 0 ) ) {
			return self::LIST;
		}
		return $submenu_file;
	}

	public static function hide_run_item(): void {
		remove_submenu_page( self::LIST, self::RUN );
	}

	/**
	 * @param array<string, scalar> $args
	 */
	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array( 'page' => $page ) + $args, admin_url( 'admin.php' ) );
	}

	public static function run_url( int $import_id, bool $dry_run ): string {
		return wp_nonce_url(
			self::url(
				self::RUN,
				array(
					'import' => $import_id,
					'mode'   => $dry_run ? 'dry' : 'live',
				)
			),
			'importright_run_' . $import_id
		);
	}

	public static function enqueue( string $hook ): void {
		$screen = array_search( $hook, self::$hooks, true );
		if ( false === $screen ) {
			return;
		}

		wp_enqueue_style( 'importright-admin', plugins_url( 'assets/admin.css', IMPORTRIGHT_FILE ), array(), IMPORTRIGHT_VERSION );
		wp_enqueue_script( 'importright-admin', plugins_url( 'assets/admin.js', IMPORTRIGHT_FILE ), array( 'wp-i18n', 'wp-hooks' ), IMPORTRIGHT_VERSION, true );
		wp_set_script_translations( 'importright-admin', 'total-import' );
		wp_add_inline_script( 'importright-admin', 'window.importrightAdmin = ' . wp_json_encode( self::script_data( (string) $screen ) ) . ';', 'before' );
		do_action( 'importright_admin_enqueue', (string) $screen );
	}

	public static function render_list(): void {
		self::check();
		$table = new ImportsTable();
		$table->prepare_items();
		?>
		<div class="wrap importright">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Imports', 'total-import' ); ?></h1>
			<a href="<?php echo esc_url( self::url( self::EDIT ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add new import', 'total-import' ); ?></a>
			<hr class="wp-header-end">
			<?php Actions::notices(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::LIST ); ?>">
				<?php $table->display(); ?>
			</form>

			<details class="importright-upload-definition">
				<summary><?php esc_html_e( 'Import a definition (JSON)', 'total-import' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="importright_import_definition">
					<?php wp_nonce_field( 'importright_import_definition' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="importright-definition-file"><?php esc_html_e( 'Definition file', 'total-import' ); ?></label></th>
							<td>
								<input type="file" id="importright-definition-file" name="definition" accept=".json,application/json" required>
								<p class="description"><?php esc_html_e( 'A file saved with Export, or the JSON that wp importright create reads.', 'total-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="importright-definition-data"><?php esc_html_e( 'Data file', 'total-import' ); ?></label></th>
							<td>
								<input type="file" id="importright-definition-data" name="data" accept=".csv,.tsv,.txt,.xlsx,.xml,.json,.zip,.gz">
								<p class="description"><?php esc_html_e( 'Only needed when the import used an uploaded file. URL and server path sources keep their location.', 'total-import' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Import definition', 'total-import' ), 'secondary', 'submit', false ); ?>
				</form>
			</details>
		</div>
		<?php
	}

	public static function render_wizard(): void {
		self::check();
		$import_id = self::import_id();
		$import    = $import_id > 0 ? Imports::get( $import_id ) : null;
		if ( $import_id > 0 && null === $import ) {
			wp_die( esc_html__( 'That import no longer exists.', 'total-import' ) );
		}
		Wizard::render( $import );
	}

	public static function render_run(): void {
		self::check();
		$import_id = self::import_id();
		$import    = Imports::get( $import_id );
		if ( null === $import ) {
			wp_die( esc_html__( 'That import no longer exists.', 'total-import' ) );
		}
		?>
		<div class="wrap importright">
			<h1>
				<?php
				/* translators: %s: import name */
				echo esc_html( sprintf( __( 'Run: %s', 'total-import' ), $import['name'] ) );
				?>
			</h1>
			<p>
				<a href="<?php echo esc_url( self::url( self::LIST ) ); ?>"><?php esc_html_e( 'Back to imports', 'total-import' ); ?></a>
				|
				<a href="<?php echo esc_url( self::url( self::EDIT, array( 'import' => $import_id ) ) ); ?>"><?php esc_html_e( 'Edit import', 'total-import' ); ?></a>
			</p>
			<?php Wizard::run_panel(); ?>
		</div>
		<?php
	}

	public static function render_history(): void {
		self::check();
		$run_id = self::run_id();
		if ( $run_id > 0 ) {
			RunScreen::render( $run_id );
			return;
		}
		$table = new RunsTable( self::import_id() );
		$table->prepare_items();
		?>
		<div class="wrap importright">
			<h1><?php esc_html_e( 'History', 'total-import' ); ?></h1>
			<p><?php esc_html_e( 'Every run of every import. Open a run for its error log and to undo it.', 'total-import' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::HISTORY ); ?>">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	public static function render_migrate(): void {
		self::check();
		Migrate::render();
	}

	public static function render_settings(): void {
		self::check();
		SettingsScreen::render();
	}

	/**
	 * Slug of the Total Import screen being shown, or ''.
	 */
	public static function current(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, array( self::LIST, self::EDIT, self::RUN, self::HISTORY, self::MIGRATE, self::SETTINGS ), true ) ? $page : '';
	}

	public static function import_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; actions check their own nonces.
		return isset( $_GET['import'] ) ? absint( wp_unslash( $_GET['import'] ) ) : 0;
	}

	public static function run_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		return isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0;
	}

	public static function check(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage imports.', 'total-import' ), 403 );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function script_data( string $screen ): array {
		$data = array(
			'screen'  => $screen,
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => Ajax::nonce(),
			'postUrl' => admin_url( 'admin-post.php' ),
			'logCsv'  => array(
				'action' => Actions::LOG_CSV,
				'nonce'  => wp_create_nonce( Actions::LOG_CSV ),
			),
			'urls'    => array(
				'list'    => self::url( self::LIST ),
				'history' => self::url( self::HISTORY ),
			),
		);

		if ( self::RUN === $screen || self::EDIT === $screen ) {
			$import_id         = self::import_id();
			$data['importId']  = $import_id;
			$data['autostart'] = '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked right here before a run may start on load.
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( self::RUN === $screen && $import_id > 0 && false !== wp_verify_nonce( $nonce, 'importright_run_' . $import_id ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
				$data['autostart'] = isset( $_GET['mode'] ) && 'dry' === sanitize_key( wp_unslash( $_GET['mode'] ) ) ? 'dry' : 'live';
			}
		}

		if ( self::EDIT === $screen ) {
			$import             = $data['importId'] > 0 ? Imports::get( $data['importId'] ) : null;
			$definition         = null === $import ? null : $import['definition'];
			$data['definition'] = null === $definition ? null : Wizard::mask( $definition );
			$data['wizard']     = Wizard::data();
		}
		return $data;
	}
}

<?php

namespace Importright\Admin;

use Importright\Plugin;
use Importright\Runs\Background;
use Importright\Settings;
use Importright\Source\Storage;
use Importright\Source\UrlFetcher;

defined( 'ABSPATH' ) || exit;

/**
 * Settings form (saved through admin-post) and the server status box.
 */
final class SettingsScreen {

	public const ACTION = 'importright_save_settings';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage imports.', 'total-import' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$input = array();
		foreach ( array_keys( Settings::LIMITS ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			$input[ $key ] = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
		}
		$input['delete_data'] = ! empty( $_POST['delete_data'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		Settings::save( $input );
		Actions::flash( 'success', __( 'Settings saved.', 'total-import' ) );
		wp_safe_redirect( Screens::url( Screens::SETTINGS ) );
		exit;
	}

	public static function render(): void {
		$settings = Settings::all();
		?>
		<div class="wrap importright importright-settings">
			<h1><?php esc_html_e( 'Total Import settings', 'total-import' ); ?></h1>
			<?php Actions::notices(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<h2><?php esc_html_e( 'Undo data', 'total-import' ); ?></h2>
				<p><?php esc_html_e( 'Each live run keeps what it changed so it can be undone from History. The daily cleanup removes that data once either limit is reached.', 'total-import' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					self::number( 'rollback_keep_runs', __( 'Runs kept per import', 'total-import' ), $settings['rollback_keep_runs'], __( 'The newest runs of each import that can be undone.', 'total-import' ) );
					self::number( 'rollback_keep_days', __( 'Days kept', 'total-import' ), $settings['rollback_keep_days'], __( 'Undo data older than this is removed even for the newest runs.', 'total-import' ) );
					?>
				</table>
				<h2><?php esc_html_e( 'Imports', 'total-import' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::number( 'batch_size', __( 'Records per batch for new imports', 'total-import' ), $settings['batch_size'], __( 'Each import can change it in its own settings. Lower it if runs time out.', 'total-import' ) );
					self::number( 'max_image_mb', __( 'Largest image to download (MB)', 'total-import' ), $settings['max_image_mb'], __( 'Bigger images are skipped with a notice in the run log.', 'total-import' ) );
					?>
				</table>
				<h2><?php esc_html_e( 'Uninstall', 'total-import' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'When the plugin is deleted', 'total-import' ); ?></th>
						<td>
							<label><input type="checkbox" name="delete_data" value="1" aria-describedby="importright-delete-data-help" <?php checked( $settings['delete_data'] ); ?>> <?php esc_html_e( 'Delete all Total Import data', 'total-import' ); ?></label>
							<p class="description" id="importright-delete-data-help"><?php esc_html_e( 'Imports, run history, undo data and uploaded source files. Off by default, so reinstalling picks up where you left off. Posts, terms, users and products you imported are never deleted.', 'total-import' ); ?></p>
						</td>
					</tr>
				</table>
				<?php do_action( 'importright_settings_sections' ); ?>
				<?php submit_button( __( 'Save settings', 'total-import' ) ); ?>
			</form>

			<section class="importright-panel" aria-labelledby="importright-status-title">
				<h2 id="importright-status-title"><?php esc_html_e( 'Server status', 'total-import' ); ?></h2>
				<table class="widefat striped importright-status-table">
					<tbody>
						<?php foreach ( self::checks() as $check ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $check['label'] ); ?></th>
								<td>
									<span class="importright-status importright-check-<?php echo esc_attr( $check['state'] ); ?>"><?php echo esc_html( 'ok' === $check['state'] ? __( 'OK', 'total-import' ) : __( 'Check', 'total-import' ) ); ?></span>
									<?php echo esc_html( $check['text'] ); ?>
									<?php if ( isset( $check['link'] ) ) : ?>
										<a href="<?php echo esc_url( $check['link']['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $check['link']['label'] ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		</div>
		<?php
	}

	private static function number( string $key, string $label, int $value, string $help ): void {
		$range = Settings::LIMITS[ $key ];
		$id    = 'importright-' . str_replace( '_', '-', $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="number" class="small-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" min="<?php echo esc_attr( (string) $range[0] ); ?>" max="<?php echo esc_attr( (string) $range[1] ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-help">
				<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * @return array<int, array{label: string, state: string, text: string, link?: array{url: string, label: string}}>
	 */
	public static function checks(): array {
		$checks = array();

		if ( Background::uses_action_scheduler() ) {
			$version  = class_exists( 'ActionScheduler_Versions', false ) ? (string) \ActionScheduler_Versions::instance()->latest_version() : '';
			$source   = class_exists( 'ActionScheduler_SystemInformation' ) && method_exists( 'ActionScheduler_SystemInformation', 'active_source_path' ) ? (string) \ActionScheduler_SystemInformation::active_source_path() : '';
			$plugin   = '' !== $source && 0 === strpos( wp_normalize_path( $source ), wp_normalize_path( WP_PLUGIN_DIR ) . '/' ) ? strtok( substr( wp_normalize_path( $source ), strlen( wp_normalize_path( WP_PLUGIN_DIR ) ) + 1 ), '/' ) : '';
			$checks[] = array(
				'label' => __( 'Background runs', 'total-import' ),
				'state' => 'ok',
				'text'  => '' === $plugin
					/* translators: %s: Action Scheduler version */
					? sprintf( __( 'Action Scheduler %s.', 'total-import' ), $version )
					/* translators: 1: Action Scheduler version, 2: plugin folder that loaded it */
					: sprintf( __( 'Action Scheduler %1$s, loaded by %2$s.', 'total-import' ), $version, $plugin ),
			);
		} else {
			$checks[] = array(
				'label' => __( 'Background runs', 'total-import' ),
				'state' => 'ok',
				'text'  => __( 'WP-Cron. Total Import uses Action Scheduler when another plugin, such as WooCommerce, loads it. WP-Cron runs only when the site gets visits, so for big imports a real cron job is better.', 'total-import' ),
				'link'  => array(
					'url'   => 'https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/',
					'label' => __( 'Hooking WP-Cron into the system task scheduler', 'total-import' ),
				),
			);
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$checks[] = array(
				'label' => __( 'WP-Cron', 'total-import' ),
				'state' => 'warning',
				'text'  => __( 'DISABLE_WP_CRON is set. Make sure a server cron job calls wp-cron.php, or background runs and the daily cleanup won\'t happen.', 'total-import' ),
			);
		}

		$memory   = (string) ini_get( 'memory_limit' );
		$bytes    = '-1' === $memory ? -1 : wp_convert_hr_to_bytes( $memory );
		$checks[] = array(
			'label' => __( 'PHP memory limit', 'total-import' ),
			'state' => -1 === $bytes || $bytes >= 128 * MB_IN_BYTES ? 'ok' : 'warning',
			'text'  => -1 === $bytes ? __( 'Unlimited.', 'total-import' ) : ( $bytes >= 128 * MB_IN_BYTES
				? (string) size_format( $bytes )
				/* translators: %s: memory size */
				: sprintf( __( '%s. Imports run with less, but 128 MB or more is safer for XLSX files and image downloads.', 'total-import' ), size_format( $bytes ) ) ),
		);

		$time     = (int) ini_get( 'max_execution_time' );
		$checks[] = array(
			'label' => __( 'PHP time limit', 'total-import' ),
			'state' => 0 === $time || $time >= 30 ? 'ok' : 'warning',
			'text'  => 0 === $time
				? __( 'None.', 'total-import' )
				/* translators: %d: seconds */
				: sprintf( _n( '%d second. Batches stop after 10 to 20 seconds, so 30 or more is enough.', '%d seconds. Batches stop after 10 to 20 seconds, so 30 or more is enough.', $time, 'total-import' ), $time ),
		);

		$dir      = Storage::dir();
		$writable = ! is_wp_error( $dir ) && wp_is_writable( $dir );
		$checks[] = array(
			'label' => __( 'Upload folder', 'total-import' ),
			'state' => $writable ? 'ok' : 'warning',
			'text'  => is_wp_error( $dir ) ? $dir->get_error_message() : ( $writable ? __( 'Writable. Uploaded and downloaded files are kept in a protected folder inside uploads.', 'total-import' ) : __( 'Importright\'s folder inside uploads isn\'t writable, so files can\'t be uploaded or downloaded.', 'total-import' ) ),
		);

		$checks[] = array(
			'label' => __( 'URL sources and image downloads', 'total-import' ),
			'state' => UrlFetcher::can_pin() ? 'ok' : 'warning',
			'text'  => UrlFetcher::can_pin()
				? __( 'cURL with CURLOPT_RESOLVE is available.', 'total-import' )
				: __( 'PHP\'s cURL extension (with CURLOPT_RESOLVE) is missing. Total Import needs it to download from URLs safely, so URL sources and image URLs will fail. Ask your host to enable cURL.', 'total-import' ),
		);

		$checks[] = array(
			'label' => __( 'Excel files', 'total-import' ),
			'state' => class_exists( 'ZipArchive' ) ? 'ok' : 'warning',
			'text'  => class_exists( 'ZipArchive' ) ? __( 'ZipArchive is available.', 'total-import' ) : __( 'PHP\'s zip extension is missing, so XLSX and zipped files can\'t be read.', 'total-import' ),
		);

		return $checks;
	}
}

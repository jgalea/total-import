<?php

namespace Importright\Admin;

use Importright\Imports\Imports;
use Importright\Migration\Migrator;
use Importright\Migration\WpaiSite;

defined( 'ABSPATH' ) || exit;

/**
 * The "Migrate from WP All Import" screen. Listing reads WP All Import's tables;
 * preview and migrate go through the migrate_* AJAX endpoints and Migrator.
 */
final class Migrate {

	public static function render(): void {
		$site      = new WpaiSite();
		$available = $site->available();
		$imports   = $available ? $site->imports() : array();
		$migrated  = Migrator::migrated();
		?>
		<div class="wrap importright importright-migrate">
			<h1><?php esc_html_e( 'Migrate from WP All Import', 'total-import' ); ?></h1>
			<p><?php esc_html_e( 'Turn WP All Import imports into Total Import imports. Check the preview first: it lists every setting as mapped, approximated (close, check it) or unsupported (left out). WP All Import and its data aren\'t changed.', 'total-import' ); ?></p>

			<section class="importright-panel" aria-labelledby="importright-wpai-title">
				<h2 id="importright-wpai-title"><?php esc_html_e( 'Imports on this site', 'total-import' ); ?></h2>
				<?php if ( ! $available ) : ?>
					<p><?php esc_html_e( 'WP All Import\'s tables aren\'t on this site. You can still migrate an exported template below.', 'total-import' ); ?></p>
				<?php elseif ( array() === $imports ) : ?>
					<p><?php esc_html_e( 'WP All Import has no imports on this site.', 'total-import' ); ?></p>
				<?php else : ?>
					<div class="importright-scroll" tabindex="0" role="region" aria-labelledby="importright-wpai-title">
						<table class="widefat striped importright-wpai importright-stack">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'WP All Import import', 'total-import' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Source', 'total-import' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Imports into', 'total-import' ); ?></th>
									<th scope="col" class="num"><?php esc_html_e( 'Records', 'total-import' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Actions', 'total-import' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $imports as $import ) :
									$target = $migrated[ $import['id'] ] ?? 0;
									$exists = $target > 0 && null !== Imports::get( $target );
									?>
									<tr>
										<td data-label="<?php esc_attr_e( 'WP All Import import', 'total-import' ); ?>">
											<strong><?php echo esc_html( '' !== $import['name'] ? $import['name'] : __( '(no name)', 'total-import' ) ); ?></strong>
											<?php /* translators: %d: WP All Import import ID */ ?>
											<br><span class="importright-muted"><?php echo esc_html( sprintf( __( 'ID %d', 'total-import' ), $import['id'] ) ); ?></span>
											<?php if ( $target > 0 ) : ?>
												<br>
												<?php if ( $exists ) : ?>
													<a href="<?php echo esc_url( Screens::url( Screens::EDIT, array( 'import' => $target ) ) ); ?>">
														<?php
														/* translators: %d: import ID */
														echo esc_html( sprintf( __( 'Migrated to import %d', 'total-import' ), $target ) );
														?>
													</a>
												<?php else : ?>
													<span class="importright-muted">
														<?php
														/* translators: %d: import ID */
														echo esc_html( sprintf( __( 'Migrated before, to import %d (since deleted)', 'total-import' ), $target ) );
														?>
													</span>
												<?php endif; ?>
											<?php endif; ?>
										</td>
										<td data-label="<?php esc_attr_e( 'Source', 'total-import' ); ?>">
											<span class="importright-muted"><?php echo esc_html( self::source_label( $import['type'] ) . ( '' !== $import['feed_type'] ? ', ' . strtoupper( $import['feed_type'] ) : '' ) ); ?></span>
											<br><code class="importright-source"><?php echo esc_html( basename( $import['path'] ) ); ?></code>
										</td>
										<td data-label="<?php esc_attr_e( 'Imports into', 'total-import' ); ?>"><?php echo esc_html( self::target_label( $import['post_type'] ) ); ?></td>
										<td class="num" data-label="<?php esc_attr_e( 'Records', 'total-import' ); ?>"><?php echo esc_html( number_format_i18n( $import['records'] ) ); ?></td>
										<td data-label="<?php esc_attr_e( 'Actions', 'total-import' ); ?>">
											<span class="importright-row-actions">
												<button type="button" class="button" data-migrate-site="<?php echo esc_attr( (string) $import['id'] ); ?>" data-dry-run="1"><?php esc_html_e( 'Preview migration', 'total-import' ); ?></button>
												<button type="button" class="button button-primary" data-migrate-site="<?php echo esc_attr( (string) $import['id'] ); ?>" data-dry-run="0" <?php echo $exists ? 'data-migrated="' . esc_attr( (string) $target ) . '"' : ''; ?>><?php esc_html_e( 'Migrate', 'total-import' ); ?></button>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<div class="importright-migrate-result" id="importright-migrate-site-result" hidden tabindex="-1"></div>
				<?php endif; ?>
			</section>

			<section class="importright-panel" aria-labelledby="importright-template-title">
				<h2 id="importright-template-title"><?php esc_html_e( 'From a template file', 'total-import' ); ?></h2>
				<p><?php esc_html_e( 'Use a template exported from WP All Import (Settings > Templates > Export) or a bundle zip from WP All Export, for example from another site.', 'total-import' ); ?></p>
				<form id="importright-template-form" novalidate>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="importright-template-file"><?php esc_html_e( 'Template or bundle', 'total-import' ); ?></label></th>
							<td>
								<input type="file" id="importright-template-file" accept=".txt,.json,.zip" aria-describedby="importright-template-file-help">
								<p class="description" id="importright-template-file-help"><?php esc_html_e( 'A templates_….txt file, or the .zip WP All Export makes. A bundle already holds the data file.', 'total-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="importright-template-data"><?php esc_html_e( 'Data file', 'total-import' ); ?></label></th>
							<td>
								<input type="file" id="importright-template-data" accept=".csv,.tsv,.txt,.xlsx,.xml,.json,.zip,.gz" aria-describedby="importright-template-data-help">
								<p class="description" id="importright-template-data-help"><?php esc_html_e( 'The file the import reads. Needed for a .txt template; for a bundle it replaces the bundled file.', 'total-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="importright-template-index"><?php esc_html_e( 'Template position', 'total-import' ); ?></label></th>
							<td>
								<input type="number" id="importright-template-index" class="small-text" min="0" value="0" aria-describedby="importright-template-index-help">
								<p class="description" id="importright-template-index-help"><?php esc_html_e( 'When the file holds several templates, which one to use. The first is 0.', 'total-import' ); ?></p>
							</td>
						</tr>
					</table>
					<p class="importright-row-actions">
						<button type="button" class="button" data-migrate-template data-dry-run="1"><?php esc_html_e( 'Preview migration', 'total-import' ); ?></button>
						<button type="button" class="button button-primary" data-migrate-template data-dry-run="0"><?php esc_html_e( 'Migrate', 'total-import' ); ?></button>
						<span class="importright-save-status" id="importright-template-status" role="status" aria-live="polite"></span>
					</p>
				</form>
				<div class="importright-migrate-result" id="importright-migrate-template-result" hidden tabindex="-1"></div>
			</section>
		</div>
		<?php
	}

	private static function source_label( string $type ): string {
		$labels = array(
			'upload' => __( 'Upload', 'total-import' ),
			'file'   => __( 'Server file', 'total-import' ),
			'url'    => __( 'URL', 'total-import' ),
			'ftp'    => __( 'FTP', 'total-import' ),
		);
		return $labels[ $type ] ?? $type;
	}

	private static function target_label( string $post_type ): string {
		if ( 0 === strpos( $post_type, 'taxonomies:' ) ) {
			$taxonomy = get_taxonomy( substr( $post_type, 11 ) );
			/* translators: %s: taxonomy name */
			return sprintf( __( 'Terms: %s', 'total-import' ), false === $taxonomy ? substr( $post_type, 11 ) : $taxonomy->labels->name );
		}
		$object = get_post_type_object( $post_type );
		return null === $object ? $post_type : (string) $object->labels->name;
	}
}

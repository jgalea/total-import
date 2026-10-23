<?php

namespace Importright\Admin;

use Importright\Registry;
use Importright\Settings;
use Importright\Source\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * The new/edit import screen: one form in six steps. The server renders the
 * fixed parts; admin.js builds the mapping fields for the chosen target and
 * fills everything from the saved definition when editing.
 */
final class Wizard {

	private const HIDDEN_POST_TYPES = array( 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_order_placehold' );

	private const HIDDEN_TAXONOMIES = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category', 'product_type', 'product_visibility' );

	/**
	 * @param array<string, mixed>|null $import
	 */
	public static function render( ?array $import ): void {
		$editing = null !== $import;
		$data    = self::data();
		?>
		<div class="wrap importright importright-wizard">
			<h1>
				<?php
				if ( $editing ) {
					/* translators: %s: import name */
					echo esc_html( sprintf( __( 'Edit import: %s', 'total-import' ), $import['name'] ) );
				} else {
					esc_html_e( 'New import', 'total-import' );
				}
				?>
			</h1>
			<?php Actions::notices(); ?>
			<div id="importright-notice" class="notice inline hidden" role="status" aria-live="polite"><p></p></div>

			<nav aria-label="<?php esc_attr_e( 'Import steps', 'total-import' ); ?>">
				<ol class="importright-steps">
					<?php foreach ( self::steps() as $index => $label ) : ?>
						<li><button type="button" class="button-link" data-goto="<?php echo esc_attr( $index ); ?>"><?php echo esc_html( $label ); ?></button></li>
					<?php endforeach; ?>
				</ol>
			</nav>

			<form id="importright-wizard" novalidate>
				<?php
				self::source_step( $data );
				self::preview_step();
				self::target_step( $data );
				self::mapping_step();
				self::matching_step();
				self::run_step();
				?>
			</form>
			<noscript><div class="notice notice-error inline"><p><?php esc_html_e( 'The import wizard needs JavaScript. You can also create imports with wp importright create.', 'total-import' ); ?></p></div></noscript>
		</div>
		<?php
	}

	/**
	 * Run and dry run controls with progress, results and preview. Used by the wizard's last step and the run screen.
	 */
	public static function run_panel(): void {
		?>
		<div class="importright-run" id="importright-run">
			<div class="importright-run-actions">
				<button type="button" class="button" data-run="dry"><?php esc_html_e( 'Dry run', 'total-import' ); ?></button>
				<button type="button" class="button button-primary" data-run="live"><?php esc_html_e( 'Run import', 'total-import' ); ?></button>
			</div>
			<p class="description"><?php esc_html_e( 'A dry run reads the file and shows what would be created, updated or skipped, without writing anything.', 'total-import' ); ?></p>

			<div class="importright-progress" hidden>
				<label for="importright-progress-bar" class="importright-progress-label"><?php esc_html_e( 'Progress', 'total-import' ); ?></label>
				<progress id="importright-progress-bar" max="100"></progress>
				<p class="importright-progress-status" role="status" aria-live="polite"></p>
				<div class="importright-run-actions">
					<button type="button" class="button" data-run-control="cancel"><?php esc_html_e( 'Cancel', 'total-import' ); ?></button>
					<button type="button" class="button" data-run-control="background"><?php esc_html_e( 'Continue in the background', 'total-import' ); ?></button>
				</div>
			</div>

			<div class="importright-result" hidden tabindex="-1"></div>
		</div>
		<?php
	}

	/**
	 * Definition safe to hand to the browser: the URL password is replaced by a flag, and importright_mask_source can hide other source secrets.
	 *
	 * @param array<string, mixed> $definition
	 * @return array<string, mixed>
	 */
	public static function mask( array $definition ): array {
		if ( 'url' === ( $definition['source']['type'] ?? '' ) ) {
			$definition['source']['has_password'] = '' !== (string) ( $definition['source']['password'] ?? '' );
		}
		if ( is_array( $definition['source'] ?? null ) ) {
			$definition['source'] = Sources::redact( $definition['source'] );
		}
		return $definition;
	}

	/**
	 * What the wizard's pickers and mapping fields are built from.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$targets = array();
		foreach ( Registry::targets() as $key => $target ) {
			$targets[ $key ] = array(
				'label'      => $target->label(),
				'fields'     => $target->fields(),
				'strategies' => $target->match_strategies(),
				'handlers'   => array_keys( Registry::handlers_for( $key ) ),
			);
		}

		$post_types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, self::HIDDEN_POST_TYPES, true ) ) {
				continue;
			}
			$post_types[] = array(
				'name'       => $type->name,
				'label'      => $type->labels->name,
				'taxonomies' => self::taxonomies( get_object_taxonomies( $type->name, 'objects' ) ),
			);
		}

		$statuses = array();
		foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $name ) {
			$status     = get_post_status_object( $name );
			$statuses[] = array(
				'name'  => $name,
				'label' => null === $status ? $name : (string) $status->label,
			);
		}

		$roles = array();
		foreach ( wp_roles()->get_names() as $name => $label ) {
			$roles[] = array(
				'name'  => $name,
				'label' => translate_user_role( $label ),
			);
		}

		$attributes = array();
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attribute ) {
				$attributes[] = array(
					'name'  => 'pa_' . $attribute->attribute_name,
					'label' => $attribute->attribute_label,
				);
			}
		}

		$data = array(
			'targets'           => $targets,
			'postTypes'         => $post_types,
			'termTaxonomies'    => self::taxonomies( get_taxonomies( array( 'show_ui' => true ), 'objects' ) ),
			'productTaxonomies' => isset( $targets['product'] ) ? self::taxonomies( get_object_taxonomies( 'product', 'objects' ) ) : array(),
			'statuses'          => $statuses,
			'roles'             => $roles,
			'defaultRole'       => (string) get_option( 'default_role', 'subscriber' ),
			'attributes'        => $attributes,
			'seo'               => array(
				'yoast'    => defined( 'WPSEO_VERSION' ),
				'rankmath' => defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ),
			),
			'canPath'           => Inspector::can_use_paths(),
			'maxUpload'         => wp_max_upload_size(),
		);

		$filtered = apply_filters( 'importright_wizard_data', $data );
		return is_array( $filtered ) ? $filtered : $data;
	}

	/**
	 * Registered targets the wizard has no built-in radio for, as key => label.
	 *
	 * @param array<string, mixed> $targets The targets entry of data().
	 * @return array<string, string>
	 */
	public static function extra_targets( array $targets ): array {
		$extra = array();
		foreach ( $targets as $key => $info ) {
			if ( ! in_array( $key, array( 'post', 'term', 'user', 'product' ), true ) ) {
				$extra[ (string) $key ] = (string) ( $info['label'] ?? $key );
			}
		}
		return $extra;
	}

	public static function show_hints(): bool {
		return (bool) apply_filters( 'importright_pro_hints', true );
	}

	/**
	 * @return array<string, string>
	 */
	private static function steps(): array {
		return array(
			'source'   => __( 'Source', 'total-import' ),
			'preview'  => __( 'Preview', 'total-import' ),
			'target'   => __( 'Target', 'total-import' ),
			'mapping'  => __( 'Mapping', 'total-import' ),
			'matching' => __( 'Matching and updates', 'total-import' ),
			'run'      => __( 'Save and run', 'total-import' ),
		);
	}

	/**
	 * @param array<int|string, \WP_Taxonomy> $taxonomies
	 * @return array<int, array{name: string, label: string, hierarchical: bool}>
	 */
	private static function taxonomies( array $taxonomies ): array {
		$list = array();
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->show_ui || in_array( $taxonomy->name, self::HIDDEN_TAXONOMIES, true ) || 0 === strpos( $taxonomy->name, 'pa_' ) ) {
				continue;
			}
			$list[] = array(
				'name'         => $taxonomy->name,
				'label'        => $taxonomy->labels->name,
				'hierarchical' => (bool) $taxonomy->hierarchical,
			);
		}
		return $list;
	}

	private static function step_start( string $id, string $title, string $intro = '' ): void {
		$steps  = array_keys( self::steps() );
		$number = (int) array_search( $id, $steps, true ) + 1;
		?>
		<section class="importright-step" id="importright-step-<?php echo esc_attr( $id ); ?>" data-step="<?php echo esc_attr( $id ); ?>" aria-labelledby="importright-step-<?php echo esc_attr( $id ); ?>-title" hidden>
			<h2 id="importright-step-<?php echo esc_attr( $id ); ?>-title" tabindex="-1">
				<?php
				/* translators: 1: step number, 2: step name */
				echo esc_html( sprintf( __( 'Step %1$d: %2$s', 'total-import' ), $number, $title ) );
				?>
			</h2>
			<?php if ( '' !== $intro ) : ?>
				<p><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>
		<?php
	}

	private static function step_end( bool $back = true, string $next = '' ): void {
		?>
			<p class="importright-step-nav">
				<?php if ( $back ) : ?>
					<button type="button" class="button" data-nav="back"><?php esc_html_e( 'Back', 'total-import' ); ?></button>
				<?php endif; ?>
				<?php if ( '' !== $next ) : ?>
					<button type="button" class="button button-primary" data-nav="next"><?php echo esc_html( $next ); ?></button>
				<?php endif; ?>
			</p>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function source_step( array $data ): void {
		self::step_start( 'source', __( 'Source', 'total-import' ) );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="importright-name"><?php esc_html_e( 'Import name', 'total-import' ); ?></label></th>
				<td><input type="text" id="importright-name" class="regular-text" required autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Read from', 'total-import' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Read from', 'total-import' ); ?></legend>
						<label><input type="radio" name="source_type" value="upload" checked> <?php esc_html_e( 'A file I upload', 'total-import' ); ?></label><br>
						<label><input type="radio" name="source_type" value="url"> <?php esc_html_e( 'A URL (also published Google Sheets)', 'total-import' ); ?></label>
						<?php if ( $data['canPath'] ) : ?>
							<br><label><input type="radio" name="source_type" value="path"> <?php esc_html_e( 'A file on this server', 'total-import' ); ?></label>
						<?php endif; ?>
					</fieldset>
				</td>
			</tr>
			<tr data-source="upload">
				<th scope="row"><label for="importright-file"><?php esc_html_e( 'File', 'total-import' ); ?></label></th>
				<td>
					<input type="file" id="importright-file" accept=".csv,.tsv,.txt,.xlsx,.xml,.json,.zip,.gz" aria-describedby="importright-file-help">
					<p class="description" id="importright-file-help">
						<?php
						/* translators: %s: maximum upload size, e.g. "64 MB" */
						echo esc_html( sprintf( __( 'CSV, TSV, Excel (XLSX), XML or JSON, also zipped or gzipped. Up to %s.', 'total-import' ), size_format( (int) $data['maxUpload'] ) ) );
						?>
					</p>
					<p class="importright-file-status" id="importright-upload-status" aria-live="polite"></p>
				</td>
			</tr>
			<tr data-source="url" hidden>
				<th scope="row"><label for="importright-url"><?php esc_html_e( 'URL', 'total-import' ); ?></label></th>
				<td>
					<div class="importright-inline">
						<input type="url" id="importright-url" class="large-text code" placeholder="https://" autocomplete="off" spellcheck="false">
						<button type="button" class="button" id="importright-test-url"><?php esc_html_e( 'Test URL', 'total-import' ); ?></button>
					</div>
					<p class="importright-file-status" id="importright-url-status" aria-live="polite"></p>
					<details class="importright-auth">
						<summary><?php esc_html_e( 'HTTP authentication', 'total-import' ); ?></summary>
						<p>
							<label for="importright-url-username"><?php esc_html_e( 'Username', 'total-import' ); ?></label><br>
							<input type="text" id="importright-url-username" class="regular-text" autocomplete="off">
						</p>
						<p>
							<label for="importright-url-password"><?php esc_html_e( 'Password', 'total-import' ); ?></label><br>
							<input type="password" id="importright-url-password" class="regular-text" autocomplete="new-password" aria-describedby="importright-url-password-help">
						</p>
						<p class="description" id="importright-url-password-help"><?php esc_html_e( 'Sent only to this host, over the same scheme and port. It is stored with the import in plain text, so use a read-only account.', 'total-import' ); ?></p>
						<div class="notice notice-warning inline importright-plain-http" id="importright-url-plain-http" hidden>
							<p><?php esc_html_e( 'This URL starts with http://, so the username and password travel unencrypted. Use https:// if the server supports it.', 'total-import' ); ?></p>
						</div>
					</details>
				</td>
			</tr>
			<?php if ( $data['canPath'] ) : ?>
				<tr data-source="path" hidden>
					<th scope="row"><label for="importright-path"><?php esc_html_e( 'Path', 'total-import' ); ?></label></th>
					<td>
						<div class="importright-inline">
							<input type="text" id="importright-path" class="large-text code" placeholder="wp-content/uploads/feed.csv" autocomplete="off" spellcheck="false" aria-describedby="importright-path-help">
							<button type="button" class="button" id="importright-read-path"><?php esc_html_e( 'Read file', 'total-import' ); ?></button>
						</div>
						<p class="description" id="importright-path-help"><?php esc_html_e( 'Relative to the WordPress folder, or absolute. The file must be inside the WordPress folder or the uploads folder.', 'total-import' ); ?></p>
						<p class="importright-file-status" id="importright-path-status" aria-live="polite"></p>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><label for="importright-format"><?php esc_html_e( 'Format', 'total-import' ); ?></label></th>
				<td>
					<select id="importright-format">
						<option value="auto"><?php esc_html_e( 'Detect', 'total-import' ); ?></option>
						<option value="csv">CSV</option>
						<option value="tsv">TSV</option>
						<option value="xlsx"><?php esc_html_e( 'Excel (XLSX)', 'total-import' ); ?></option>
						<option value="xml">XML</option>
						<option value="json">JSON</option>
					</select>
					<span class="importright-detected" id="importright-format-detected"></span>
				</td>
			</tr>
			<tr data-format="csv" hidden>
				<th scope="row"><label for="importright-delimiter"><?php esc_html_e( 'Delimiter', 'total-import' ); ?></label></th>
				<td>
					<select id="importright-delimiter">
						<option value=""><?php esc_html_e( 'Detect', 'total-import' ); ?></option>
						<option value=","><?php esc_html_e( 'Comma (,)', 'total-import' ); ?></option>
						<option value=";"><?php esc_html_e( 'Semicolon (;)', 'total-import' ); ?></option>
						<option value="&#9;"><?php esc_html_e( 'Tab', 'total-import' ); ?></option>
						<option value="|"><?php esc_html_e( 'Pipe (|)', 'total-import' ); ?></option>
					</select>
					<span class="importright-detected" id="importright-delimiter-detected"></span>
				</td>
			</tr>
			<tr data-format="csv" hidden>
				<th scope="row"><label for="importright-encoding"><?php esc_html_e( 'Encoding', 'total-import' ); ?></label></th>
				<td>
					<select id="importright-encoding">
						<option value=""><?php esc_html_e( 'Detect', 'total-import' ); ?></option>
						<option value="UTF-8">UTF-8</option>
						<option value="Windows-1252">Windows-1252</option>
						<option value="ISO-8859-1">ISO-8859-1</option>
						<option value="UTF-16">UTF-16</option>
					</select>
					<span class="importright-detected" id="importright-encoding-detected"></span>
				</td>
			</tr>
			<tr data-format="csv xlsx" hidden>
				<th scope="row"><?php esc_html_e( 'Header row', 'total-import' ); ?></th>
				<td><label><input type="checkbox" id="importright-has-header" checked> <?php esc_html_e( 'The first row holds column names', 'total-import' ); ?></label></td>
			</tr>
			<tr data-format="xlsx" hidden>
				<th scope="row"><label for="importright-sheet"><?php esc_html_e( 'Sheet', 'total-import' ); ?></label></th>
				<td><select id="importright-sheet"><option value=""><?php esc_html_e( 'First sheet', 'total-import' ); ?></option></select></td>
			</tr>
			<tr data-format="xml" hidden>
				<th scope="row"><label for="importright-record-path"><?php esc_html_e( 'Record element', 'total-import' ); ?></label></th>
				<td>
					<select id="importright-record-path" aria-describedby="importright-record-path-help"><option value=""><?php esc_html_e( 'Detect', 'total-import' ); ?></option></select>
					<p class="description" id="importright-record-path-help"><?php esc_html_e( 'The element that repeats once per record, such as /rss/channel/item. Each one becomes a row.', 'total-import' ); ?></p>
				</td>
			</tr>
			<tr data-format="json" hidden>
				<th scope="row"><label for="importright-json-path"><?php esc_html_e( 'Records at', 'total-import' ); ?></label></th>
				<td>
					<input type="text" id="importright-json-path" class="regular-text code" placeholder="data.items" autocomplete="off" spellcheck="false" aria-describedby="importright-json-path-help">
					<p class="description" id="importright-json-path-help"><?php esc_html_e( 'Leave empty to use the first list of objects in the file.', 'total-import' ); ?></p>
				</td>
			</tr>
			<?php do_action( 'importright_wizard_source_fields' ); ?>
		</table>
		<?php
		self::step_end( false, __( 'Preview records', 'total-import' ) );
	}

	private static function preview_step(): void {
		self::step_start( 'preview', __( 'Preview', 'total-import' ), __( 'The first records as Total Import reads them. Each column becomes a {Column} you can use in the mapping.', 'total-import' ) );
		?>
		<p class="importright-summary" id="importright-preview-summary"></p>
		<div class="importright-scroll" id="importright-preview-table" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'First records', 'total-import' ); ?>"></div>
		<h3><?php esc_html_e( 'Columns', 'total-import' ); ?></h3>
		<p class="description"><?php esc_html_e( 'In the mapping step, click a column to insert it into the field you were last in, or drag it into any field.', 'total-import' ); ?></p>
		<div class="importright-chips" data-chips></div>
		<?php
		self::step_end( true, __( 'Choose target', 'total-import' ) );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function target_step( array $data ): void {
		self::step_start( 'target', __( 'Target', 'total-import' ) );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Import into', 'total-import' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Import into', 'total-import' ); ?></legend>
						<label><input type="radio" name="target_type" value="post" checked> <?php esc_html_e( 'Posts, pages or a custom post type', 'total-import' ); ?></label><br>
						<label><input type="radio" name="target_type" value="term"> <?php esc_html_e( 'Taxonomy terms (categories, tags…)', 'total-import' ); ?></label><br>
						<label><input type="radio" name="target_type" value="user"> <?php esc_html_e( 'Users', 'total-import' ); ?></label>
						<?php if ( isset( $data['targets']['product'] ) ) : ?>
							<br><label><input type="radio" name="target_type" value="product"> <?php esc_html_e( 'WooCommerce simple products', 'total-import' ); ?></label>
						<?php endif; ?>
						<?php foreach ( self::extra_targets( $data['targets'] ) as $key => $label ) : ?>
							<br><label><input type="radio" name="target_type" value="<?php echo esc_attr( $key ); ?>"> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
			<tr data-target="post">
				<th scope="row"><label for="importright-post-type"><?php esc_html_e( 'Post type', 'total-import' ); ?></label></th>
				<td>
					<select id="importright-post-type">
						<?php foreach ( $data['postTypes'] as $type ) : ?>
							<option value="<?php echo esc_attr( $type['name'] ); ?>"><?php echo esc_html( $type['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr data-target="post product">
				<th scope="row"><label for="importright-default-status"><?php esc_html_e( 'Status of new items', 'total-import' ); ?></label></th>
				<td>
					<select id="importright-default-status" aria-describedby="importright-default-status-help">
						<?php foreach ( $data['statuses'] as $status ) : ?>
							<option value="<?php echo esc_attr( $status['name'] ); ?>"><?php echo esc_html( $status['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description" id="importright-default-status-help"><?php esc_html_e( 'Used when the Status field isn\'t mapped or holds an unknown status.', 'total-import' ); ?></p>
				</td>
			</tr>
			<tr data-target="term" hidden>
				<th scope="row"><label for="importright-taxonomy"><?php esc_html_e( 'Taxonomy', 'total-import' ); ?></label></th>
				<td>
					<select id="importright-taxonomy">
						<?php foreach ( $data['termTaxonomies'] as $taxonomy ) : ?>
							<option value="<?php echo esc_attr( $taxonomy['name'] ); ?>"><?php echo esc_html( $taxonomy['label'] . ' (' . $taxonomy['name'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr data-target="user" hidden>
				<th scope="row"><label for="importright-default-role"><?php esc_html_e( 'Role of new users', 'total-import' ); ?></label></th>
				<td>
					<select id="importright-default-role">
						<?php foreach ( $data['roles'] as $role ) : ?>
							<option value="<?php echo esc_attr( $role['name'] ); ?>" <?php selected( $role['name'], $data['defaultRole'] ); ?>><?php echo esc_html( $role['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr data-target="user" hidden>
				<th scope="row"><?php esc_html_e( 'User options', 'total-import' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'User options', 'total-import' ); ?></legend>
						<label><input type="checkbox" id="importright-send-notification"> <?php esc_html_e( 'Email new users a link to set their password', 'total-import' ); ?></label><br>
						<label><input type="checkbox" id="importright-allow-privileged"> <?php esc_html_e( 'Allow roles that can manage the site, such as Administrator', 'total-import' ); ?></label>
						<p class="description"><?php esc_html_e( 'Passwords are never imported. Existing administrators are only updated when privileged roles are allowed.', 'total-import' ); ?></p>
					</fieldset>
				</td>
			</tr>
			<tr data-target="product" hidden>
				<th scope="row"><?php esc_html_e( 'Price and stock updates', 'total-import' ); ?></th>
				<td>
					<label><input type="checkbox" id="importright-fast-price-stock" checked> <?php esc_html_e( 'Write price and stock changes directly when nothing else changed (much faster for feeds)', 'total-import' ); ?></label>
				</td>
			</tr>
			<?php do_action( 'importright_wizard_target_fields' ); ?>
		</table>
		<?php
		self::step_end( true, __( 'Map fields', 'total-import' ) );
	}

	private static function mapping_step(): void {
		self::step_start( 'mapping', __( 'Mapping', 'total-import' ), __( 'Type text and {Column} references into each field. Leave a field empty to skip it.', 'total-import' ) );
		?>
		<div class="importright-mapping-tools">
			<div class="importright-chips" data-chips data-insert></div>
			<p class="importright-preview-record">
				<label for="importright-preview-record"><?php esc_html_e( 'Preview values with record', 'total-import' ); ?></label>
				<select id="importright-preview-record"></select>
			</p>
		</div>
		<div id="importright-mapping"></div>
		<?php
		self::step_end( true, __( 'Matching and updates', 'total-import' ) );
	}

	private static function matching_step(): void {
		self::step_start( 'matching', __( 'Matching and updates', 'total-import' ) );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="importright-unique-key"><?php esc_html_e( 'Unique key', 'total-import' ); ?></label></th>
				<td>
					<input type="text" id="importright-unique-key" class="large-text code importright-template" data-path="unique_key" autocomplete="off" spellcheck="false" aria-describedby="importright-unique-key-help">
					<output class="importright-rendered" for="importright-unique-key" data-rendered="unique_key"></output>
					<p class="description" id="importright-unique-key-help"><?php esc_html_e( 'A value that never changes for a record, such as {id} or {sku}. A later run uses it to find what this import created and update it instead of adding a duplicate.', 'total-import' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'On each run', 'total-import' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'On each run', 'total-import' ); ?></legend>
						<label><input type="radio" name="mode" value="create_update" checked> <?php esc_html_e( 'Create new records and update existing ones', 'total-import' ); ?></label><br>
						<label><input type="radio" name="mode" value="create"> <?php esc_html_e( 'Only create new records, never touch existing ones', 'total-import' ); ?></label><br>
						<label><input type="radio" name="mode" value="update"> <?php esc_html_e( 'Only update existing records, never create', 'total-import' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="importright-match-existing"><?php esc_html_e( 'Match content this import didn\'t create', 'total-import' ); ?></label></th>
				<td>
					<select id="importright-match-existing" aria-describedby="importright-match-existing-help"></select>
					<input type="text" id="importright-match-meta" class="regular-text code" placeholder="_external_id" hidden aria-label="<?php esc_attr_e( 'Custom field key to match on', 'total-import' ); ?>" autocomplete="off" spellcheck="false">
					<p class="description" id="importright-match-existing-help"><?php esc_html_e( 'When no record of this import has the key, look for existing content by this value instead, so the first run adopts it rather than creating a copy.', 'total-import' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Update these fields', 'total-import' ); ?></th>
				<td>
					<fieldset id="importright-update-rules">
						<legend class="screen-reader-text"><?php esc_html_e( 'Update these fields', 'total-import' ); ?></legend>
					</fieldset>
					<p class="description"><?php esc_html_e( 'New records always get every mapped field. Unticked fields are left as they are on records that already exist.', 'total-import' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Options', 'total-import' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Options', 'total-import' ); ?></legend>
						<span class="importright-option"><label><input type="checkbox" id="importright-skip-unchanged" checked> <?php esc_html_e( 'Skip records whose data hasn\'t changed since the last run', 'total-import' ); ?></label></span>
						<span class="importright-option" data-handler="taxonomies"><label><input type="checkbox" id="importright-create-terms" checked> <?php esc_html_e( 'Create categories, tags and other terms that don\'t exist yet', 'total-import' ); ?></label></span>
						<span class="importright-option" data-handler="attributes"><label><input type="checkbox" id="importright-create-values" checked> <?php esc_html_e( 'Create attribute values that don\'t exist yet', 'total-import' ); ?></label></span>
						<span class="importright-option" data-handler="images">
							<label><input type="checkbox" id="importright-match-filename" checked aria-describedby="importright-match-filename-help"> <?php esc_html_e( 'Reuse a Media Library image with the same file name instead of downloading it again', 'total-import' ); ?></label>
							<span class="description" id="importright-match-filename-help"><?php esc_html_e( 'Turn this off when many images share generic names such as image.jpg.', 'total-import' ); ?></span>
						</span>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="importright-batch-size"><?php esc_html_e( 'Records per batch', 'total-import' ); ?></label></th>
				<td>
					<input type="number" id="importright-batch-size" class="small-text" min="1" max="5000" value="<?php echo esc_attr( (string) Settings::get( 'batch_size' ) ); ?>" aria-describedby="importright-batch-size-help">
					<p class="description" id="importright-batch-size-help"><?php esc_html_e( 'Lower it if runs time out, for example when every record downloads images.', 'total-import' ); ?></p>
				</td>
			</tr>
			<?php do_action( 'importright_wizard_matching_fields' ); ?>
		</table>
		<?php if ( self::show_hints() ) : ?>
			<p class="importright-pro-hint"><?php esc_html_e( 'Removing or drafting content that disappears from the file is part of sync in Total Import Pro.', 'total-import' ); ?></p>
		<?php endif; ?>
		<?php
		self::step_end( true, __( 'Save and run', 'total-import' ) );
	}

	private static function run_step(): void {
		self::step_start( 'run', __( 'Save and run', 'total-import' ) );
		?>
		<p class="importright-run-save">
			<button type="button" class="button button-primary" id="importright-save"><?php esc_html_e( 'Save import', 'total-import' ); ?></button>
			<span class="importright-save-status" id="importright-save-status" role="status" aria-live="polite"></span>
		</p>
		<div class="importright-after-save">
			<?php self::run_panel(); ?>
		</div>
		<?php do_action( 'importright_wizard_run_fields' ); ?>
		<?php if ( self::show_hints() ) : ?>
			<p class="importright-pro-hint"><?php esc_html_e( 'Scheduled runs are part of Total Import Pro. You can run this import again at any time from the Imports screen.', 'total-import' ); ?></p>
		<?php endif; ?>
		<?php
		self::step_end( true );
	}
}

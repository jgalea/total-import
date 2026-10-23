<?php

namespace Importright\Admin;

use Importright\Imports\Imports;
use Importright\Plugin;
use Importright\Registry;
use Importright\Runs\Runs;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class ImportsTable extends \WP_List_Table {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $latest = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'import',
				'plural'   => 'imports',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Runs on load-{hook}, before output, so bulk deletes can redirect.
	 */
	public static function load(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		$table = new self();
		if ( 'delete' !== $table->current_action() ) {
			return;
		}
		check_admin_referer( 'bulk-imports' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$ids = isset( $_REQUEST['import'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['import'] ) ) : array();
		$ids = array_filter( $ids );
		foreach ( $ids as $id ) {
			Imports::delete( $id );
		}
		Actions::flash(
			'success',
			sprintf(
				/* translators: %d: number of imports */
				_n( '%d import deleted. The content it imported was kept.', '%d imports deleted. The content they imported was kept.', count( $ids ), 'total-import' ),
				count( $ids )
			)
		);
		wp_safe_redirect( Screens::url( Screens::LIST ) );
		exit;
	}

	public function get_columns(): array {
		$columns = apply_filters(
			'importright_imports_columns',
			array(
				'cb'       => '<input type="checkbox">',
				'name'     => __( 'Name', 'total-import' ),
				'target'   => __( 'Imports into', 'total-import' ),
				'source'   => __( 'Source', 'total-import' ),
				'last_run' => __( 'Last run', 'total-import' ),
			)
		);
		return is_array( $columns ) ? $columns : array();
	}

	protected function get_bulk_actions(): array {
		return array( 'delete' => __( 'Delete', 'total-import' ) );
	}

	public function prepare_items(): void {
		$this->items           = Imports::all();
		$this->latest          = Runs::latest_for( array_column( $this->items, 'id' ) );
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'name' );
	}

	public function no_items(): void {
		printf(
			/* translators: %s: link to the new import screen */
			esc_html__( 'No imports yet. %s', 'total-import' ),
			'<a href="' . esc_url( Screens::url( Screens::EDIT ) ) . '">' . esc_html__( 'Create your first import.', 'total-import' ) . '</a>'
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="importright-import-%1$d">%2$s</label><input type="checkbox" id="importright-import-%1$d" name="import[]" value="%1$d">',
			(int) $item['id'],
			/* translators: %s: import name */
			esc_html( sprintf( __( 'Select %s', 'total-import' ), $item['name'] ) )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_name( $item ): string {
		$id   = (int) $item['id'];
		$edit = Screens::url( Screens::EDIT, array( 'import' => $id ) );
		$name = '' !== $item['name'] ? $item['name'] : __( '(no name)', 'total-import' );
		return sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $edit ), esc_html( $name ) );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		if ( $primary !== $column_name ) {
			return '';
		}
		$id      = (int) $item['id'];
		$name    = '' !== $item['name'] ? $item['name'] : __( '(no name)', 'total-import' );
		$actions = array(
			'run'       => sprintf( '<a href="%s">%s</a>', esc_url( Screens::run_url( $id, false ) ), esc_html__( 'Run', 'total-import' ) ),
			'dry_run'   => sprintf( '<a href="%s">%s</a>', esc_url( Screens::run_url( $id, true ) ), esc_html__( 'Dry run', 'total-import' ) ),
			'edit'      => sprintf( '<a href="%s">%s</a>', esc_url( Screens::url( Screens::EDIT, array( 'import' => $id ) ) ), esc_html__( 'Edit', 'total-import' ) ),
			'duplicate' => sprintf( '<a href="%s">%s</a>', esc_url( Actions::url( 'duplicate', $id ) ), esc_html__( 'Duplicate', 'total-import' ) ),
			'export'    => sprintf( '<a href="%s">%s</a>', esc_url( Actions::url( 'export', $id ) ), esc_html__( 'Export JSON', 'total-import' ) ),
			'delete'    => sprintf(
				'<a href="%s" class="submitdelete" data-importright-confirm="%s">%s</a>',
				esc_url( Actions::url( 'delete', $id ) ),
				/* translators: %s: import name */
				esc_attr( sprintf( __( 'Delete the import "%s"? Its runs and record list are removed. Posts, terms and users it imported stay.', 'total-import' ), $name ) ),
				esc_html__( 'Delete', 'total-import' )
			),
		);
		return $this->row_actions( $actions );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_target( $item ): string {
		$target = (array) ( $item['definition']['target'] ?? array() );
		$type   = (string) ( $target['type'] ?? '' );
		switch ( $type ) {
			case 'post':
				$object = get_post_type_object( (string) ( $target['post_type'] ?? '' ) );
				return esc_html( null === $object ? (string) ( $target['post_type'] ?? '' ) : $object->labels->name );
			case 'term':
				$taxonomy = get_taxonomy( (string) ( $target['taxonomy'] ?? '' ) );
				return esc_html( false === $taxonomy ? (string) ( $target['taxonomy'] ?? '' ) : $taxonomy->labels->name );
			case 'user':
				return esc_html__( 'Users', 'total-import' );
			case 'product':
				return esc_html__( 'WooCommerce products', 'total-import' );
		}
		$registered = Registry::target( $type );
		return esc_html( null === $registered ? $type : $registered->label() );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_source( $item ): string {
		$source = (array) ( $item['definition']['source'] ?? array() );
		switch ( $source['type'] ?? '' ) {
			case 'upload':
				$label = __( 'Upload', 'total-import' );
				$value = (string) ( $source['original_name'] ?? '' );
				break;
			case 'url':
				$label = __( 'URL', 'total-import' );
				$value = (string) ( $source['url'] ?? '' );
				break;
			case 'path':
				$label = __( 'Server path', 'total-import' );
				$value = (string) ( $source['path'] ?? '' );
				break;
			default:
				return '';
		}
		return sprintf( '<span class="importright-muted">%s</span><br><code class="importright-source">%s</code>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_last_run( $item ): string {
		$run = $this->latest[ (int) $item['id'] ] ?? null;
		if ( null === $run ) {
			return '<span class="importright-muted">' . esc_html__( 'Never run', 'total-import' ) . '</span>';
		}

		$statuses = array(
			'pending'   => __( 'Pending', 'total-import' ),
			'running'   => __( 'Running', 'total-import' ),
			'completed' => __( 'Completed', 'total-import' ),
			'failed'    => __( 'Failed', 'total-import' ),
			'cancelled' => __( 'Cancelled', 'total-import' ),
		);
		$status   = (string) $run['status'];
		$when     = (string) ( $run['finished_at'] ?? $run['created_at'] );
		$time     = (int) strtotime( $when . ' UTC' );

		$counts = sprintf(
			/* translators: 1: created, 2: updated, 3: unchanged, 4: failed */
			__( '%1$s created, %2$s updated, %3$s unchanged, %4$s failed', 'total-import' ),
			number_format_i18n( $run['created'] ),
			number_format_i18n( $run['updated'] ),
			number_format_i18n( $run['unchanged'] ),
			number_format_i18n( $run['failed'] )
		);

		$html  = '<span class="importright-status importright-status-' . esc_attr( $status ) . '">' . esc_html( $statuses[ $status ] ?? $status ) . '</span>';
		$html .= $run['dry_run'] ? ' <span class="importright-tag">' . esc_html__( 'Dry run', 'total-import' ) . '</span>' : '';
		$html .= '<br><span class="importright-counts">' . esc_html( $counts ) . '</span>';
		$html .= '<br><time class="importright-muted" datetime="' . esc_attr( gmdate( 'c', $time ) ) . '" title="' . esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ) . '">';
		/* translators: %s: time span such as "5 minutes" */
		$html .= esc_html( sprintf( __( '%s ago', 'total-import' ), human_time_diff( $time ) ) ) . '</time>';
		return $html;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param string               $column_name
	 */
	protected function column_default( $item, $column_name ): string {
		return wp_kses_post( (string) apply_filters( 'importright_imports_column_' . $column_name, '', $item ) );
	}
}

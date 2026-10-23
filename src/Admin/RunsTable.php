<?php

namespace Importright\Admin;

use Importright\Imports\Imports;
use Importright\Runs\Runs;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Runs of every import, newest first, on the History screen.
 */
final class RunsTable extends \WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * @var array<int, string> Import ID => name.
	 */
	private array $names = array();

	private int $import_id;

	public function __construct( int $import_id ) {
		parent::__construct(
			array(
				'singular' => 'run',
				'plural'   => 'runs',
				'ajax'     => false,
			)
		);
		$this->import_id = $import_id;
	}

	public function get_columns(): array {
		return array(
			'id'     => __( 'Run', 'total-import' ),
			'import' => __( 'Import', 'total-import' ),
			'mode'   => __( 'Mode', 'total-import' ),
			'user'   => __( 'Started by', 'total-import' ),
			'time'   => __( 'Started / finished', 'total-import' ),
			'counts' => __( 'Records', 'total-import' ),
			'status' => __( 'Status', 'total-import' ),
		);
	}

	public function prepare_items(): void {
		foreach ( Imports::all() as $import ) {
			$this->names[ (int) $import['id'] ] = (string) $import['name'];
		}
		$total       = Runs::count( $this->import_id );
		$this->items = Runs::page( $this->import_id, $this->get_pagenum(), self::PER_PAGE );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'id' );
	}

	public function no_items(): void {
		esc_html_e( 'No runs yet. Runs appear here once an import or dry run has started.', 'total-import' );
	}

	/**
	 * @param string $which
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || array() === $this->names ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<label for="importright-filter-import" class="screen-reader-text"><?php esc_html_e( 'Filter by import', 'total-import' ); ?></label>
			<select name="import" id="importright-filter-import">
				<option value="0"><?php esc_html_e( 'All imports', 'total-import' ); ?></option>
				<?php foreach ( $this->names as $id => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $id, $this->import_id ); ?>><?php echo esc_html( '' !== $name ? $name : __( '(no name)', 'total-import' ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'total-import' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_id( $item ): string {
		$url = Screens::url( Screens::HISTORY, array( 'run' => (int) $item['id'] ) );
		/* translators: %d: run ID */
		return sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $url ), esc_html( sprintf( __( 'Run %d', 'total-import' ), (int) $item['id'] ) ) );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		if ( $primary !== $column_name ) {
			return '';
		}
		return $this->row_actions(
			array(
				'view' => sprintf( '<a href="%s">%s</a>', esc_url( Screens::url( Screens::HISTORY, array( 'run' => (int) $item['id'] ) ) ), esc_html__( 'Details and log', 'total-import' ) ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_import( $item ): string {
		$id = (int) $item['import_id'];
		if ( ! isset( $this->names[ $id ] ) ) {
			/* translators: %d: import ID */
			return '<span class="importright-muted">' . esc_html( sprintf( __( 'Deleted import %d', 'total-import' ), $id ) ) . '</span>';
		}
		$name = '' !== $this->names[ $id ] ? $this->names[ $id ] : __( '(no name)', 'total-import' );
		return sprintf( '<a href="%s">%s</a>', esc_url( Screens::url( Screens::HISTORY, array( 'import' => $id ) ) ), esc_html( $name ) );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_mode( $item ): string {
		$mode = $item['dry_run'] ? '<span class="importright-tag">' . esc_html__( 'Dry run', 'total-import' ) . '</span>' : esc_html__( 'Live', 'total-import' );
		return $mode . '<br><span class="importright-muted">' . esc_html( self::origin( (string) $item['origin'] ) ) . '</span>';
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_user( $item ): string {
		$user = (int) $item['user_id'] > 0 ? get_userdata( (int) $item['user_id'] ) : false;
		return false === $user ? '<span class="importright-muted">' . esc_html__( 'System', 'total-import' ) . '</span>' : esc_html( $user->display_name );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_time( $item ): string {
		$started  = self::time( $item['started_at'] ?? $item['created_at'] );
		$finished = null === $item['finished_at'] ? '<span class="importright-muted">' . esc_html__( 'Not finished', 'total-import' ) . '</span>' : self::time( $item['finished_at'] );
		return $started . '<br>' . $finished;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_counts( $item ): string {
		$parts = array(
			/* translators: %s: number of records */
			sprintf( __( '%s read', 'total-import' ), number_format_i18n( $item['processed'] ) ),
			/* translators: 1: created, 2: updated, 3: unchanged */
			sprintf( __( '%1$s created, %2$s updated, %3$s unchanged', 'total-import' ), number_format_i18n( $item['created'] ), number_format_i18n( $item['updated'] ), number_format_i18n( $item['unchanged'] ) ),
		);
		if ( $item['skipped'] > 0 || $item['failed'] > 0 ) {
			/* translators: 1: skipped, 2: failed */
			$parts[] = sprintf( __( '%1$s skipped, %2$s failed', 'total-import' ), number_format_i18n( $item['skipped'] ), number_format_i18n( $item['failed'] ) );
		}
		return '<span class="importright-counts">' . implode( '<br>', array_map( 'esc_html', $parts ) ) . '</span>';
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_status( $item ): string {
		$status = (string) $item['status'];
		$html   = '<span class="importright-status importright-status-' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';
		$undo   = self::rollback_label( (string) $item['rollback_status'] );
		if ( '' !== $undo ) {
			$html .= '<br><span class="importright-muted">' . esc_html( $undo ) . '</span>';
		}
		return $html;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param string               $column_name
	 */
	protected function column_default( $item, $column_name ): string {
		return '';
	}

	public static function status_label( string $status ): string {
		$labels = array(
			'pending'   => __( 'Pending', 'total-import' ),
			'running'   => __( 'Running', 'total-import' ),
			'completed' => __( 'Completed', 'total-import' ),
			'failed'    => __( 'Failed', 'total-import' ),
			'cancelled' => __( 'Cancelled', 'total-import' ),
		);
		return $labels[ $status ] ?? $status;
	}

	public static function rollback_label( string $status ): string {
		$labels = array(
			'running' => __( 'Undo in progress', 'total-import' ),
			'done'    => __( 'Undone', 'total-import' ),
			'partial' => __( 'Partly undone', 'total-import' ),
			'expired' => __( 'Undo data cleaned up', 'total-import' ),
		);
		return $labels[ $status ] ?? '';
	}

	public static function origin( string $origin ): string {
		$labels = array(
			'ajax'       => __( 'From the admin', 'total-import' ),
			'cli'        => __( 'WP-CLI', 'total-import' ),
			'background' => __( 'In the background', 'total-import' ),
		);
		return $labels[ $origin ] ?? $origin;
	}

	/**
	 * @param string|null $gmt "Y-m-d H:i:s" in GMT.
	 */
	public static function time( ?string $gmt ): string {
		if ( null === $gmt || '' === $gmt ) {
			return '';
		}
		$time = (int) strtotime( $gmt . ' UTC' );
		return '<time datetime="' . esc_attr( gmdate( 'c', $time ) ) . '">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ) . '</time>';
	}
}

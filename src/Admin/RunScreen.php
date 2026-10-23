<?php

namespace Importright\Admin;

use Importright\Imports\Imports;
use Importright\Runs\History;
use Importright\Runs\Log;
use Importright\Runs\Rollback;
use Importright\Runs\Runs;
use Importright\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One run on the History screen (&run=N): summary, counts, undo and the per-row log.
 * The undo flow runs in admin.js through the rollback_* AJAX endpoints.
 */
final class RunScreen {

	private const LOG_PER_PAGE = 50;

	public static function render( int $run_id ): void {
		$row = Runs::get( $run_id );
		if ( null === $row ) {
			wp_die( esc_html__( 'That run no longer exists.', 'total-import' ) );
		}
		$run    = History::describe( $row );
		$import = Imports::get( $run['import_id'] );
		$name   = null === $import ? '' : $import['name'];
		$dry    = 'dry_run' === $run['mode'];
		?>
		<div class="wrap importright importright-run-detail" id="importright-run-detail" data-run="<?php echo esc_attr( (string) $run_id ); ?>">
			<h1>
				<?php
				echo esc_html(
					'' === $name
						/* translators: %d: run ID */
						? sprintf( __( 'Run %d', 'total-import' ), $run_id )
						/* translators: 1: run ID, 2: import name */
						: sprintf( __( 'Run %1$d: %2$s', 'total-import' ), $run_id, $name )
				);
				?>
			</h1>
			<p class="importright-links">
				<a href="<?php echo esc_url( Screens::url( Screens::HISTORY ) ); ?>"><?php esc_html_e( 'All runs', 'total-import' ); ?></a>
				<?php if ( null !== $import ) : ?>
					<a href="<?php echo esc_url( Screens::url( Screens::HISTORY, array( 'import' => $run['import_id'] ) ) ); ?>"><?php esc_html_e( 'Runs of this import', 'total-import' ); ?></a>
					<a href="<?php echo esc_url( Screens::url( Screens::EDIT, array( 'import' => $run['import_id'] ) ) ); ?>"><?php esc_html_e( 'Edit import', 'total-import' ); ?></a>
				<?php endif; ?>
			</p>

			<?php self::summary( $run, $row ); ?>
			<?php self::undo( $run, $row ); ?>
			<?php self::log( $run ); ?>
			<?php
			if ( $dry && $run['log']['previews'] > 0 ) {
				self::preview( $run );
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $run
	 * @param array<string, mixed> $row
	 */
	private static function summary( array $run, array $row ): void {
		$dry    = 'dry_run' === $run['mode'];
		$labels = array(
			'processed' => __( 'Records read', 'total-import' ),
			'created'   => $dry ? __( 'Would be created', 'total-import' ) : __( 'Created', 'total-import' ),
			'updated'   => $dry ? __( 'Would be updated', 'total-import' ) : __( 'Updated', 'total-import' ),
			'unchanged' => __( 'Unchanged', 'total-import' ),
			'skipped'   => __( 'Skipped', 'total-import' ),
			'failed'    => $dry ? __( 'Would fail', 'total-import' ) : __( 'Failed', 'total-import' ),
		);
		$user   = '' !== $run['user']['name'] ? $run['user']['name'] : __( 'System', 'total-import' );
		?>
		<h2 class="screen-reader-text"><?php esc_html_e( 'Summary', 'total-import' ); ?></h2>
		<dl class="importright-facts">
			<div>
				<dt><?php esc_html_e( 'Status', 'total-import' ); ?></dt>
				<dd>
					<span class="importright-status importright-status-<?php echo esc_attr( $run['status'] ); ?>"><?php echo esc_html( RunsTable::status_label( $run['status'] ) ); ?></span>
					<?php if ( $dry ) : ?>
						<span class="importright-tag"><?php esc_html_e( 'Dry run', 'total-import' ); ?></span>
					<?php endif; ?>
				</dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Started by', 'total-import' ); ?></dt>
				<dd><?php echo esc_html( $user . ', ' . RunsTable::origin( $run['origin'] ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Started', 'total-import' ); ?></dt>
				<dd><?php echo wp_kses_post( RunsTable::time( $run['started_at'] ?? $run['created_at'] ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Finished', 'total-import' ); ?></dt>
				<dd>
					<?php
					if ( null === $run['finished_at'] ) {
						esc_html_e( 'Not finished', 'total-import' );
					} else {
						echo wp_kses_post( RunsTable::time( $run['finished_at'] ) );
						if ( null !== $run['duration'] ) {
							/* translators: %s: number of seconds */
							echo ' <span class="importright-muted">' . esc_html( sprintf( _n( '(%s second)', '(%s seconds)', (int) $run['duration'], 'total-import' ), number_format_i18n( (int) $run['duration'] ) ) ) . '</span>';
						}
					}
					?>
				</dd>
			</div>
		</dl>
		<?php if ( '' !== $run['message'] && 'completed' !== $run['status'] ) : ?>
			<div class="notice inline <?php echo 'failed' === $run['status'] ? 'notice-error' : 'notice-warning'; ?>"><p><?php echo esc_html( $run['message'] ); ?></p></div>
		<?php endif; ?>
		<dl class="importright-counts-list">
			<?php foreach ( $labels as $key => $label ) : ?>
				<div class="importright-count importright-count-<?php echo esc_attr( $key ); ?>">
					<dt><?php echo esc_html( $label ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( (int) $run['counts'][ $key ] ) ); ?></dd>
				</div>
			<?php endforeach; ?>
			<?php foreach ( self::extra_counts( $run ) as $extra ) : ?>
				<div class="importright-count importright-count-<?php echo esc_attr( $extra['key'] ); ?>">
					<dt><?php echo esc_html( $extra['label'] ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( $extra['value'] ) ); ?></dd>
				</div>
			<?php endforeach; ?>
		</dl>
		<?php
		$stale = in_array( $run['status'], Runs::ACTIVE, true ) && (int) $row['heartbeat'] < time() - (int) apply_filters( 'importright_watchdog_grace', 120 );
		if ( $stale ) :
			?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'This run hasn\'t moved for a while. The page or process driving it may have closed.', 'total-import' ); ?></p>
				<p><button type="button" class="button" data-take-over><?php esc_html_e( 'Take over and continue in the background', 'total-import' ); ?></button> <span role="status" aria-live="polite" data-take-over-status></span></p>
			</div>
			<?php
		endif;
	}

	/**
	 * The extra count tiles a run description carries, with malformed entries dropped.
	 *
	 * @param array<string, mixed> $run
	 * @return list<array{key: string, label: string, value: int}>
	 */
	public static function extra_counts( array $run ): array {
		$tiles = array();
		foreach ( (array) ( $run['extra_counts'] ?? array() ) as $extra ) {
			if ( is_array( $extra ) && isset( $extra['key'], $extra['label'], $extra['value'] ) && is_scalar( $extra['key'] ) && is_scalar( $extra['label'] ) && is_numeric( $extra['value'] ) ) {
				$tiles[] = array(
					'key'   => sanitize_html_class( (string) $extra['key'] ),
					'label' => (string) $extra['label'],
					'value' => (int) $extra['value'],
				);
			}
		}
		return $tiles;
	}

	/**
	 * @param array<string, mixed> $run
	 * @param array<string, mixed> $row
	 */
	private static function undo( array $run, array $row ): void {
		if ( 'dry_run' === $run['mode'] ) {
			return;
		}
		$rollback = $run['rollback'];
		?>
		<section class="importright-panel" aria-labelledby="importright-undo-title">
			<h2 id="importright-undo-title"><?php esc_html_e( 'Undo this run', 'total-import' ); ?></h2>
			<?php
			if ( Rollback::DONE === $rollback['status'] || Rollback::PARTIAL === $rollback['status'] ) {
				$by = $rollback['by'] > 0 ? get_userdata( $rollback['by'] ) : false;
				echo '<p>' . esc_html(
					sprintf(
						/* translators: 1: date, 2: user name */
						Rollback::DONE === $rollback['status'] ? __( 'Undone on %1$s by %2$s.', 'total-import' ) : __( 'Partly undone on %1$s by %2$s. Some items were skipped or failed; you can try again.', 'total-import' ),
						null === $rollback['at'] ? '' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $rollback['at'] . ' UTC' ) ),
						false === $by ? __( 'someone', 'total-import' ) : $by->display_name
					)
				) . '</p>';
				self::rollback_counts( (array) $rollback['counts'] );
			}

			if ( $rollback['available'] ) {
				?>
				<p><?php esc_html_e( 'Undo puts back the values this run changed, moves posts and products it created to the trash, and deletes terms, users and images it added. Anything edited after the run is left alone and listed as skipped. You see the full list before anything changes.', 'total-import' ); ?></p>
				<p class="description"><?php echo esc_html( self::retention_note( $run, $row ) ); ?></p>
				<p><button type="button" class="button" data-undo-preview><?php esc_html_e( 'Check what undo would do', 'total-import' ); ?></button></p>
				<div class="importright-undo" data-undo hidden tabindex="-1"></div>
				<?php
			} elseif ( Rollback::EXPIRED === $rollback['status'] ) {
				echo '<p>' . esc_html__( 'The undo data for this run has been cleaned up, so it can\'t be undone any more.', 'total-import' ) . '</p>';
			} elseif ( Rollback::RUNNING === $rollback['status'] ) {
				echo '<p>' . esc_html__( 'An undo of this run is in progress.', 'total-import' ) . '</p>';
			} elseif ( in_array( $run['status'], Runs::ACTIVE, true ) ) {
				echo '<p>' . esc_html__( 'A run can be undone once it has finished.', 'total-import' ) . '</p>';
			} elseif ( Rollback::DONE !== $rollback['status'] ) {
				echo '<p>' . esc_html__( 'This run didn\'t change anything that can be undone.', 'total-import' ) . '</p>';
			}
			?>
		</section>
		<?php
	}

	/**
	 * @param array<string, int> $counts
	 */
	private static function rollback_counts( array $counts ): void {
		$labels = self::outcome_labels();
		$shown  = array_filter(
			$labels,
			static function ( string $key ) use ( $counts ): bool {
				return ( $counts[ $key ] ?? 0 ) > 0;
			},
			ARRAY_FILTER_USE_KEY
		);
		if ( array() === $shown ) {
			return;
		}
		echo '<dl class="importright-counts-list">';
		foreach ( $shown as $key => $label ) {
			printf( '<div class="importright-count importright-count-%s"><dt>%s</dt><dd>%s</dd></div>', esc_attr( $key ), esc_html( $label ), esc_html( number_format_i18n( (int) $counts[ $key ] ) ) );
		}
		echo '</dl>';
	}

	/**
	 * @return array<string, string>
	 */
	private static function outcome_labels(): array {
		return array(
			'restored' => __( 'Restored', 'total-import' ),
			'deleted'  => __( 'Trashed or deleted', 'total-import' ),
			'kept'     => __( 'Kept (still in use)', 'total-import' ),
			'skipped'  => __( 'Skipped (edited since)', 'total-import' ),
			'gone'     => __( 'Already gone', 'total-import' ),
			'failed'   => __( 'Failed', 'total-import' ),
		);
	}

	/**
	 * When the retention cleanup will remove this run's undo data.
	 *
	 * @param array<string, mixed> $run
	 * @param array<string, mixed> $row
	 */
	private static function retention_note( array $run, array $row ): string {
		$keep_days = max( 1, (int) apply_filters( 'importright_rollback_keep_days', Settings::get( 'rollback_keep_days' ) ) );
		$keep_runs = max( 1, (int) apply_filters( 'importright_rollback_keep_runs', Settings::get( 'rollback_keep_runs' ) ) );
		$finished  = null === $run['finished_at'] ? time() : (int) strtotime( $run['finished_at'] . ' UTC' );
		$until     = wp_date( get_option( 'date_format' ), $finished + $keep_days * DAY_IN_SECONDS );
		$left      = max( 0, $keep_runs - 1 - Runs::newer_live( (int) $row['import_id'], (int) $row['id'] ) );
		if ( 0 === $left ) {
			return __( 'Undo data is cleaned up daily. This run is past the number of runs kept per import, so undo it soon if you need to.', 'total-import' );
		}
		return sprintf(
			/* translators: 1: date, 2: number of runs */
			_n( 'Undo stays available until %1$s, or until %2$s more run of this import, whichever comes first.', 'Undo stays available until %1$s, or until %2$s more runs of this import, whichever comes first.', $left, 'total-import' ),
			$until,
			number_format_i18n( $left )
		);
	}

	/**
	 * @param array<string, mixed> $run
	 */
	private static function log( array $run ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters and paging.
		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$page  = isset( $_GET['log_page'] ) ? max( 1, absint( wp_unslash( $_GET['log_page'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$level  = in_array( $level, array( Log::ERROR, Log::NOTICE ), true ) ? $level : '';
		$found  = Log::entries( $run['id'], '' === $level ? array() : array( $level ), $page, self::LOG_PER_PAGE );
		$total  = $run['log']['errors'] + $run['log']['notices'];
		$base   = Screens::url( Screens::HISTORY, array( 'run' => $run['id'] ) );
		$levels = array(
			''          => __( 'All', 'total-import' ) . ' (' . number_format_i18n( $total ) . ')',
			Log::ERROR  => __( 'Errors', 'total-import' ) . ' (' . number_format_i18n( $run['log']['errors'] ) . ')',
			Log::NOTICE => __( 'Notices', 'total-import' ) . ' (' . number_format_i18n( $run['log']['notices'] ) . ')',
		);
		?>
		<section class="importright-panel" aria-labelledby="importright-log-title">
			<h2 id="importright-log-title"><?php esc_html_e( 'Errors and notices', 'total-import' ); ?></h2>
			<?php if ( 0 === $total ) : ?>
				<p><?php esc_html_e( 'Nothing was logged for this run.', 'total-import' ); ?></p>
			<?php else : ?>
				<div class="importright-log-tools">
					<ul class="subsubsub">
						<?php
						$links = array();
						foreach ( $levels as $key => $label ) {
							$url     = '' === $key ? $base : add_query_arg( 'level', $key, $base );
							$links[] = sprintf( '<li><a href="%s"%s>%s</a></li>', esc_url( $url ), $key === $level ? ' class="current" aria-current="page"' : '', esc_html( $label ) );
						}
						echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
						?>
					</ul>
					<?php Actions::log_csv_form( (int) $run['id'], __( 'Download the log (CSV)', 'total-import' ) ); ?>
				</div>
				<div class="importright-scroll" tabindex="0" role="region" aria-labelledby="importright-log-title">
					<table class="widefat striped importright-log">
						<thead>
							<tr>
								<th scope="col" class="num"><?php esc_html_e( 'Row', 'total-import' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Key', 'total-import' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Level', 'total-import' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Message', 'total-import' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( array() === $found['items'] ) : ?>
								<tr><td colspan="4"><?php esc_html_e( 'Nothing at this level.', 'total-import' ); ?></td></tr>
							<?php endif; ?>
							<?php foreach ( $found['items'] as $entry ) : ?>
								<tr>
									<td class="num"><?php echo esc_html( number_format_i18n( (int) $entry['row'] ) ); ?></td>
									<td class="importright-key"><?php echo esc_html( (string) $entry['key'] ); ?></td>
									<td><span class="importright-status importright-level-<?php echo esc_attr( (string) $entry['level'] ); ?>"><?php echo esc_html( Log::ERROR === $entry['level'] ? __( 'Error', 'total-import' ) : __( 'Notice', 'total-import' ) ); ?></span></td>
									<td><?php echo esc_html( (string) $entry['message'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php
				$pages = (int) ceil( $found['total'] / self::LOG_PER_PAGE );
				if ( $pages > 1 ) {
					echo '<nav class="importright-pages" aria-label="' . esc_attr__( 'Log pages', 'total-import' ) . '">';
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'      => add_query_arg( 'log_page', '%#%', '' === $level ? $base : add_query_arg( 'level', $level, $base ) ),
								'format'    => '',
								'current'   => $page,
								'total'     => $pages,
								'prev_text' => __( 'Previous', 'total-import' ),
								'next_text' => __( 'Next', 'total-import' ),
							)
						)
					);
					echo '</nav>';
				}
				?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $run
	 */
	private static function preview( array $run ): void {
		$found   = Log::entries( $run['id'], array( Log::PREVIEW ), 1, 50 );
		$actions = array(
			'create'    => __( 'Create', 'total-import' ),
			'update'    => __( 'Update', 'total-import' ),
			'unchanged' => __( 'Unchanged', 'total-import' ),
			'skip'      => __( 'Skip', 'total-import' ),
			'fail'      => __( 'Fail', 'total-import' ),
		);
		?>
		<section class="importright-panel" aria-labelledby="importright-preview-title">
			<h2 id="importright-preview-title"><?php esc_html_e( 'Dry run preview', 'total-import' ); ?></h2>
			<p>
				<?php
				/* translators: 1: rows shown, 2: rows stored */
				echo esc_html( sprintf( __( 'The first %1$s of %2$s records stored with details.', 'total-import' ), number_format_i18n( count( $found['items'] ) ), number_format_i18n( $found['total'] ) ) );
				?>
			</p>
			<div class="importright-scroll" tabindex="0" role="region" aria-labelledby="importright-preview-title">
				<table class="widefat striped importright-preview-rows">
					<thead>
						<tr>
							<th scope="col" class="num"><?php esc_html_e( 'Row', 'total-import' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Key', 'total-import' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Action', 'total-import' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Changes (old → new)', 'total-import' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $found['items'] as $item ) : ?>
							<tr>
								<td class="num"><?php echo esc_html( number_format_i18n( (int) $item['row'] ) ); ?></td>
								<td class="importright-key"><?php echo esc_html( (string) $item['key'] ); ?></td>
								<td><span class="importright-status importright-action-<?php echo esc_attr( (string) $item['action'] ); ?>"><?php echo esc_html( $actions[ $item['action'] ] ?? (string) $item['action'] ); ?></span></td>
								<td>
									<?php if ( array() === (array) ( $item['changes'] ?? array() ) ) : ?>
										<span class="importright-muted">—</span>
									<?php else : ?>
										<ul class="importright-changes">
											<?php foreach ( (array) $item['changes'] as $change ) : ?>
												<li>
													<span class="importright-field-name"><?php echo esc_html( (string) $change['field'] ); ?></span>
													<?php if ( null !== $change['old'] && 'create' !== $item['action'] ) : ?>
														<del><?php echo esc_html( self::clip( (string) $change['old'] ) ); ?></del> →
													<?php endif; ?>
													<ins><?php echo esc_html( null === $change['new'] ? __( '(cleared)', 'total-import' ) : self::clip( (string) $change['new'] ) ); ?></ins>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	private static function clip( string $text ): string {
		return mb_strlen( $text ) > 200 ? mb_substr( $text, 0, 200 ) . '…' : $text;
	}
}

<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$importright_sites = is_multisite() ? get_sites(
	array(
		'fields' => 'ids',
		'number' => 0,
	)
) : array( get_current_blog_id() );

foreach ( $importright_sites as $importright_site ) {
	if ( is_multisite() ) {
		switch_to_blog( (int) $importright_site );
	}

	$importright_as_actions = $wpdb->prefix . 'actionscheduler_actions';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $importright_as_actions ) ) ) === $importright_as_actions ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE hook IN ('importright_run_step','importright_watchdog','importright_cleanup') AND status IN ('pending','in-progress')", $importright_as_actions ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	foreach ( array( 'importright_run_step', 'importright_watchdog', 'importright_cleanup' ) as $importright_hook ) {
		wp_unschedule_hook( $importright_hook );
	}
	delete_option( 'importright_schedules_checked' );

	// Imports, run history and rollback data stay unless Settings > "Delete all Total Import data" was ticked.
	$importright_settings = get_option( 'importright_settings' );
	if ( ! is_array( $importright_settings ) || empty( $importright_settings['delete_data'] ) ) {
		if ( is_multisite() ) {
			restore_current_blog();
		}
		continue;
	}

	foreach ( array( 'importright_records', 'importright_runs', 'importright_imports', 'importright_changes', 'importright_log' ) as $importright_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $importright_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables.
	}

	$importright_dir_name = get_option( 'importright_storage_dir' );
	if ( is_string( $importright_dir_name ) && preg_match( '/^importright-[a-f0-9]{20}$/', $importright_dir_name ) ) {
		$importright_uploads = wp_upload_dir( null, false );
		$importright_dir     = untrailingslashit( (string) $importright_uploads['basedir'] ) . '/' . $importright_dir_name;
		if ( is_dir( $importright_dir ) ) {
			foreach ( (array) scandir( $importright_dir ) as $importright_file ) {
				if ( is_file( $importright_dir . '/' . $importright_file ) ) {
					wp_delete_file( $importright_dir . '/' . $importright_file );
				}
			}
			rmdir( $importright_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- the plugin's own folder, emptied above.
		}
	}

	delete_option( 'importright_db_version' );
	delete_option( 'importright_storage_dir' );
	delete_option( 'importright_wpai_migrations' );
	delete_option( 'importright_settings' );

	if ( is_multisite() ) {
		restore_current_blog();
	}
}

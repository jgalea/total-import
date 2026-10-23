<?php

namespace Importright\Database;

use Importright\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Tables per site: import definitions, runs, the record map that ties each
 * source record's unique key to the object it created, the changes a run
 * made (for rollback), and the per-row log (errors, notices, dry-run preview).
 */
final class Schema {

	public const VERSION        = '3';
	public const VERSION_OPTION = 'importright_db_version';

	public static function imports(): string {
		global $wpdb;
		return $wpdb->prefix . 'importright_imports';
	}

	public static function runs(): string {
		global $wpdb;
		return $wpdb->prefix . 'importright_runs';
	}

	public static function records(): string {
		global $wpdb;
		return $wpdb->prefix . 'importright_records';
	}

	public static function changes(): string {
		global $wpdb;
		return $wpdb->prefix . 'importright_changes';
	}

	public static function log(): string {
		global $wpdb;
		return $wpdb->prefix . 'importright_log';
	}

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( self::imports(), self::runs(), self::records(), self::changes(), self::log() );
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$imports = self::imports();
		$runs    = self::runs();
		$records = self::records();
		$changes = self::changes();
		$log     = self::log();

		dbDelta(
			"CREATE TABLE {$imports} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL DEFAULT '',
				definition longtext NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id)
			) {$charset};
			CREATE TABLE {$runs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				import_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				origin varchar(20) NOT NULL DEFAULT '',
				background tinyint(1) unsigned NOT NULL DEFAULT 0,
				dry_run tinyint(1) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				definition longtext NOT NULL,
				source_file text NOT NULL,
				source_ext varchar(10) NOT NULL DEFAULT '',
				state longtext NOT NULL,
				total bigint(20) unsigned DEFAULT NULL,
				processed bigint(20) unsigned NOT NULL DEFAULT 0,
				created bigint(20) unsigned NOT NULL DEFAULT 0,
				updated bigint(20) unsigned NOT NULL DEFAULT 0,
				unchanged bigint(20) unsigned NOT NULL DEFAULT 0,
				skipped bigint(20) unsigned NOT NULL DEFAULT 0,
				failed bigint(20) unsigned NOT NULL DEFAULT 0,
				errors longtext NOT NULL,
				message text NOT NULL,
				lock_token varchar(32) NOT NULL DEFAULT '',
				locked_until bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				started_at datetime DEFAULT NULL,
				finished_at datetime DEFAULT NULL,
				heartbeat bigint(20) unsigned NOT NULL DEFAULT 0,
				rollback_status varchar(20) NOT NULL DEFAULT '',
				rollback longtext DEFAULT NULL,
				rolled_back_at datetime DEFAULT NULL,
				rolled_back_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY import_status (import_id,status)
			) {$charset};
			CREATE TABLE {$records} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				import_id bigint(20) unsigned NOT NULL,
				unique_key varbinary(255) NOT NULL,
				object_type varchar(32) NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				data_hash char(32) NOT NULL DEFAULT '',
				adopted tinyint(1) unsigned NOT NULL DEFAULT 0,
				first_run_id bigint(20) unsigned NOT NULL DEFAULT 0,
				last_run_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY import_key (import_id,unique_key),
				KEY object (object_type,object_id),
				KEY import_run (import_id,last_run_id)
			) {$charset};
			CREATE TABLE {$changes} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				import_id bigint(20) unsigned NOT NULL,
				object_type varchar(32) NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				action varchar(10) NOT NULL,
				unique_key varbinary(255) NOT NULL DEFAULT '',
				data longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT '',
				note text DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_object (run_id,object_type,object_id),
				KEY run_status (run_id,status)
			) {$charset};
			CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				row_index bigint(20) NOT NULL DEFAULT 0,
				unique_key varchar(255) NOT NULL DEFAULT '',
				level varchar(10) NOT NULL,
				action varchar(10) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				message text DEFAULT NULL,
				data longtext DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY run_level (run_id,level,id)
			) {$charset};"
		);

		self::upgrade_from( (string) Store::get( self::VERSION_OPTION ) );

		Store::set( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Data changes for sites on an older schema, after dbDelta added the columns.
	 */
	public static function upgrade_from( string $previous ): void {
		global $wpdb;
		if ( '' !== $previous && version_compare( $previous, '3', '<' ) ) {
			// Before v3 every run Background::start() or adopt() queued had origin "background".
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET background = 1 WHERE origin = 'background' AND background = 0", self::runs() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	public static function maybe_install(): void {
		if ( self::VERSION !== Store::get( self::VERSION_OPTION ) ) {
			self::install();
		}
	}
}

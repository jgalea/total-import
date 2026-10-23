<?php

namespace Importright;

use Importright\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		if ( is_admin() ) {
			add_action( 'admin_init', array( Schema::class, 'maybe_install' ) );
			Admin\Ajax::register();
			Admin\Screens::register();
		}
		Runs\Background::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'importright', Cli\Command::class );
			\WP_CLI::add_command( 'importright migrate', Cli\MigrateCommand::class );
		}
	}

	/**
	 * Capability needed to create and run imports.
	 */
	public static function capability(): string {
		return (string) apply_filters( 'importright_capability', 'manage_options' );
	}

	public static function activate(): void {
		Schema::install();
	}

	public static function deactivate(): void {
		Runs\Background::unschedule();
	}
}

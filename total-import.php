<?php
/**
 * Plugin Name:       Total Import
 * Description:       Import CSV, Excel, XML and JSON files into posts, pages, custom post types, terms, users and WooCommerce products, and update them on later runs.
 * Version:           0.2.2
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            WP Mayor
 * Author URI:        https://wpmayor.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       total-import
 */

defined( 'ABSPATH' ) || exit;

define( 'IMPORTRIGHT_VERSION', '0.2.2' );
define( 'IMPORTRIGHT_FILE', __FILE__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'Importright\\' ) ) {
			return;
		}
		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( 'Importright\\' ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, array( Importright\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Importright\Plugin::class, 'deactivate' ) );

Importright\Plugin::boot();

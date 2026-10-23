<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Universal.NamingConventions.NoReservedKeywordParameterNames,WordPress.WP.AlternativeFunctions -- stands in for WordPress inside the source namespace.

namespace Importright\Source;

/**
 * Uploads live in $GLOBALS['importright_test_uploads']; with none set, uploads are unavailable.
 */
function wp_upload_dir( $time = null, $create = true ) {
	unset( $time, $create );
	if ( empty( $GLOBALS['importright_test_uploads'] ) ) {
		return array( 'error' => 'no uploads in unit tests' );
	}
	return array(
		'basedir' => $GLOBALS['importright_test_uploads'],
		'error'   => false,
	);
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}

function wp_delete_file_from_directory( $file, $dir ) {
	unset( $dir );
	return unlink( $file );
}

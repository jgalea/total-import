<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Universal.NamingConventions.NoReservedKeywordParameterNames -- stands in for WordPress inside the runner's namespace.

namespace Importright\Runs;

foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
) as $importright_name => $importright_value ) {
	if ( ! defined( $importright_name ) ) {
		define( $importright_name, $importright_value );
	}
}

require_once __DIR__ . '/store-functions.php';

/**
 * WP-Cron events: $GLOBALS['importright_test_cron'] = list of [hook, args, recurrence|false, timestamp].
 */
function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['importright_test_cron'] ?? array() as $event ) {
		if ( $event[0] === $hook && $event[1] === $args ) {
			return $event[3];
		}
	}
	return false;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['importright_test_cron'][] = array( $hook, $args, false, $timestamp );
	return true;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	$GLOBALS['importright_test_cron'][] = array( $hook, $args, $recurrence, $timestamp );
	return true;
}

function wp_unschedule_hook( $hook ) {
	$GLOBALS['importright_test_cron'] = array_values(
		array_filter(
			$GLOBALS['importright_test_cron'] ?? array(),
			static function ( $event ) use ( $hook ) {
				return $event[0] !== $hook;
			}
		)
	);
	return 0;
}

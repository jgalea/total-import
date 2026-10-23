<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Universal.Files.SeparateFunctionsFromOO.Mixed -- stands in for Action Scheduler loaded by another plugin.

/**
 * Actions: $GLOBALS['importright_test_as'] = list of [hook, args, group, status, timestamp, interval].
 * Readiness: $GLOBALS['importright_test_as_ready'] (false before init).
 */
final class ActionScheduler {
	public static function is_initialized( $function_name = null ) {
		unset( $function_name );
		return ! empty( $GLOBALS['importright_test_as_ready'] );
	}
}

function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	$GLOBALS['importright_test_as'][] = array( $hook, $args, $group, 'pending', time(), 0 );
	return count( $GLOBALS['importright_test_as'] );
}

function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
	$GLOBALS['importright_test_as'][] = array( $hook, $args, $group, 'pending', $timestamp, 0 );
	return count( $GLOBALS['importright_test_as'] );
}

function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '' ) {
	$GLOBALS['importright_test_as'][] = array( $hook, $args, $group, 'pending', $timestamp, $interval );
	return count( $GLOBALS['importright_test_as'] );
}

function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['importright_test_as'] ?? array() as $action ) {
		if ( $action[0] === $hook && $action[2] === $group && in_array( $action[3], array( 'pending', 'in-progress' ), true ) ) {
			return true;
		}
	}
	return false;
}

function as_get_scheduled_actions( $query = array(), $return_format = 'OBJECT' ) {
	unset( $return_format );
	$ids = array();
	foreach ( $GLOBALS['importright_test_as'] ?? array() as $i => $action ) {
		if ( $action[0] === $query['hook'] && $action[1] === $query['args'] && $action[2] === $query['group'] && $action[3] === $query['status'] ) {
			$ids[] = $i + 1;
		}
	}
	return $ids;
}

function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
	unset( $hook, $args );
	$GLOBALS['importright_test_as'] = array_values(
		array_filter(
			$GLOBALS['importright_test_as'] ?? array(),
			static function ( $action ) use ( $group ) {
				return $action[2] !== $group;
			}
		)
	);
}

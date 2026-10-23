<?php

namespace Importright\Runs;

use Importright\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every step of a run or rollback acts as the user who started it, whichever
 * request drives the step: WP-CLI, the admin screen's AJAX calls, or an
 * Action Scheduler request (which carries the cookies of whoever loaded the
 * page that dispatched it). Anyone with Plugin::capability() may drive or
 * cancel a run; what it writes, and the checks it passes, are the owner's.
 * The owner must still exist and still have the capability, or the step fails.
 */
final class Actor {

	/**
	 * Switches to the owner and re-hooks kses for them.
	 *
	 * @return int|WP_Error The previous user's ID, for restore().
	 */
	public static function become( int $owner ) {
		if ( $owner <= 0 ) {
			return new WP_Error( 'importright_owner', __( 'This run has no user. Start it again as a user who can run imports (in WP-CLI, pass --user).', 'total-import' ) );
		}
		$user = get_userdata( $owner );
		if ( ! $user instanceof \WP_User ) {
			return new WP_Error( 'importright_owner', __( 'The user who started this run no longer exists, so it can\'t continue.', 'total-import' ) );
		}
		if ( ! user_can( $user, Plugin::capability() ) ) {
			/* translators: %s: user login */
			return new WP_Error( 'importright_owner', sprintf( __( '%s started this run but is no longer allowed to run imports, so it can\'t continue.', 'total-import' ), $user->user_login ) );
		}
		$previous = get_current_user_id();
		wp_set_current_user( $owner );
		kses_init();
		return $previous;
	}

	public static function restore( int $previous ): void {
		wp_set_current_user( $previous );
		kses_init();
	}
}

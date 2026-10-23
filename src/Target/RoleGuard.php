<?php

namespace Importright\Target;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an import may give a user a role. A role must be one the
 * importing user can assign (WordPress's editable roles). A role is
 * privileged by what it can do, not by its name: administrator, and any role
 * (shop_manager, custom roles) carrying a capability that manages users,
 * plugins, themes, options or the store, or that may post unfiltered HTML.
 * Privileged roles are refused unless the importing user can promote_users
 * and the import's definition explicitly allows them.
 */
final class RoleGuard {

	public const OK         = '';
	public const UNKNOWN    = 'unknown';
	public const NOT_YOURS  = 'not_assignable';
	public const PRIVILEGED = 'privileged';

	public const PRIVILEGED_CAPS = array(
		'promote_users',
		'edit_users',
		'create_users',
		'delete_users',
		'remove_users',
		'manage_options',
		'activate_plugins',
		'install_plugins',
		'edit_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_theme_options',
		'switch_themes',
		'install_themes',
		'edit_themes',
		'edit_files',
		'update_core',
		'unfiltered_html',
		'unfiltered_upload',
		'manage_woocommerce',
		'manage_network',
		'manage_network_options',
		'manage_network_users',
		'manage_sites',
	);

	/**
	 * @param array<string, array<string, bool>> $all_roles      Every role => its capabilities.
	 * @param string[]                           $editable_roles Roles the importing user may assign.
	 * @return string self::OK or the reason for refusing.
	 */
	public static function check( string $role, array $all_roles, array $editable_roles, bool $can_promote, bool $allow_privileged ): string {
		if ( ! isset( $all_roles[ $role ] ) ) {
			return self::UNKNOWN;
		}
		if ( ! in_array( $role, $editable_roles, true ) ) {
			return self::NOT_YOURS;
		}
		if ( self::is_privileged( $role, $all_roles[ $role ] ) && ! ( $can_promote && $allow_privileged ) ) {
			return self::PRIVILEGED;
		}
		return self::OK;
	}

	/**
	 * @param array<string, bool> $caps
	 */
	public static function is_privileged( string $role, array $caps ): bool {
		if ( 'administrator' === $role ) {
			return true;
		}
		foreach ( self::PRIVILEGED_CAPS as $cap ) {
			if ( ! empty( $caps[ $cap ] ) ) {
				return true;
			}
		}
		return false;
	}
}

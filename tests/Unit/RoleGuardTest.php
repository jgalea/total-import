<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Field\MetaHandler;
use Importright\Target\RoleGuard;

final class RoleGuardTest extends TestCase {

	private const ROLES = array(
		'administrator' => array(
			'manage_options' => true,
			'promote_users'  => true,
		),
		'editor'        => array( 'edit_others_posts' => true ),
		'subscriber'    => array( 'read' => true ),
		'shop_manager'  => array(
			'list_users'         => true,
			'edit_theme_options' => true,
			'manage_woocommerce' => true,
			'edit_products'      => true,
		),
		'store_admin'   => array(
			'read'               => true,
			'manage_woocommerce' => true,
		),
		'html_writer'   => array(
			'edit_posts'      => true,
			'unfiltered_html' => true,
		),
		'theme_tweaker' => array( 'edit_theme_options' => true ),
		'sneaky'        => array(
			'read'          => true,
			'promote_users' => false,
		),
	);

	public function test_assignable_roles_pass(): void {
		$this->assertSame( RoleGuard::OK, RoleGuard::check( 'subscriber', self::ROLES, array( 'subscriber', 'editor' ), false, false ) );
		$this->assertSame( RoleGuard::OK, RoleGuard::check( 'sneaky', self::ROLES, array( 'sneaky' ), false, false ), 'A capability set to false is not granted.' );
	}

	public function test_unknown_and_unassignable_roles(): void {
		$this->assertSame( RoleGuard::UNKNOWN, RoleGuard::check( 'ghost', self::ROLES, array( 'ghost' ), true, true ) );
		$this->assertSame( RoleGuard::NOT_YOURS, RoleGuard::check( 'editor', self::ROLES, array( 'subscriber' ), true, true ) );
	}

	public function test_administrator_needs_promote_users_and_the_flag(): void {
		$all = array_keys( self::ROLES );
		$this->assertSame( RoleGuard::PRIVILEGED, RoleGuard::check( 'administrator', self::ROLES, $all, false, false ) );
		$this->assertSame( RoleGuard::PRIVILEGED, RoleGuard::check( 'administrator', self::ROLES, $all, true, false ) );
		$this->assertSame( RoleGuard::PRIVILEGED, RoleGuard::check( 'administrator', self::ROLES, $all, false, true ) );
		$this->assertSame( RoleGuard::OK, RoleGuard::check( 'administrator', self::ROLES, $all, true, true ) );
	}

	public function test_roles_with_user_management_caps_count_as_privileged(): void {
		$this->assertSame( RoleGuard::PRIVILEGED, RoleGuard::check( 'shop_manager', self::ROLES, array( 'shop_manager' ), true, false ) );
		$this->assertTrue( RoleGuard::is_privileged( 'administrator', array() ), 'Administrator by name, whatever its caps.' );
	}

	public function test_privilege_comes_from_capabilities_not_role_names(): void {
		$this->assertTrue( RoleGuard::is_privileged( 'shop_manager', self::ROLES['shop_manager'] ), 'WooCommerce\'s shop_manager has no edit_users.' );
		foreach ( array( 'store_admin', 'html_writer', 'theme_tweaker' ) as $role ) {
			$this->assertSame( RoleGuard::PRIVILEGED, RoleGuard::check( $role, self::ROLES, array( $role ), true, false ), $role );
			$this->assertSame( RoleGuard::OK, RoleGuard::check( $role, self::ROLES, array( $role ), true, true ), $role );
		}
		foreach ( array( 'manage_woocommerce', 'edit_users', 'promote_users', 'manage_options', 'edit_theme_options', 'activate_plugins', 'unfiltered_html' ) as $cap ) {
			$this->assertTrue( RoleGuard::is_privileged( 'custom_' . $cap, array( $cap => true ) ), $cap );
		}
		$this->assertFalse( RoleGuard::is_privileged( 'editor', self::ROLES['editor'] ) );
	}

	public function test_user_meta_that_grants_roles_is_protected(): void {
		$this->assertTrue( MetaHandler::is_protected_user_meta( 'wp_capabilities' ) );
		$this->assertTrue( MetaHandler::is_protected_user_meta( 'wp_2_capabilities' ) );
		$this->assertTrue( MetaHandler::is_protected_user_meta( 'WP_USER_LEVEL' ) );
		$this->assertTrue( MetaHandler::is_protected_user_meta( 'session_tokens' ) );
		$this->assertFalse( MetaHandler::is_protected_user_meta( 'billing_phone' ) );
	}
}

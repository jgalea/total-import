<?php

namespace Importright\Target;

use Importright\Field\TaxonomiesHandler;
use Importright\Runs\Context;
use Importright\Runs\Diff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Users, saved through wp_insert_user and wp_update_user. Passwords are never
 * imported: new users get a random one and can reset it. Creating users needs
 * create_users and editing one needs edit_user for that user. Roles pass
 * RoleGuard, and existing users with a privileged role are left alone unless
 * the definition allows privileged roles and the importer can promote_users.
 */
final class UserTarget implements Target, NormalizesKey, Previewable {

	private const COLUMNS = array(
		'email'        => 'user_email',
		'display_name' => 'display_name',
		'first_name'   => 'first_name',
		'last_name'    => 'last_name',
		'nickname'     => 'nickname',
		'url'          => 'user_url',
		'description'  => 'description',
	);

	public function key(): string {
		return 'user';
	}

	public function label(): string {
		return __( 'Users', 'total-import' );
	}

	public function object_type(): string {
		return 'user';
	}

	public function fields(): array {
		return array(
			'login'        => __( 'Username (new users only)', 'total-import' ),
			'email'        => __( 'Email', 'total-import' ),
			'display_name' => __( 'Display name', 'total-import' ),
			'first_name'   => __( 'First name', 'total-import' ),
			'last_name'    => __( 'Last name', 'total-import' ),
			'nickname'     => __( 'Nickname', 'total-import' ),
			'url'          => __( 'Website', 'total-import' ),
			'description'  => __( 'Biographical info', 'total-import' ),
			'role'         => __( 'Role', 'total-import' ),
		);
	}

	public function match_strategies(): array {
		return array( 'id', 'email', 'login' );
	}

	public function validate( array $settings ) {
		$role = sanitize_key( (string) ( $settings['default_role'] ?? get_option( 'default_role', 'subscriber' ) ) );
		if ( null === get_role( $role ) ) {
			/* translators: %s: role */
			return new WP_Error( 'importright_target', sprintf( __( 'Unknown role: %s', 'total-import' ), $role ) );
		}
		return array(
			'default_role'           => $role,
			'allow_privileged_roles' => '1' === TaxonomiesHandler::flag( $settings['allow_privileged_roles'] ?? false ),
			'send_notification'      => '1' === TaxonomiesHandler::flag( $settings['send_notification'] ?? false ),
		);
	}

	/**
	 * Email addresses are case-insensitive in WordPress, so a key that is an
	 * email is tracked lower-cased.
	 */
	public function normalize_key( string $key ): string {
		return false === strpos( $key, '@' ) ? $key : strtolower( $key );
	}

	public function prime( array $ids ): void {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( array() !== $ids ) {
			cache_users( $ids );
		}
	}

	public function exists( int $id, array $settings ): bool {
		return false !== get_userdata( $id );
	}

	public function find( string $strategy, array $values, string $unique_key, array $settings ): ?int {
		$user = null;
		if ( 'id' === $strategy && ctype_digit( $unique_key ) ) {
			$user = get_user_by( 'id', (int) $unique_key );
		} elseif ( 'email' === $strategy && is_email( trim( (string) ( $values['email'] ?? '' ) ) ) ) {
			$user = get_user_by( 'email', trim( (string) $values['email'] ) );
		} elseif ( 'login' === $strategy && '' !== trim( (string) ( $values['login'] ?? '' ) ) ) {
			$user = get_user_by( 'login', trim( (string) $values['login'] ) );
		} elseif ( 0 === strpos( $strategy, 'meta:' ) ) {
			$found = get_users(
				array(
					'meta_key'   => substr( $strategy, 5 ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $unique_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'number'     => 1,
					'fields'     => 'ID',
					'blog_id'    => 0,
				)
			);
			return array() === $found ? null : (int) $found[0];
		}
		return $user instanceof \WP_User ? (int) $user->ID : null;
	}

	public function save( ?int $id, array $fields, array $settings, Context $context ) {
		$data = $this->prepare( $id, $fields, $settings, $context );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( null === $id ) {
			$data['user_login'] = self::login( $fields, $data );
			$data['user_pass']  = wp_generate_password( 32, true, true );
			$result             = wp_insert_user( wp_slash( $data ) );
			if ( ! is_wp_error( $result ) && $settings['send_notification'] ) {
				wp_new_user_notification( (int) $result, null, 'user' );
			}
		} else {
			if ( array() === $data ) {
				return $id;
			}
			$data['ID'] = $id;
			add_filter( 'send_email_change_email', '__return_false' );
			$result = wp_update_user( wp_slash( $data ) );
			remove_filter( 'send_email_change_email', '__return_false' );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (int) $result;
	}

	public function preview( ?int $id, array $fields, array $settings, Context $context, Diff $diff ) {
		$data = $this->prepare( $id, $fields, $settings, $context );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$user = null === $id ? null : get_userdata( $id );
		if ( null === $id ) {
			$login = self::login( $fields, $data );
			if ( username_exists( $login ) ) {
				return new WP_Error( 'existing_user_login', __( 'Sorry, that username already exists!' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own message, as wp_insert_user returns it.
			}
			if ( email_exists( (string) $data['user_email'] ) ) {
				return new WP_Error( 'existing_user_email', __( 'Sorry, that email address is already used!' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own message, as wp_insert_user returns it.
			}
			$diff->add( 'login', null, $login );
		}
		foreach ( self::COLUMNS as $field => $column ) {
			if ( array_key_exists( $column, $data ) ) {
				$diff->add( $field, $user instanceof \WP_User ? $user->{$column} : null, $data[ $column ] );
			}
		}
		if ( isset( $data['role'] ) ) {
			$diff->add( 'role', $user instanceof \WP_User ? implode( ', ', $user->roles ) : null, $data['role'] );
		}
		return true;
	}

	/**
	 * @param array<string, string> $fields
	 * @param array<string, mixed>  $data
	 */
	private static function login( array $fields, array $data ): string {
		$login = trim( (string) ( $fields['login'] ?? '' ) );
		return '' === $login ? (string) ( $data['user_email'] ?? '' ) : $login;
	}

	/**
	 * Checks the record and returns the wp_insert_user / wp_update_user fields, without the login or password.
	 *
	 * @param array<string, string> $fields
	 * @param array<string, mixed>  $settings
	 * @return array<string, string>|WP_Error
	 */
	private function prepare( ?int $id, array $fields, array $settings, Context $context ) {
		$data = array();
		foreach ( self::COLUMNS as $field => $column ) {
			if ( array_key_exists( $field, $fields ) ) {
				$data[ $column ] = trim( (string) $fields[ $field ] );
			}
		}
		if ( isset( $data['user_email'] ) && '' !== $data['user_email'] && ! is_email( $data['user_email'] ) ) {
			/* translators: %s: email */
			return new WP_Error( 'importright_save', sprintf( __( '"%s" is not a valid email address.', 'total-import' ), $data['user_email'] ) );
		}
		if ( null !== $id && isset( $data['user_email'] ) && '' === $data['user_email'] ) {
			unset( $data['user_email'] );
		}

		$role = array_key_exists( 'role', $fields ) ? sanitize_key( (string) $fields['role'] ) : '';
		if ( null === $id && '' === $role ) {
			$role = (string) $settings['default_role'];
		}
		if ( '' !== $role ) {
			$refused = $this->refuse_role( $role, $settings );
			if ( null !== $refused ) {
				return $refused;
			}
			$data['role'] = $role;
		}

		if ( null === $id ) {
			if ( ! current_user_can( 'create_users' ) ) {
				return new WP_Error( 'importright_permission', __( 'The user running this import can\'t create users.', 'total-import' ) );
			}
			if ( '' === self::login( $fields, $data ) || empty( $data['user_email'] ) ) {
				return new WP_Error( 'importright_save', __( 'A new user needs a username and an email address.', 'total-import' ) );
			}
			return $data;
		}

		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'importright_permission', __( 'The user running this import can\'t edit this user.', 'total-import' ) );
		}
		if ( $this->is_privileged_user( $id ) && ! ( $settings['allow_privileged_roles'] && current_user_can( 'promote_users' ) ) ) {
			return new WP_Error( 'importright_permission', __( 'This user has an administrator-level role, so the import left them unchanged.', 'total-import' ) );
		}
		if ( array_key_exists( 'login', $fields ) && '' !== trim( (string) $fields['login'] ) && get_userdata( $id )->user_login !== trim( (string) $fields['login'] ) ) {
			$context->notice( __( 'Usernames can\'t be changed, so the username was left as it is.', 'total-import' ) );
		}
		return $data;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function refuse_role( string $role, array $settings ): ?WP_Error {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$all = array();
		foreach ( wp_roles()->roles as $key => $details ) {
			$all[ $key ] = (array) ( $details['capabilities'] ?? array() );
		}
		$reason = RoleGuard::check( $role, $all, array_keys( get_editable_roles() ), current_user_can( 'promote_users' ), (bool) $settings['allow_privileged_roles'] );
		if ( RoleGuard::OK === $reason ) {
			return null;
		}
		if ( RoleGuard::UNKNOWN === $reason ) {
			/* translators: %s: role */
			$message = __( 'The role "%s" doesn\'t exist.', 'total-import' );
		} elseif ( RoleGuard::NOT_YOURS === $reason ) {
			/* translators: %s: role */
			$message = __( 'The user running this import can\'t assign the role "%s".', 'total-import' );
		} else {
			/* translators: %s: role */
			$message = __( 'The role "%s" has administrator-level capabilities. Imports only assign it when the definition sets allow_privileged_roles and the user running the import can promote users.', 'total-import' );
		}
		return new WP_Error( 'importright_role', sprintf( $message, $role ) );
	}

	private function is_privileged_user( int $id ): bool {
		$user = get_userdata( $id );
		if ( false === $user ) {
			return false;
		}
		if ( is_super_admin( $id ) || in_array( 'administrator', $user->roles, true ) ) {
			return true;
		}
		foreach ( RoleGuard::PRIVILEGED_CAPS as $cap ) {
			if ( ! empty( $user->allcaps[ $cap ] ) ) {
				return true;
			}
		}
		return false;
	}
}

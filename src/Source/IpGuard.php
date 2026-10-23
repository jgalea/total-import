<?php

namespace Importright\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an address is on the public internet. Used for every hop
 * of a URL download, so a feed can't be pointed at the server's own network.
 */
final class IpGuard {

	private const BLOCKED = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'::/96',
		'::ffff:0:0/96',
		'64:ff9b:1::/48',
		'100::/64',
		'2001::/23',
		'2001:db8::/32',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	);

	public static function is_public( string $ip ): bool {
		$ip = trim( $ip, '[] ' );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$embedded = self::embedded_ipv4( $ip );
		if ( null !== $embedded ) {
			return self::is_public( $embedded );
		}
		foreach ( self::BLOCKED as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return false;
			}
		}
		return true;
	}

	public static function in_range( string $ip, string $cidr ): bool {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, null );

		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( (string) $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}
		$bits  = null === $bits ? strlen( $ip_bin ) * 8 : (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( 0 !== strncmp( $ip_bin, $subnet_bin, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
	}

	/**
	 * The IPv4 address inside an IPv4-mapped (::ffff:a.b.c.d), NAT64 (64:ff9b::a.b.c.d) or 6to4 (2002:aabb:ccdd::) address.
	 */
	private static function embedded_ipv4( string $ip ): ?string {
		if ( false === strpos( $ip, ':' ) ) {
			return null;
		}
		$bin = inet_pton( $ip );
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return null;
		}
		if ( self::in_range( $ip, '::ffff:0:0/96' ) || self::in_range( $ip, '64:ff9b::/96' ) ) {
			$v4 = inet_ntop( substr( $bin, 12 ) );
			return false === $v4 ? null : $v4;
		}
		if ( self::in_range( $ip, '2002::/16' ) ) {
			$v4 = inet_ntop( substr( $bin, 2, 4 ) );
			return false === $v4 ? null : $v4;
		}
		return null;
	}
}

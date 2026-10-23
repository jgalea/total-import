<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Source\IpGuard;

final class IpGuardTest extends TestCase {

	/**
	 * @dataProvider blocked
	 */
	public function test_blocks_non_public_addresses( string $ip ): void {
		$this->assertFalse( IpGuard::is_public( $ip ) );
	}

	/**
	 * @return array<int, array{0: string}>
	 */
	public function blocked(): array {
		return array_map(
			static function ( string $ip ): array {
				return array( $ip );
			},
			array( '127.0.0.1', '127.255.0.9', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255', '198.18.0.1', '192.0.2.10', '::1', '::', 'fe80::1', 'fd00::1', 'fc12::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:7f00:1', '::ffff:10.0.0.1', '64:ff9b::a9fe:a9fe', '2001:db8::1', '2002:7f00:1::1', '2002:c0a8:101::1', '2002:a9fe:a9fe::', '64:ff9b::7f00:1', '64:ff9b::c0a8:1', '::ffff:192.168.0.1', '::ffff:169.254.169.254', '::127.0.0.1', '::0', '0:0:0:0:0:ffff:7f00:1', '[::1]', 'not-an-ip', '', '1.2.3' )
		);
	}

	/**
	 * @dataProvider allowed
	 */
	public function test_allows_public_addresses( string $ip ): void {
		$this->assertTrue( IpGuard::is_public( $ip ) );
	}

	/**
	 * @return array<int, array{0: string}>
	 */
	public function allowed(): array {
		return array_map(
			static function ( string $ip ): array {
				return array( $ip );
			},
			array( '8.8.8.8', '1.1.1.1', '172.32.0.1', '100.128.0.1', '172.15.255.255', '2606:4700:4700::1111', '::ffff:8.8.8.8', '64:ff9b::808:808', '2002:808:808::1', '2a00:1450:4001::200e' )
		);
	}

	public function test_cidr_matching(): void {
		$this->assertTrue( IpGuard::in_range( '10.0.0.255', '10.0.0.0/24' ) );
		$this->assertFalse( IpGuard::in_range( '10.0.1.0', '10.0.0.0/24' ) );
		$this->assertTrue( IpGuard::in_range( '100.127.255.255', '100.64.0.0/10' ) );
		$this->assertFalse( IpGuard::in_range( '::1', '10.0.0.0/8' ) );
	}
}

<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Source\Credentials;

final class CredentialsTest extends TestCase {

	private const STORED = array(
		'type'     => 'url',
		'url'      => 'https://feeds.example.com/products.csv',
		'username' => 'reader',
		'password' => 'secret',
	);

	/**
	 * @dataProvider same_origin
	 */
	public function test_keeps_password_for_the_same_origin_and_username( string $url ): void {
		$this->assertTrue(
			Credentials::can_reuse(
				self::STORED,
				array(
					'url'      => $url,
					'username' => 'reader',
				)
			)
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function same_origin(): array {
		return array(
			'unchanged'          => array( 'https://feeds.example.com/products.csv' ),
			'other path'         => array( 'https://feeds.example.com/v2/products.csv?page=1' ),
			'explicit 443'       => array( 'https://feeds.example.com:443/products.csv' ),
			'host case'          => array( 'https://FEEDS.example.com/products.csv' ),
			'surrounding spaces' => array( '  https://feeds.example.com/products.csv ' ),
		);
	}

	/**
	 * @dataProvider other_origin
	 */
	public function test_drops_password_when_the_destination_changes( string $url, string $username ): void {
		$this->assertFalse(
			Credentials::can_reuse(
				self::STORED,
				array(
					'url'      => $url,
					'username' => $username,
				)
			)
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function other_origin(): array {
		return array(
			'other host'       => array( 'https://attacker.example.net/products.csv', 'reader' ),
			'subdomain'        => array( 'https://evil.feeds.example.com/products.csv', 'reader' ),
			'host as userinfo' => array( 'https://feeds.example.com@attacker.example.net/products.csv', 'reader' ),
			'other port'       => array( 'https://feeds.example.com:8443/products.csv', 'reader' ),
			'http instead'     => array( 'http://feeds.example.com/products.csv', 'reader' ),
			'other username'   => array( 'https://feeds.example.com/products.csv', 'admin' ),
			'username removed' => array( 'https://feeds.example.com/products.csv', '' ),
			'not a url'        => array( 'feeds.example.com/products.csv', 'reader' ),
			'other scheme'     => array( 'ftp://feeds.example.com/products.csv', 'reader' ),
		);
	}

	public function test_a_stored_source_without_a_valid_url_never_matches(): void {
		$this->assertFalse(
			Credentials::can_reuse(
				array(
					'url'      => '',
					'username' => '',
				),
				array(
					'url'      => '',
					'username' => '',
				)
			)
		);
	}

	public function test_origin_fills_default_ports(): void {
		$this->assertSame( 'http://example.com:80', Credentials::origin( 'http://example.com/a' ) );
		$this->assertSame( 'https://example.com:443', Credentials::origin( 'https://example.com' ) );
		$this->assertNull( Credentials::origin( 'javascript:alert(1)' ) );
	}
}

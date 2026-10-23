<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Source\UrlFetcher;

require_once dirname( __DIR__ ) . '/stubs/source-functions.php';

final class UrlFetcherTest extends TestCase {

	/**
	 * Follows $locations as redirects, then answers 200, returning the Authorization header each hop carried.
	 *
	 * @param string[] $locations
	 * @return array<int, string|null>
	 */
	private function auth_per_hop( string $url, array $locations ): array {
		$GLOBALS['importright_test_requests']  = array();
		$GLOBALS['importright_test_responses'] = array();
		foreach ( $locations as $location ) {
			$GLOBALS['importright_test_responses'][] = array(
				'code'    => 302,
				'headers' => array( 'location' => $location ),
			);
		}
		$GLOBALS['importright_test_responses'][]                               = array(
			'code'    => 200,
			'headers' => array(),
		);
		$GLOBALS['importright_test_filters']['importright_allow_private_urls'] = static function (): bool {
			return true;
		};
		$target = sys_get_temp_dir() . '/importright-fetch-' . uniqid() . '.csv';
		try {
			$result = UrlFetcher::download(
				array(
					'url'      => $url,
					'username' => 'feed',
					'password' => 'secret',
				),
				$target
			);
		} finally {
			unset( $GLOBALS['importright_test_filters']['importright_allow_private_urls'] );
			if ( is_file( $target ) ) {
				unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- a test temp file.
			}
		}
		$this->assertIsArray( $result );
		$this->assertCount( count( $locations ) + 1, $GLOBALS['importright_test_requests'] );
		return array_map(
			static function ( array $request ): ?string {
				return $request['headers']['Authorization'] ?? null;
			},
			$GLOBALS['importright_test_requests']
		);
	}

	public function test_credentials_follow_a_redirect_on_the_same_scheme_host_and_port(): void {
		$basic = 'Basic ' . base64_encode( 'feed:secret' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertSame( array( $basic, $basic ), $this->auth_per_hop( 'https://203.0.113.5/feed', array( '/feed.csv' ) ) );
		$this->assertSame( array( $basic, $basic ), $this->auth_per_hop( 'https://203.0.113.5/feed', array( 'https://203.0.113.5:443/feed.csv' ) ) );
		$this->assertSame( array( $basic, $basic ), $this->auth_per_hop( 'http://203.0.113.5/feed', array( 'http://203.0.113.5/feed.csv' ) ), 'A plain http source keeps http (the wizard warns about it).' );
	}

	public function test_credentials_are_not_sent_after_a_downgrade_to_http(): void {
		$hops = $this->auth_per_hop( 'https://203.0.113.5/feed', array( 'http://203.0.113.5/feed.csv' ) );
		$this->assertNotNull( $hops[0] );
		$this->assertNull( $hops[1] );
	}

	public function test_credentials_are_not_sent_to_another_port(): void {
		$hops = $this->auth_per_hop( 'https://203.0.113.5/feed', array( 'https://203.0.113.5:8443/feed.csv', 'https://203.0.113.5/back.csv' ) );
		$this->assertNotNull( $hops[0] );
		$this->assertNull( $hops[1] );
		$this->assertNotNull( $hops[2], 'Back on the original endpoint they are sent again.' );
	}

	public function test_credentials_are_not_sent_to_another_host(): void {
		$hops = $this->auth_per_hop( 'https://203.0.113.5/feed', array( 'https://203.0.113.6/feed.csv' ) );
		$this->assertNull( $hops[1] );
	}

	public function test_google_sheets_links_become_csv_exports(): void {
		$this->assertSame( 'https://docs.google.com/spreadsheets/d/abc_DEF-123/export?format=csv&gid=0', UrlFetcher::normalize( 'https://docs.google.com/spreadsheets/d/abc_DEF-123/edit' ) );
		$this->assertSame( 'https://docs.google.com/spreadsheets/d/abc/export?format=csv&gid=42', UrlFetcher::normalize( 'https://docs.google.com/spreadsheets/d/abc/edit#gid=42' ) );
		$this->assertSame( 'https://docs.google.com/spreadsheets/d/abc/export?format=csv&gid=7', UrlFetcher::normalize( 'https://docs.google.com/spreadsheets/d/abc/edit?usp=sharing&gid=7' ) );
	}

	public function test_refuses_to_download_when_connections_cannot_be_pinned(): void {
		$GLOBALS['importright_test_filters']['importright_can_pin_connections'] = static function (): bool {
			return false;
		};
		try {
			$this->assertFalse( UrlFetcher::can_pin() );
			$result = UrlFetcher::download( array( 'url' => 'https://example.com/feed.csv' ), sys_get_temp_dir() . '/importright-never-written.csv' );
		} finally {
			unset( $GLOBALS['importright_test_filters']['importright_can_pin_connections'] );
		}
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'importright_no_pinning', $result->get_error_code() );
		$this->assertFileDoesNotExist( sys_get_temp_dir() . '/importright-never-written.csv' );
	}

	public function test_published_and_other_links_pass_through(): void {
		$published = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vabc/pub?gid=0&single=true&output=csv';
		$this->assertSame( $published, UrlFetcher::normalize( $published ) );
		$this->assertSame( 'https://example.com/feed.xml', UrlFetcher::normalize( ' https://example.com/feed.xml ' ) );
	}
}

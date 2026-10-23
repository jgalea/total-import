<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- tests work on temporary files.

namespace Importright\Tests\Unit;

use Importright\Source\Sources;
use WP_Error;

require_once dirname( __DIR__ ) . '/stubs/source-functions.php';
require_once dirname( __DIR__ ) . '/stubs/store-functions.php';
require_once dirname( __DIR__ ) . '/stubs/storage-functions.php';

final class SourceHooksTest extends FileCase {

	private string $uploads;

	protected function setUp(): void {
		$this->uploads                       = sys_get_temp_dir() . '/importright-test-' . bin2hex( random_bytes( 4 ) );
		$GLOBALS['importright_test_uploads'] = $this->uploads;
		$GLOBALS['importright_test_options'] = array();
		$GLOBALS['importright_test_filters'] = array();
		mkdir( $this->uploads );
	}

	protected function tearDown(): void {
		$GLOBALS['importright_test_filters'] = array();
		unset( $GLOBALS['importright_test_uploads'] );
		foreach ( (array) glob( $this->uploads . '/*/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( (array) glob( $this->uploads . '/*' ) as $dir ) {
			rmdir( $dir );
		}
		rmdir( $this->uploads );
		parent::tearDown();
	}

	public function test_default_types(): void {
		$this->assertSame( array( 'upload', 'url', 'path' ), array_keys( Sources::types() ) );
	}

	public function test_a_listener_adds_a_type(): void {
		$GLOBALS['importright_test_filters']['importright_source_types'] = static function ( array $types ): array {
			$types['ftp'] = 'FTP';
			return $types;
		};
		$this->assertSame( 'FTP', Sources::types()['ftp'] );
	}

	public function test_unknown_type_without_a_listener_keeps_todays_errors(): void {
		$error = Sources::normalize( array( 'type' => 'ftp' ) );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'Choose an upload, a URL or a server path as the source.', $error->get_error_message() );

		$error = Sources::prepare( array( 'type' => 'ftp' ), 5 );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'The import has no valid source.', $error->get_error_message() );
	}

	public function test_a_fake_ftp_type_normalizes_with_the_stored_secret(): void {
		$seen = null;
		$GLOBALS['importright_test_filters']['importright_normalize_source'] = static function ( $normalized, array $source, ?array $stored ) use ( &$seen ) {
			$seen = $stored;
			return array(
				'host'     => (string) $source['host'],
				'password' => '' === $source['password'] ? (string) ( $stored['password'] ?? '' ) : $source['password'],
			);
		};
		$stored     = array(
			'type'     => 'ftp',
			'host'     => 'ftp.example.com',
			'password' => 'kept',
		);
		$normalized = Sources::normalize(
			array(
				'type'     => 'ftp',
				'host'     => 'ftp.example.com',
				'password' => '',
			),
			$stored
		);
		$this->assertEquals( $stored, $normalized );
		$this->assertSame( $stored, $seen );
	}

	public function test_a_stored_secret_of_another_type_is_not_passed(): void {
		$seen = 'unset';
		$GLOBALS['importright_test_filters']['importright_normalize_source'] = static function ( $normalized, array $source, ?array $stored ) use ( &$seen ) {
			unset( $normalized, $source );
			$seen = $stored;
			return null;
		};
		Sources::normalize(
			array( 'type' => 'api' ),
			array(
				'type'     => 'ftp',
				'password' => 'kept',
			)
		);
		$this->assertNull( $seen );
	}

	public function test_redact_blanks_the_password_and_applies_the_mask_filter(): void {
		$GLOBALS['importright_test_filters']['importright_mask_source'] = static function ( array $source ): array {
			$source['token'] = '';
			return $source;
		};
		$redacted = Sources::redact(
			array(
				'type'     => 'api',
				'password' => 'p',
				'token'    => 'secret-token',
			)
		);
		$this->assertSame( '', $redacted['password'] );
		$this->assertSame( '', $redacted['token'] );
	}

	public function test_a_normalize_error_is_returned(): void {
		$GLOBALS['importright_test_filters']['importright_normalize_source'] = static function () {
			return new WP_Error( 'ftp', 'Bad host' );
		};
		$error = Sources::normalize( array( 'type' => 'ftp' ) );
		$this->assertSame( 'Bad host', $error->get_error_message() );
	}

	public function test_a_fetched_file_goes_through_the_unpack_path(): void {
		$dest_seen = '';
		$GLOBALS['importright_test_filters']['importright_fetch_source'] = static function ( $fetched, array $source, string $dest, int $run_id ) use ( &$dest_seen ) {
			unset( $fetched, $source );
			$dest_seen = $dest;
			file_put_contents( $dest, gzencode( "a,b\n1,2\n" ) );
			return array( 'name' => 'feed-' . $run_id . '.csv.gz' );
		};

		$prepared = Sources::prepare( array( 'type' => 'ftp' ), 9 );

		$this->assertIsArray( $prepared );
		$this->assertSame( 'csv', $prepared['ext'] );
		$this->assertSame( "a,b\n1,2\n", file_get_contents( $prepared['path'] ) );
		$this->assertStringContainsString( '/run-9-', $dest_seen );
		$this->assertFileDoesNotExist( $dest_seen );
	}

	public function test_a_plain_fetched_file_is_used_as_is(): void {
		$GLOBALS['importright_test_filters']['importright_fetch_source'] = static function ( $fetched, array $source, string $dest ) {
			unset( $fetched, $source );
			file_put_contents( $dest, "a\n1\n" );
			return array( 'name' => 'feed.csv' );
		};
		$prepared = Sources::prepare( array( 'type' => 'ftp' ), 10 );
		$this->assertSame( 'csv', $prepared['ext'] );
		$this->assertSame( "a\n1\n", file_get_contents( $prepared['path'] ) );
	}

	public function test_a_fetch_error_is_returned(): void {
		$GLOBALS['importright_test_filters']['importright_fetch_source'] = static function () {
			return new WP_Error( 'ftp', 'Login failed' );
		};
		$error = Sources::prepare( array( 'type' => 'ftp' ), 11 );
		$this->assertSame( 'Login failed', $error->get_error_message() );
	}
}

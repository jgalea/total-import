<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- tests work on temporary files.

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Migration\Migrator;
use WP_Error;

final class WpaiSiteSourceTest extends TestCase {

	private string $root;
	private string $uploads;

	protected function setUp(): void {
		$this->root    = realpath( sys_get_temp_dir() ) . '/importright-wpai-' . bin2hex( random_bytes( 4 ) );
		$this->uploads = $this->root . '/uploads';
		mkdir( $this->uploads . '/wpallimport/uploads/abc', 0777, true );
		file_put_contents( $this->uploads . '/wpallimport/uploads/abc/posts.csv', "title\nA\n" );
		file_put_contents( $this->root . '/secret.csv', "secret\n1\n" );
		file_put_contents( $this->root . '/wp-config.php', "<?php\n" );
		symlink( $this->root . '/secret.csv', $this->uploads . '/wpallimport/uploads/abc/link.csv' );
	}

	protected function tearDown(): void {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private function source( string $type, string $path, string $data_file = '', bool $can_use_paths = true ) {
		return Migrator::site_source(
			array(
				'type' => $type,
				'path' => $path,
			),
			$data_file,
			$this->uploads,
			array( $this->uploads ),
			$can_use_paths
		);
	}

	public function test_a_wpai_upload_inside_uploads_becomes_a_path_source(): void {
		$source = $this->source( 'upload', '/wpallimport/uploads/abc/posts.csv' );
		$this->assertSame( 'path', $source['type'] );
		$this->assertSame( $this->uploads . '/wpallimport/uploads/abc/posts.csv', $source['path'] );

		$absolute = $this->source( 'file', $this->uploads . '/wpallimport/uploads/abc/posts.csv' );
		$this->assertSame( $source['path'], $absolute['path'] );
	}

	/**
	 * @dataProvider escapes
	 */
	public function test_paths_that_leave_uploads_are_refused( string $path ): void {
		$source = $this->source( 'upload', $path );
		$this->assertInstanceOf( WP_Error::class, $source );
		$this->assertSame( 'importright_migration', $source->get_error_code() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function escapes(): array {
		return array(
			'dot-dot to a csv'         => array( '/wpallimport/../../secret.csv' ),
			'dot-dot to wp-config.php' => array( '../wp-config.php' ),
			'absolute outside'         => array( '/etc/passwd' ),
			'symlink out of uploads'   => array( '/wpallimport/uploads/abc/link.csv' ),
			'stream wrapper'           => array( 'php://filter/resource=/etc/passwd' ),
		);
	}

	public function test_basedir_prefix_with_dot_dot_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, $this->source( 'file', $this->uploads . '/../secret.csv' ) );
	}

	public function test_a_data_file_must_be_inside_the_allowed_roots(): void {
		$this->assertInstanceOf( WP_Error::class, $this->source( 'url', 'https://example.com/a.csv', $this->root . '/secret.csv' ) );
		$ok = $this->source( 'url', 'https://example.com/a.csv', $this->uploads . '/wpallimport/uploads/abc/posts.csv' );
		$this->assertSame( 'path', $ok['type'] );
	}

	public function test_server_paths_need_the_right_to_use_them(): void {
		$refused = $this->source( 'upload', '/wpallimport/uploads/abc/posts.csv', '', false );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'importright_source', $refused->get_error_code() );

		$data = $this->source( 'url', 'https://example.com/a.csv', $this->uploads . '/wpallimport/uploads/abc/posts.csv', false );
		$this->assertSame( 'importright_source', $data->get_error_code() );

		$url = $this->source( 'url', 'https://example.com/a.csv', '', false );
		$this->assertSame(
			array(
				'type' => 'url',
				'url'  => 'https://example.com/a.csv',
			),
			$url
		);
	}
}

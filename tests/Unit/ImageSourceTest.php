<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Media\ImageSource;

final class ImageSourceTest extends TestCase {

	public function test_attachment_ids(): void {
		$source = ImageSource::classify( ' 42 ' );
		$this->assertSame( ImageSource::ID, $source['type'] );
		$this->assertSame( '42', $source['key'] );
	}

	public function test_urls_get_a_stable_dedup_key(): void {
		$a = ImageSource::classify( 'HTTPS://Example.COM:443/img/Photo%201.JPG?v=2#top' );
		$b = ImageSource::classify( 'https://example.com/img/Photo%201.JPG?v=2' );
		$this->assertSame( ImageSource::URL, $a['type'] );
		$this->assertSame( 'https://example.com/img/Photo%201.JPG?v=2', $a['key'] );
		$this->assertSame( $a['key'], $b['key'] );
		$this->assertSame( 'Photo 1.JPG', $a['filename'] );
	}

	public function test_url_keys_keep_what_identifies_the_file(): void {
		$this->assertNotSame( ImageSource::url_key( 'https://example.com/a.jpg?v=1' ), ImageSource::url_key( 'https://example.com/a.jpg?v=2' ) );
		$this->assertNotSame( ImageSource::url_key( 'https://example.com/a.jpg' ), ImageSource::url_key( 'https://example.com/A.jpg' ), 'Paths are case-sensitive.' );
		$this->assertSame( 'http://example.com:8080/a.jpg', ImageSource::url_key( 'http://example.com:8080/a.jpg' ) );
		$this->assertSame( 'http://example.com/a%20b.jpg', ImageSource::url_key( 'http://example.com/a b.jpg' ) );
	}

	public function test_refuses_urls_with_credentials_or_other_schemes(): void {
		$this->assertNull( ImageSource::url_key( 'https://user:pass@example.com/a.jpg' ) );
		$this->assertNull( ImageSource::classify( 'https://user:pass@example.com/a.jpg' ) );
		$this->assertSame( ImageSource::FILE, ImageSource::classify( 'file:///etc/passwd' )['type'], 'Not a URL, so only a Media Library name lookup.' );
		$this->assertSame( 'passwd', ImageSource::classify( 'file:///etc/passwd' )['filename'] );
	}

	public function test_file_names_lose_their_directories(): void {
		$this->assertSame( 'photo.jpg', ImageSource::classify( '../../wp-config/photo.jpg' )['filename'] );
		$this->assertSame( 'photo.jpg', ImageSource::classify( 'C:\\images\\photo.jpg' )['filename'] );
		$this->assertNull( ImageSource::classify( '../..' ) );
		$this->assertNull( ImageSource::classify( '  ' ) );
	}

	public function test_url_without_file_name(): void {
		$this->assertSame( '', ImageSource::classify( 'https://example.com/images/' )['filename'] );
	}
}

<?php
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- builds WP All Import's storage format.
// phpcs:disable WordPress.WP.AlternativeFunctions -- tests work on temporary files.

namespace Importright\Tests\Unit;

use Importright\Migration\InvalidTemplate;
use Importright\Migration\TemplateFile;

final class WpaiTemplateFileTest extends FileCase {

	private function export( array $rows ): string {
		return (string) json_encode( $rows );
	}

	public function test_reads_a_template_export_with_several_templates(): void {
		$file = $this->file(
			"\xEF\xBB\xBF" . $this->export(
				array(
					WpaiFixtures::row( 'Products', WpaiFixtures::csv_products(), 3 ),
					WpaiFixtures::row( 'Posts', WpaiFixtures::xml_posts(), 4 ),
				)
			),
			'.txt'
		);
		$rows = TemplateFile::read( $file );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Products', $rows[0]['name'] );
		$this->assertSame( 'product', $rows[0]['options']['custom_type'] );
		$this->assertSame( array( 'Color', 'Material', '' ), $rows[0]['options']['attribute_name'] );
		$this->assertSame( 'manual', $rows[1]['options']['duplicate_matching'] );
	}

	public function test_bundle_shape_grouped_by_post_type_with_array_options(): void {
		$row                 = WpaiFixtures::row( 'Bundle', WpaiFixtures::xml_posts() );
		$row['options']      = WpaiFixtures::xml_posts();
		$row['_import_type'] = 'url';
		$row['_import_url']  = 'https://example.com/feed.xml';
		$rows                = TemplateFile::parse( (string) json_encode( array( 'post' => array( $row ) ) ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'url', $rows[0]['import_type'] );
		$this->assertSame( 'https://example.com/feed.xml', $rows[0]['import_url'] );
		$this->assertSame( '{title[1]}', $rows[0]['options']['title'] );
	}

	public function test_object_injection_is_refused(): void {
		$payloads = array(
			'nested object'      => 'a:2:{s:5:"title";s:3:"abc";s:1:"x";O:8:"stdClass":1:{s:1:"a";i:1;}}',
			'deep object'        => 'a:1:{s:1:"x";a:1:{i:0;O:20:"Some_Plugin_Gadget_X":0:{}}}',
			'top-level object'   => 'O:8:"stdClass":0:{}',
			'serializable class' => 'a:1:{s:1:"x";C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}}',
		);
		foreach ( $payloads as $label => $payload ) {
			try {
				TemplateFile::options( $payload );
				$this->fail( $label . ' was accepted' );
			} catch ( InvalidTemplate $e ) {
				$this->assertNotSame( '', $e->getMessage(), $label );
			}
		}
	}

	public function test_damaged_options_are_refused_without_warnings(): void {
		foreach ( array( 'a:2:{s:5:"title";s:3:"abc"', 'b:0;', 's:3:"abc";', 'not serialized', '' ) as $payload ) {
			try {
				TemplateFile::options( $payload );
				$this->fail( $payload . ' was accepted' );
			} catch ( InvalidTemplate $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_export_with_an_injected_row_is_refused_as_a_whole(): void {
		$rows           = array( WpaiFixtures::row( 'Good', WpaiFixtures::xml_posts() ) );
		$bad            = WpaiFixtures::row( 'Bad', array() );
		$bad['options'] = 'a:1:{s:5:"title";O:8:"stdClass":0:{}}';
		$rows[]         = $bad;
		$this->expectException( InvalidTemplate::class );
		TemplateFile::parse( $this->export( $rows ) );
	}

	public function test_refuses_non_templates(): void {
		foreach ( array( 'hello', '[]', '{"a":1}', '[{"name":"x"}]' ) as $json ) {
			try {
				TemplateFile::parse( $json );
				$this->fail( $json . ' was accepted' );
			} catch ( InvalidTemplate $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$export = WpaiFixtures::row( 'Export', array( 'is_user_export' => 1 ) );
		$this->expectExceptionMessage( 'WP All Export template' );
		TemplateFile::parse( $this->export( array( $export ) ) );
	}

	public function test_reads_a_bundle_and_extracts_its_data_without_using_entry_names(): void {
		$zip  = $this->zip(
			array(
				'__MACOSX/._WP All Import Template - Shop.txt' => 'junk',
				'readme.txt'                        => 'Read me',
				'../../evil.php'                    => '<?php echo 1;',
				'nested/../../products.csv'         => "SKU;Name\nS-1;Shirt\n",
				'WP All Import Template - Shop.txt' => $this->export( array( WpaiFixtures::row( 'Shop', WpaiFixtures::csv_products() ) ) ),
			)
		);
		$rows = TemplateFile::read( $zip );
		$this->assertSame( 'Shop', $rows[0]['name'] );

		$entries = TemplateFile::bundle_entries( $zip );
		$this->assertSame( 'products.csv', $entries['data_name'] );

		$target = $this->path( '.data' );
		$this->assertSame( 'csv', TemplateFile::extract_data( $zip, $target, 1048576 ) );
		$this->assertSame( "SKU;Name\nS-1;Shirt\n", file_get_contents( $target ) );
		$this->assertFileDoesNotExist( dirname( $target, 3 ) . '/products.csv' );
		$this->assertFileDoesNotExist( dirname( $target, 2 ) . '/evil.php' );
	}

	public function test_bundle_without_a_template_or_data(): void {
		$zip = $this->zip( array( 'products.csv' => "a\n1\n" ) );
		try {
			TemplateFile::read( $zip );
			$this->fail( 'A zip without a template was accepted' );
		} catch ( InvalidTemplate $e ) {
			$this->assertStringContainsString( 'no WP All Import template', $e->getMessage() );
		}
		$only = $this->zip( array( 'templates_1.txt' => $this->export( array( WpaiFixtures::row( 'T', WpaiFixtures::xml_posts() ) ) ) ) );
		$this->expectException( InvalidTemplate::class );
		TemplateFile::extract_data( $only, $this->path(), 1048576 );
	}

	public function test_size_caps(): void {
		$file = $this->file( str_repeat( ' ', 2048 ) . '[]', '.txt' );
		try {
			TemplateFile::read( $file, 1024 );
			$this->fail( 'An oversized template was read' );
		} catch ( InvalidTemplate $e ) {
			$this->assertStringContainsString( 'limit', $e->getMessage() );
		}

		$zip = $this->zip(
			array(
				'templates_1.txt' => $this->export( array( WpaiFixtures::row( 'T', WpaiFixtures::xml_posts() ) ) ),
				'data.csv'        => str_repeat( "a,b\n", 1000 ),
			)
		);
		$this->expectExceptionMessage( 'limit' );
		TemplateFile::extract_data( $zip, $this->path(), 100 );
	}
}

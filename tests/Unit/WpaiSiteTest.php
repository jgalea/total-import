<?php
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- builds WP All Import's storage format.

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Migration\InvalidTemplate;
use Importright\Migration\WpaiSite;
use Importright\Runs\Tracker;

final class WpaiSiteTest extends TestCase {

	private function db(): FakeWpaiDb {
		$db             = new FakeWpaiDb();
		$db->imports[1] = array(
			'id'               => '1',
			'parent_import_id' => '0',
			'name'             => 'products.csv',
			'friendly_name'    => 'Shop feed',
			'type'             => 'upload',
			'feed_type'        => 'csv',
			'path'             => '/wpallimport/uploads/abc/products.csv',
			'xpath'            => '/node',
			'root_element'     => 'node',
			'options'          => serialize( WpaiFixtures::csv_products() ),
		);
		$db->imports[2] = array(
			'id'               => '2',
			'parent_import_id' => '0',
			'name'             => 'broken',
			'friendly_name'    => '',
			'type'             => 'url',
			'feed_type'        => 'xml',
			'path'             => 'https://example.com/feed.xml',
			'xpath'            => '/item',
			'root_element'     => 'item',
			'options'          => 'a:1:{s:1:"x";O:8:"stdClass":0:{}}',
		);
		return $db;
	}

	public function test_lists_imports_with_record_counts(): void {
		$db          = $this->db();
		$db->posts[] = array(
			'id'         => 1,
			'post_id'    => 10,
			'import_id'  => 1,
			'unique_key' => 'A',
		);
		$site        = new WpaiSite( $db );
		$this->assertTrue( $site->available() );

		$imports = $site->imports();
		$this->assertCount( 2, $imports );
		$this->assertSame( 'Shop feed', $imports[0]['name'] );
		$this->assertSame( 'product', $imports[0]['post_type'] );
		$this->assertSame( 1, $imports[0]['records'] );
		$this->assertSame( '?', $imports[1]['post_type'] );
		$this->assertSame( 0, $imports[1]['records'] );

		$db->has_tables = false;
		$this->assertFalse( $site->available() );
	}

	public function test_import_decodes_options_and_refuses_objects(): void {
		$site   = new WpaiSite( $this->db() );
		$import = $site->import( 1 );
		$this->assertSame( 'node', $import['root_element'] );
		$this->assertSame( '{sku[1]}', $import['options']['unique_key'] );
		$this->assertNull( $site->import( 99 ) );

		$this->expectException( InvalidTemplate::class );
		$site->import( 2 );
	}

	public function test_tracking_rows(): void {
		$seen = array();
		$long = str_repeat( 'k', 300 );
		$rows = WpaiSite::tracking_rows(
			array(
				array(
					'post_id'    => '10',
					'unique_key' => ' SKU-1 ',
				),
				array(
					'post_id'    => '11',
					'unique_key' => '',
				),
				array(
					'post_id'    => '0',
					'unique_key' => 'SKU-0',
				),
				array(
					'post_id'    => '12',
					'unique_key' => 'SKU-1',
				),
				array(
					'post_id'    => '13',
					'unique_key' => 'sku-1',
				),
				array(
					'post_id'    => '14',
					'unique_key' => $long,
				),
			),
			$seen
		);
		$this->assertSame(
			array(
				array(
					'unique_key' => 'SKU-1',
					'object_id'  => 10,
				),
				array(
					'unique_key' => 'sku-1',
					'object_id'  => 13,
				),
				array(
					'unique_key' => Tracker::storage_key( $long ),
					'object_id'  => 14,
				),
			),
			$rows['rows']
		);
		$this->assertSame( 2, $rows['empty'] );
		$this->assertSame( 1, $rows['duplicates'] );
		$this->assertStringStartsWith( 'sha1:', $rows['rows'][2]['unique_key'] );

		$again = WpaiSite::tracking_rows(
			array(
				array(
					'post_id'    => '20',
					'unique_key' => 'SKU-1',
				),
			),
			$seen
		);
		$this->assertSame( array(), $again['rows'] );
		$this->assertSame( 1, $again['duplicates'] );
	}

	public function test_copy_tracking_in_chunks_with_empty_hashes(): void {
		$db = $this->db();
		for ( $i = 1; $i <= 2500; $i++ ) {
			$db->posts[] = array(
				'id'         => $i,
				'post_id'    => 100 + $i,
				'import_id'  => 1,
				'unique_key' => 1500 === $i ? 'SKU-1' : 'SKU-' . $i,
			);
		}
		$db->posts[] = array(
			'id'         => 2501,
			'post_id'    => 9,
			'import_id'  => 2,
			'unique_key' => 'OTHER',
		);

		$totals = ( new WpaiSite( $db ) )->copy_tracking( 1, 7, 'product' );
		$this->assertSame(
			array(
				'read'       => 2500,
				'copied'     => 2499,
				'empty'      => 0,
				'duplicates' => 1,
			),
			$totals
		);
		$this->assertSame( 3, $db->selects );
		$this->assertCount( 3, $db->inserts );

		$first = $db->inserts[0];
		$this->assertStringStartsWith( 'INSERT IGNORE INTO %i', $first['sql'] );
		$this->assertStringContainsString( "'', 0, 0", $first['sql'] );
		$this->assertSame( 'wp_importright_records', $first['args'][0] );
		$this->assertSame( array( 7, 'SKU-1', 'product', 101 ), array_slice( $first['args'], 1, 4 ) );
		$this->assertSame( 1 + 6 * 1000, count( $first['args'] ) );

		$keys = array();
		foreach ( $db->inserts as $insert ) {
			foreach ( array_chunk( array_slice( $insert['args'], 1 ), 6 ) as $values ) {
				$this->assertSame( 7, $values[0] );
				$keys[] = $values[1];
			}
		}
		$this->assertCount( 2499, $keys );
		$this->assertNotContains( 'OTHER', $keys );
	}

	public function test_copy_tracking_with_nothing_to_copy(): void {
		$db     = $this->db();
		$totals = ( new WpaiSite( $db ) )->copy_tracking( 1, 7, 'post' );
		$this->assertSame( 0, $totals['read'] );
		$this->assertSame( array(), $db->inserts );
	}
}

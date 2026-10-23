<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Mapping\Template;
use Importright\Migration\Converter;
use Importright\Migration\Report;

final class WpaiConverterTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function entry( Report $report, string $wpai_key, ?string $field = null ): array {
		foreach ( $report->entries() as $entry ) {
			if ( $entry['wpai_key'] === $wpai_key && ( null === $field || $entry['field'] === $field ) ) {
				return $entry;
			}
		}
		$this->fail( 'No report entry for ' . $wpai_key );
	}

	private function product_result(): array {
		return ( new Converter(
			array(
				'product_target' => true,
				'feed'           => 'csv',
				'columns'        => WpaiFixtures::PRODUCT_COLUMNS,
			)
		) )->convert( WpaiFixtures::csv_products(), 'Products' );
	}

	public function test_csv_product_definition(): void {
		$result     = $this->product_result();
		$definition = $result['definition'];

		$this->assertSame( 'Shop feed', $definition['name'] );
		$this->assertSame(
			array(
				'type'        => 'csv',
				'delimiter'   => ';',
				'encoding'    => '',
				'has_header'  => true,
				'record_path' => '',
			),
			$definition['format']
		);
		$this->assertSame(
			array(
				'type'           => 'product',
				'default_status' => 'publish',
			),
			$definition['target']
		);

		$mapping = $definition['mapping'];
		$this->assertSame( '{Product Name}', $mapping['name'] );
		$this->assertSame( '{Description}', $mapping['description'] );
		$this->assertArrayNotHasKey( 'short_description', $mapping );
		$this->assertSame( '{SKU}', $mapping['sku'] );
		$this->assertSame( '{Regular Price}', $mapping['regular_price'] );
		$this->assertSame( '{Sale Price}', $mapping['sale_price'] );
		$this->assertSame( 'yes', $mapping['manage_stock'] );
		$this->assertSame( '{Stock}', $mapping['stock_quantity'] );
		$this->assertSame( '{Weight (kg)}', $mapping['weight'] );
		$this->assertArrayNotHasKey( 'stock_status', $mapping );
		$this->assertSame( array( '_custom_note' => '{Note}' ), $mapping['meta'] );

		$this->assertSame(
			array(
				'Color'    => array(
					'values'    => '{Color}',
					'separator' => '|',
					'visible'   => '1',
					'create'    => '1',
				),
				'Material' => array(
					'values'    => '{Material}',
					'separator' => '|',
					'visible'   => '0',
					'create'    => '1',
				),
			),
			$mapping['attributes']
		);

		$this->assertSame(
			array(
				'featured'       => array( 'source' => '{Image 1}' ),
				'gallery'        => array(
					'source'    => '{Image 2}|{Image 3}',
					'separator' => '|',
				),
				'match_filename' => '0',
			),
			$mapping['images']
		);

		$this->assertSame(
			array(
				'product_cat' => array(
					'terms'     => '{Category}',
					'separator' => '|',
					'hierarchy' => '>',
					'create'    => '1',
					'mode'      => 'replace',
				),
				'product_tag' => array(
					'terms'     => '{Tags}',
					'separator' => ',',
					'hierarchy' => '',
					'create'    => '1',
					'mode'      => 'replace',
				),
			),
			$mapping['taxonomies']
		);

		$this->assertSame(
			array(
				'unique_key'     => '{SKU}',
				'mode'           => 'create_update',
				'match_existing' => '',
			),
			$definition['matching']
		);
		$this->assertTrue( $result['tracking'] );

		$update = $definition['update'];
		$this->assertFalse( $update['description'] );
		$this->assertSame( array( '_custom_note', '_regular_price' ), $update['meta'] );
		$this->assertFalse( $update['sku'] );
		$this->assertFalse( $update['stock_quantity'] );
		$this->assertFalse( $update['weight'] );
		$this->assertArrayNotHasKey( 'regular_price', $update );
		$this->assertArrayNotHasKey( 'name', $update );
	}

	public function test_converted_unique_key_renders_what_wpai_stored(): void {
		$definition    = $this->product_result()['definition'];
		$record        = array_combine( WpaiFixtures::PRODUCT_COLUMNS, array_fill( 0, count( WpaiFixtures::PRODUCT_COLUMNS ), '' ) );
		$record['SKU'] = 'TSHIRT-RED';
		$this->assertSame( 'TSHIRT-RED', ( new Template( $definition['matching']['unique_key'] ) )->render( $record ) );
	}

	public function test_csv_product_report(): void {
		$report = $this->product_result()['report'];

		$excerpt = $this->entry( $report, 'post_excerpt' );
		$this->assertSame( Report::UNSUPPORTED, $excerpt['status'] );
		$this->assertSame( '[wpai_shorten({description[1]},20)]', $excerpt['wpai_value'] );
		$this->assertStringContainsString( 'never runs PHP', $excerpt['note'] );

		$this->assertSame( Report::MAPPED, $this->entry( $report, 'title' )['status'] );
		$this->assertSame( '{productname[1]}', $this->entry( $report, 'title' )['wpai_value'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $report, 'single_product_url' )['status'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $report, 'custom_value[_price]' )['status'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $report, 'custom_value[_sku]' )['status'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $report, 'custom_value[_regular_price]' )['status'] );
		$this->assertSame( Report::APPROXIMATED, $this->entry( $report, 'attribute_value[Color]' )['status'] );
		$this->assertSame( Report::MAPPED, $this->entry( $report, 'attribute_value[Material]' )['status'] );
		$this->assertSame( Report::MAPPED, $this->entry( $report, 'download_featured_image', 'mapping.images.gallery' )['status'] );
		$this->assertSame( Report::MAPPED, $this->entry( $report, 'download_featured_image', 'mapping.images.featured' )['status'] );
		$this->assertSame( Report::MAPPED, $this->entry( $report, 'tax_logic[product_cat]' )['status'] );

		$counts = $report->counts();
		$this->assertGreaterThan( 15, $counts[ Report::MAPPED ] );
		$this->assertGreaterThan( 0, $counts[ Report::APPROXIMATED ] );
		$this->assertGreaterThan( 2, $counts[ Report::UNSUPPORTED ] );
	}

	public function test_no_shipping_class_from_the_wizard_is_not_reported(): void {
		$settings = array(
			'product_target' => true,
			'feed'           => 'csv',
			'columns'        => WpaiFixtures::PRODUCT_COLUMNS,
		);
		foreach ( array(
			'-1' => false,
			'12' => true,
		) as $value => $reported ) {
			$options                                    = WpaiFixtures::csv_products();
			$options['multiple_product_shipping_class'] = (string) $value;
			$keys                                       = array_column( ( new Converter( $settings ) )->convert( $options, 'Products' )['report']->entries(), 'wpai_key' );
			$this->assertSame( $reported, in_array( 'multiple_product_shipping_class', $keys, true ), (string) $value );
		}
	}

	public function test_product_gallery_on_posts_is_approximated_but_products_map(): void {
		$options = WpaiFixtures::csv_products();
		$result  = ( new Converter(
			array(
				'product_target' => true,
				'feed'           => 'csv',
				'columns'        => WpaiFixtures::PRODUCT_COLUMNS,
			)
		) )->convert( $options );
		$this->assertStringNotContainsString( '_importright_gallery', $this->entry( $result['report'], 'download_featured_image', 'mapping.images.gallery' )['note'] );

		$options['custom_type'] = 'page';
		$page                   = ( new Converter(
			array(
				'feed'    => 'csv',
				'columns' => WpaiFixtures::PRODUCT_COLUMNS,
			)
		) )->convert( $options );
		$this->assertStringContainsString( '_importright_gallery', $this->entry( $page['report'], 'download_featured_image', 'mapping.images.gallery' )['note'] );
		$this->assertSame( '{Product Name}', $page['definition']['mapping']['title'] );
	}

	public function test_products_without_woocommerce_import_as_posts_and_flag_product_fields(): void {
		$result = ( new Converter(
			array(
				'feed'    => 'csv',
				'columns' => WpaiFixtures::PRODUCT_COLUMNS,
			)
		) )->convert( WpaiFixtures::csv_products() );
		$this->assertSame( 'post', $result['definition']['target']['type'] );
		$this->assertSame( 'product', $result['definition']['target']['post_type'] );
		$this->assertArrayNotHasKey( 'sku', $result['definition']['mapping'] );
		$this->assertArrayHasKey( '_sku', $result['definition']['mapping']['meta'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $result['report'], 'single_product_sku' )['status'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $result['report'], 'attribute_value' )['status'] );
	}

	public function test_csv_without_the_data_file_keeps_wpai_names_and_blocks_tracking(): void {
		$result = ( new Converter( array( 'product_target' => true ) ) )->convert( WpaiFixtures::csv_products() );
		$this->assertSame( 'auto', $result['definition']['format']['type'] );
		$this->assertSame( '{sku}', $result['definition']['matching']['unique_key'] );
		$this->assertSame( Report::APPROXIMATED, $this->entry( $result['report'], 'unique_key' )['status'] );
		$this->assertFalse( $result['tracking'] );
	}

	public function test_numbered_csv_columns(): void {
		$options = WpaiFixtures::options(
			array(
				'title'      => '{column_2[1]}',
				'unique_key' => '{column_1[1]}',
			)
		);
		$result  = ( new Converter(
			array(
				'feed'    => 'csv',
				'columns' => array( '1001', 'Shirt' ),
			)
		) )->convert( $options );
		$this->assertFalse( $result['definition']['format']['has_header'] );
		$this->assertSame( '{column_2}', $result['definition']['mapping']['title'] );
		$this->assertSame( '{column_1}', $result['definition']['matching']['unique_key'] );
		$this->assertTrue( $result['tracking'] );
	}

	public function test_xml_post_definition(): void {
		$result     = ( new Converter(
			array(
				'feed'         => 'xml',
				'xpath'        => '/item',
				'root_element' => 'item',
				'sample'       => array( '@id', 'title', 'body', 'tags.tag', 'tags.tag[1]', 'tags.tag[2]' ),
			)
		) )->convert( WpaiFixtures::xml_posts(), 'Feed' );
		$definition = $result['definition'];
		$mapping    = $definition['mapping'];

		$this->assertSame( 'item', $definition['format']['record_path'] );
		$this->assertSame( 'xml', $definition['format']['type'] );
		$this->assertSame( 'post', $definition['target']['post_type'] );
		$this->assertSame( '{title}', $mapping['title'] );
		$this->assertSame( 'Intro: {body} Pricey', $mapping['content'] );
		$this->assertSame( '{author.email}', $mapping['author'] );
		$this->assertSame( '{pubDate}', $mapping['date'] );
		$this->assertSame( '{status}', $mapping['status'] );
		$this->assertSame( 'open', $mapping['comment_status'] );
		$this->assertSame(
			array(
				'price'        => '{price}',
				'currency'     => '{price@currency}',
				'second_image' => '{images.image[2]}',
			),
			$mapping['meta']
		);
		$this->assertSame(
			array(
				'featured'       => array(
					'source' => '{images.image}',
					'alt'    => '{title}',
				),
				'match_filename' => '0',
			),
			$mapping['images']
		);
		$this->assertSame( '', $mapping['taxonomies']['category']['separator'] );
		$this->assertSame( '0', $mapping['taxonomies']['category']['create'] );
		$this->assertSame( '{tags.tag}', $mapping['taxonomies']['post_tag']['terms'] );

		$this->assertSame(
			array(
				'unique_key'     => '{@id}',
				'mode'           => 'create_update',
				'match_existing' => 'meta:_external_id',
			),
			$definition['matching']
		);
		$this->assertFalse( $result['tracking'] );
		$this->assertSame( array(), $definition['update'] );

		$report = $result['report'];
		$this->assertSame( Report::APPROXIMATED, $this->entry( $report, 'content' )['status'] );
		$this->assertStringContainsString( 'str_replace', $this->entry( $report, 'content' )['note'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $report, 'custom_value[size]' )['status'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $report, 'custom_value[blob]' )['status'] );
		$this->assertSame( Report::APPROXIMATED, $this->entry( $report, 'tax_logic[post_tag]' )['status'] );
		$this->assertSame( Report::APPROXIMATED, $this->entry( $report, 'featured_image' )['status'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $report, 'is_delete_missing' )['status'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $report, 'scheduling_enable' )['status'] );
	}

	public function test_record_filter_in_xpath_is_unsupported(): void {
		$result = ( new Converter(
			array(
				'feed'         => 'xml',
				'xpath'        => '/product[price > 5]',
				'root_element' => 'product',
			)
		) )->convert(
			WpaiFixtures::options(
				array(
					'title'      => '{name[1]}',
					'unique_key' => '{id[1]}',
				)
			)
		);
		$this->assertSame( 'product', $result['definition']['format']['record_path'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $result['report'], 'xpath' )['status'] );
	}

	public function test_matching_rules(): void {
		$base = array(
			'title'      => '{name[1]}',
			'unique_key' => '{id[1]}',
		);

		$pid = ( new Converter( array( 'feed' => 'xml' ) ) )->convert(
			WpaiFixtures::options(
				$base + array(
					'duplicate_matching'  => 'manual',
					'duplicate_indicator' => 'pid',
					'pid_xpath'           => '{wp_id[1]}',
				)
			)
		);
		$this->assertSame( 'id', $pid['definition']['matching']['match_existing'] );
		$this->assertSame( '{wp_id}', $pid['definition']['matching']['unique_key'] );
		$this->assertFalse( $pid['tracking'] );

		$title = ( new Converter( array( 'feed' => 'xml' ) ) )->convert(
			WpaiFixtures::options(
				$base + array(
					'duplicate_matching'  => 'manual',
					'duplicate_indicator' => 'title',
				)
			)
		);
		$this->assertSame( 'title', $title['definition']['matching']['match_existing'] );
		$this->assertSame( '{id}', $title['definition']['matching']['unique_key'] );
		$this->assertTrue( $title['tracking'] );

		$keep = ( new Converter( array( 'feed' => 'xml' ) ) )->convert( WpaiFixtures::options( $base + array( 'is_keep_former_posts' => 'yes' ) ) );
		$this->assertSame( 'create', $keep['definition']['matching']['mode'] );

		$only_update = ( new Converter( array( 'feed' => 'xml' ) ) )->convert( WpaiFixtures::options( $base + array( 'create_new_records' => 0 ) ) );
		$this->assertSame( 'update', $only_update['definition']['matching']['mode'] );

		$no_key = ( new Converter( array( 'feed' => 'xml' ) ) )->convert( WpaiFixtures::options( array( 'title' => '{name[1]}' ) ) );
		$this->assertSame( '{name}', $no_key['definition']['matching']['unique_key'] );
		$this->assertFalse( $no_key['tracking'] );

		$php_key = ( new Converter( array( 'feed' => 'xml' ) ) )->convert(
			WpaiFixtures::options(
				array(
					'title'      => '{name[1]}',
					'unique_key' => '{id[1]}-[md5({name[1]})]',
				)
			)
		);
		$this->assertSame( '{id}-', $php_key['definition']['matching']['unique_key'] );
		$this->assertFalse( $php_key['tracking'] );
	}

	public function test_update_rules_all_except_and_taxonomies(): void {
		$options = WpaiFixtures::options(
			array(
				'title'                      => '{name[1]}',
				'unique_key'                 => '{id[1]}',
				'custom_name'                => array( 'a', 'b', 'c' ),
				'custom_value'               => array( '{a[1]}', '{b[1]}', '{c[1]}' ),
				'tax_logic'                  => array(
					'category' => 'single',
					'post_tag' => 'single',
				),
				'tax_assing'                 => array(
					'category' => 1,
					'post_tag' => 1,
				),
				'tax_single_xpath'           => array(
					'category' => '{cat[1]}',
					'post_tag' => '{tag[1]}',
				),
				'update_all_data'            => 'no',
				'is_update_title'            => 0,
				'update_custom_fields_logic' => 'all_except',
				'custom_fields_except_list'  => 'b',
				'update_categories_logic'    => 'only',
				'taxonomies_only_list'       => 'post_tag',
				'is_update_images'           => 0,
			)
		);
		$update  = ( new Converter( array( 'feed' => 'xml' ) ) )->convert( $options )['definition']['update'];
		$this->assertSame(
			array(
				'title'      => false,
				'meta'       => array( 'a', 'c' ),
				'taxonomies' => array( 'post_tag' ),
			),
			$update
		);

		$options['update_categories_logic'] = 'add_new';
		$append                             = ( new Converter( array( 'feed' => 'xml' ) ) )->convert( $options )['definition'];
		$this->assertSame( 'append', $append['mapping']['taxonomies']['category']['mode'] );
	}

	public function test_hierarchical_paths_without_group_separator(): void {
		$options = WpaiFixtures::options(
			array(
				'title'                         => '{name[1]}',
				'unique_key'                    => '{id[1]}',
				'tax_logic'                     => array( 'category' => 'hierarchical' ),
				'tax_assing'                    => array( 'category' => 1 ),
				'tax_hierarchical_logic_entire' => array( 'category' => 1 ),
				'tax_hierarchical_xpath'        => array( 'category' => array( '{cat1[1]}', '{cat2[1]}', '' ) ),
				'tax_hierarchical_delim'        => array( 'category' => '>' ),
			)
		);
		$result  = ( new Converter( array( 'feed' => 'xml' ) ) )->convert( $options );
		$this->assertSame(
			array(
				'terms'     => "{cat1}\n{cat2}",
				'separator' => "\n",
				'hierarchy' => '>',
				'create'    => '1',
				'mode'      => 'replace',
			),
			$result['definition']['mapping']['taxonomies']['category']
		);
		$this->assertSame( Report::APPROXIMATED, $this->entry( $result['report'], 'tax_logic[category]' )['status'] );

		$options['tax_hierarchical_logic_manual'] = array( 'category' => 1 );
		$options['tax_hierarchical_logic_entire'] = array();
		$manual                                   = ( new Converter( array( 'feed' => 'xml' ) ) )->convert( $options );
		$this->assertArrayNotHasKey( 'taxonomies', $manual['definition']['mapping'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $manual['report'], 'post_taxonomies[category]' )['status'] );
	}

	public function test_term_import(): void {
		$options    = WpaiFixtures::options(
			array(
				'custom_type'         => 'taxonomies',
				'taxonomy_type'       => 'product_cat',
				'title'               => '{name[1]}',
				'content'             => '{desc[1]}',
				'taxonomy_parent'     => '{parent[1]}',
				'taxonomy_slug'       => 'xpath',
				'taxonomy_slug_xpath' => '{slug[1]}',
				'unique_key'          => '{id[1]}',
			)
		);
		$definition = ( new Converter( array( 'feed' => 'xml' ) ) )->convert( $options )['definition'];
		$this->assertSame(
			array(
				'type'     => 'term',
				'taxonomy' => 'product_cat',
			),
			$definition['target']
		);
		$this->assertSame(
			array(
				'name'        => '{name}',
				'description' => '{desc}',
				'slug'        => '{slug}',
				'parent'      => '{parent}',
			),
			$definition['mapping']
		);
	}

	public function test_variable_products_are_unsupported_without_a_listener(): void {
		$result = ( new Converter( array( 'product_target' => true ) ) )->convert(
			WpaiFixtures::options(
				array(
					'custom_type'           => 'product',
					'multiple_product_type' => 'variable',
				)
			)
		);

		$this->assertSame( 'Total Import imports simple products only. Variable, grouped and external products fail on update.', $this->entry( $result['report'], 'product_type' )['note'] );
		$this->assertSame(
			array(
				'type'           => 'product',
				'default_status' => 'publish',
			),
			$result['definition']['target']
		);
	}

	public function test_migration_convert_listener_can_take_over_variable_products(): void {
		$seen = array();
		$GLOBALS['importright_test_filters']['importright_migration_convert'] = static function ( array $converted, array $options, Report $report ) use ( &$seen ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature.
			$seen                          = array( $converted['type'], $options['multiple_product_type'] );
			$converted['handled']          = true;
			$converted['target']['type']   = 'variable_product';
			$converted['mapping']['extra'] = array( 'x' => '1' );
			return $converted;
		};
		try {
			$result = ( new Converter( array( 'product_target' => true ) ) )->convert(
				WpaiFixtures::options(
					array(
						'custom_type'           => 'product',
						'multiple_product_type' => 'variable',
					)
				)
			);
		} finally {
			unset( $GLOBALS['importright_test_filters']['importright_migration_convert'] );
		}

		$this->assertSame( array( 'variable', 'variable' ), $seen );
		foreach ( $result['report']->entries() as $entry ) {
			$this->assertNotSame( 'product_type', $entry['wpai_key'] );
		}
		$this->assertSame( 'variable_product', $result['definition']['target']['type'] );
		$this->assertSame( array( 'x' => '1' ), $result['definition']['mapping']['extra'] );
	}

	private function variable_options(): array {
		return WpaiFixtures::options(
			array(
				'custom_type'           => 'product',
				'multiple_product_type' => 'variable',
			)
		);
	}

	public function test_converted_hook_without_a_listener_changes_nothing(): void {
		$env      = array(
			'product_target' => true,
			'columns'        => array( 'a' ),
		);
		$expected = ( new Converter( $env ) )->convert( $this->variable_options() );
		$GLOBALS['importright_test_filters']['importright_migration_converted'] = static function () {
			return 'junk';
		};
		try {
			$junk = ( new Converter( $env ) )->convert( $this->variable_options() );
		} finally {
			unset( $GLOBALS['importright_test_filters']['importright_migration_converted'] );
		}
		$this->assertSame( wp_json_encode( $expected['report']->to_array() ), wp_json_encode( $junk['report']->to_array() ) );
		$this->assertSame( $expected['definition'], $junk['definition'] );
	}

	public function test_converted_hook_gets_the_env_and_can_replace_and_remove_lines(): void {
		$seen = array();
		$GLOBALS['importright_test_filters']['importright_migration_converted'] = static function ( array $converted, array $env, Report $report ) use ( &$seen ) {
			$seen = $env['columns'];
			$report->replace( Report::MAPPED, 'target.type', 'product_type', 'variable', 'variable_product', 'Handled.' );
			$report->remove( 'sku' );
			$converted['definition']['name'] = 'Changed';
			return $converted;
		};
		try {
			$result = ( new Converter(
				array(
					'product_target' => true,
					'columns'        => array( 'sku', 'name' ),
				)
			) )->convert( $this->variable_options() );
		} finally {
			unset( $GLOBALS['importright_test_filters']['importright_migration_converted'] );
		}
		$this->assertSame( array( 'sku', 'name' ), $seen );
		$this->assertSame( 'Changed', $result['definition']['name'] );
		$this->assertSame( Report::MAPPED, $this->entry( $result['report'], 'product_type' )['status'] );
		$this->assertCount( 1, array_filter( $result['report']->entries(), static fn( array $e ): bool => 'product_type' === $e['wpai_key'] ) );
	}

	public function test_report_replace_keeps_position_and_remove_counts(): void {
		$report = new Report();
		$report->add( Report::MAPPED, 'a', 'one', 1 );
		$report->add( Report::UNSUPPORTED, '', 'two', 2 );
		$report->add( Report::UNSUPPORTED, '', 'two', 2 );
		$report->add( Report::MAPPED, 'c', 'three', 3 );
		$report->replace( Report::APPROXIMATED, 'x', 'two', 2, 'v', 'n' );
		$this->assertSame( array( 'one', 'two', 'three' ), array_column( $report->entries(), 'wpai_key' ) );
		$this->assertSame( Report::APPROXIMATED, $report->entries()[1]['status'] );
		$report->replace( Report::MAPPED, 'z', 'four', 4 );
		$this->assertSame( 'four', $report->entries()[3]['wpai_key'] );
		$this->assertSame( 1, $report->remove( 'one' ) );
		$this->assertSame( 0, $report->remove( 'missing' ) );
		$this->assertSame( array( 'two', 'three', 'four' ), array_column( $report->entries(), 'wpai_key' ) );
	}

	public function test_unsupported_import_types(): void {
		foreach ( array( 'import_users', 'shop_order', 'comments' ) as $type ) {
			$result = ( new Converter() )->convert( WpaiFixtures::options( array( 'custom_type' => $type ) ) );
			$this->assertNull( $result['definition'], $type );
			$this->assertSame( 1, $result['report']->counts()[ Report::UNSUPPORTED ] );
		}
	}

	public function test_status_page_template_and_encoding(): void {
		$options    = WpaiFixtures::options(
			array(
				'custom_type'               => 'page',
				'title'                     => '{name[1]}',
				'unique_key'                => '{id[1]}',
				'status'                    => 'draft',
				'is_multiple_page_template' => 'no',
				'single_page_template'      => '{template[1]}',
				'is_multiple_page_parent'   => 'no',
				'single_page_parent'        => '{parent[1]}',
				'order'                     => '{order[1]}',
				'encoding'                  => 'Windows-1252',
				'ping_status'               => 'closed',
				'post_format'               => 'aside',
			)
		);
		$result     = ( new Converter( array( 'feed' => 'xml' ) ) )->convert( $options );
		$definition = $result['definition'];
		$this->assertSame( 'draft', $definition['target']['default_status'] );
		$this->assertSame( '{template}', $definition['mapping']['meta']['_wp_page_template'] );
		$this->assertSame( '{parent}', $definition['mapping']['parent'] );
		$this->assertSame( '{order}', $definition['mapping']['menu_order'] );
		$this->assertSame( 'closed', $definition['mapping']['ping_status'] );
		$this->assertSame( 'WINDOWS-1252', $definition['format']['encoding'] );
		$this->assertSame( Report::UNSUPPORTED, $this->entry( $result['report'], 'post_format' )['status'] );
	}
}

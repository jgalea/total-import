<?php

namespace Importright\Tests\Unit;

/**
 * WP All Import option sets built on the plugin's own defaults, the way its
 * template export stores them.
 */
final class WpaiFixtures {

	public const PRODUCT_COLUMNS = array( 'Product Name', 'Description', 'SKU', 'Regular Price', 'Sale Price', 'Stock', 'Weight (kg)', 'Color', 'Material', 'Image 1', 'Image 2', 'Image 3', 'Category', 'Tags', 'Note', 'column_16' );

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	public static function options( array $overrides = array(), bool $woocommerce = false ): array {
		$options = require dirname( __DIR__ ) . '/fixtures/wpai/defaults.php';
		if ( $woocommerce ) {
			$options = array_merge( $options, require dirname( __DIR__ ) . '/fixtures/wpai/woocommerce.php' );
		}
		return array_merge( $options, $overrides );
	}

	/**
	 * A CSV product import with the WooCommerce add-on: prices, stock, attributes, images, a category tree and inline PHP.
	 *
	 * @return array<string, mixed>
	 */
	public static function csv_products(): array {
		return self::options(
			array(
				'custom_type'                        => 'product',
				'friendly_name'                      => 'Shop feed',
				'delimiter'                          => ';',
				'title'                              => '{productname[1]}',
				'content'                            => '{description[1]}',
				'post_excerpt'                       => '[wpai_shorten({description[1]},20)]',
				'unique_key'                         => '{sku[1]}',
				'single_product_sku'                 => '{sku[1]}',
				'single_product_regular_price'       => '{regularprice[1]}',
				'single_product_sale_price'          => '{saleprice[1]}',
				'is_product_manage_stock'            => 'yes',
				'single_product_stock_qty'           => '{stock[1]}',
				'single_product_weight'              => '{weightkg[1]}',
				'single_product_url'                 => '{url[1]}',
				'attribute_name'                     => array( 'Color', 'Material', '' ),
				'attribute_value'                    => array( '{color[1]}', '{material[1]}', '' ),
				'is_visible'                         => array( '1', '0', '1' ),
				'is_taxonomy'                        => array( '1', '0', '1' ),
				'create_taxonomy_in_not_exists'      => array( '1', '1', '1' ),
				'in_variations'                      => array( '0', '0', '0' ),
				'download_images'                    => 'yes',
				'download_featured_image'            => "{image1[1]}\n{image2[1]}|{image3[1]}",
				'download_featured_delim'            => '|',
				'tax_logic'                          => array(
					'product_cat'  => 'hierarchical',
					'product_tag'  => 'multiple',
					'product_type' => 'single',
				),
				'tax_assing'                         => array(
					'product_cat'  => 1,
					'product_tag'  => 1,
					'product_type' => 1,
				),
				'tax_hierarchical_logic_entire'      => array( 'product_cat' => 1 ),
				'tax_hierarchical_xpath'             => array( 'product_cat' => array( '{category[1]}' ) ),
				'tax_hierarchical_delim'             => array( 'product_cat' => '>' ),
				'is_tax_hierarchical_group_delim'    => array( 'product_cat' => 1 ),
				'tax_hierarchical_group_delim'       => array( 'product_cat' => '|' ),
				'tax_hierarchical_last_level_assign' => array( 'product_cat' => 1 ),
				'tax_multiple_xpath'                 => array( 'product_tag' => '{tags[1]}' ),
				'tax_multiple_delim'                 => array( 'product_tag' => ',' ),
				'tax_single_xpath'                   => array( 'product_type' => 'simple' ),
				'custom_name'                        => array( '_custom_note', '_sku', '_regular_price', '_price' ),
				'custom_value'                       => array( '{note[1]}', '{sku[1]}', '{regularprice[1]}', '{regularprice[1]}' ),
				'custom_format'                      => array( '0', '0', '0', '0' ),
				'update_all_data'                    => 'no',
				'is_update_content'                  => 0,
				'update_custom_fields_logic'         => 'only',
				'custom_fields_only_list'            => '_custom_note,_regular_price',
			),
			true
		);
	}

	/**
	 * An XML post import: nested paths, attributes, repeated elements, XPath predicates, PHP and IF blocks,
	 * Media Library images, custom-field matching, delete-missing and a schedule.
	 *
	 * @return array<string, mixed>
	 */
	public static function xml_posts(): array {
		return self::options(
			array(
				'custom_type'            => 'post',
				'title'                  => '{title[1]}',
				'content'                => 'Intro: {./body[1]} [str_replace("a","b",{title[1]})][IF({price[1]} > 5)]Pricey[ENDIF]',
				'author'                 => '{author[1]/email[1]}',
				'date'                   => '{pubDate[1]}',
				'status'                 => 'xpath',
				'status_xpath'           => '{status[1]}',
				'unique_key'             => '{@id}',
				'custom_name'            => array( 'price', 'currency', 'second_image', 'size', 'blob', '' ),
				'custom_value'           => array( '{price[1]}', '{price[1]/@currency}', '{images[1]/image[2]}', '{specs/spec[@name="size"]}', '{blob[1]}', 'x' ),
				'custom_format'          => array( '0', '0', '0', '0', '1', '0' ),
				'download_images'        => 'no',
				'featured_image'         => '{images[1]/image[1]}',
				'set_image_meta_alt'     => 1,
				'image_meta_alt'         => '{title[1]}',
				'tax_logic'              => array(
					'category' => 'single',
					'post_tag' => 'multiple',
				),
				'tax_assing'             => array(
					'category' => 1,
					'post_tag' => 1,
				),
				'tax_single_xpath'       => array( 'category' => '{category[1]}' ),
				'tax_multiple_xpath'     => array( 'post_tag' => '{tags[1]/tag}' ),
				'tax_multiple_delim'     => array( 'post_tag' => ',' ),
				'do_not_create_terms'    => 1,
				'duplicate_matching'     => 'manual',
				'duplicate_indicator'    => 'custom field',
				'custom_duplicate_name'  => '_external_id',
				'custom_duplicate_value' => '{@id}',
				'is_delete_missing'      => 1,
				'scheduling_enable'      => 1,
				'scheduling_run_on'      => 'weekly',
			)
		);
	}

	/**
	 * A pmxi_templates row as the export writes it: options serialized.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public static function row( string $name, array $options, int $id = 1 ): array {
		return array(
			'id'                 => (string) $id,
			'options'            => serialize( $options ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- matches WP All Import's storage.
			'scheduled'          => '',
			'name'               => $name,
			'title'              => (string) ( $options['title'] ?? '' ),
			'content'            => (string) ( $options['content'] ?? '' ),
			'is_keep_linebreaks' => '1',
			'is_leave_html'      => '0',
			'fix_characters'     => '0',
			'meta'               => '',
		);
	}
}

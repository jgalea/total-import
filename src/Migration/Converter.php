<?php

namespace Importright\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a WP All Import options array (a template's or an import's) into a
 * Total Import definition, minus the source, plus a report of what mapped.
 *
 * Plain PHP, no WordPress calls: whether WooCommerce is active and what the
 * data file's columns are come in through $env.
 */
final class Converter {

	private const UNSUPPORTED_TYPES = array( 'import_users', 'shop_customer', 'comments', 'woo_reviews', 'shop_order', 'gf_entries' );

	private const PRODUCT_TAXONOMIES_SKIPPED = array( 'product_type', 'product_visibility', 'product_shipping_class' );

	/**
	 * Product meta keys WP All Import users often map as custom fields, and the product field that sets them.
	 */
	private const PRODUCT_META_FIELDS = array(
		'_sku'           => 'sku',
		'_regular_price' => 'regular_price',
		'_sale_price'    => 'sale_price',
		'_stock'         => 'stock_quantity',
		'_stock_status'  => 'stock_status',
		'_manage_stock'  => 'manage_stock',
		'_backorders'    => 'backorders',
		'_weight'        => 'weight',
		'_length'        => 'length',
		'_width'         => 'width',
		'_height'        => 'height',
	);

	/**
	 * WooCommerce add-on settings Total Import has no equivalent for, with the add-on's defaults (add-on 1.5.5).
	 */
	private const WOO_UNSUPPORTED = array(
		'is_product_virtual'                  => 'no',
		'is_product_downloadable'             => 'no',
		'is_product_enabled'                  => 'yes',
		'is_product_featured'                 => 'no',
		'is_product_visibility'               => 'visible',
		'single_product_global_unique_id'     => '',
		'single_product_url'                  => '',
		'single_product_button_text'          => '',
		'single_product_files'                => '',
		'multiple_product_tax_status'         => 'none',
		'single_product_tax_status'           => '',
		'multiple_product_tax_class'          => '',
		'single_product_tax_class'            => '',
		'multiple_product_shipping_class'     => '',
		'single_product_shipping_class'       => '',
		'product_sold_individually'           => 'no',
		'single_product_up_sells'             => '',
		'single_product_cross_sells'          => '',
		'single_product_purchase_note'        => '',
		'single_product_low_stock_amount'     => '',
		'single_product_whosale_price'        => '',
		'is_regular_price_shedule'            => 0,
		'single_product_regular_price_adjust' => '',
		'single_product_sale_price_adjust'    => '',
		'multiple_grouping_product'           => '',
		'single_grouping_product'             => '',
		'is_product_enable_reviews'           => 'no',
		'variations_xpath'                    => '',
	);

	/**
	 * @var array<string, mixed>
	 */
	private array $env;

	/**
	 * @var array<string, mixed>
	 */
	private array $options = array();

	private Report $report;

	private Expression $expression;

	private string $target = 'post';

	/**
	 * @var array<string, mixed>
	 */
	private array $target_definition = array();

	/**
	 * @var array<string, mixed>
	 */
	private array $mapping = array();

	/**
	 * @param array<string, mixed> $env product_target (bool, WooCommerce is active), feed (csv, xml, xlsx, json or ''),
	 *                                  xpath and root_element (from pmxi_imports), columns (the data file's column
	 *                                  names, or null), sample (record keys for XML, or null).
	 */
	public function __construct( array $env = array() ) {
		$this->env = $env;
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array{definition: array<string, mixed>|null, report: Report, tracking: bool}
	 *         tracking is true when the unique key renders exactly what WP All Import stored in pmxi_posts.
	 */
	public function convert( array $options, string $name = '' ): array {
		$this->options = $options;
		$this->report  = new Report();
		$this->mapping = array();

		$type = (string) $this->opt( 'custom_type' );
		if ( '' === $type ) {
			$type = (string) $this->opt( 'type', 'post' );
		}
		if ( in_array( $type, self::UNSUPPORTED_TYPES, true ) ) {
			/* translators: %s: WP All Import import type */
			$this->report->add( Report::UNSUPPORTED, 'target', 'custom_type', $type, null, sprintf( __( 'Importing %s isn\'t supported yet.', 'total-import' ), $type ) );
			return array(
				'definition' => null,
				'report'     => $this->report,
				'tracking'   => false,
			);
		}

		$format           = $this->format();
		$this->expression = new Expression( $format['csv'], $format['columns'], (string) ( $this->env['root_element'] ?? '' ), $this->env['sample'] ?? null );
		unset( $format['csv'], $format['columns'] );

		$this->target_definition = $this->target( $type );
		$this->core_fields();
		if ( 'product' === $this->target ) {
			$this->woocommerce();
		} elseif ( 'product' === $type ) {
			$this->woocommerce_unavailable();
		}
		$this->custom_fields();
		if ( 'term' !== $this->target ) {
			$this->images();
			$this->taxonomies();
		}
		list( $matching, $tracking ) = $this->matching();
		$update                      = $this->update_rules();
		$this->unsupported_settings();

		$friendly = trim( (string) $this->opt( 'friendly_name' ) );
		$result   = array(
			'definition' => array(
				'name'     => '' !== $friendly ? $friendly : $name,
				'format'   => $format,
				'target'   => $this->target_definition,
				'mapping'  => $this->mapping,
				'matching' => $matching,
				'update'   => $update,
				'options'  => array( 'skip_unchanged' => true ),
			),
			'report'     => $this->report,
			'tracking'   => $tracking,
		);

		$filtered = apply_filters( 'importright_migration_converted', $result, $this->env, $this->report );
		if ( is_array( $filtered )
			&& array_key_exists( 'definition', $filtered )
			&& ( null === $filtered['definition'] || is_array( $filtered['definition'] ) )
			&& isset( $filtered['report'] ) && $filtered['report'] instanceof Report
			&& isset( $filtered['tracking'] ) && is_bool( $filtered['tracking'] ) ) {
			return $filtered;
		}
		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function format(): array {
		$feed      = strtolower( (string) ( $this->env['feed'] ?? '' ) );
		$delimiter = (string) $this->opt( 'delimiter' );
		$root      = (string) ( $this->env['root_element'] ?? '' );
		$xpath     = trim( (string) ( $this->env['xpath'] ?? '' ) );
		$columns   = isset( $this->env['columns'] ) && is_array( $this->env['columns'] ) ? array_values( array_map( 'strval', $this->env['columns'] ) ) : null;

		$csv = in_array( $feed, array( 'csv', 'tsv', 'txt', 'xlsx' ), true ) || ( '' === $feed && ( '' !== $delimiter || ( 'node' === $root && '/node' === $xpath ) ) );

		$format = array(
			'type'        => in_array( $feed, array( 'csv', 'tsv', 'xlsx', 'xml', 'json' ), true ) ? $feed : 'auto',
			'delimiter'   => '',
			'encoding'    => '',
			'has_header'  => true,
			'record_path' => '',
			'csv'         => $csv,
			'columns'     => null,
		);

		if ( $csv ) {
			if ( '' !== $delimiter && 'xlsx' !== $feed ) {
				$format['delimiter'] = '\\t' === $delimiter ? "\t" : $delimiter;
				$this->report->add( Report::MAPPED, 'format.delimiter', 'delimiter', $delimiter, $format['delimiter'] );
			}
			if ( null !== $columns ) {
				if ( Expression::wpai_numbers_columns( $columns ) ) {
					$format['has_header'] = false;
					$numbered             = array();
					foreach ( array_keys( $columns ) as $i ) {
						$numbered[ 'column_' . ( $i + 1 ) ] = 'column_' . ( $i + 1 );
					}
					$format['columns'] = $numbered;
					$this->report->add( Report::MAPPED, 'format.has_header', '', '', false, __( 'The first row has a number or URL in it, so WP All Import read it as data and named the columns column_1, column_2…; Total Import does the same.', 'total-import' ) );
				} else {
					$format['columns'] = Expression::wpai_columns( $columns );
				}
			}
		} elseif ( '' !== $root ) {
			$format['record_path'] = $root;
			if ( '' === $xpath || '/' . $root === $xpath ) {
				$this->report->add( Report::MAPPED, 'format.record_path', 'root_element', $root, $root );
			} elseif ( preg_match( '#^(/[A-Za-z_][A-Za-z0-9_.:\-]*)+$#', $xpath ) ) {
				$format['record_path'] = ltrim( $xpath, '/' );
				$this->report->add( Report::APPROXIMATED, 'format.record_path', 'xpath', $xpath, $format['record_path'], __( 'Check the record path: WP All Import read it relative to its own record list.', 'total-import' ) );
			} else {
				$this->report->add( Report::UNSUPPORTED, 'format.record_path', 'xpath', $xpath, $root, __( 'WP All Import filtered records with this XPath. Total Import imports every record; filter the file first.', 'total-import' ) );
			}
		}

		$encoding = strtoupper( trim( (string) $this->opt( 'encoding', 'UTF-8' ) ) );
		if ( '' !== $encoding && 'UTF-8' !== $encoding && 'UTF8' !== $encoding ) {
			$format['encoding'] = $encoding;
			$this->report->add( Report::MAPPED, 'format.encoding', 'encoding', $encoding, $encoding );
		}
		return $format;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function target( string $type ): array {
		if ( 'taxonomies' === $type ) {
			$this->target = 'term';
			$taxonomy     = (string) $this->opt( 'taxonomy_type' );
			$this->report->add( Report::MAPPED, 'target.taxonomy', 'taxonomy_type', $taxonomy, $taxonomy );
			return array(
				'type'     => 'term',
				'taxonomy' => $taxonomy,
			);
		}

		$status = (string) $this->opt( 'status', 'publish' );
		$target = array( 'default_status' => 'xpath' === $status ? 'publish' : $status );
		if ( 'xpath' === $status ) {
			$this->template( 'status', 'status_xpath' );
		} else {
			$this->report->add( Report::MAPPED, 'target.default_status', 'status', $status, $status );
		}

		if ( 'product' === $type && ! empty( $this->env['product_target'] ) ) {
			$this->target = 'product';
			$this->report->add( Report::MAPPED, 'target.type', 'custom_type', $type, 'product' );
			return array( 'type' => 'product' ) + $target;
		}

		$this->target = 'post';
		$this->report->add( Report::MAPPED, 'target.post_type', 'custom_type', $type, $type );
		return array(
			'type'      => 'post',
			'post_type' => $type,
		) + $target;
	}

	private function core_fields(): void {
		$fields = array(
			'post'    => array(
				'title'   => 'title',
				'content' => 'content',
				'excerpt' => 'post_excerpt',
				'slug'    => 'post_slug',
				'author'  => 'author',
			),
			'product' => array(
				'name'              => 'title',
				'description'       => 'content',
				'short_description' => 'post_excerpt',
				'slug'              => 'post_slug',
			),
			'term'    => array(
				'name'        => 'title',
				'description' => 'content',
			),
		);
		foreach ( $fields[ $this->target ] as $field => $key ) {
			$this->template( $field, $key );
		}

		foreach ( array( 'is_leave_html', 'fix_characters' ) as $key ) {
			if ( ! empty( $this->opt( $key ) ) ) {
				$this->report->add( Report::UNSUPPORTED, '', $key, $this->opt( $key ), null, __( 'Total Import stores text as the file has it; this WP All Import clean-up step has no equivalent.', 'total-import' ) );
			}
		}

		if ( 'term' === $this->target ) {
			if ( 'xpath' === $this->opt( 'taxonomy_slug' ) ) {
				$this->template( 'slug', 'taxonomy_slug_xpath' );
			}
			$this->template( 'parent', 'taxonomy_parent' );
			return;
		}

		if ( 'product' === $this->target ) {
			foreach ( array( 'author', 'order', 'single_page_parent' ) as $key ) {
				if ( '' !== trim( (string) $this->opt( $key ) ) && '0' !== (string) $this->opt( $key ) ) {
					$this->report->add( Report::UNSUPPORTED, '', $key, $this->opt( $key ), null, __( 'The product target doesn\'t set this field.', 'total-import' ) );
				}
			}
			$this->date( true );
			return;
		}

		if ( '' !== trim( (string) $this->opt( 'order' ) ) && '0' !== trim( (string) $this->opt( 'order' ) ) ) {
			$this->template( 'menu_order', 'order' );
		}
		$this->date( false );

		if ( 'no' === $this->opt( 'is_multiple_page_parent', 'yes' ) ) {
			$this->template( 'parent', 'single_page_parent' );
		} elseif ( (int) $this->opt( 'parent' ) > 0 ) {
			$this->mapping['parent'] = (string) (int) $this->opt( 'parent' );
			$this->report->add( Report::MAPPED, 'mapping.parent', 'parent', $this->opt( 'parent' ), $this->mapping['parent'] );
		}

		foreach ( array( 'comment_status', 'ping_status' ) as $key ) {
			$value = (string) $this->opt( $key, 'open' );
			if ( 'xpath' === $value ) {
				$this->template( $key, $key . '_xpath' );
			} elseif ( in_array( $value, array( 'open', 'closed' ), true ) ) {
				$this->mapping[ $key ] = $value;
				$this->report->add( Report::MAPPED, 'mapping.' . $key, $key, $value, $value );
			}
		}

		if ( 'no' === $this->opt( 'is_multiple_page_template', 'yes' ) ) {
			$this->template( 'meta._wp_page_template', 'single_page_template' );
		} elseif ( ! in_array( (string) $this->opt( 'page_template', 'default' ), array( '', 'default' ), true ) ) {
			$this->mapping['meta']['_wp_page_template'] = (string) $this->opt( 'page_template' );
			$this->report->add( Report::MAPPED, 'mapping.meta._wp_page_template', 'page_template', $this->opt( 'page_template' ), $this->opt( 'page_template' ) );
		}

		$format = (string) $this->opt( 'post_format', 'standard' );
		if ( 'standard' !== $format && '' !== $format ) {
			$this->report->add( Report::UNSUPPORTED, '', 'post_format', 'xpath' === $format ? $this->opt( 'post_format_xpath' ) : $format, null, __( 'Post formats aren\'t imported yet.', 'total-import' ) );
		}
	}

	private function date( bool $product ): void {
		if ( 'specific' !== $this->opt( 'date_type', 'specific' ) ) {
			$this->report->add( Report::UNSUPPORTED, '', 'date_type', $this->opt( 'date_type' ), null, __( 'Random dates between two dates aren\'t supported; posts get the import time.', 'total-import' ) );
			return;
		}
		$date = trim( (string) $this->opt( 'date', 'now' ) );
		if ( '' === $date || 'now' === strtolower( $date ) ) {
			return;
		}
		if ( $product ) {
			$this->report->add( Report::UNSUPPORTED, '', 'date', $date, null, __( 'The product target doesn\'t set this field.', 'total-import' ) );
			return;
		}
		$this->template( 'date', 'date' );
	}

	private function custom_fields(): void {
		$names   = (array) $this->opt( 'custom_name', array() );
		$values  = (array) $this->opt( 'custom_value', array() );
		$formats = (array) $this->opt( 'custom_format', array() );

		foreach ( $names as $i => $name ) {
			$name  = trim( (string) ( is_scalar( $name ) ? $name : '' ) );
			$value = $values[ $i ] ?? '';
			if ( '' === $name ) {
				continue;
			}
			$key = 'custom_value[' . $name . ']';
			if ( ! empty( $formats[ $i ] ) || ! is_scalar( $value ) ) {
				$this->report->add( Report::UNSUPPORTED, '', $key, $value, null, __( 'Serialized custom fields aren\'t supported yet.', 'total-import' ) );
				continue;
			}
			if ( false !== strpbrk( $name, '{}[]' ) ) {
				$this->report->add( Report::UNSUPPORTED, '', 'custom_name', $name, null, __( 'Custom field names must be fixed text; this one is built from the file.', 'total-import' ) );
				continue;
			}

			if ( 'product' === $this->target && '_price' === $name ) {
				$this->report->add( Report::UNSUPPORTED, '', $key, $value, null, __( 'WooCommerce works out _price from the regular and sale prices, so it isn\'t written directly.', 'total-import' ) );
				continue;
			}
			if ( 'product' === $this->target && isset( self::PRODUCT_META_FIELDS[ $name ] ) ) {
				$field = self::PRODUCT_META_FIELDS[ $name ];
				if ( isset( $this->mapping[ $field ] ) ) {
					$this->report->add( Report::UNSUPPORTED, '', $key, $value, null, __( 'Already set by the WooCommerce settings.', 'total-import' ) );
					continue;
				}
				$this->template( $field, $key, (string) $value, __( 'Moved to the product field so WooCommerce keeps its lookup tables in step.', 'total-import' ) );
				continue;
			}
			$this->template( 'meta.' . $name, $key, (string) $value );
		}
	}

	private function images(): void {
		$mode = (string) $this->opt( 'download_images', 'yes' );
		switch ( $mode ) {
			case 'no':
				$key = 'featured_image';
				$sep = 'featured_delim';
				break;
			case 'gallery':
				$key = 'gallery_featured_image';
				$sep = 'gallery_featured_delim';
				break;
			default:
				$key = 'download_featured_image';
				$sep = 'download_featured_delim';
		}
		$source = trim( (string) $this->opt( $key ) );
		if ( '' === $source ) {
			return;
		}
		$delimiter = (string) $this->opt( $sep, ',' );
		$separator = '' === $delimiter ? ',' : $delimiter;
		$lines     = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $source ) ), 'strlen' ) );

		$featured = null;
		$gallery  = array();
		if ( ! empty( $this->opt( 'is_featured', 1 ) ) ) {
			$featured = array_shift( $lines );
		}
		$gallery = $lines;

		$notes = array();
		if ( 'no' === $mode ) {
			$notes[] = __( 'WP All Import looked these file names up in its own upload folder; Total Import looks them up in the Media Library.', 'total-import' );
		}

		$images = array();
		if ( null !== $featured ) {
			$converted = $this->expression->convert( $featured );
			if ( '' !== $converted['template'] ) {
				$images['featured'] = array( 'source' => $converted['template'] );
				$extra              = $notes;
				if ( array() === $gallery ) {
					/* translators: %s: separator */
					$extra[] = sprintf( __( 'If this holds several images separated by "%s", WP All Import used the first as the featured image and attached the rest. Total Import needs the featured image and the gallery mapped separately.', 'total-import' ), $separator );
				}
				$this->record( 'mapping.images.featured', $key, $featured, $converted, $extra );
			} else {
				$this->record( 'mapping.images.featured', $key, $featured, $converted, $notes );
			}
		}
		if ( array() !== $gallery ) {
			$raw       = implode( "\n", $gallery );
			$converted = $this->expression->convert( implode( $separator, $gallery ) );
			if ( '' !== $converted['template'] ) {
				$images['gallery'] = array(
					'source'    => $converted['template'],
					'separator' => $separator,
				);
				$extra             = $notes;
				if ( 'post' === $this->target ) {
					$extra[] = __( 'WP All Import only attached these images to the post. Total Import attaches them too and also lists their IDs in the _importright_gallery custom field.', 'total-import' );
				}
				$this->record( 'mapping.images.gallery', $key, $raw, $converted, $extra );
			} else {
				$this->record( 'mapping.images.gallery', $key, $raw, $converted, $notes );
			}
		}
		if ( array() === $images ) {
			return;
		}

		foreach ( array(
			'alt'     => 'image_meta_alt',
			'title'   => 'image_meta_title',
			'caption' => 'image_meta_caption',
		) as $part => $meta ) {
			if ( empty( $this->opt( 'set_' . $meta ) ) || '' === trim( (string) $this->opt( $meta ) ) ) {
				continue;
			}
			$converted = $this->expression->convert( (string) $this->opt( $meta ) );
			foreach ( array_keys( $images ) as $which ) {
				$images[ $which ][ $part ] = $converted['template'];
			}
			$extra = array();
			if ( isset( $images['gallery'] ) && (string) $this->opt( $meta . '_delim', ',' ) !== $separator ) {
				/* translators: %s: separator */
				$extra[] = sprintf( __( 'Total Import splits gallery %1$s values on the gallery separator "%2$s".', 'total-import' ), $part, $separator );
			}
			$this->record( 'mapping.images.*.' . $part, $meta, $this->opt( $meta ), $converted, $extra );
		}
		if ( ! empty( $this->opt( 'set_image_meta_description' ) ) ) {
			$this->report->add( Report::UNSUPPORTED, '', 'image_meta_description', $this->opt( 'image_meta_description' ), null, __( 'Image descriptions aren\'t imported.', 'total-import' ) );
		}

		if ( 'gallery' === $mode || ( ! empty( $this->opt( 'search_existing_images', 1 ) ) && 'by_filename' === $this->opt( 'search_existing_images_logic', 'by_url' ) ) ) {
			$images['match_filename'] = '1';
			$this->report->add( Report::MAPPED, 'mapping.images.match_filename', 'search_existing_images_logic', $this->opt( 'search_existing_images_logic' ), '1' );
		} elseif ( ! empty( $this->opt( 'search_existing_images', 1 ) ) ) {
			$images['match_filename'] = '0';
			$this->report->add( Report::MAPPED, 'mapping.images.match_filename', 'search_existing_images_logic', $this->opt( 'search_existing_images_logic', 'by_url' ), '0' );
		} else {
			$images['match_filename'] = '0';
			$this->report->add( Report::APPROXIMATED, 'mapping.images.match_filename', 'search_existing_images', 0, '0', __( 'WP All Import downloaded every image again; Total Import reuses images it imported before from the same URL.', 'total-import' ) );
		}
		$this->mapping['images'] = $images;

		foreach ( array( 'auto_rename_images', 'auto_set_extension', 'import_img_tags' ) as $flag ) {
			if ( ! empty( $this->opt( $flag ) ) ) {
				$this->report->add( Report::UNSUPPORTED, '', $flag, $this->opt( $flag ), null, __( 'This image option has no equivalent yet.', 'total-import' ) );
			}
		}
	}

	private function taxonomies(): void {
		$logic     = (array) $this->opt( 'tax_logic', array() );
		$assign    = (array) $this->opt( 'tax_assing', array() );
		$no_create = ! empty( $this->opt( 'do_not_create_terms' ) );
		$append    = 'add_new' === $this->opt( 'update_categories_logic', 'full_update' ) && 'no' === $this->opt( 'update_all_data', 'yes' );

		foreach ( $logic as $taxonomy => $kind ) {
			$taxonomy = (string) $taxonomy;
			if ( empty( $assign[ $taxonomy ] ) || 'post_format' === $taxonomy ) {
				continue;
			}
			if ( 'product' === $this->target && ( in_array( $taxonomy, self::PRODUCT_TAXONOMIES_SKIPPED, true ) || 0 === strpos( $taxonomy, 'pa_' ) ) ) {
				continue;
			}
			$field = 'mapping.taxonomies.' . $taxonomy;
			$notes = array();
			if ( ! empty( $this->opt( 'tax_enable_mapping' )[ $taxonomy ] ?? null ) ) {
				$notes[] = __( 'WP All Import\'s term mapping rules were dropped.', 'total-import' );
			}

			switch ( (string) $kind ) {
				case 'single':
					$raw       = (string) ( $this->opt( 'tax_single_xpath' )[ $taxonomy ] ?? '' );
					$separator = '';
					$hierarchy = '';
					break;
				case 'multiple':
					$raw       = (string) ( $this->opt( 'tax_multiple_xpath' )[ $taxonomy ] ?? '' );
					$separator = (string) ( $this->opt( 'tax_multiple_delim' )[ $taxonomy ] ?? '' );
					$separator = '' === $separator ? ',' : $separator;
					$hierarchy = '';
					break;
				case 'hierarchical':
					if ( ! empty( $this->opt( 'tax_hierarchical_logic_manual' )[ $taxonomy ] ?? null ) ) {
						$this->report->add( Report::UNSUPPORTED, '', 'post_taxonomies[' . $taxonomy . ']', $this->opt( 'post_taxonomies' )[ $taxonomy ] ?? '', null, __( 'Manually built term trees aren\'t supported; put the full path ("Parent > Child") in one column.', 'total-import' ) );
						continue 2;
					}
					$paths     = array_values( array_filter( array_map( 'strval', (array) ( $this->opt( 'tax_hierarchical_xpath' )[ $taxonomy ] ?? array() ) ), 'strlen' ) );
					$hierarchy = (string) ( $this->opt( 'tax_hierarchical_delim' )[ $taxonomy ] ?? '' );
					$hierarchy = '' === $hierarchy ? ',' : $hierarchy;
					$group     = ! empty( $this->opt( 'is_tax_hierarchical_group_delim' )[ $taxonomy ] ?? null ) ? (string) ( $this->opt( 'tax_hierarchical_group_delim' )[ $taxonomy ] ?? '' ) : '';
					if ( '' !== $group ) {
						$separator = $group;
					} else {
						$separator = count( $paths ) > 1 ? "\n" : '';
					}
					$raw = implode( '' === $separator ? '' : $separator, $paths );
					if ( empty( $this->opt( 'tax_hierarchical_last_level_assign' )[ $taxonomy ] ?? null ) ) {
						$notes[] = __( 'WP All Import assigned every level of the path; Total Import assigns the deepest term only.', 'total-import' );
					}
					break;
				default:
					continue 2;
			}
			if ( '' === trim( $raw ) ) {
				continue;
			}

			$converted = $this->expression->convert( $raw );
			if ( '' !== $converted['template'] ) {
				$this->mapping['taxonomies'][ $taxonomy ] = array(
					'terms'     => $converted['template'],
					'separator' => $separator,
					'hierarchy' => $hierarchy,
					'create'    => $no_create ? '0' : '1',
					'mode'      => $append ? 'append' : 'replace',
				);
			}
			$this->record( $field, 'tax_logic[' . $taxonomy . ']', $raw, $converted, $notes );
		}
	}

	private function woocommerce(): void {
		$type    = 'yes' === $this->opt( 'is_multiple_product_type', 'yes' ) ? (string) $this->opt( 'multiple_product_type', 'simple' ) : 'xpath';
		$handled = false;
		if ( 'simple' !== $type ) {
			$converted = apply_filters(
				'importright_migration_convert',
				array(
					'handled' => false,
					'type'    => $type,
					'target'  => $this->target_definition,
					'mapping' => $this->mapping,
				),
				$this->options,
				$this->report
			);
			if ( is_array( $converted ) && ! empty( $converted['handled'] ) ) {
				$handled = true;
				if ( isset( $converted['target'] ) && is_array( $converted['target'] ) ) {
					$this->target_definition = $converted['target'];
				}
				if ( isset( $converted['mapping'] ) && is_array( $converted['mapping'] ) ) {
					$this->mapping = $converted['mapping'];
				}
			}
		}
		if ( 'simple' !== $type && ! $handled ) {
			$this->report->add( Report::UNSUPPORTED, '', 'product_type', 'xpath' === $type ? $this->opt( 'single_product_type' ) : $type, null, __( 'Total Import imports simple products only. Variable, grouped and external products fail on update.', 'total-import' ) );
		}

		foreach ( array(
			'sku'            => 'single_product_sku',
			'regular_price'  => 'single_product_regular_price',
			'sale_price'     => 'single_product_sale_price',
			'stock_quantity' => 'single_product_stock_qty',
			'weight'         => 'single_product_weight',
			'length'         => 'single_product_length',
			'width'          => 'single_product_width',
			'height'         => 'single_product_height',
		) as $field => $key ) {
			$this->template( $field, $key );
		}
		if ( ! empty( $this->opt( 'prepare_price_to_woo_format' ) ) ) {
			$this->report->add( Report::UNSUPPORTED, '', 'prepare_price_to_woo_format', 1, null, __( 'Prices are imported as the file has them; reformat them in the file.', 'total-import' ) );
		}

		$this->switcher( 'manage_stock', 'is_product_manage_stock', 'single_product_manage_stock', array( 'yes', 'no' ), 'no' );
		$this->switcher( 'stock_status', 'product_stock_status', 'single_product_stock_status', array( 'instock', 'outofstock', 'onbackorder' ), 'auto' );
		$this->switcher( 'backorders', 'product_allow_backorders', 'single_product_allow_backorders', array( 'no', 'notify', 'yes' ), 'no' );

		$this->attributes();

		foreach ( self::WOO_UNSUPPORTED as $key => $default ) {
			if ( ! array_key_exists( $key, $this->options ) ) {
				continue;
			}
			$value = $this->options[ $key ];
			if ( 'multiple_product_shipping_class' === $key && '-1' === (string) $value ) {
				// The add-on's dropdown posts -1 for "No shipping class", so every import saved from the wizard holds it.
				continue;
			}
			if ( is_scalar( $value ) && (string) $value !== (string) $default && 'now' !== (string) $value ) {
				$this->report->add( Report::UNSUPPORTED, '', $key, $value, null, __( 'This WooCommerce setting isn\'t imported yet.', 'total-import' ) );
			}
		}
	}

	/**
	 * A WooCommerce add-on setting that is either a fixed choice or "xpath" with a template beside it.
	 *
	 * @param string[] $choices
	 */
	private function switcher( string $field, string $key, string $xpath_key, array $choices, string $skip ): void {
		$value = (string) $this->opt( $key, $skip );
		if ( 'xpath' === $value ) {
			$this->template( $field, $xpath_key );
			return;
		}
		if ( $value === $skip || ! in_array( $value, $choices, true ) ) {
			return;
		}
		$this->mapping[ $field ] = $value;
		$this->report->add( Report::MAPPED, 'mapping.' . $field, $key, $value, $value );
	}

	private function attributes(): void {
		$names     = (array) $this->opt( 'attribute_name', array() );
		$values    = (array) $this->opt( 'attribute_value', array() );
		$visible   = (array) $this->opt( 'is_visible', array() );
		$taxonomy  = (array) $this->opt( 'is_taxonomy', array() );
		$create    = (array) $this->opt( 'create_taxonomy_in_not_exists', array() );
		$variation = (array) $this->opt( 'in_variations', array() );
		$advanced  = (array) $this->opt( 'is_advanced', array() );

		foreach ( $names as $j => $name ) {
			$name = trim( (string) ( is_scalar( $name ) ? $name : '' ) );
			$raw  = (string) ( is_scalar( $values[ $j ] ?? '' ) ? ( $values[ $j ] ?? '' ) : '' );
			if ( '' === $name || '' === trim( $raw ) ) {
				continue;
			}
			$key = 'attribute_value[' . $name . ']';
			if ( false !== strpbrk( $name, '{}[]' ) ) {
				$this->report->add( Report::UNSUPPORTED, '', 'attribute_name', $name, null, __( 'Attribute names must be fixed text; this one is built from the file.', 'total-import' ) );
				continue;
			}
			$converted = $this->expression->convert( $raw );
			$notes     = array();
			if ( ! empty( $taxonomy[ $j ] ) ) {
				$notes[] = __( 'Used as a global attribute when one with this name exists. Total Import never creates global attributes, so otherwise it becomes a custom attribute.', 'total-import' );
			}
			if ( ! empty( $variation[ $j ] ) ) {
				$notes[] = __( '"Used for variations" was dropped: Total Import imports simple products.', 'total-import' );
			}
			if ( ! empty( $advanced[ $j ] ) ) {
				$notes[] = __( 'Per-record attribute options were dropped; the defaults apply.', 'total-import' );
			}
			if ( '' !== $converted['template'] ) {
				$this->mapping['attributes'][ $name ] = array(
					'values'    => $converted['template'],
					'separator' => '|',
					'visible'   => '0' === (string) ( $visible[ $j ] ?? '1' ) ? '0' : '1',
					'create'    => '0' === (string) ( $create[ $j ] ?? '1' ) ? '0' : '1',
				);
			}
			$this->record( 'mapping.attributes.' . $name, $key, $raw, $converted, $notes );
		}
	}

	private function woocommerce_unavailable(): void {
		foreach ( $this->options as $key => $value ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, 'single_product_' ) ) {
				$set = is_scalar( $value ) && '' !== trim( (string) $value );
			} elseif ( 'attribute_value' === $key ) {
				$set = is_array( $value ) && array() !== array_filter( $value );
			} else {
				continue;
			}
			if ( $set ) {
				$this->report->add( Report::UNSUPPORTED, '', $key, $value, null, __( 'WooCommerce isn\'t active, so product fields can\'t be imported. Activate it and migrate again.', 'total-import' ) );
			}
		}
	}

	/**
	 * @return array{0: array<string, string>, 1: bool}
	 */
	private function matching(): array {
		$mode = 'create_update';
		if ( empty( $this->opt( 'create_new_records', 1 ) ) ) {
			$mode = 'update';
			$this->report->add( Report::MAPPED, 'matching.mode', 'create_new_records', 0, 'update' );
		}
		if ( 'yes' === $this->opt( 'is_keep_former_posts', 'no' ) ) {
			$mode = 'create';
			$this->report->add( Report::MAPPED, 'matching.mode', 'is_keep_former_posts', 'yes', 'create' );
		}

		$unique   = trim( (string) $this->opt( 'unique_key' ) );
		$key      = '';
		$tracking = false;
		if ( '' !== $unique ) {
			$converted = $this->expression->convert( $unique );
			$key       = $converted['template'];
			$tracking  = Report::MAPPED === $converted['status'] && false !== strpos( $key, '{' );
			$notes     = $tracking ? array() : array( __( 'The key no longer renders what WP All Import stored, so its record list can\'t be carried over and the first run creates new posts. Fix the key, or match existing posts by title or slug.', 'total-import' ) );
			$this->record( 'matching.unique_key', 'unique_key', $unique, $converted, $notes );
		}

		$match = '';
		if ( 'manual' === $this->opt( 'duplicate_matching', 'auto' ) ) {
			$indicator = (string) $this->opt( 'duplicate_indicator', 'title' );
			switch ( $indicator ) {
				case 'title':
				case 'slug':
					$match = 'term' === $this->target && 'title' === $indicator ? 'name' : $indicator;
					$xpath = trim( (string) $this->opt( $indicator . '_xpath' ) );
					if ( '' !== $xpath ) {
						$this->report->add( Report::APPROXIMATED, 'matching.match_existing', $indicator . '_xpath', $xpath, $match, __( 'Total Import compares the mapped field itself, not a separate template.', 'total-import' ) );
					} else {
						$this->report->add( Report::MAPPED, 'matching.match_existing', 'duplicate_indicator', $indicator, $match );
					}
					break;
				case 'pid':
					$converted = $this->expression->convert( (string) $this->opt( 'pid_xpath' ) );
					if ( '' !== $converted['template'] ) {
						$match    = 'id';
						$key      = $converted['template'];
						$tracking = false;
					}
					$this->record( 'matching.unique_key', 'pid_xpath', $this->opt( 'pid_xpath' ), $converted, array(), array( __( 'Matched by ID; the unique key is the ID.', 'total-import' ) ) );
					break;
				case 'custom field':
					$name = trim( (string) $this->opt( 'custom_duplicate_name' ) );
					if ( '' === $name || false !== strpbrk( $name, '{}[]' ) ) {
						$this->report->add( Report::UNSUPPORTED, '', 'custom_duplicate_name', $name, null, __( 'The custom field to match on must be fixed text.', 'total-import' ) );
						break;
					}
					$converted = $this->expression->convert( (string) $this->opt( 'custom_duplicate_value' ) );
					if ( '' !== $converted['template'] ) {
						$match    = 'meta:' . $name;
						$key      = $converted['template'];
						$tracking = false;
					}
					$this->record( 'matching.unique_key', 'custom_duplicate_value', $this->opt( 'custom_duplicate_value' ), $converted, array(), array( __( 'Matched on a custom field; the unique key is its value.', 'total-import' ) ) );
					break;
				default:
					/* translators: %s: matching rule */
					$this->report->add( Report::UNSUPPORTED, '', 'duplicate_indicator', $indicator, null, sprintf( __( 'Matching existing posts by %s isn\'t supported.', 'total-import' ), $indicator ) );
			}
		}

		if ( '' === $key ) {
			$title = (string) ( $this->mapping['title'] ?? $this->mapping['name'] ?? '' );
			$key   = $title;
			$this->report->add( '' === $title ? Report::UNSUPPORTED : Report::APPROXIMATED, 'matching.unique_key', 'unique_key', $unique, $title, __( 'There was no usable unique key, so the title is used. Set a column that never changes, such as an ID or SKU.', 'total-import' ) );
		}

		return array(
			array(
				'unique_key'     => $key,
				'mode'           => $mode,
				'match_existing' => $match,
			),
			$tracking,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function update_rules(): array {
		if ( 'no' !== $this->opt( 'update_all_data', 'yes' ) ) {
			return array();
		}

		$flags = array(
			'post'    => array(
				'title'          => 'is_update_title',
				'content'        => 'is_update_content',
				'excerpt'        => 'is_update_excerpt',
				'slug'           => 'is_update_slug',
				'status'         => 'is_update_status',
				'date'           => 'is_update_dates',
				'author'         => 'is_update_author',
				'parent'         => 'is_update_parent',
				'menu_order'     => 'is_update_menu_order',
				'comment_status' => 'is_update_comment_status',
				'ping_status'    => 'is_update_ping_status',
			),
			'product' => array(
				'name'              => 'is_update_title',
				'description'       => 'is_update_content',
				'short_description' => 'is_update_excerpt',
				'slug'              => 'is_update_slug',
				'status'            => 'is_update_status',
			),
			'term'    => array(
				'name'        => 'is_update_title',
				'description' => 'is_update_content',
				'slug'        => 'is_update_slug',
				'parent'      => 'is_update_parent',
			),
		);

		$update = array();
		foreach ( $flags[ $this->target ] as $field => $flag ) {
			if ( isset( $this->mapping[ $field ] ) && empty( $this->opt( $flag, 1 ) ) ) {
				$update[ $field ] = false;
				$this->report->add( Report::MAPPED, 'update.' . $field, $flag, 0, false );
			}
		}

		$meta_rule = $this->list_rule( 'is_update_custom_fields', 'update_custom_fields_logic', 'custom_fields_list', 'custom_fields_only_list', 'custom_fields_except_list', array_keys( (array) ( $this->mapping['meta'] ?? array() ) ) );
		if ( isset( $this->mapping['meta'] ) && true !== $meta_rule ) {
			$update['meta'] = $meta_rule;
			$this->report->add( Report::MAPPED, 'update.meta', 'update_custom_fields_logic', $this->opt( 'update_custom_fields_logic' ), $meta_rule );
		}
		if ( 'product' === $this->target ) {
			$all_product_meta = array_keys( self::PRODUCT_META_FIELDS );
			$product_rule     = $this->list_rule( 'is_update_custom_fields', 'update_custom_fields_logic', 'custom_fields_list', 'custom_fields_only_list', 'custom_fields_except_list', $all_product_meta );
			foreach ( self::PRODUCT_META_FIELDS as $meta => $field ) {
				if ( isset( $this->mapping[ $field ] ) && ( false === $product_rule || ( is_array( $product_rule ) && ! in_array( $meta, $product_rule, true ) ) ) ) {
					$update[ $field ] = false;
					$this->report->add( Report::MAPPED, 'update.' . $field, 'update_custom_fields_logic', $this->opt( 'update_custom_fields_logic' ), false, sprintf( /* translators: %s: meta key */ __( 'WP All Import updated this as the custom field %s.', 'total-import' ), $meta ) );
				}
			}
		}

		if ( isset( $this->mapping['taxonomies'] ) ) {
			$rule = $this->list_rule( 'is_update_categories', 'update_categories_logic', 'taxonomies_list', 'taxonomies_only_list', 'taxonomies_except_list', array_keys( $this->mapping['taxonomies'] ) );
			if ( true !== $rule ) {
				$update['taxonomies'] = $rule;
				$this->report->add( Report::MAPPED, 'update.taxonomies', 'update_categories_logic', $this->opt( 'update_categories_logic' ), $rule );
			}
		}

		if ( isset( $this->mapping['images'] ) ) {
			if ( empty( $this->opt( 'is_update_images', 1 ) ) ) {
				$update['images'] = false;
				$this->report->add( Report::MAPPED, 'update.images', 'is_update_images', 0, false );
			} elseif ( 'add_new' === $this->opt( 'update_images_logic', 'full_update' ) ) {
				$this->report->add( Report::APPROXIMATED, 'update.images', 'update_images_logic', 'add_new', true, __( 'WP All Import kept existing images and added new ones; Total Import replaces them with the file\'s images.', 'total-import' ) );
			}
		}

		if ( isset( $this->mapping['attributes'] ) ) {
			$rule = $this->list_rule( 'is_update_attributes', 'update_attributes_logic', 'attributes_list', 'attributes_only_list', 'attributes_except_list', array_keys( $this->mapping['attributes'] ) );
			if ( true !== $rule ) {
				$update['attributes'] = $rule;
				$this->report->add( Report::MAPPED, 'update.attributes', 'update_attributes_logic', $this->opt( 'update_attributes_logic' ), $rule );
			}
		}

		return $update;
	}

	/**
	 * WP All Import's "update these / all except these" choices as a Total Import rule.
	 *
	 * @param string[] $mapped Sub-keys the mapping has.
	 * @return bool|string[]
	 */
	private function list_rule( string $flag, string $logic_key, string $list_key, string $only_key, string $except_key, array $mapped ) {
		if ( empty( $this->opt( $flag, 1 ) ) ) {
			return false;
		}
		$logic = (string) $this->opt( $logic_key, 'full_update' );
		if ( 'only' !== $logic && 'all_except' !== $logic ) {
			return true;
		}
		$list = $this->opt( $list_key, array() );
		if ( ! is_array( $list ) || array() === $list ) {
			$list = explode( ',', (string) $this->opt( 'only' === $logic ? $only_key : $except_key ) );
		}
		$list = array_values( array_filter( array_map( 'trim', array_map( 'strval', $list ) ), 'strlen' ) );
		if ( 'only' === $logic ) {
			return $list;
		}
		return array_values( array_diff( $mapped, $list ) );
	}

	private function unsupported_settings(): void {
		$checks = array(
			'is_delete_missing'    => __( 'Deleting or changing posts that left the file isn\'t supported yet.', 'total-import' ),
			'is_import_specified'  => __( 'Importing only some records of the file isn\'t supported.', 'total-import' ),
			'is_update_missing_cf' => __( 'Updating a custom field on posts that left the file isn\'t supported yet.', 'total-import' ),
		);
		foreach ( $checks as $key => $note ) {
			if ( ! empty( $this->opt( $key ) ) ) {
				$this->report->add( Report::UNSUPPORTED, '', $key, $this->opt( $key ), null, $note );
			}
		}
		if ( ! empty( $this->opt( 'scheduling_enable' ) ) || ! empty( $this->opt( 'is_scheduled' ) ) ) {
			$this->report->add( Report::UNSUPPORTED, '', 'scheduling_enable', $this->opt( 'scheduling_run_on' ), null, __( 'Scheduled runs aren\'t migrated. Run the import from WP-CLI or cron for now.', 'total-import' ) );
		}
		if ( 'yes' === $this->opt( 'create_draft' ) ) {
			$this->report->add( Report::UNSUPPORTED, '', 'create_draft', 'yes', null, __( 'Saving posts whose images failed as drafts isn\'t supported.', 'total-import' ) );
		}
		if ( '' !== trim( (string) $this->opt( 'attachments' ) ) ) {
			$this->report->add( Report::UNSUPPORTED, '', 'attachments', $this->opt( 'attachments' ), null, __( 'Non-image attachments aren\'t imported.', 'total-import' ) );
		}
	}

	/**
	 * Converts a template option and puts it in the mapping at $field ("title", "meta.key").
	 */
	private function template( string $field, string $key, ?string $raw = null, string $note = '' ): void {
		$raw = $raw ?? (string) ( is_scalar( $this->opt( $key ) ) ? $this->opt( $key ) : '' );
		if ( '' === trim( $raw ) ) {
			return;
		}
		$converted = $this->expression->convert( $raw );
		if ( '' !== $converted['template'] ) {
			if ( 0 === strpos( $field, 'meta.' ) ) {
				$this->mapping['meta'][ substr( $field, 5 ) ] = $converted['template'];
			} else {
				$this->mapping[ $field ] = $converted['template'];
			}
		}
		$this->record( 'mapping.' . $field, $key, $raw, $converted, array(), '' === $note ? array() : array( $note ) );
	}

	/**
	 * @param mixed                                                    $raw
	 * @param array{template: string, status: string, notes: string[]} $converted
	 * @param string[]                                                 $caveats Notes that make a clean conversion approximate.
	 * @param string[]                                                 $info    Notes that don't.
	 */
	private function record( string $field, string $key, $raw, array $converted, array $caveats = array(), array $info = array() ): void {
		$status = $converted['status'];
		if ( Report::MAPPED === $status && array() !== $caveats ) {
			$status = Report::APPROXIMATED;
		}
		$notes = array_merge( $converted['notes'], $caveats, $info );
		$this->report->add( $status, Report::UNSUPPORTED === $status ? '' : $field, $key, $raw, '' === $converted['template'] ? null : $converted['template'], implode( ' ', $notes ) );
	}

	/**
	 * @param mixed $fallback
	 * @return mixed
	 */
	private function opt( string $key, $fallback = '' ) {
		return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback;
	}
}

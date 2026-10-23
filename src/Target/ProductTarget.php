<?php

namespace Importright\Target;

use Importright\Field\OwnsMeta;
use Importright\Field\TaxonomiesHandler;
use Importright\Runs\Context;
use Importright\Runs\Diff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce simple products, saved through WC_Product_Simple so Woo's
 * lookup tables, SKU checks and hooks all run. Registered only when
 * WooCommerce is active.
 *
 * On update, a product whose only changes are its regular price, sale price
 * or stock quantity takes the fast path (setting fast_price_stock, on by
 * default): those meta values and _price are written directly, the product
 * lookup row is refreshed and the product's transients cleared, instead of a
 * full save. Anything else, a sale schedule, or a change that would flip the
 * stock status takes the full save.
 */
final class ProductTarget implements Target, Previewable, OwnsMeta {

	private const TEXT = array( 'name', 'description', 'short_description', 'slug', 'sku' );

	private const DECIMALS = array( 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' );

	private const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Product fields WooCommerce keeps in protected meta, for sites that map them as custom fields.
	 */
	private const WOO_META = array( '_sku', '_regular_price', '_sale_price', '_price', '_sale_price_dates_from', '_sale_price_dates_to', '_stock', '_stock_status', '_manage_stock', '_backorders', '_low_stock_amount', '_sold_individually', '_weight', '_length', '_width', '_height', '_tax_status', '_tax_class', '_purchase_note', '_virtual', '_downloadable', '_product_url', '_button_text', '_global_unique_id' );

	public function owned_meta( string $target ): array {
		return 'product' === $target ? self::WOO_META : array();
	}

	public function key(): string {
		return 'product';
	}

	public function label(): string {
		return __( 'WooCommerce simple products', 'total-import' );
	}

	public function object_type(): string {
		return 'product';
	}

	public function fields(): array {
		return array(
			'name'              => __( 'Name', 'total-import' ),
			'description'       => __( 'Description', 'total-import' ),
			'short_description' => __( 'Short description', 'total-import' ),
			'slug'              => __( 'Slug', 'total-import' ),
			'status'            => __( 'Status', 'total-import' ),
			'sku'               => __( 'SKU', 'total-import' ),
			'regular_price'     => __( 'Regular price', 'total-import' ),
			'sale_price'        => __( 'Sale price', 'total-import' ),
			'manage_stock'      => __( 'Manage stock', 'total-import' ),
			'stock_quantity'    => __( 'Stock quantity', 'total-import' ),
			'stock_status'      => __( 'Stock status', 'total-import' ),
			'backorders'        => __( 'Backorders', 'total-import' ),
			'weight'            => __( 'Weight', 'total-import' ),
			'length'            => __( 'Length', 'total-import' ),
			'width'             => __( 'Width', 'total-import' ),
			'height'            => __( 'Height', 'total-import' ),
		);
	}

	public function match_strategies(): array {
		return array( 'id', 'sku', 'slug', 'title' );
	}

	public function validate( array $settings ) {
		$status = sanitize_key( (string) ( $settings['default_status'] ?? 'publish' ) );
		return array(
			'default_status'   => in_array( $status, self::STATUSES, true ) ? $status : 'publish',
			'fast_price_stock' => '1' === TaxonomiesHandler::flag( $settings['fast_price_stock'] ?? true ),
		);
	}

	public function prime( array $ids ): void {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( array() !== $ids ) {
			_prime_post_caches( $ids, false, true );
		}
	}

	public function exists( int $id, array $settings ): bool {
		$post = get_post( $id );
		return $post instanceof \WP_Post && 'product' === $post->post_type && 'trash' !== $post->post_status;
	}

	public function find( string $strategy, array $values, string $unique_key, array $settings ): ?int {
		global $wpdb;

		$id = null;
		if ( 'id' === $strategy && ctype_digit( $unique_key ) ) {
			$id = (int) $unique_key;
		} elseif ( 'sku' === $strategy ) {
			$sku = trim( (string) ( $values['sku'] ?? '' ) );
			$id  = (int) wc_get_product_id_by_sku( '' !== $sku ? $sku : $unique_key );
		} elseif ( 'slug' === $strategy && '' !== (string) ( $values['slug'] ?? '' ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'product' AND post_status <> 'trash' ORDER BY ID LIMIT 1", sanitize_title( (string) $values['slug'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( 'title' === $strategy && '' !== (string) ( $values['name'] ?? '' ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'product' AND post_status <> 'trash' ORDER BY ID LIMIT 1", (string) $values['name'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( 0 === strpos( $strategy, 'meta:' ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = 'product' AND p.post_status <> 'trash' ORDER BY p.ID LIMIT 1", substr( $strategy, 5 ), $unique_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return null !== $id && $id > 0 && $this->exists( $id, $settings ) ? $id : null;
	}

	public function save( ?int $id, array $fields, array $settings, Context $context ) {
		$denied = Permissions::post( $id, 'product' );
		if ( null !== $denied ) {
			return $denied;
		}
		$props = $this->props( $id, $fields, $context );

		if ( null === $id ) {
			$product = new \WC_Product_Simple();
			if ( ! isset( $props['status'] ) ) {
				$props['status'] = (string) Permissions::status( 'product', (string) $settings['default_status'], null, $context );
			}
			if ( isset( $props['stock_quantity'] ) && ! isset( $props['manage_stock'] ) ) {
				$props['manage_stock'] = true;
			}
		} else {
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product ) {
				return new WP_Error( 'importright_save', __( 'WooCommerce could not load this product.', 'total-import' ) );
			}
			if ( ! $product->is_type( 'simple' ) ) {
				/* translators: %s: product type */
				return new WP_Error( 'importright_save', sprintf( __( 'This is a %s product; only simple products are updated.', 'total-import' ), $product->get_type() ) );
			}
			$changed = $this->changed( $product, $props );
			if ( array() === $changed ) {
				return $id;
			}
			if ( $settings['fast_price_stock'] && PriceStock::is_fast( $changed ) && $this->can_fast( $product, $props, $changed ) ) {
				$this->fast( $product, $props, $changed );
				return $id;
			}
			$props = array_intersect_key( $props, array_flip( $changed ) );
		}

		$set = $product->set_props( $props );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		try {
			$saved = $product->save();
		} catch ( \Exception $e ) {
			return new WP_Error( 'importright_save', $e->getMessage() );
		}
		if ( 0 === (int) $saved ) {
			return new WP_Error( 'importright_save', __( 'WooCommerce did not save the product.', 'total-import' ) );
		}
		return (int) $saved;
	}

	public function preview( ?int $id, array $fields, array $settings, Context $context, Diff $diff ) {
		$denied = Permissions::post( $id, 'product' );
		if ( null !== $denied ) {
			return $denied;
		}
		$props = $this->props( $id, $fields, $context );

		if ( null === $id ) {
			if ( ! isset( $props['status'] ) ) {
				$props['status'] = (string) Permissions::status( 'product', (string) $settings['default_status'], null, $context );
			}
			if ( isset( $props['sku'] ) && '' !== $props['sku'] && ! wc_product_has_unique_sku( 0, $props['sku'] ) ) {
				/* translators: %s: SKU */
				return new WP_Error( 'importright_save', sprintf( __( 'Another product already has the SKU "%s".', 'total-import' ), $props['sku'] ) );
			}
			foreach ( $props as $prop => $value ) {
				$diff->add( $prop, null, $value );
			}
			return true;
		}

		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			return new WP_Error( 'importright_save', __( 'WooCommerce could not load this product.', 'total-import' ) );
		}
		if ( ! $product->is_type( 'simple' ) ) {
			/* translators: %s: product type */
			return new WP_Error( 'importright_save', sprintf( __( 'This is a %s product; only simple products are updated.', 'total-import' ), $product->get_type() ) );
		}
		foreach ( $this->changed( $product, $props ) as $prop ) {
			if ( 'sku' === $prop && '' !== $props['sku'] && ! wc_product_has_unique_sku( $id, $props['sku'] ) ) {
				/* translators: %s: SKU */
				return new WP_Error( 'importright_save', sprintf( __( 'Another product already has the SKU "%s".', 'total-import' ), $props['sku'] ) );
			}
			$diff->add( $prop, $product->{'get_' . $prop}( 'edit' ), $props[ $prop ] );
		}
		return true;
	}

	/**
	 * Turns rendered fields into WC_Product props, leaving out values it can't read.
	 *
	 * @param array<string, string> $fields
	 * @return array<string, mixed>
	 */
	private function props( ?int $id, array $fields, Context $context ): array {
		$props = array();
		foreach ( $fields as $field => $value ) {
			$value = trim( (string) $value );
			if ( in_array( $field, self::TEXT, true ) ) {
				$props[ $field ] = 'name' === $field || 'sku' === $field ? $value : (string) $fields[ $field ];
				if ( 'slug' === $field && '' === $value ) {
					unset( $props[ $field ] );
				}
			} elseif ( in_array( $field, self::DECIMALS, true ) ) {
				$decimal = '' === $value ? '' : wc_format_decimal( $value );
				if ( '' !== $value && ! is_numeric( $decimal ) ) {
					/* translators: 1: field, 2: value */
					$context->notice( sprintf( __( '%1$s "%2$s" is not a number and was ignored.', 'total-import' ), $field, $value ) );
					continue;
				}
				$props[ $field ] = (string) $decimal;
			} elseif ( 'status' === $field ) {
				$status = sanitize_key( $value );
				if ( in_array( $status, self::STATUSES, true ) ) {
					$status = Permissions::status( 'product', $status, $id, $context );
					if ( null !== $status ) {
						$props['status'] = $status;
					}
				} elseif ( '' !== $status ) {
					/* translators: %s: status */
					$context->notice( sprintf( __( 'Unknown status "%s" ignored.', 'total-import' ), $status ) );
				}
			} elseif ( 'manage_stock' === $field ) {
				if ( '' !== $value ) {
					$props['manage_stock'] = '1' === TaxonomiesHandler::flag( $value );
				}
			} elseif ( 'stock_quantity' === $field ) {
				if ( '' === $value ) {
					continue;
				}
				if ( ! is_numeric( $value ) ) {
					/* translators: %s: value */
					$context->notice( sprintf( __( 'Stock quantity "%s" is not a number and was ignored.', 'total-import' ), $value ) );
					continue;
				}
				$props['stock_quantity'] = wc_stock_amount( $value );
			} elseif ( 'stock_status' === $field ) {
				$status = self::stock_status( $value );
				if ( null !== $status ) {
					$props['stock_status'] = $status;
				} elseif ( '' !== $value ) {
					/* translators: %s: value */
					$context->notice( sprintf( __( 'Stock status "%s" ignored.', 'total-import' ), $value ) );
				}
			} elseif ( 'backorders' === $field ) {
				$backorders = strtolower( $value );
				if ( in_array( $backorders, array( 'no', 'notify', 'yes' ), true ) ) {
					$props['backorders'] = $backorders;
				} elseif ( '' !== $value ) {
					/* translators: %s: value */
					$context->notice( sprintf( __( 'Backorders "%s" ignored; use no, notify or yes.', 'total-import' ), $value ) );
				}
			}
		}
		return $props;
	}

	private static function stock_status( string $value ): ?string {
		$value = str_replace( array( ' ', '_', '-' ), '', strtolower( $value ) );
		$map   = array(
			'instock'     => 'instock',
			'1'           => 'instock',
			'yes'         => 'instock',
			'true'        => 'instock',
			'outofstock'  => 'outofstock',
			'0'           => 'outofstock',
			'no'          => 'outofstock',
			'false'       => 'outofstock',
			'onbackorder' => 'onbackorder',
			'backorder'   => 'onbackorder',
		);
		return $map[ $value ] ?? null;
	}

	/**
	 * Props whose new value differs from the product's.
	 *
	 * @param array<string, mixed> $props
	 * @return string[]
	 */
	private function changed( \WC_Product $product, array $props ): array {
		$changed = array();
		foreach ( $props as $prop => $value ) {
			$current = $product->{'get_' . $prop}( 'edit' );
			if ( is_bool( $value ) || is_bool( $current ) ) {
				$same = (bool) $value === (bool) $current;
			} elseif ( is_numeric( $value ) && is_numeric( $current ) && ! in_array( $prop, self::TEXT, true ) ) {
				$same = (float) $value === (float) $current;
			} else {
				$same = (string) $value === (string) $current;
			}
			if ( ! $same ) {
				$changed[] = $prop;
			}
		}
		return $changed;
	}

	/**
	 * @param array<string, mixed> $props
	 * @param string[]             $changed
	 */
	private function can_fast( \WC_Product $product, array $props, array $changed ): bool {
		if ( ! PriceStock::can_refresh_lookup( \WC_Data_Store::load( 'product' ) ) ) {
			return false;
		}
		if ( null !== $product->get_date_on_sale_from( 'edit' ) || null !== $product->get_date_on_sale_to( 'edit' ) ) {
			return false;
		}
		if ( in_array( 'stock_quantity', $changed, true ) ) {
			if ( ! $product->get_manage_stock( 'edit' ) ) {
				return false;
			}
			$status = PriceStock::managed_status( (float) $props['stock_quantity'], (string) $product->get_backorders( 'edit' ), (float) get_option( 'woocommerce_notify_no_stock_amount', 0 ) );
			if ( $status !== $product->get_stock_status( 'edit' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $props
	 * @param string[]             $changed
	 */
	private function fast( \WC_Product $product, array $props, array $changed ): void {
		$id         = $product->get_id();
		$data_store = \WC_Data_Store::load( 'product' );

		if ( array() !== array_intersect( array( 'regular_price', 'sale_price' ), $changed ) ) {
			$regular = (string) ( $props['regular_price'] ?? $product->get_regular_price( 'edit' ) );
			$sale    = (string) ( $props['sale_price'] ?? $product->get_sale_price( 'edit' ) );
			update_post_meta( $id, '_regular_price', $regular );
			update_post_meta( $id, '_sale_price', $sale );
			update_post_meta( $id, '_price', PriceStock::active_price( $regular, $sale ) );
		}
		if ( in_array( 'stock_quantity', $changed, true ) ) {
			$data_store->update_product_stock( $id, $props['stock_quantity'], 'set' );
		}

		wp_cache_delete( 'lookup_table', 'object_' . $id );
		$data_store->refresh_product_lookup_table( $id );
		wc_delete_product_transients( $id );
	}
}

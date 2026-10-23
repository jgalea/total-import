<?php

namespace Importright\Target;

defined( 'ABSPATH' ) || exit;

/**
 * The parts of WooCommerce's product rules the price and stock fast path has
 * to reproduce without a full product save.
 */
final class PriceStock {

	public const FAST_PROPS = array( 'regular_price', 'sale_price', 'stock_quantity' );

	/**
	 * The active price, as WC_Product::is_on_sale() decides it for a product with no sale schedule.
	 */
	public static function active_price( string $regular, string $sale ): string {
		if ( '' !== $sale && '' !== $regular && (float) $regular > (float) $sale ) {
			return $sale;
		}
		return $regular;
	}

	/**
	 * Stock status for a stock-managed product, as WC_Product::validate_props() sets it.
	 */
	public static function managed_status( float $quantity, string $backorders, float $no_stock_amount ): string {
		if ( $quantity > abs( $no_stock_amount ) ) {
			return 'instock';
		}
		return 'no' !== $backorders ? 'onbackorder' : 'outofstock';
	}

	/**
	 * WC_Data_Store::load() returns a wrapper that forwards calls through
	 * __call, so method_exists() on it is always false.
	 *
	 * @param object $store
	 */
	public static function can_refresh_lookup( $store ): bool {
		if ( method_exists( $store, 'has_callable' ) ) {
			return (bool) $store->has_callable( 'refresh_product_lookup_table' );
		}
		return method_exists( $store, 'refresh_product_lookup_table' );
	}

	/**
	 * Whether a set of changed props can skip the full product save.
	 *
	 * @param string[] $changed
	 */
	public static function is_fast( array $changed ): bool {
		return array() !== $changed && array() === array_diff( $changed, self::FAST_PROPS );
	}
}

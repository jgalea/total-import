<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Target\PriceStock;

final class PriceStockTest extends TestCase {

	public function test_active_price_follows_the_sale_price_only_when_lower(): void {
		$this->assertSame( '8', PriceStock::active_price( '10', '8' ) );
		$this->assertSame( '10', PriceStock::active_price( '10', '' ) );
		$this->assertSame( '10', PriceStock::active_price( '10', '12' ), 'A sale price above the regular price is not a sale.' );
		$this->assertSame( '10', PriceStock::active_price( '10', '10' ) );
		$this->assertSame( '', PriceStock::active_price( '', '5' ), 'WooCommerce needs a regular price to be on sale.' );
		$this->assertSame( '9.5', PriceStock::active_price( '10.00', '9.5' ) );
	}

	public function test_managed_stock_status(): void {
		$this->assertSame( 'instock', PriceStock::managed_status( 3, 'no', 0 ) );
		$this->assertSame( 'outofstock', PriceStock::managed_status( 0, 'no', 0 ) );
		$this->assertSame( 'onbackorder', PriceStock::managed_status( 0, 'notify', 0 ) );
		$this->assertSame( 'outofstock', PriceStock::managed_status( 2, 'no', 2 ), 'At the out-of-stock threshold.' );
	}

	public function test_only_price_and_quantity_changes_are_fast(): void {
		$this->assertTrue( PriceStock::is_fast( array( 'regular_price' ) ) );
		$this->assertTrue( PriceStock::is_fast( array( 'sale_price', 'stock_quantity' ) ) );
		$this->assertFalse( PriceStock::is_fast( array( 'regular_price', 'name' ) ) );
		$this->assertFalse( PriceStock::is_fast( array( 'stock_status' ) ) );
		$this->assertFalse( PriceStock::is_fast( array() ) );
	}

	public function test_lookup_refresh_is_detected_through_the_data_store_wrapper(): void {
		$inner   = new class() {
			public function refresh_product_lookup_table( int $product_id ): void {}
		};
		$wrapper = new class( $inner ) {
			private $instance;

			public function __construct( $instance ) {
				$this->instance = $instance;
			}

			public function has_callable( string $method ): bool {
				return is_callable( array( $this->instance, $method ) );
			}

			public function __call( $method, $args ) {
				return $this->instance->$method( ...$args );
			}
		};
		$this->assertFalse( method_exists( $wrapper, 'refresh_product_lookup_table' ), 'The wrapper hides the method from method_exists().' );
		$this->assertTrue( PriceStock::can_refresh_lookup( $wrapper ) );
		$this->assertFalse(
			PriceStock::can_refresh_lookup(
				new class() {
					public function has_callable( string $method ): bool {
						return false;
					}
				}
			)
		);
	}
}

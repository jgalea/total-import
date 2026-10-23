<?php

namespace Importright;

use Importright\Field\AttributesHandler;
use Importright\Field\FieldHandler;
use Importright\Field\ImagesHandler;
use Importright\Field\MetaHandler;
use Importright\Field\SeoHandler;
use Importright\Field\TaxonomiesHandler;
use Importright\Target\PostTarget;
use Importright\Target\ProductTarget;
use Importright\Target\Target;
use Importright\Target\TermTarget;
use Importright\Target\UserTarget;

defined( 'ABSPATH' ) || exit;

/**
 * Targets and field handlers, built once per request. Add-ons register theirs
 * through the importright_targets and importright_field_handlers filters,
 * before the first lookup (plugins_loaded is safe). A callback added after the
 * list was built would be silently missed, so it triggers _doing_it_wrong().
 */
final class Registry {

	/**
	 * @var array<string, Target>|null
	 */
	private static ?array $targets = null;

	/**
	 * @var array<string, FieldHandler>|null
	 */
	private static ?array $handlers = null;

	/**
	 * Callback counts per filter when its list was built.
	 *
	 * @var array<string, int>
	 */
	private static array $built = array();

	/**
	 * @var array<string, true>
	 */
	private static array $warned = array();

	/**
	 * @return array<string, Target>
	 */
	public static function targets(): array {
		if ( null !== self::$targets ) {
			self::check_late( 'importright_targets' );
		} else {
			$targets = array(
				'post' => new PostTarget(),
				'term' => new TermTarget(),
				'user' => new UserTarget(),
			);
			if ( self::woocommerce() ) {
				$targets['product'] = new ProductTarget();
			}
			$targets                            = apply_filters( 'importright_targets', $targets );
			self::$targets                      = array();
			self::$built['importright_targets'] = self::callbacks( 'importright_targets' );
			foreach ( (array) $targets as $target ) {
				if ( $target instanceof Target ) {
					self::$targets[ $target->key() ] = $target;
				}
			}
		}
		return self::$targets;
	}

	public static function target( string $key ): ?Target {
		return self::targets()[ $key ] ?? null;
	}

	/**
	 * @return array<string, FieldHandler>
	 */
	public static function handlers(): array {
		if ( null !== self::$handlers ) {
			self::check_late( 'importright_field_handlers' );
		} else {
			$handlers = array(
				'meta'       => new MetaHandler(),
				'taxonomies' => new TaxonomiesHandler(),
				'images'     => new ImagesHandler(),
				'seo'        => new SeoHandler(),
			);
			if ( self::woocommerce() ) {
				$handlers['attributes'] = new AttributesHandler();
			}
			$handlers                                  = apply_filters( 'importright_field_handlers', $handlers );
			self::$handlers                            = array();
			self::$built['importright_field_handlers'] = self::callbacks( 'importright_field_handlers' );
			foreach ( (array) $handlers as $handler ) {
				if ( $handler instanceof FieldHandler ) {
					self::$handlers[ $handler->key() ] = $handler;
				}
			}
		}
		return self::$handlers;
	}

	/**
	 * @return array<string, FieldHandler>
	 */
	public static function handlers_for( string $target ): array {
		return array_filter(
			self::handlers(),
			static function ( FieldHandler $handler ) use ( $target ): bool {
				return $handler->supports( $target );
			}
		);
	}

	private static function woocommerce(): bool {
		return class_exists( 'WooCommerce', false ) && class_exists( 'WC_Product_Simple' );
	}

	private static function check_late( string $hook ): void {
		if ( isset( self::$warned[ $hook ] ) || self::callbacks( $hook ) <= ( self::$built[ $hook ] ?? 0 ) ) {
			return;
		}
		self::$warned[ $hook ] = true;
		_doing_it_wrong(
			esc_html( $hook ),
			esc_html__( 'A callback was added after Total Import built this list, so it has no effect in this request. Add it on plugins_loaded or earlier.', 'total-import' ),
			'0.2.0'
		);
	}

	private static function callbacks( string $hook ): int {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			return 0;
		}
		$count = 0;
		foreach ( (array) $wp_filter[ $hook ]->callbacks as $priority ) {
			$count += count( (array) $priority );
		}
		return $count;
	}

	public static function reset(): void {
		self::$targets  = null;
		self::$handlers = null;
		self::$built    = array();
		self::$warned   = array();
	}
}

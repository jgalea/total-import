<?php

namespace Importright\Field;

use Importright\Runs\Context;
use Importright\Runs\Diff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Non-variation product attributes:
 * {"attributes": {"Material": "{material}",
 *                 "pa_color": {"values": "{colors}", "separator": "|", "visible": "1", "create": "1"}}}
 *
 * A name that is a global attribute (pa_color, or a label/slug WooCommerce
 * knows) uses its taxonomy, creating missing terms unless create is "0";
 * anything else becomes a custom attribute on the product. Attributes the
 * mapping doesn't name are kept. An empty cell removes the attribute on
 * update. Update rules may list attribute names.
 */
final class AttributesHandler implements FieldHandler, Previewable {

	public function key(): string {
		return 'attributes';
	}

	public function supports( string $target ): bool {
		return 'product' === $target;
	}

	public function validate( array $config ) {
		$clean = array();
		foreach ( $config as $name => $settings ) {
			$name = trim( (string) $name );
			if ( is_scalar( $settings ) ) {
				$settings = array( 'values' => (string) $settings );
			}
			if ( '' === $name || ! is_array( $settings ) || ! is_scalar( $settings['values'] ?? null ) ) {
				return new WP_Error( 'importright_mapping', __( 'Each attribute needs a name and a values template.', 'total-import' ) );
			}
			$clean[ $name ] = array(
				'values'    => (string) $settings['values'],
				'separator' => (string) ( $settings['separator'] ?? '|' ),
				'visible'   => TaxonomiesHandler::flag( $settings['visible'] ?? true ),
				'create'    => TaxonomiesHandler::flag( $settings['create'] ?? true ),
			);
		}
		return $clean;
	}

	public function apply( string $target, int $object_id, array $values, bool $is_new, $rule, Context $context ) {
		$product = wc_get_product( $object_id );
		if ( ! $product instanceof \WC_Product ) {
			return new WP_Error( 'importright_attributes', __( 'WooCommerce could not load the product to set its attributes.', 'total-import' ) );
		}
		$attributes = $product->get_attributes( 'edit' );
		$position   = count( $attributes );

		foreach ( $values as $name => $settings ) {
			$name = (string) $name;
			if ( ! $is_new && ( false === $rule || ( is_array( $rule ) && ! in_array( $name, $rule, true ) ) ) ) {
				continue;
			}
			$settings = (array) $settings;
			$taxonomy = self::taxonomy( $name );
			if ( null === $taxonomy && 0 === strpos( $name, 'pa_' ) ) {
				/* translators: %s: attribute taxonomy */
				$context->notice( sprintf( __( 'There is no global attribute %s, so it was skipped.', 'total-import' ), $name ) );
				continue;
			}
			$key     = sanitize_title( null !== $taxonomy ? $taxonomy : $name );
			$options = TermPath::split( (string) ( $settings['values'] ?? '' ), (string) ( $settings['separator'] ?? '|' ) );

			if ( array() === $options ) {
				if ( ! $is_new ) {
					unset( $attributes[ $key ] );
				}
				continue;
			}

			$attribute = isset( $attributes[ $key ] ) && $attributes[ $key ] instanceof \WC_Product_Attribute ? $attributes[ $key ] : new \WC_Product_Attribute();
			if ( ! isset( $attributes[ $key ] ) ) {
				$attribute->set_position( $position++ );
			}
			if ( null !== $taxonomy ) {
				$ids = array();
				foreach ( $options as $option ) {
					$id = TaxonomiesHandler::resolve( $taxonomy, array( $option ), '1' === ( $settings['create'] ?? '1' ), $context );
					if ( null !== $id ) {
						$ids[] = $id;
					}
				}
				$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
				$attribute->set_name( $taxonomy );
				$attribute->set_options( $ids );
			} else {
				$attribute->set_id( 0 );
				$attribute->set_name( $name );
				$attribute->set_options( $options );
			}
			$attribute->set_visible( '1' === ( $settings['visible'] ?? '1' ) );
			$attribute->set_variation( false );
			$attributes[ $key ] = $attribute;
		}

		$product->set_attributes( $attributes );
		if ( array() !== $product->get_changes() ) {
			$product->save();
		}
		return true;
	}

	/**
	 * The registered taxonomy of a global attribute, or null for a custom one.
	 */
	private static function taxonomy( string $name ): ?string {
		if ( 0 === strpos( $name, 'pa_' ) ) {
			return taxonomy_exists( $name ) ? $name : null;
		}
		$taxonomy = wc_attribute_taxonomy_name( $name );
		if ( wc_attribute_taxonomy_id_by_name( $name ) > 0 && taxonomy_exists( $taxonomy ) ) {
			return $taxonomy;
		}
		return null;
	}

	public function preview( string $target, ?int $object_id, array $values, bool $is_new, $rule, Context $context, Diff $diff ): void {
		$product    = null === $object_id ? null : wc_get_product( $object_id );
		$attributes = $product instanceof \WC_Product ? $product->get_attributes( 'edit' ) : array();

		foreach ( $values as $name => $settings ) {
			$name = (string) $name;
			if ( ! $is_new && ( false === $rule || ( is_array( $rule ) && ! in_array( $name, $rule, true ) ) ) ) {
				continue;
			}
			$settings = (array) $settings;
			$taxonomy = self::taxonomy( $name );
			if ( null === $taxonomy && 0 === strpos( $name, 'pa_' ) ) {
				/* translators: %s: attribute taxonomy */
				$context->notice( sprintf( __( 'There is no global attribute %s, so it was skipped.', 'total-import' ), $name ) );
				continue;
			}
			$key     = sanitize_title( null !== $taxonomy ? $taxonomy : $name );
			$options = TermPath::split( (string) ( $settings['values'] ?? '' ), (string) ( $settings['separator'] ?? '|' ) );
			if ( array() === $options && $is_new ) {
				continue;
			}
			$old = null;
			if ( isset( $attributes[ $key ] ) && $attributes[ $key ] instanceof \WC_Product_Attribute ) {
				$old = $attributes[ $key ]->is_taxonomy() ? wc_get_product_terms( (int) $object_id, $attributes[ $key ]->get_name(), array( 'fields' => 'names' ) ) : $attributes[ $key ]->get_options();
				$old = (array) $old;
				sort( $old );
				$old = implode( ', ', $old );
			}
			sort( $options );
			$diff->add( 'attributes.' . $name, $old, array() === $options ? null : implode( ', ', $options ) );
		}
	}
}

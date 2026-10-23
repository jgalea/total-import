<?php

namespace Importright\Field;

use Importright\Runs\Context;
use Importright\Runs\Diff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * SEO title and description for Yoast SEO and Rank Math, written as the
 * plain post meta each plugin reads:
 * {"seo": {"title": "{seo_title}", "description": "{seo_desc}", "plugins": "auto"}}
 *
 * "auto" writes for whichever of the two is active; a list such as
 * "yoast,rankmath" writes for those even when they're inactive (for a site
 * about to switch). Update rules may list "title" and "description".
 */
final class SeoHandler implements FieldHandler, Previewable, OwnsMeta {

	public const PLUGINS = array( 'yoast', 'rankmath' );

	private const KEYS = array(
		'yoast'    => array(
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
		),
		'rankmath' => array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
		),
	);

	public function key(): string {
		return 'seo';
	}

	public function supports( string $target ): bool {
		return in_array( $target, array( 'post', 'product' ), true );
	}

	public function owned_meta( string $target ): array {
		return $this->supports( $target ) ? array_merge( ...array_map( 'array_values', array_values( self::KEYS ) ) ) : array();
	}

	public function validate( array $config ) {
		$plugins = strtolower( trim( (string) ( $config['plugins'] ?? 'auto' ) ) );
		if ( '' === $plugins ) {
			$plugins = 'auto';
		}
		if ( 'auto' !== $plugins ) {
			$list = array_filter( array_map( 'trim', explode( ',', $plugins ) ), 'strlen' );
			if ( array() === $list || array() !== array_diff( $list, self::PLUGINS ) ) {
				return new WP_Error( 'importright_mapping', __( 'SEO plugins must be "auto" or a list of yoast and rankmath.', 'total-import' ) );
			}
			$plugins = implode( ',', $list );
		}
		$clean = array( 'plugins' => $plugins );
		foreach ( array( 'title', 'description' ) as $field ) {
			if ( isset( $config[ $field ] ) ) {
				if ( ! is_scalar( $config[ $field ] ) ) {
					return new WP_Error( 'importright_mapping', __( 'SEO title and description must be templates.', 'total-import' ) );
				}
				$clean[ $field ] = (string) $config[ $field ];
			}
		}
		return $clean;
	}

	public function apply( string $target, int $object_id, array $values, bool $is_new, $rule, Context $context ) {
		$plugins = self::plugins( (string) ( $values['plugins'] ?? 'auto' ) );
		foreach ( array( 'title', 'description' ) as $field ) {
			if ( ! isset( $values[ $field ] ) ) {
				continue;
			}
			if ( ! $is_new && ( false === $rule || ( is_array( $rule ) && ! in_array( $field, $rule, true ) ) ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $values[ $field ] );
			if ( $is_new && '' === $value ) {
				continue;
			}
			foreach ( $plugins as $plugin ) {
				update_post_meta( $object_id, self::KEYS[ $plugin ][ $field ], wp_slash( $value ) );
			}
		}
		return true;
	}

	/**
	 * @return string[]
	 */
	public static function plugins( string $setting ): array {
		if ( 'auto' !== $setting ) {
			return array_values( array_intersect( self::PLUGINS, explode( ',', $setting ) ) );
		}
		$active = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$active[] = 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
			$active[] = 'rankmath';
		}
		return $active;
	}

	public function preview( string $target, ?int $object_id, array $values, bool $is_new, $rule, Context $context, Diff $diff ): void {
		$plugins = self::plugins( (string) ( $values['plugins'] ?? 'auto' ) );
		foreach ( array( 'title', 'description' ) as $field ) {
			if ( ! isset( $values[ $field ] ) ) {
				continue;
			}
			if ( ! $is_new && ( false === $rule || ( is_array( $rule ) && ! in_array( $field, $rule, true ) ) ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $values[ $field ] );
			if ( $is_new && '' === $value ) {
				continue;
			}
			foreach ( $plugins as $plugin ) {
				$meta_key = self::KEYS[ $plugin ][ $field ];
				$old      = null !== $object_id && metadata_exists( 'post', $object_id, $meta_key ) ? (string) get_post_meta( $object_id, $meta_key, true ) : null;
				$diff->add( 'seo.' . $meta_key, $old, $value );
			}
		}
	}
}

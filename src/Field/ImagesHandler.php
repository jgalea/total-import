<?php

namespace Importright\Field;

use Importright\Media\Library;
use Importright\Runs\Context;
use Importright\Runs\Diff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Featured image and gallery:
 * {"images": {"featured": {"source": "{image}", "alt": "{alt}", "title": "", "caption": ""},
 *             "gallery":  {"source": "{gallery}", "separator": "|", "alt": "", "meta_key": "_importright_gallery"},
 *             "match_filename": "1"}}
 *
 * A source is a URL, a Media Library file name or an attachment ID. Alt, title
 * and caption are set on attachments this import downloads; for a gallery they
 * may hold one value per image, split by the same separator. Products store
 * the gallery in WooCommerce's own field; other posts in meta_key as a comma
 * separated ID list. With match_filename "0", a URL is only matched to an
 * attachment downloaded from the same URL. Update rules may list "featured"
 * and "gallery".
 */
final class ImagesHandler implements FieldHandler, Previewable {

	public const DEFAULT_GALLERY_META = '_importright_gallery';

	public function key(): string {
		return 'images';
	}

	public function supports( string $target ): bool {
		return in_array( $target, array( 'post', 'product' ), true );
	}

	public function validate( array $config ) {
		$clean = array( 'match_filename' => TaxonomiesHandler::flag( $config['match_filename'] ?? true ) );
		foreach ( array( 'featured', 'gallery' ) as $part ) {
			if ( ! isset( $config[ $part ] ) ) {
				continue;
			}
			$settings = is_scalar( $config[ $part ] ) ? array( 'source' => (string) $config[ $part ] ) : $config[ $part ];
			if ( ! is_array( $settings ) || ! is_scalar( $settings['source'] ?? null ) ) {
				/* translators: %s: featured or gallery */
				return new WP_Error( 'importright_mapping', sprintf( __( 'The %s image mapping needs a source template.', 'total-import' ), $part ) );
			}
			$clean[ $part ] = array(
				'source'  => (string) $settings['source'],
				'alt'     => (string) ( $settings['alt'] ?? '' ),
				'title'   => (string) ( $settings['title'] ?? '' ),
				'caption' => (string) ( $settings['caption'] ?? '' ),
			);
			if ( 'gallery' === $part ) {
				$meta_key = trim( (string) ( $settings['meta_key'] ?? self::DEFAULT_GALLERY_META ) );
				if ( '' === $meta_key ) {
					return new WP_Error( 'importright_mapping', __( 'The gallery needs a meta key to store its image IDs in.', 'total-import' ) );
				}
				$clean[ $part ]['separator'] = (string) ( $settings['separator'] ?? '|' );
				$clean[ $part ]['meta_key']  = $meta_key; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a settings array key, not a query.
			}
		}
		return $clean;
	}

	public function apply( string $target, int $object_id, array $values, bool $is_new, $rule, Context $context ) {
		$options = array( 'match_filename' => '1' === ( $values['match_filename'] ?? '1' ) );
		$product = 'product' === $target && function_exists( 'wc_get_product' ) ? wc_get_product( $object_id ) : null;

		if ( isset( $values['featured'] ) && self::allowed( 'featured', $is_new, $rule ) ) {
			$featured = (array) $values['featured'];
			$source   = trim( (string) ( $featured['source'] ?? '' ) );
			$image_id = 0;
			if ( '' !== $source ) {
				$found = Library::attachment( $source, $object_id, $options + self::details( $featured, 0, 1 ), $context );
				if ( is_wp_error( $found ) ) {
					$context->notice( $found->get_error_message() );
				} else {
					$image_id = $found;
				}
			}
			if ( $image_id > 0 || ( '' === $source && ! $is_new ) ) {
				if ( $product instanceof \WC_Product ) {
					$product->set_image_id( $image_id );
				} elseif ( $image_id > 0 ) {
					set_post_thumbnail( $object_id, $image_id );
				} else {
					delete_post_thumbnail( $object_id );
				}
			}
		}

		if ( isset( $values['gallery'] ) && self::allowed( 'gallery', $is_new, $rule ) ) {
			$gallery = (array) $values['gallery'];
			$sources = TermPath::split( (string) ( $gallery['source'] ?? '' ), (string) ( $gallery['separator'] ?? '|' ) );
			$ids     = array();
			foreach ( $sources as $position => $source ) {
				$found = Library::attachment( $source, $object_id, $options + self::details( $gallery, $position, count( $sources ) ), $context );
				if ( is_wp_error( $found ) ) {
					$context->notice( $found->get_error_message() );
				} else {
					$ids[] = $found;
				}
			}
			$ids = array_values( array_unique( $ids ) );
			if ( array() !== $ids || ( array() === $sources && ! $is_new ) ) {
				if ( $product instanceof \WC_Product ) {
					$product->set_gallery_image_ids( $ids );
				} elseif ( array() === $ids ) {
					delete_post_meta( $object_id, (string) $gallery['meta_key'] );
				} else {
					update_post_meta( $object_id, wp_slash( (string) $gallery['meta_key'] ), implode( ',', $ids ) );
				}
			}
		}

		if ( $product instanceof \WC_Product && array() !== $product->get_changes() ) {
			$product->save();
		}
		return true;
	}

	/**
	 * @param bool|string[] $rule
	 */
	private static function allowed( string $part, bool $is_new, $rule ): bool {
		return $is_new || true === $rule || ( is_array( $rule ) && in_array( $part, $rule, true ) );
	}

	/**
	 * Alt, title and caption for the image at $position: one value each, or one per image when the counts line up.
	 *
	 * @param array<string, mixed> $settings
	 * @return array{alt: string, title: string, caption: string}
	 */
	private static function details( array $settings, int $position, int $count ): array {
		$separator = (string) ( $settings['separator'] ?? '' );
		$details   = array();
		foreach ( array( 'alt', 'title', 'caption' ) as $field ) {
			$value = trim( (string) ( $settings[ $field ] ?? '' ) );
			if ( $count > 1 && '' !== $separator ) {
				$parts = array_map( 'trim', explode( $separator, $value ) );
				if ( count( $parts ) === $count ) {
					$value = $parts[ $position ];
				}
			}
			$details[ $field ] = $value;
		}
		return $details;
	}

	public function preview( string $target, ?int $object_id, array $values, bool $is_new, $rule, Context $context, Diff $diff ): void {
		$match = '1' === ( $values['match_filename'] ?? '1' );

		if ( isset( $values['featured'] ) && self::allowed( 'featured', $is_new, $rule ) ) {
			$source  = trim( (string) ( ( (array) $values['featured'] )['source'] ?? '' ) );
			$current = null === $object_id ? 0 : (int) get_post_meta( $object_id, '_thumbnail_id', true );
			$new     = null;
			if ( '' !== $source ) {
				$new = self::would_use( $source, $match, $context );
			}
			if ( null !== $new || ( '' === $source && ! $is_new ) ) {
				$diff->add( 'images.featured', $current > 0 ? (string) $current : null, $new );
			}
		}

		if ( isset( $values['gallery'] ) && self::allowed( 'gallery', $is_new, $rule ) ) {
			$gallery = (array) $values['gallery'];
			$sources = TermPath::split( (string) ( $gallery['source'] ?? '' ), (string) ( $gallery['separator'] ?? '|' ) );
			$new     = array();
			foreach ( $sources as $source ) {
				$use = self::would_use( $source, $match, $context );
				if ( null !== $use ) {
					$new[] = $use;
				}
			}
			$meta_key = 'product' === $target ? '_product_image_gallery' : (string) ( $gallery['meta_key'] ?? self::DEFAULT_GALLERY_META );
			$current  = null === $object_id ? '' : (string) get_post_meta( $object_id, $meta_key, true );
			if ( array() !== $new || ( array() === $sources && ! $is_new ) ) {
				$diff->add( 'images.gallery', '' === $current ? null : str_replace( ',', ', ', $current ), array() === $new ? null : implode( ', ', array_unique( $new ) ) );
			}
		}
	}

	/**
	 * The attachment ID a source resolves to, or "download <url>" for one that would be fetched.
	 */
	private static function would_use( string $source, bool $match_filename, Context $context ): ?string {
		$found = Library::lookup( $source, $match_filename, $context );
		if ( is_wp_error( $found ) ) {
			$context->notice( $found->get_error_message() );
			return null;
		}
		if ( null !== $found['id'] ) {
			return (string) $found['id'];
		}
		$context->would_download( (string) $found['download'] );
		/* translators: %s: image URL */
		return sprintf( __( 'download %s', 'total-import' ), (string) $found['download'] );
	}
}

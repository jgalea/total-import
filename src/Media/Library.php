<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- lookups over indexed meta keys; results are cached per run by the caller.

namespace Importright\Media;

use Importright\Runs\Context;
use Importright\Settings;
use Importright\Source\Storage;
use Importright\Source\UrlFetcher;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an image reference into an attachment ID. Existing attachments are
 * reused: by ID, by the source URL a previous import stored on the
 * attachment, or by file name. Downloads go through UrlFetcher (public
 * addresses only, pinned connections), are capped in size, and must be a
 * real JPEG, PNG, GIF, WebP or AVIF image.
 */
final class Library {

	public const SOURCE_META = '_importright_source_url';

	private const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
	);

	/**
	 * @param array{match_filename?: bool, title?: string, caption?: string, alt?: string} $options
	 * @return int|WP_Error
	 */
	public static function attachment( string $reference, int $post_id, array $options, Context $context ) {
		$source = ImageSource::classify( $reference );
		if ( null === $source ) {
			/* translators: %s: image reference from the file */
			return new WP_Error( 'importright_image', sprintf( __( 'Image "%s" is not a URL, file name or attachment ID.', 'total-import' ), $reference ) );
		}
		$match_filename = (bool) ( $options['match_filename'] ?? true );

		$found = self::existing( $source, $match_filename, $context );
		if ( null !== $found ) {
			return $found;
		}

		if ( ImageSource::URL !== $source['type'] ) {
			/* translators: %s: image reference from the file */
			return new WP_Error( 'importright_image', sprintf( __( 'No image in the Media Library matches "%s".', 'total-import' ), $reference ) );
		}

		$id = self::download( $source, $post_id, $options, $context );
		if ( ! is_wp_error( $id ) ) {
			$context->remember(
				'image',
				$source['type'] . '|' . $source['key'] . '|' . (int) $match_filename,
				static function () use ( $id ): int {
					return $id;
				}
			);
		}
		return $id;
	}

	/**
	 * What attachment() would do, without downloading: the existing attachment, or the URL it would fetch.
	 *
	 * @return array{id: int|null, download: string|null}|WP_Error
	 */
	public static function lookup( string $reference, bool $match_filename, Context $context ) {
		$source = ImageSource::classify( $reference );
		if ( null === $source ) {
			/* translators: %s: image reference from the file */
			return new WP_Error( 'importright_image', sprintf( __( 'Image "%s" is not a URL, file name or attachment ID.', 'total-import' ), $reference ) );
		}
		$found = self::existing( $source, $match_filename, $context );
		if ( null !== $found ) {
			return array(
				'id'       => $found,
				'download' => null,
			);
		}
		if ( ImageSource::URL !== $source['type'] ) {
			/* translators: %s: image reference from the file */
			return new WP_Error( 'importright_image', sprintf( __( 'No image in the Media Library matches "%s".', 'total-import' ), $reference ) );
		}
		return array(
			'id'       => null,
			'download' => $source['value'],
		);
	}

	/**
	 * @param array{type: string, value: string, key: string, filename: string} $source
	 */
	private static function existing( array $source, bool $match_filename, Context $context ): ?int {
		return $context->remember(
			'image',
			$source['type'] . '|' . $source['key'] . '|' . (int) $match_filename,
			static function () use ( $source, $match_filename ): ?int {
				if ( ImageSource::ID === $source['type'] ) {
					return self::is_image( (int) $source['value'] ) ? (int) $source['value'] : null;
				}
				if ( ImageSource::URL === $source['type'] ) {
					$id = self::find_by_url( $source['key'] );
					if ( null === $id && $match_filename && '' !== $source['filename'] ) {
						$id = self::find_by_filename( $source['filename'] );
					}
					return $id;
				}
				return self::find_by_filename( $source['filename'] );
			},
			false
		);
	}

	public static function find_by_url( string $key ): ?int {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = 'attachment' ORDER BY m.post_id LIMIT 1", self::SOURCE_META, $key ) );
		return $id > 0 ? $id : null;
	}

	public static function find_by_filename( string $name ): ?int {
		global $wpdb;
		$name = sanitize_file_name( $name );
		if ( '' === $name ) {
			return null;
		}
		$dot    = strrpos( $name, '.' );
		$scaled = false === $dot ? $name . '-scaled' : substr( $name, 0, $dot ) . '-scaled' . substr( $name, $dot );
		$id     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = '_wp_attached_file' AND p.post_type = 'attachment'
				AND ( m.meta_value = %s OR m.meta_value LIKE %s OR m.meta_value = %s OR m.meta_value LIKE %s )
				ORDER BY m.post_id LIMIT 1",
				$name,
				'%/' . $wpdb->esc_like( $name ),
				$scaled,
				'%/' . $wpdb->esc_like( $scaled )
			)
		);
		return $id > 0 ? $id : null;
	}

	private static function is_image( int $id ): bool {
		$post = get_post( $id );
		return $post instanceof \WP_Post && 'attachment' === $post->post_type && wp_attachment_is_image( $id );
	}

	/**
	 * @param array{type: string, value: string, key: string, filename: string} $source
	 * @param array<string, mixed>                                               $options
	 * @return int|WP_Error
	 */
	private static function download( array $source, int $post_id, array $options, Context $context ) {
		$path = Storage::work_path( $context->run_id, 'img' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$max    = (int) apply_filters( 'importright_max_image_bytes', (int) Settings::get( 'max_image_mb' ) * MB_IN_BYTES );
		$result = UrlFetcher::download( array( 'url' => $source['value'] ), $path, max( 1, $max ) );
		if ( is_wp_error( $result ) ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			/* translators: 1: image URL, 2: error message */
			return new WP_Error( 'importright_image', sprintf( __( 'Image %1$s: %2$s', 'total-import' ), $source['value'], $result->get_error_message() ) );
		}

		$name = self::file_name( '' !== $result['name'] ? $result['name'] : $source['filename'], $result['type'] );
		$mime = self::mimes();
		$file = wp_check_filetype_and_ext( $path, $name, $mime );
		if ( ! empty( $file['proper_filename'] ) ) {
			$name = (string) $file['proper_filename'];
		}
		$real = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $path ) : false;
		if ( empty( $file['type'] ) || ! in_array( $file['type'], $mime, true ) || $real !== $file['type'] ) {
			wp_delete_file( $path );
			/* translators: %s: image URL */
			return new WP_Error( 'importright_image', sprintf( __( 'Image %s is not a JPEG, PNG, GIF, WebP or AVIF file.', 'total-import' ), $source['value'] ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$post_data = array();
		if ( '' !== (string) ( $options['title'] ?? '' ) ) {
			$post_data['post_title'] = (string) $options['title'];
		}
		if ( '' !== (string) ( $options['caption'] ?? '' ) ) {
			$post_data['post_excerpt'] = (string) $options['caption'];
		}

		$mimes = static function () use ( $mime ): array {
			return $mime;
		};
		add_filter( 'upload_mimes', $mimes, 999 );
		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $path,
			),
			$post_id,
			null,
			wp_slash( $post_data )
		);
		remove_filter( 'upload_mimes', $mimes, 999 );

		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
		if ( is_wp_error( $id ) ) {
			/* translators: 1: image URL, 2: error message */
			return new WP_Error( 'importright_image', sprintf( __( 'Image %1$s: %2$s', 'total-import' ), $source['value'], $id->get_error_message() ) );
		}

		$context->added( 'attachment', (int) $id );
		update_post_meta( $id, self::SOURCE_META, wp_slash( $source['key'] ) );
		if ( '' !== (string) ( $options['alt'] ?? '' ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( (string) $options['alt'] ) ) );
		}
		return (int) $id;
	}

	/**
	 * @return array<string, string>
	 */
	private static function mimes(): array {
		$mimes = self::MIMES;
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			unset( $mimes['avif'] );
		}
		return $mimes;
	}

	/**
	 * Gives the download a file name with an image extension, taken from the
	 * content type when the URL has none.
	 */
	private static function file_name( string $name, string $content_type ): string {
		$name = sanitize_file_name( '' !== $name ? $name : 'image' );
		if ( preg_match( '/\.(jpe?g|jpe|png|gif|webp|avif)$/i', $name ) ) {
			return $name;
		}
		$extensions = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);
		return $name . '.' . ( $extensions[ $content_type ] ?? 'jpg' );
	}
}

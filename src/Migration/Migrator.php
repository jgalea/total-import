<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- temporary working files outside the web root.

namespace Importright\Migration;

use Importright\Admin\Inspector;
use Importright\Imports\Imports;
use Importright\Reader\Formats;
use Importright\Reader\ReaderException;
use Importright\Reader\Unpacker;
use Importright\Registry;
use Importright\Source\PathGuard;
use Importright\Source\Sources;
use Importright\Source\Storage;
use Importright\Source\UrlFetcher;
use Importright\Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Migrates WP All Import settings into Total Import imports. The WP-CLI command
 * and the admin screen both call this.
 *
 * Every method returns a result array:
 *   definition  the Total Import definition (null when the import type isn't supported)
 *   report      a Report of mapped, approximated and unsupported settings
 *   import_id   the new import's ID, or null on a dry run
 *   tracking    for site imports: {available, read, copied, empty, duplicates, skipped_reason}
 */
final class Migrator {

	public const MIGRATIONS_OPTION = 'importright_wpai_migrations';

	/**
	 * Converts a template export (.txt) or WP All Export bundle (.zip).
	 *
	 * @param array<string, mixed> $args dry_run (bool), index (which template in the file, default 0),
	 *                                   data_file (path to the data file, overrides the bundle's), name.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function from_template( string $file, array $args = array() ) {
		try {
			$rows = TemplateFile::read( $file, (int) apply_filters( 'importright_max_template_bytes', TemplateFile::DEFAULT_MAX_BYTES ) );
		} catch ( InvalidTemplate $e ) {
			return new WP_Error( 'importright_migration', $e->getMessage() );
		}
		$index = (int) ( $args['index'] ?? 0 );
		if ( ! isset( $rows[ $index ] ) ) {
			/* translators: %d: number of templates */
			return new WP_Error( 'importright_migration', sprintf( __( 'The file holds %d templates; pick one by its position, starting at 0.', 'total-import' ), count( $rows ) ) );
		}
		$row     = $rows[ $index ];
		$dry_run = ! empty( $args['dry_run'] );

		$data   = null;
		$source = null;
		$temp   = null;
		if ( ! empty( $args['data_file'] ) ) {
			$path = realpath( (string) $args['data_file'] );
			if ( false === $path || ! is_file( $path ) ) {
				return new WP_Error( 'importright_migration', __( 'The data file can\'t be read.', 'total-import' ) );
			}
			$data = array(
				'path' => $path,
				'name' => basename( $path ),
			);
		} elseif ( TemplateFile::is_zip( $file ) ) {
			$temp = wp_tempnam( 'importright-bundle' );
			try {
				$ext  = TemplateFile::extract_data( $file, $temp, (int) apply_filters( 'importright_max_unpacked_bytes', Sources::DEFAULT_MAX_UNPACKED_BYTES ) );
				$data = array(
					'path' => $temp,
					'name' => 'bundle-data.' . ( '' === $ext ? 'csv' : $ext ),
				);
			} catch ( InvalidTemplate $e ) {
				wp_delete_file( $temp );
				$temp = null;
			}
		}
		if ( null === $data && 'url' === $row['import_type'] && '' !== $row['import_url'] ) {
			$source = array(
				'type' => 'url',
				'url'  => $row['import_url'],
			);
		}

		$env    = self::environment( null === $data ? '' : Unpacker::extension( $data['name'] ), null === $data ? null : $data['path'] );
		$result = self::convert( $row['options'], (string) ( $args['name'] ?? $row['name'] ), $env );

		if ( null === $result['definition'] || $dry_run ) {
			self::cleanup( $temp );
			return $result;
		}
		if ( null !== $data ) {
			$stored = Storage::store_upload( $data['path'], $data['name'], null !== $temp );
			if ( is_wp_error( $stored ) ) {
				self::cleanup( $temp );
				return $stored;
			}
			$temp   = null;
			$source = array(
				'type'          => 'upload',
				'file'          => $stored,
				'original_name' => $data['name'],
			);
		}
		if ( null === $source ) {
			return new WP_Error( 'importright_migration', __( 'The template has no data file. Pass the file the import should read.', 'total-import' ) );
		}
		return self::save( $result, $source );
	}

	/**
	 * Converts one of WP All Import's imports on this site, including its source,
	 * and carries over its record list so the first run updates its posts.
	 *
	 * @param array<string, mixed> $args dry_run (bool), data_file (path that replaces the import's source).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function from_site( int $wpai_id, array $args = array(), ?WpaiSite $site = null ) {
		$site = $site ?? new WpaiSite();
		if ( ! $site->available() ) {
			return new WP_Error( 'importright_migration', __( 'WP All Import\'s tables aren\'t on this site.', 'total-import' ) );
		}
		try {
			$import = $site->import( $wpai_id );
		} catch ( InvalidTemplate $e ) {
			return new WP_Error( 'importright_migration', $e->getMessage() );
		}
		if ( null === $import ) {
			return new WP_Error( 'importright_migration', __( 'WP All Import has no import with that ID.', 'total-import' ) );
		}
		$dry_run = ! empty( $args['dry_run'] );

		$uploads = wp_upload_dir( null, false );
		$source  = self::site_source( $import, (string) ( $args['data_file'] ?? '' ), (string) $uploads['basedir'], PathGuard::roots(), Inspector::can_use_paths() );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$sample = self::sample_file( $source, $import['feed_type'] );
		$env    = self::environment( $sample['ext'], $sample['path'], $import['root_element'] );
		self::cleanup( $sample['temp'] );
		$env['xpath']        = $import['xpath'];
		$env['root_element'] = $import['root_element'];
		if ( '' === $env['feed'] && in_array( $import['feed_type'], array( 'csv', 'xml' ), true ) ) {
			$env['feed'] = $import['feed_type'];
		}

		$result             = self::convert( $import['options'], $import['name'], $env );
		$result['tracking'] = array(
			'available'      => $site->tracked_count( $wpai_id ),
			'read'           => 0,
			'copied'         => 0,
			'empty'          => 0,
			'duplicates'     => 0,
			'skipped_reason' => $result['tracking'] ? '' : __( 'The unique key couldn\'t be converted exactly, so WP All Import\'s record list wasn\'t copied.', 'total-import' ),
		);
		if ( null !== $sample['error'] ) {
			$result['report']->add( Report::APPROXIMATED, 'source', 'path', $import['path'], null, $sample['error'] );
		}
		$previous = (array) Store::get( self::MIGRATIONS_OPTION, array() );
		if ( isset( $previous[ $wpai_id ] ) ) {
			/* translators: %d: import ID */
			$result['report']->add( Report::APPROXIMATED, '', 'id', $wpai_id, null, sprintf( __( 'This import was migrated before, to import %d. A second copy would update the same posts.', 'total-import' ), (int) $previous[ $wpai_id ] ) );
		}

		if ( null === $result['definition'] || $dry_run ) {
			return $result;
		}

		$saved = self::save( $result, $source );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( '' === $result['tracking']['skipped_reason'] ) {
			$target            = Registry::target( (string) $saved['definition']['target']['type'] );
			$copy              = $site->copy_tracking( $wpai_id, (int) $saved['import_id'], null === $target ? 'post' : $target->object_type() );
			$saved['tracking'] = array_merge( $saved['tracking'], $copy );
		}
		$previous[ $wpai_id ] = (int) $saved['import_id'];
		Store::set( self::MIGRATIONS_OPTION, $previous );
		return $saved;
	}

	/**
	 * @return array<int, int> WP All Import import ID => Total Import import ID.
	 */
	public static function migrated(): array {
		return array_map( 'intval', (array) Store::get( self::MIGRATIONS_OPTION, array() ) );
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, mixed> $env
	 * @return array<string, mixed>
	 */
	private static function convert( array $options, string $name, array $env ): array {
		$converted = ( new Converter( $env ) )->convert( $options, $name );
		$result    = array(
			'definition' => $converted['definition'],
			'report'     => $converted['report'],
			'import_id'  => null,
			'tracking'   => $converted['tracking'],
		);
		if ( null !== $result['definition'] ) {
			$result['definition'] = self::fit_to_site( $result['definition'], $result['report'] );
		}
		return $result;
	}

	/**
	 * Drops what this site can't hold: unregistered taxonomies and post types.
	 *
	 * @param array<string, mixed> $definition
	 * @return array<string, mixed>
	 */
	private static function fit_to_site( array $definition, Report $report ): array {
		foreach ( array_keys( (array) ( $definition['mapping']['taxonomies'] ?? array() ) ) as $taxonomy ) {
			if ( ! taxonomy_exists( (string) $taxonomy ) ) {
				$report->add( Report::UNSUPPORTED, '', 'tax_logic[' . $taxonomy . ']', $definition['mapping']['taxonomies'][ $taxonomy ]['terms'], null, __( 'This taxonomy isn\'t registered on this site.', 'total-import' ) );
				unset( $definition['mapping']['taxonomies'][ $taxonomy ] );
				if ( is_array( $definition['update']['taxonomies'] ?? null ) ) {
					$definition['update']['taxonomies'] = array_values( array_diff( $definition['update']['taxonomies'], array( $taxonomy ) ) );
				}
			}
		}
		if ( array() === ( $definition['mapping']['taxonomies'] ?? null ) ) {
			unset( $definition['mapping']['taxonomies'] );
		}
		if ( 'post' === ( $definition['target']['type'] ?? '' ) && ! post_type_exists( (string) $definition['target']['post_type'] ) ) {
			$report->add( Report::UNSUPPORTED, 'target.post_type', 'custom_type', $definition['target']['post_type'], null, __( 'This post type isn\'t registered on this site; activate the plugin that adds it before saving.', 'total-import' ) );
		}
		return $definition;
	}

	/**
	 * @param array<string, mixed>  $result
	 * @param array<string, string> $source
	 * @return array<string, mixed>|WP_Error
	 */
	private static function save( array $result, array $source ) {
		$definition           = $result['definition'];
		$definition['source'] = $source;
		$id                   = Imports::create( $definition );
		if ( is_wp_error( $id ) ) {
			if ( 'upload' === $source['type'] ) {
				Storage::delete_upload( $source['file'] );
			}
			return $id;
		}
		$saved                = Imports::get( $id );
		$result['import_id']  = $id;
		$result['definition'] = null === $saved ? $definition : $saved['definition'];
		return $result;
	}

	/**
	 * The Total Import source for a WP All Import import. A file on the server goes through the
	 * same rules as any server-path source (PathGuard; WP All Import's own files must sit in
	 * uploads) and needs the right to use server paths, before anything reads it.
	 *
	 * @param array<string, mixed> $import
	 * @param string               $data_file     Path that replaces the import's source, or ''.
	 * @param string               $uploads_dir   Uploads base folder WP All Import's paths are relative to.
	 * @param string[]             $roots         Folders a data file may come from (PathGuard::roots()).
	 * @param bool                 $can_use_paths Whether the user may import from server paths.
	 * @return array<string, string>|WP_Error
	 */
	public static function site_source( array $import, string $data_file, string $uploads_dir, array $roots, bool $can_use_paths ) {
		$type = (string) $import['type'];
		if ( ( '' !== $data_file || in_array( $type, array( 'upload', 'file' ), true ) ) && ! $can_use_paths ) {
			return new WP_Error( 'importright_source', __( 'Only network administrators can import files from a server path.', 'total-import' ) );
		}

		if ( '' !== $data_file ) {
			$path = PathGuard::resolve( $data_file, $roots );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
			return array(
				'type' => 'path',
				'path' => $path,
			);
		}

		$path = trim( (string) $import['path'] );
		switch ( $type ) {
			case 'url':
				if ( false !== strpbrk( $path, '{}[]' ) ) {
					return new WP_Error( 'importright_migration', __( 'The import\'s URL is built from a template or PHP. Pass the data file, or create the import with a fixed URL.', 'total-import' ) );
				}
				return array(
					'type' => 'url',
					'url'  => $path,
				);
			case 'upload':
			case 'file':
				$basedir = rtrim( $uploads_dir, '/\\' );
				$full    = 0 === strpos( $path, $basedir . '/' ) ? $path : $basedir . '/' . ltrim( $path, '/\\' );
				$real    = PathGuard::resolve( $full, array( $basedir ) );
				if ( is_wp_error( $real ) ) {
					/* translators: %s: why the path was refused */
					return new WP_Error( 'importright_migration', sprintf( __( 'The file WP All Import read can\'t be used: %s Pass the data file.', 'total-import' ), $real->get_error_message() ) );
				}
				return array(
					'type' => 'path',
					'path' => $real,
				);
		}
		/* translators: %s: source type such as ftp */
		return new WP_Error( 'importright_migration', sprintf( __( 'Sources of type "%s" aren\'t supported. Pass the data file.', 'total-import' ), $type ) );
	}

	/**
	 * A local, unpacked copy of the source to read column names from. URL sources are downloaded
	 * once (through the same public-address checks as a run) unless WP All Import said they're XML.
	 *
	 * @param array<string, string> $source
	 * @return array{path: string|null, ext: string, temp: string[], error: string|null}
	 */
	private static function sample_file( array $source, string $feed_type ): array {
		$result = array(
			'path'  => null,
			'ext'   => '',
			'temp'  => array(),
			'error' => null,
		);
		if ( 'path' === $source['type'] ) {
			$path = $source['path'];
			$name = basename( $path );
		} else {
			if ( 'xml' === $feed_type ) {
				return $result;
			}
			$path             = wp_tempnam( 'importright-sample' );
			$got              = UrlFetcher::download( $source, $path );
			$result['temp'][] = $path;
			if ( is_wp_error( $got ) ) {
				$result['error'] = $got->get_error_message();
				return $result;
			}
			$name = $got['name'];
		}

		$ext       = Unpacker::extension( $name );
		$container = Unpacker::container( $path );
		if ( 'gzip' === $container || 'zip' === $container ) {
			$plain            = wp_tempnam( 'importright-sample' );
			$result['temp'][] = $plain;
			try {
				$ext  = Unpacker::unpack( $path, $plain, (int) apply_filters( 'importright_max_unpacked_bytes', Sources::DEFAULT_MAX_UNPACKED_BYTES ), $name );
				$path = $plain;
			} catch ( ReaderException $e ) {
				$result['error'] = $e->getMessage();
				return $result;
			}
		} elseif ( 'xlsx' === $container ) {
			$ext = 'xlsx';
		}
		$result['path'] = $path;
		$result['ext']  = $ext;
		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function environment( string $ext, ?string $path, string $record_path = '' ): array {
		$env = array(
			'product_target' => null !== Registry::target( 'product' ),
			'feed'           => '',
			'columns'        => null,
			'sample'         => null,
		);
		if ( null === $path ) {
			return $env;
		}
		try {
			$feed    = Formats::detect( $path, 'auto', $ext );
			$reader  = Formats::open(
				$path,
				array(
					'type'        => $feed,
					'record_path' => $record_path,
					'cache_dir'   => null,
				),
				$ext
			);
			$columns = $reader->columns();
			if ( 'xml' === $feed ) {
				foreach ( $reader->records() as $record ) {
					$columns = array_keys( $record );
					break;
				}
			}
			$reader->close();
		} catch ( ReaderException $e ) {
			return $env;
		}
		$env['feed'] = $feed;
		if ( 'xml' === $feed || 'json' === $feed ) {
			$env['sample'] = array_map( 'strval', $columns );
		} else {
			$env['columns'] = array_map( 'strval', $columns );
		}
		return $env;
	}

	/**
	 * @param string|string[]|null $paths
	 */
	private static function cleanup( $paths ): void {
		foreach ( (array) $paths as $path ) {
			if ( is_string( $path ) && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}
}

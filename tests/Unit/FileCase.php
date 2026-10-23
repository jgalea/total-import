<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- tests work on temporary files.

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Reader\Reader;

abstract class FileCase extends TestCase {

	/**
	 * @var string[]
	 */
	private array $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	protected function file( string $contents, string $suffix = '' ): string {
		$path          = tempnam( sys_get_temp_dir(), 'importright' ) . $suffix;
		$this->files[] = $path;
		file_put_contents( $path, $contents );
		return $path;
	}

	protected function path( string $suffix = '' ): string {
		$path          = tempnam( sys_get_temp_dir(), 'importright' ) . $suffix;
		$this->files[] = $path;
		return $path;
	}

	/**
	 * @param array<string, string> $entries
	 */
	protected function zip( array $entries, string $suffix = '.zip' ): string {
		$path = $this->path( $suffix );
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();
		return $path;
	}

	/**
	 * @param array<string, int|string>|null $cursor
	 * @return array<int, array<string, string>>
	 */
	protected function read_all( Reader $reader, ?array $cursor = null ): array {
		$records = array();
		foreach ( $reader->records( $cursor ) as $index => $record ) {
			$records[ $index ] = $record;
		}
		return $records;
	}

	/**
	 * Reads $count records, returning them and the cursor after the last.
	 *
	 * @return array{0: array<int, array<string, string>>, 1: array<string, int|string>}
	 */
	protected function read_some( Reader $reader, int $count ): array {
		$records = array();
		$cursor  = array();
		foreach ( $reader->records() as $index => $record ) {
			$records[ $index ] = $record;
			$cursor            = $reader->cursor();
			if ( count( $records ) >= $count ) {
				break;
			}
		}
		return array( $records, $cursor );
	}
}

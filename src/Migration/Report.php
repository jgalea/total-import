<?php

namespace Importright\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * What a migration did with each WP All Import setting: mapped (same result),
 * approximated (close, check it) or unsupported (dropped). Entries keep the
 * original WP All Import value so a UI can show both sides.
 */
final class Report {

	public const MAPPED       = 'mapped';
	public const APPROXIMATED = 'approximated';
	public const UNSUPPORTED  = 'unsupported';

	/**
	 * @var array<int, array{status: string, field: string, wpai_key: string, wpai_value: mixed, value: mixed, note: string}>
	 */
	private array $entries = array();

	/**
	 * @param string $field      Where it went in the definition, e.g. mapping.title; '' when it went nowhere.
	 * @param mixed  $wpai_value
	 * @param mixed  $value      What the definition holds now.
	 */
	public function add( string $status, string $field, string $wpai_key, $wpai_value, $value = null, string $note = '' ): void {
		$this->entries[] = array(
			'status'     => $status,
			'field'      => $field,
			'wpai_key'   => $wpai_key,
			'wpai_value' => $wpai_value,
			'value'      => $value,
			'note'       => $note,
		);
	}

	/**
	 * Swaps the lines for a WP All Import key for one new line, at the position of the first. Adds the line at the
	 * end when the key had none.
	 *
	 * @param string $field      Where it went in the definition, e.g. mapping.title; '' when it went nowhere.
	 * @param mixed  $wpai_value
	 * @param mixed  $value      What the definition holds now.
	 */
	public function replace( string $status, string $field, string $wpai_key, $wpai_value, $value = null, string $note = '' ): void {
		$new     = array(
			'status'     => $status,
			'field'      => $field,
			'wpai_key'   => $wpai_key,
			'wpai_value' => $wpai_value,
			'value'      => $value,
			'note'       => $note,
		);
		$entries = array();
		$placed  = false;
		foreach ( $this->entries as $entry ) {
			if ( $entry['wpai_key'] !== $wpai_key ) {
				$entries[] = $entry;
			} elseif ( ! $placed ) {
				$entries[] = $new;
				$placed    = true;
			}
		}
		if ( ! $placed ) {
			$entries[] = $new;
		}
		$this->entries = $entries;
	}

	/**
	 * @return int How many lines were removed.
	 */
	public function remove( string $wpai_key ): int {
		$before        = count( $this->entries );
		$this->entries = array_values(
			array_filter(
				$this->entries,
				static function ( array $entry ) use ( $wpai_key ): bool {
					return $entry['wpai_key'] !== $wpai_key;
				}
			)
		);
		return $before - count( $this->entries );
	}

	/**
	 * @return array<int, array{status: string, field: string, wpai_key: string, wpai_value: mixed, value: mixed, note: string}>
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * @return array<int, array{status: string, field: string, wpai_key: string, wpai_value: mixed, value: mixed, note: string}>
	 */
	public function with_status( string $status ): array {
		return array_values(
			array_filter(
				$this->entries,
				static function ( array $entry ) use ( $status ): bool {
					return $entry['status'] === $status;
				}
			)
		);
	}

	/**
	 * @return array{mapped: int, approximated: int, unsupported: int}
	 */
	public function counts(): array {
		$counts = array(
			self::MAPPED       => 0,
			self::APPROXIMATED => 0,
			self::UNSUPPORTED  => 0,
		);
		foreach ( $this->entries as $entry ) {
			++$counts[ $entry['status'] ];
		}
		return $counts;
	}

	/**
	 * @return array{counts: array{mapped: int, approximated: int, unsupported: int}, entries: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'counts'  => $this->counts(),
			'entries' => $this->entries,
		);
	}
}

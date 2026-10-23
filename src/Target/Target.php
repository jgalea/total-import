<?php

namespace Importright\Target;

use Importright\Runs\Context;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Something records are imported into: posts, terms, users, WooCommerce products.
 * Register more through the importright_targets filter.
 */
interface Target {

	public function key(): string;

	public function label(): string;

	/**
	 * Value stored in the record map's object_type column.
	 */
	public function object_type(): string;

	/**
	 * Core fields this target maps directly, keyed by mapping key.
	 *
	 * @return array<string, string> Key => label.
	 */
	public function fields(): array;

	/**
	 * Ways to find existing content the import didn't create, besides "meta:<key>".
	 *
	 * @return string[]
	 */
	public function match_strategies(): array;

	/**
	 * Checks and fills in the definition's "target" section.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>|WP_Error
	 */
	public function validate( array $settings );

	/**
	 * Loads objects into the cache before a batch looks at them.
	 *
	 * @param int[] $ids
	 */
	public function prime( array $ids ): void;

	/**
	 * Whether a mapped object still exists and still belongs to this target (right post type, not trashed).
	 *
	 * @param array<string, mixed> $settings
	 */
	public function exists( int $id, array $settings ): bool;

	/**
	 * Finds existing content to adopt when the record map has no entry.
	 *
	 * @param array<string, mixed> $values   Rendered mapping.
	 * @param array<string, mixed> $settings
	 */
	public function find( string $strategy, array $values, string $unique_key, array $settings ): ?int;

	/**
	 * Creates ($id null) or updates an object from rendered core field values.
	 * On update, $fields holds only the fields the update rules allow.
	 *
	 * @param array<string, string> $fields
	 * @param array<string, mixed>  $settings
	 * @return int|WP_Error The object's ID.
	 */
	public function save( ?int $id, array $fields, array $settings, Context $context );
}

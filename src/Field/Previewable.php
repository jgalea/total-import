<?php

namespace Importright\Field;

use Importright\Runs\Context;
use Importright\Runs\Diff;

defined( 'ABSPATH' ) || exit;

/**
 * A field handler that can say what apply() would change without writing, for dry runs.
 */
interface Previewable {

	/**
	 * Adds what would change to $diff (fields named "<section>.<key>"). Never writes.
	 *
	 * @param array<mixed>  $values
	 * @param bool|string[] $rule
	 */
	public function preview( string $target, ?int $object_id, array $values, bool $is_new, $rule, Context $context, Diff $diff ): void;
}

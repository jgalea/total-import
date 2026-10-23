<?php

namespace Importright\Field;

use Importright\Runs\Context;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one mapping section (custom fields, taxonomies, images, SEO,
 * attributes) after the target has saved the object. Register more
 * through the importright_field_handlers filter.
 */
interface FieldHandler {

	/**
	 * The mapping section it owns, e.g. "meta".
	 */
	public function key(): string;

	public function supports( string $target ): bool;

	/**
	 * Checks the section's mapping when the import is saved.
	 *
	 * @param array<mixed> $config
	 * @return array<mixed>|WP_Error
	 */
	public function validate( array $config );

	/**
	 * @param array<mixed>  $values Rendered section.
	 * @param bool|string[] $rule   Update rule: true, false, or the sub-keys an update may change. Ignored for new objects.
	 * @return true|WP_Error
	 */
	public function apply( string $target, int $object_id, array $values, bool $is_new, $rule, Context $context );
}

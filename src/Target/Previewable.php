<?php

namespace Importright\Target;

use Importright\Runs\Context;
use Importright\Runs\Diff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A target that can say what save() would change without writing, for dry runs.
 */
interface Previewable {

	/**
	 * Adds the core fields that would change to $diff. Returns a WP_Error when save() would refuse the record.
	 *
	 * @param array<string, string> $fields
	 * @param array<string, mixed>  $settings
	 * @return true|WP_Error
	 */
	public function preview( ?int $id, array $fields, array $settings, Context $context, Diff $diff );
}

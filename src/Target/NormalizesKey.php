<?php

namespace Importright\Target;

defined( 'ABSPATH' ) || exit;

/**
 * A target whose unique keys compare loosely (user emails ignore case), so the
 * record map stores one canonical form and a key's spelling can change
 * without creating a second row.
 */
interface NormalizesKey {

	public function normalize_key( string $key ): string;
}

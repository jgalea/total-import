<?php

namespace Importright\Field;

defined( 'ABSPATH' ) || exit;

/**
 * A target or field handler that writes protected (underscore) meta keys on
 * purpose, such as WooCommerce's product fields or SEO plugins' title and
 * description. Custom fields mapped to one of these keys are written for
 * importers without unfiltered_html too; any other protected key needs the
 * edit_{type}_meta capability.
 */
interface OwnsMeta {

	/**
	 * @return string[]
	 */
	public function owned_meta( string $target ): array;
}

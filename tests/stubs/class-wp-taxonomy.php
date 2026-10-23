<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- stands in for WordPress.

class WP_Taxonomy {

	public object $labels;

	public object $cap;

	public function __construct( string $label, string $cap ) {
		$this->labels = (object) array( 'name' => $label );
		$this->cap    = (object) array(
			'manage_terms' => $cap,
			'edit_terms'   => $cap,
			'assign_terms' => 'edit_posts',
		);
	}
}

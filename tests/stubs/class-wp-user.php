<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Generic.Files.OneObjectStructurePerFile -- stands in for WordPress.

class WP_User {

	public int $ID;

	public string $user_login;

	/**
	 * @var array<string, bool>
	 */
	public array $allcaps;

	/**
	 * @param array<string, bool> $allcaps
	 */
	public function __construct( int $id, string $login, array $allcaps = array() ) {
		$this->ID         = $id;
		$this->user_login = $login;
		$this->allcaps    = $allcaps;
	}
}

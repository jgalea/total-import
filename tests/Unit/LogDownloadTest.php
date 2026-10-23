<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Admin\Actions;
use Importright\Admin\Ajax;

require_once dirname( __DIR__ ) . '/stubs/admin-functions.php';

final class LogDownloadTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['importright_test_actions'] = array();
	}

	public function test_the_log_downloads_through_a_post_form_with_its_own_nonce(): void {
		ob_start();
		Actions::log_csv_form( 12, 'Download' );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#<form method="post" action="https://example.com/wp-admin/admin-post.php"#', $html );
		$this->assertStringContainsString( 'name="action" value="importright_run_log_csv"', $html );
		$this->assertStringContainsString( 'name="run_id" value="12"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce-for-importright_run_log_csv"', $html, 'A nonce for this download only, not the AJAX nonce.' );
		$this->assertDoesNotMatchRegularExpression( '#(href|action)="[^"]*nonce#', $html, 'No nonce in a URL.' );
	}

	public function test_only_admin_post_serves_the_log(): void {
		Ajax::register();
		Actions::register();
		$this->assertArrayNotHasKey( 'wp_ajax_importright_run_log_csv', $GLOBALS['importright_test_actions'], 'The AJAX nonce must not unlock a GET download.' );
		$this->assertArrayHasKey( 'admin_post_importright_run_log_csv', $GLOBALS['importright_test_actions'] );
	}
}

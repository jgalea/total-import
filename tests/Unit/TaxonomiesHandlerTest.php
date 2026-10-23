<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Field\TaxonomiesHandler;
use Importright\Runs\Context;

require_once dirname( __DIR__ ) . '/stubs/field-functions.php';

final class TaxonomiesHandlerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['importright_test_terms'] = array( 'News' => 5 );
		$GLOBALS['importright_test_set']   = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['importright_test_can'] );
	}

	/**
	 * @param string[] $allowed
	 */
	private function allow( array $allowed ): void {
		$GLOBALS['importright_test_can'] = static function ( string $cap ) use ( $allowed ): bool {
			return in_array( $cap, $allowed, true );
		};
	}

	public function test_terms_are_left_alone_without_assign_terms(): void {
		$this->allow( array( 'edit_post_tag' ) );
		$context = $this->apply(
			array(
				'terms' => 'News',
				'mode'  => 'replace',
			)
		);
		$this->assertSame( array(), $GLOBALS['importright_test_set'] );
		$this->assertStringContainsString( 'assign post_tag', $context->take_notices()[0] );
	}

	public function test_missing_terms_are_not_created_without_edit_terms(): void {
		$this->allow( array( 'assign_post_tag' ) );
		$context = $this->apply(
			array(
				'terms'  => 'News, Fresh',
				'create' => '1',
				'mode'   => 'replace',
			)
		);
		$this->assertSame( array( array( 42, array( 5 ), 'post_tag', false ) ), $GLOBALS['importright_test_set'], 'Existing terms are still assigned.' );
		$this->assertArrayNotHasKey( 'Fresh', $GLOBALS['importright_test_terms'] );
		$this->assertStringContainsString( 'create post_tag', $context->take_notices()[0] );
	}

	/**
	 * @param array<string, string> $settings
	 */
	private function apply( array $settings, bool $is_new = false ): Context {
		$context = new Context( 1, 1, false );
		( new TaxonomiesHandler() )->apply(
			'post',
			42,
			array(
				'post_tag' => $settings + array(
					'separator' => ',',
					'hierarchy' => '',
				),
			),
			$is_new,
			true,
			$context
		);
		return $context;
	}

	public function test_unresolved_terms_with_create_off_leave_terms_unchanged(): void {
		$context = $this->apply(
			array(
				'terms'  => 'Nope, Missing',
				'create' => '0',
				'mode'   => 'replace',
			)
		);
		$this->assertSame( array(), $GLOBALS['importright_test_set'], 'Nothing resolved, so the terms must not be replaced with nothing.' );
		$notices = $context->take_notices();
		$this->assertCount( 3, $notices );
		$this->assertStringContainsString( 'left unchanged', $notices[2] );
	}

	public function test_partly_resolved_cell_sets_the_terms_that_exist(): void {
		$this->apply(
			array(
				'terms'  => 'News, Missing',
				'create' => '0',
				'mode'   => 'replace',
			)
		);
		$this->assertSame( array( array( 42, array( 5 ), 'post_tag', false ) ), $GLOBALS['importright_test_set'] );
	}

	public function test_empty_cell_clears_in_replace_mode_on_update(): void {
		$this->apply(
			array(
				'terms'  => ' ',
				'create' => '0',
				'mode'   => 'replace',
			)
		);
		$this->assertSame( array( array( 42, array(), 'post_tag', false ) ), $GLOBALS['importright_test_set'] );
	}

	public function test_empty_cell_is_skipped_on_create_and_in_append_mode(): void {
		$this->apply(
			array(
				'terms' => '',
				'mode'  => 'replace',
			),
			true
		);
		$this->apply(
			array(
				'terms' => '',
				'mode'  => 'append',
			)
		);
		$this->assertSame( array(), $GLOBALS['importright_test_set'] );
	}

	public function test_missing_terms_are_created_when_create_is_on(): void {
		$this->apply(
			array(
				'terms'  => 'Fresh',
				'create' => '1',
				'mode'   => 'replace',
			)
		);
		$this->assertSame( array( array( 42, array( 101 ), 'post_tag', false ) ), $GLOBALS['importright_test_set'] );
	}
}

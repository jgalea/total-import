<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Field\MetaHandler;
use Importright\Field\SeoHandler;
use Importright\Runs\Context;
use Importright\Runs\Diff;

require_once dirname( __DIR__ ) . '/stubs/field-functions.php';

final class MetaHandlerTest extends TestCase {

	/**
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $checks = array();

	protected function setUp(): void {
		$GLOBALS['importright_test_meta'] = array();
		$this->checks                     = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['importright_test_can'], $GLOBALS['importright_test_filters']['auth_post_meta__secret'] );
	}

	/**
	 * An importer without unfiltered_html (a single-site Author, or any non-super admin on multisite).
	 *
	 * @param string[] $allowed Capabilities granted besides the defaults.
	 */
	private function as_restricted_user( array $allowed = array() ): void {
		$GLOBALS['importright_test_can'] = function ( string $cap, ...$args ) use ( $allowed ): bool {
			$this->checks[] = array( $cap, $args );
			return in_array( $cap, $allowed, true );
		};
	}

	/**
	 * @param array<string, string> $values
	 */
	private function apply( string $target, array $values ): Context {
		$context = new Context( 1, 1, false );
		( new MetaHandler() )->apply( $target, 42, $values, true, true, $context );
		return $context;
	}

	/**
	 * @return string[]
	 */
	private function written(): array {
		return array_column( $GLOBALS['importright_test_meta'], 2 );
	}

	public function test_protected_keys_are_skipped_without_edit_meta(): void {
		$this->as_restricted_user();
		$context = $this->apply(
			'post',
			array(
				'colour'            => 'red',
				'_wp_page_template' => 'evil.php',
			)
		);
		$this->assertSame( array( 'colour' ), $this->written() );
		$notices = $context->take_notices();
		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( '_wp_page_template', $notices[0] );
		$this->assertContains( array( 'edit_post_meta', array( 42, '_wp_page_template' ) ), $this->checks, 'Posts map to edit_post_meta on the object.' );
	}

	public function test_protected_keys_are_written_with_edit_meta(): void {
		$this->as_restricted_user( array( 'edit_post_meta' ) );
		$this->apply( 'post', array( '_secret' => 'x' ) );
		$this->assertSame( array( '_secret' ), $this->written() );
	}

	public function test_term_and_user_meta_check_their_own_capability(): void {
		$this->as_restricted_user();
		$this->apply( 'term', array( '_order' => '1' ) );
		$this->apply( 'user', array( '_flag' => '1' ) );
		$this->assertSame( array(), $this->written() );
		$caps = array_column( $this->checks, 0 );
		$this->assertContains( 'edit_term_meta', $caps );
		$this->assertContains( 'edit_user_meta', $caps );
	}

	public function test_unfiltered_html_writes_any_key(): void {
		$this->as_restricted_user( array( 'unfiltered_html' ) );
		$this->apply( 'post', array( '_wp_page_template' => 'full.php' ) );
		$this->assertSame( array( '_wp_page_template' ), $this->written() );
	}

	public function test_keys_a_handler_owns_stay_allowed(): void {
		$this->as_restricted_user();
		$this->apply(
			'post',
			array(
				'_yoast_wpseo_title'   => 'Title',
				'_yoast_wpseo_focuskw' => 'not owned',
			)
		);
		$this->assertSame( array( '_yoast_wpseo_title' ), $this->written() );
		$this->assertContains( '_yoast_wpseo_metadesc', ( new SeoHandler() )->owned_meta( 'product' ) );
		$this->assertSame( array(), ( new SeoHandler() )->owned_meta( 'user' ) );
	}

	public function test_dry_run_reports_the_same_skip(): void {
		$this->as_restricted_user();
		$context = new Context( 1, 1, true );
		$diff    = new Diff();
		( new MetaHandler() )->preview( 'post', null, array( '_secret' => 'x' ), true, true, $context, $diff );
		$this->assertCount( 1, $context->take_notices() );

		$GLOBALS['importright_test_filters']['auth_post_meta__secret'] = static function (): bool {
			return true;
		};
		( new MetaHandler() )->preview( 'post', null, array( '_secret' => 'x' ), true, true, $context, $diff );
		$this->assertSame( array(), $context->take_notices(), 'A registered auth callback allows the key.' );
	}
}

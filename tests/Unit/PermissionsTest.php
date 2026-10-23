<?php

namespace Importright\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Importright\Runs\Context;
use Importright\Target\PostTarget;
use Importright\Target\TermTarget;

require_once dirname( __DIR__ ) . '/stubs/runs-functions.php';
require_once dirname( __DIR__ ) . '/stubs/target-functions.php';

final class PermissionsTest extends TestCase {

	/**
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $checks = array();

	protected function setUp(): void {
		$GLOBALS['importright_test_saved'] = array();
		$this->checks                      = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['importright_test_can'] );
	}

	/**
	 * @param string[] $allowed
	 */
	private function allow( array $allowed ): void {
		$GLOBALS['importright_test_can'] = function ( string $cap, ...$args ) use ( $allowed ): bool {
			$this->checks[] = array( $cap, $args );
			return in_array( $cap, $allowed, true );
		};
	}

	/**
	 * @param array<string, string> $fields
	 * @return int|\WP_Error
	 */
	private function save_post( ?int $id, array $fields, Context $context, string $post_type = 'post' ) {
		$settings = array(
			'post_type'      => $post_type,
			'default_status' => 'publish',
		);
		return ( new PostTarget() )->save( $id, $fields, $settings, $context );
	}

	public function test_creating_needs_the_post_types_create_capability(): void {
		$this->allow( array( 'edit_posts', 'publish_posts' ) );
		$result = $this->save_post( null, array( 'title' => 'A page' ), new Context( 1, 1, false ), 'page' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'create Pages', $result->get_error_message() );
		$this->assertSame( array(), $GLOBALS['importright_test_saved'] );
	}

	public function test_updating_needs_edit_post_on_that_post(): void {
		$this->allow( array( 'edit_posts', 'publish_posts' ) );
		$result = $this->save_post( 31, array( 'title' => 'Changed' ), new Context( 1, 1, false ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( array( 'edit_post', array( 31 ) ), $this->checks );
		$this->assertSame( array(), $GLOBALS['importright_test_saved'] );

		$this->allow( array( 'edit_post' ) );
		$this->assertSame( 31, $this->save_post( 31, array( 'title' => 'Changed' ), new Context( 1, 1, false ) ) );
	}

	public function test_publishing_without_publish_posts_saves_as_pending(): void {
		$this->allow( array( 'edit_posts' ) );
		$context = new Context( 1, 1, false );
		$this->assertSame( 500, $this->save_post( null, array( 'title' => 'Default status' ), $context ) );
		$this->assertSame( 500, $this->save_post( null, array( 'status' => 'future' ), $context ) );
		$this->assertSame( 500, $this->save_post( null, array( 'status' => 'draft' ), $context ) );
		$this->assertSame( array( 'pending', 'pending', 'draft' ), array_column( $GLOBALS['importright_test_saved'], 'post_status' ) );
		$this->assertCount( 2, $context->take_notices() );
	}

	public function test_publishing_with_publish_posts_is_kept(): void {
		$this->allow( array( 'edit_posts', 'publish_posts' ) );
		$this->save_post( null, array( 'status' => 'private' ), new Context( 1, 1, false ) );
		$this->assertSame( 'private', $GLOBALS['importright_test_saved'][0]['post_status'] );
	}

	public function test_trash_needs_delete_post_and_an_existing_post(): void {
		$this->allow( array( 'edit_post', 'edit_posts', 'publish_posts' ) );
		$context = new Context( 1, 1, false );
		$this->save_post(
			31,
			array(
				'title'  => 'Kept',
				'status' => 'trash',
			),
			$context
		);
		$this->save_post( null, array( 'status' => 'trash' ), $context );
		$this->assertArrayNotHasKey( 'post_status', $GLOBALS['importright_test_saved'][0], 'Left unchanged without delete_post.' );
		$this->assertSame( 'draft', $GLOBALS['importright_test_saved'][1]['post_status'], 'Never created in the trash.' );
		$this->assertCount( 2, $context->take_notices() );

		$this->allow( array( 'edit_post', 'delete_post', 'edit_posts', 'publish_posts' ) );
		$this->save_post(
			31,
			array(
				'title'  => 'Kept',
				'status' => 'trash',
			),
			$context
		);
		$this->assertContains( array( 'delete_post', array( 31 ) ), $this->checks );
		$this->assertSame( 'trash', $GLOBALS['importright_test_saved'][2]['post_status'] );
		$this->save_post( null, array( 'status' => 'trash' ), $context );
		$this->assertSame( 'draft', $GLOBALS['importright_test_saved'][3]['post_status'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function other_statuses(): array {
		return array(
			'WooCommerce order' => array( 'wc-pending' ),
			'auto-draft'        => array( 'auto-draft' ),
			'inherit'           => array( 'inherit' ),
			'unregistered'      => array( 'nonsense' ),
		);
	}

	/**
	 * @dataProvider other_statuses
	 */
	public function test_other_statuses_fall_back( string $status ): void {
		$context = new Context( 1, 1, false );
		$this->save_post( null, array( 'status' => $status ), $context );
		$this->save_post(
			31,
			array(
				'title'  => 'Kept',
				'status' => $status,
			),
			$context
		);
		$this->assertSame( 'draft', $GLOBALS['importright_test_saved'][0]['post_status'] );
		$this->assertArrayNotHasKey( 'post_status', $GLOBALS['importright_test_saved'][1] );
		$this->assertCount( 2, $context->take_notices() );
	}

	public function test_custom_statuses_can_be_allowed_by_filter(): void {
		$GLOBALS['importright_test_filters']['importright_post_statuses'] = static function ( array $statuses ): array {
			$statuses[] = 'archived';
			return $statuses;
		};
		try {
			$this->save_post( 31, array( 'status' => 'archived' ), new Context( 1, 1, false ) );
		} finally {
			unset( $GLOBALS['importright_test_filters']['importright_post_statuses'] );
		}
		$this->assertSame( 'archived', $GLOBALS['importright_test_saved'][0]['post_status'] );
	}

	public function test_another_author_needs_edit_others_posts(): void {
		$this->allow( array( 'edit_posts', 'publish_posts' ) );
		$context = new Context( 1, 1, false );
		$this->save_post( null, array( 'author' => '8' ), $context );
		$this->save_post( null, array( 'author' => '7' ), $context );
		$this->assertArrayNotHasKey( 'post_author', $GLOBALS['importright_test_saved'][0] );
		$this->assertSame( 7, $GLOBALS['importright_test_saved'][1]['post_author'], 'Their own ID is fine.' );
		$this->assertCount( 1, $context->take_notices() );

		$this->allow( array( 'edit_posts', 'publish_posts', 'edit_others_posts' ) );
		$this->save_post( null, array( 'author' => '8' ), $context );
		$this->assertSame( 8, $GLOBALS['importright_test_saved'][2]['post_author'] );
	}

	public function test_terms_need_edit_terms_to_create_and_edit_term_to_update(): void {
		$settings = array( 'taxonomy' => 'category' );
		$this->allow( array( 'edit_posts' ) );
		$this->assertInstanceOf( \WP_Error::class, ( new TermTarget() )->save( null, array( 'name' => 'New' ), $settings, new Context( 1, 1, false ) ) );
		$this->assertInstanceOf( \WP_Error::class, ( new TermTarget() )->save( 9, array( 'name' => 'Renamed' ), $settings, new Context( 1, 1, false ) ) );
		$this->assertContains( array( 'edit_term', array( 9 ) ), $this->checks );
		$this->assertSame( array(), $GLOBALS['importright_test_saved'] );

		$this->allow( array( 'manage_categories', 'edit_term' ) );
		$this->assertSame( 600, ( new TermTarget() )->save( null, array( 'name' => 'New' ), $settings, new Context( 1, 1, false ) ) );
		$this->assertSame( 9, ( new TermTarget() )->save( 9, array( 'name' => 'Renamed' ), $settings, new Context( 1, 1, false ) ) );
	}
}

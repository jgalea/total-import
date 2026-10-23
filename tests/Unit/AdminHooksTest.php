<?php

namespace Importright\Tests\Unit;

use Importright\Admin\RunScreen;
use Importright\Admin\Wizard;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs/admin-functions.php';

final class AdminHooksTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['importright_test_filters'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['importright_test_filters'] = array();
	}

	public function test_only_targets_without_a_built_in_radio_are_extra(): void {
		$targets = array(
			'post'              => array( 'label' => 'Posts' ),
			'term'              => array( 'label' => 'Terms' ),
			'user'              => array( 'label' => 'Users' ),
			'product'           => array( 'label' => 'Products' ),
			'product_variation' => array( 'label' => 'Variations' ),
			'widget'            => array(),
		);
		$this->assertSame(
			array(
				'product_variation' => 'Variations',
				'widget'            => 'widget',
			),
			Wizard::extra_targets( $targets )
		);
	}

	public function test_no_extra_targets_by_default(): void {
		$this->assertSame( array(), Wizard::extra_targets( array( 'post' => array( 'label' => 'Posts' ) ) ) );
	}

	public function test_hints_show_unless_a_listener_says_otherwise(): void {
		$this->assertTrue( Wizard::show_hints() );
		$GLOBALS['importright_test_filters']['importright_pro_hints'] = static function ( bool $show ): bool {
			return ! $show;
		};
		$this->assertFalse( Wizard::show_hints() );
	}

	public function test_no_extra_counts_by_default(): void {
		$this->assertSame( array(), RunScreen::extra_counts( array( 'counts' => array() ) ) );
	}

	public function test_extra_counts_are_cleaned(): void {
		$run = array(
			'extra_counts' => array(
				array(
					'key'   => 'deleted',
					'label' => 'Deleted',
					'value' => '7',
				),
				array(
					'key'   => 'odd key!',
					'label' => 'Odd',
					'value' => 2,
				),
				array(
					'key'   => 'no_value',
					'label' => 'No value',
				),
				array(
					'key'   => 'text',
					'label' => 'Text',
					'value' => 'many',
				),
				'junk',
			),
		);
		$this->assertSame(
			array(
				array(
					'key'   => 'deleted',
					'label' => 'Deleted',
					'value' => 7,
				),
				array(
					'key'   => 'oddkey',
					'label' => 'Odd',
					'value' => 2,
				),
			),
			RunScreen::extra_counts( $run )
		);
	}
}

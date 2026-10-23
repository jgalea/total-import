<?php

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- a fake $wpdb.

namespace Importright\Tests\Unit;

use Importright\Admin\Wizard;
use Importright\Imports\Definition;
use Importright\Imports\Imports;
use Importright\Registry;
use Importright\Runs\Context;
use Importright\Target\Target;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/stubs/definition-functions.php';

final class DefinitionExtensionsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['importright_test_filters'] = array(
			'importright_targets'          => static function ( array $targets ): array {
				$targets['fake'] = new class() implements Target {
					public function key(): string {
						return 'fake';
					}
					public function label(): string {
						return 'Fake';
					}
					public function object_type(): string {
						return 'post';
					}
					public function fields(): array {
						return array( 'title' => 'Title' );
					}
					public function match_strategies(): array {
						return array();
					}
					public function validate( array $settings ) {
						return array();
					}
					public function prime( array $ids ): void {}
					public function exists( int $id, array $settings ): bool {
						return false;
					}
					public function find( string $strategy, array $values, string $unique_key, array $settings ): ?int {
						return null;
					}
					public function save( ?int $id, array $fields, array $settings, Context $context ) {
						return 1;
					}
				};
				return $targets;
			},
			'importright_normalize_source' => static function ( $normalized, array $source ) {
				unset( $normalized );
				return array( 'host' => (string) ( $source['host'] ?? '' ) );
			},
		);
		Registry::reset();
	}

	protected function tearDown(): void {
		$GLOBALS['importright_test_filters'] = array();
		unset( $GLOBALS['wpdb'] );
		Registry::reset();
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function input( array $extra = array() ): array {
		return $extra + array(
			'name'     => 'Feed',
			'source'   => array(
				'type' => 'ftp',
				'host' => 'ftp.example.com',
			),
			'target'   => array( 'type' => 'fake' ),
			'mapping'  => array( 'title' => '{title}' ),
			'matching' => array( 'unique_key' => '{id}' ),
		);
	}

	public function test_a_definition_has_empty_extensions_by_default(): void {
		$definition = Definition::normalize( $this->input( array( 'extensions' => array( 'x' => 1 ) ) ) );
		$this->assertSame( array(), $definition['extensions'] );
	}

	public function test_a_listener_claims_its_extension(): void {
		$GLOBALS['importright_test_filters']['importright_definition_extensions'] = static function ( array $normalized, array $input ): array {
			$normalized['x'] = array( 'every' => (int) ( $input['x']['every'] ?? 0 ) );
			return $normalized;
		};
		$definition = Definition::normalize( $this->input( array( 'extensions' => array( 'x' => array( 'every' => '5' ) ) ) ) );
		$this->assertSame( array( 'x' => array( 'every' => 5 ) ), $definition['extensions'] );
	}

	public function test_an_error_from_the_filter_blocks_the_save(): void {
		$GLOBALS['importright_test_filters']['importright_definition_extensions'] = static function () {
			return new WP_Error( 'importright_extension', 'Bad schedule' );
		};
		$GLOBALS['wpdb'] = $this->database( array() );

		$result = Imports::create( $this->input() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'Bad schedule', $result->get_error_message() );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
	}

	public function test_editing_without_a_listener_keeps_unclaimed_extensions(): void {
		$stored               = Definition::normalize( $this->input() );
		$stored['extensions'] = array(
			'x' => array( 'every' => 5 ),
			'y' => true,
		);
		$GLOBALS['wpdb']      = $this->database( $stored );

		$this->assertTrue( Imports::update( 3, $this->input( array( 'extensions' => array( 'x' => array( 'every' => 9 ) ) ) ) ) );

		$saved = json_decode( $GLOBALS['wpdb']->writes[0]['definition'], true );
		$this->assertSame( $stored['extensions'], $saved['extensions'] );
	}

	public function test_a_claimed_extension_wins_over_the_stored_one(): void {
		$stored               = Definition::normalize( $this->input() );
		$stored['extensions'] = array(
			'x' => 'old',
			'y' => true,
		);
		$GLOBALS['wpdb']      = $this->database( $stored );
		$GLOBALS['importright_test_filters']['importright_definition_extensions'] = static function (): array {
			return array( 'x' => 'new' );
		};

		Imports::update( 3, $this->input() );

		$saved = json_decode( $GLOBALS['wpdb']->writes[0]['definition'], true );
		$this->assertSame(
			array(
				'x' => 'new',
				'y' => true,
			),
			$saved['extensions']
		);
	}

	public function test_a_masked_secret_never_reaches_the_browser(): void {
		$GLOBALS['importright_test_filters']['importright_mask_source'] = static function ( array $source ): array {
			$source['has_password'] = '' !== $source['password'];
			$source['password']     = '';
			return $source;
		};
		$masked = Wizard::mask(
			array(
				'source' => array(
					'type'     => 'ftp',
					'host'     => 'ftp.example.com',
					'password' => 'hunter2-secret',
				),
			)
		);
		$this->assertStringNotContainsString( 'hunter2-secret', (string) wp_json_encode( $masked ) );
		$this->assertTrue( $masked['source']['has_password'] );
	}

	public function test_a_masked_token_is_stripped_from_the_wizard_data(): void {
		$GLOBALS['importright_test_filters']['importright_mask_source'] = static function ( array $source ): array {
			$source['token'] = '';
			return $source;
		};
		$masked = Wizard::mask(
			array(
				'source' => array(
					'type'  => 'api',
					'token' => 'secret-token',
				),
			)
		);
		$this->assertStringNotContainsString( 'secret-token', (string) wp_json_encode( $masked ) );
	}

	public function test_mask_is_unchanged_without_a_listener(): void {
		$source = array(
			'type' => 'ftp',
			'host' => 'ftp.example.com',
		);
		$this->assertSame( array( 'source' => $source ), Wizard::mask( array( 'source' => $source ) ) );
	}

	/**
	 * @param array<string, mixed> $definition Stored definition, empty for none.
	 */
	private function database( array $definition ): object {
		return new class( $definition ) {
			public string $prefix = 'wp_';
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $writes  = array();
			public int $insert_id = 1;
			/**
			 * @var array<string, mixed>
			 */
			private array $definition;

			/**
			 * @param array<string, mixed> $definition
			 */
			public function __construct( array $definition ) {
				$this->definition = $definition;
			}
			public function prepare( string $query ): string {
				return $query;
			}
			public function get_row(): ?array {
				return array() === $this->definition ? null : array(
					'id'         => 3,
					'name'       => 'Feed',
					'definition' => wp_json_encode( $this->definition ),
					'created_by' => 7,
					'created_at' => '2026-01-01 00:00:00',
					'updated_at' => '2026-01-01 00:00:00',
				);
			}
			/**
			 * @param array<string, mixed> $data
			 */
			public function update( string $table, array $data ) {
				unset( $table );
				$this->writes[] = $data;
				return 1;
			}
			/**
			 * @param array<string, mixed> $data
			 */
			public function insert( string $table, array $data ) {
				unset( $table );
				$this->writes[] = $data;
				return 1;
			}
		};
	}
}

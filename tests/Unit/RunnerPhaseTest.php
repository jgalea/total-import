<?php

namespace Importright\Tests\Unit;

use Importright\Database\Schema;
use Importright\Mapping\Mapper;
use Importright\Reader\Reader;
use Importright\Runs\Batch;
use Importright\Runs\Context;
use Importright\Runs\History;
use Importright\Runs\Runner;
use Importright\Runs\Runs;
use Importright\Runs\Session;
use Importright\Runs\Tracker;
use Importright\Target\Target;
use PHPUnit\Framework\TestCase;
use WP_User;

require_once dirname( __DIR__ ) . '/stubs/runs-functions.php';
require_once dirname( __DIR__ ) . '/stubs/runner-functions.php';
require_once dirname( __DIR__ ) . '/stubs/storage-functions.php';

final class RunnerPhaseTest extends TestCase {

	private FakeRunsDb $db;

	protected function setUp(): void {
		$this->db                                     = new FakeRunsDb();
		$GLOBALS['wpdb']                              = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the fake stands in for wpdb.
		$GLOBALS['importright_test_filters']          = array();
		$GLOBALS['importright_test_actions']          = array();
		$GLOBALS['importright_test_action_callbacks'] = array();
		$GLOBALS['importright_test_current']          = 0;
		$GLOBALS['importright_test_users']            = array( 1 => new WP_User( 1, 'admin', array( 'manage_options' => true ) ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['importright_test_filters']          = array();
		$GLOBALS['importright_test_action_callbacks'] = array();
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function seed_run( array $overrides = array() ): int {
		$this->db->runs[5] = array_merge(
			array(
				'id'              => 5,
				'import_id'       => 9,
				'status'          => 'running',
				'origin'          => 'background',
				'dry_run'         => 0,
				'user_id'         => 1,
				'definition'      => '{}',
				'source_file'     => '',
				'source_ext'      => '',
				'state'           => '{}',
				'total'           => null,
				'processed'       => 0,
				'created'         => 0,
				'updated'         => 0,
				'unchanged'       => 0,
				'skipped'         => 0,
				'failed'          => 0,
				'errors'          => '[]',
				'message'         => '',
				'lock_token'      => '',
				'locked_until'    => 0,
				'created_at'      => '2026-10-22 10:00:00',
				'started_at'      => '2026-10-22 10:00:00',
				'finished_at'     => null,
				'heartbeat'       => 0,
				'rollback_status' => '',
				'rollback'        => null,
				'rolled_back_at'  => null,
				'rolled_back_by'  => 0,
			),
			$overrides
		);
		return 5;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function run_row(): array {
		$run = Runs::get( 5 );
		$this->assertNotNull( $run );
		return $run;
	}

	/**
	 * A session over $count records keyed k0, k1, ...; the target creates each one (or finds it, when $found is set).
	 *
	 * @param array<string, int> $found Unique key => existing object ID that find() reports.
	 */
	private function session( int $count, bool $dry_run = false, array $found = array() ): Session {
		$reader  = new class( $count ) implements Reader {
			public bool $closed = false;
			private int $count;
			private int $position = 0;

			public function __construct( int $count ) {
				$this->count = $count;
			}

			public function records( ?array $cursor = null ): \Generator {
				for ( $i = (int) ( $cursor['i'] ?? 0 ); $i < $this->count; $i++ ) {
					$this->position = $i + 1;
					yield $i => array( 'id' => 'k' . $i );
				}
			}

			public function cursor(): array {
				return array( 'i' => $this->position );
			}

			public function columns(): array {
				return array( 'id' );
			}

			public function total(): ?int {
				return $this->count;
			}

			public function progress(): ?float {
				return null;
			}

			public function close(): void {
				$this->closed = true;
			}
		};
		$target  = new class( $found ) implements Target {
			public int $next = 100;
			/** @var array<string, int> */
			private array $found;

			/** @param array<string, int> $found */
			public function __construct( array $found ) {
				$this->found = $found;
			}

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
				return array();
			}

			public function match_strategies(): array {
				return array( 'by_key' );
			}

			public function validate( array $settings ) {
				return $settings;
			}

			public function prime( array $ids ): void {
				unset( $ids );
			}

			public function exists( int $id, array $settings ): bool {
				unset( $id, $settings );
				return true;
			}

			public function find( string $strategy, array $values, string $unique_key, array $settings ): ?int {
				unset( $strategy, $values, $settings );
				return $this->found[ $unique_key ] ?? null;
			}

			public function save( ?int $id, array $fields, array $settings, Context $context ) {
				unset( $fields, $settings, $context );
				return $id ?? $this->next++;
			}
		};
		$session = ( new \ReflectionClass( Session::class ) )->newInstanceWithoutConstructor();

		$session->run_id     = 5;
		$session->definition = array(
			'target'   => array(),
			'matching' => array(
				'mode'           => 'create_update',
				'match_existing' => array() === $found ? '' : 'by_key',
				'unique_key'     => '{id}',
			),
			'options'  => array( 'batch_size' => 10 ),
		);
		$session->reader     = $reader;
		$session->mapper     = new Mapper( array(), '{id}' );
		$session->target     = $target;
		$session->context    = new Context( 5, 9, $dry_run );
		$session->journal    = null;
		$session->handlers   = array();
		$session->cursor     = array();
		return $session;
	}

	/**
	 * @param array<string, mixed> $run
	 * @return array<string, mixed>
	 */
	private function step( ?Session &$session ): array {
		$run = Runner::step( 5, $session );
		$this->assertIsArray( $run, 'step returned a WP_Error' );
		return $run;
	}

	private function actions( string $hook ): int {
		return count( array_filter( $GLOBALS['importright_test_actions'], static fn( $a ) => $a[0] === $hook ) );
	}

	public function test_without_phases_the_run_completes_and_state_gains_no_keys(): void {
		$this->seed_run();
		$session = $this->session( 3 );

		$run = $this->step( $session );

		$this->assertSame( Runs::COMPLETED, $run['status'] );
		$this->assertSame( array( 'cursor' ), array_keys( $run['state'] ) );
		$this->assertSame( 3, $run['created'] );
		$this->assertSame( 1, $this->actions( 'importright_run_finished' ) );
	}

	public function test_exhausting_the_reader_enters_the_first_phase_and_keeps_the_run_running(): void {
		$this->seed_run( array( 'dry_run' => 1 ) );
		$seen = null;
		$GLOBALS['importright_test_filters']['importright_run_phases'] = static function ( array $phases, array $run ) use ( &$seen ): array {
			$seen = $run['dry_run'];
			return array( 'sweep', 'tidy' );
		};
		$session = $this->session( 3, true );
		$reader  = $session->reader;

		$run = $this->step( $session );

		$this->assertSame( Runs::RUNNING, $run['status'] );
		$this->assertSame( 'sweep', $run['state']['phase'] );
		$this->assertSame( array( 'sweep', 'tidy' ), $run['state']['phases'] );
		$this->assertSame( array(), $run['state']['phase_state'] );
		$this->assertSame( array(), $run['state']['phase_counts'] );
		$this->assertSame( array( 'i' => 3 ), $run['state']['cursor'] );
		$this->assertTrue( $seen, 'the phases filter sees the dry run flag' );
		$this->assertNull( $session );
		$this->assertTrue( $reader->closed );
		$this->assertSame( 0, $this->actions( 'importright_run_finished' ) );
		$this->assertNull( Runner::percent( $this->run_row() ) );
	}

	public function test_a_phase_continues_twice_then_finishes_and_the_run_completes(): void {
		$this->seed_run();
		$GLOBALS['importright_test_filters']['importright_run_phases'] = static fn(): array => array( 'count' );
		$calls = array();
		$GLOBALS['importright_test_filters']['importright_run_phase_count'] = static function ( $result, array $run, array $phase_state ) use ( &$calls ): array {
			$calls[] = $phase_state;
			$n       = (int) ( $phase_state['n'] ?? 0 ) + 1;
			return array(
				'done'     => 3 === $n,
				'state'    => array( 'n' => $n ),
				'counts'   => array( 'removed' => 10 ),
				'errors'   => array(),
				'previews' => array(),
			);
		};
		$session = $this->session( 2 );

		$run = $this->step( $session );
		$this->assertSame( 'count', $run['state']['phase'] );

		$run = $this->step( $session );
		$this->assertSame( Runs::RUNNING, $run['status'] );
		$this->assertSame( array( 'n' => 1 ), $run['state']['phase_state'] );

		$run = $this->step( $session );
		$this->assertSame( Runs::RUNNING, $run['status'] );
		$this->assertSame( array( 'n' => 2 ), $run['state']['phase_state'] );

		$run = $this->step( $session );
		$this->assertSame( Runs::COMPLETED, $run['status'] );
		$this->assertSame( array( array(), array( 'n' => 1 ), array( 'n' => 2 ) ), $calls );
		$stored = $this->run_row();
		$this->assertSame( array( 'count' => array( 'removed' => 30 ) ), $stored['state']['phase_counts'] );
		$this->assertArrayNotHasKey( 'phase', $stored['state'] );
		$this->assertSame( 1, $this->actions( 'importright_run_finished' ) );
		$this->assertSame( 100.0, Runner::percent( $stored ) );
	}

	public function test_phases_run_in_order_and_each_phase_logs_errors_and_previews(): void {
		$this->seed_run();
		$GLOBALS['importright_test_filters']['importright_run_phases']    = static fn(): array => array( 'one', 'two' );
		$GLOBALS['importright_test_filters']['importright_run_phase_one'] = static fn() => array(
			'done'     => true,
			'state'    => array(),
			'counts'   => array( 'a' => 1 ),
			'errors'   => array(
				array(
					'index'   => 0,
					'key'     => 'k',
					'level'   => 'error',
					'message' => 'It\'s broken',
				),
			),
			'previews' => array(
				array(
					'index'  => 0,
					'key'    => 'k',
					'level'  => 'preview',
					'action' => 'delete',
					'data'   => array( 'x' => 1 ),
				),
			),
		);
		$GLOBALS['importright_test_filters']['importright_run_phase_two'] = static fn() => array(
			'done'   => true,
			'counts' => array( 'b' => 2 ),
		);
		$session = $this->session( 1 );

		$this->step( $session );
		$run = $this->step( $session );
		$this->assertSame( 'two', $run['state']['phase'] );
		$this->assertSame( 1, count( $this->db->log_for( 5, 'error' ) ) );
		$this->assertSame( 'It\'s broken', $this->db->log_for( 5, 'error' )[0]['message'] );
		$this->assertSame( 1, count( $this->db->log_for( 5, 'preview' ) ) );
		$this->assertSame( 'It\'s broken', $this->run_row()['errors'][0]['message'] );

		$run = $this->step( $session );
		$this->assertSame( Runs::COMPLETED, $run['status'] );
		$this->assertSame(
			array(
				'one' => array( 'a' => 1 ),
				'two' => array( 'b' => 2 ),
			),
			$this->run_row()['state']['phase_counts']
		);
	}

	public function test_a_phase_nobody_answers_is_skipped_with_a_notice_and_the_run_carries_on(): void {
		$this->seed_run();
		$GLOBALS['importright_test_filters']['importright_run_phases']     = static fn(): array => array( 'orphan', 'live' );
		$GLOBALS['importright_test_filters']['importright_run_phase_live'] = static fn() => array( 'done' => true );
		$session = $this->session( 1 );

		$this->step( $session );
		$run = $this->step( $session );

		$this->assertSame( Runs::RUNNING, $run['status'] );
		$this->assertSame( 'live', $run['state']['phase'] );
		$notices = $this->db->log_for( 5, 'notice' );
		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( '"orphan"', $notices[0]['message'] );
		$this->assertSame( 'notice', $this->run_row()['errors'][0]['level'] );

		$run = $this->step( $session );
		$this->assertSame( Runs::COMPLETED, $run['status'] );
	}

	public function test_a_throwing_phase_rolls_back_its_writes_and_fails_the_run(): void {
		$this->seed_run();
		$GLOBALS['importright_test_filters']['importright_run_phases']     = static fn(): array => array( 'boom' );
		$GLOBALS['importright_test_filters']['importright_run_phase_boom'] = static function ( $result, array $run ) {
			\Importright\Runs\Log::add(
				(int) $run['id'],
				array(
					array(
						'index'   => 0,
						'key'     => '',
						'level'   => 'notice',
						'message' => 'half done',
					),
				)
			);
			throw new \RuntimeException( 'phase blew up' );
		};
		$session = $this->session( 1 );

		$this->step( $session );
		$run = $this->step( $session );

		$this->assertSame( Runs::FAILED, $run['status'] );
		$this->assertSame( 'phase blew up', $run['message'] );
		$this->assertSame( array(), $this->db->log_for( 5, 'notice' ), 'the phase\'s log row was rolled back' );
		$this->assertSame( array( 'start', 'commit', 'start', 'rollback' ), $this->db->transactions );
		$this->assertSame( 1, $this->actions( 'importright_run_finished' ) );
		$this->assertSame( '', $this->run_row()['lock_token'] );
	}

	public function test_batch_transaction_filter_is_respected_in_a_phase(): void {
		$this->seed_run( array( 'state' => '{"phase":"p","phases":["p"],"phase_state":{},"phase_counts":{}}' ) );
		$GLOBALS['importright_test_filters']['importright_batch_transaction'] = static fn() => false;
		$GLOBALS['importright_test_filters']['importright_run_phase_p']       = static fn() => array( 'done' => true );
		$session = null;

		$run = $this->step( $session );

		$this->assertSame( Runs::COMPLETED, $run['status'] );
		$this->assertSame( array(), $this->db->transactions );
	}

	public function test_a_run_already_in_a_phase_resumes_it_with_a_fresh_session(): void {
		$this->seed_run( array( 'state' => '{"cursor":{"i":3},"phase":"p","phases":["p"],"phase_state":{"n":4},"phase_counts":{"p":{"x":5}}}' ) );
		$received = null;
		$GLOBALS['importright_test_filters']['importright_run_phase_p'] = static function ( $result, array $run, array $phase_state ) use ( &$received ): array {
			$received = array( $phase_state, $run['dry_run'] );
			return array(
				'done'   => true,
				'counts' => array( 'x' => 2 ),
			);
		};
		$session = null;

		$run = $this->step( $session );

		$this->assertSame( array( array( 'n' => 4 ), false ), $received );
		$this->assertNull( $session, 'no reader is opened for a phase' );
		$this->assertSame( Runs::COMPLETED, $run['status'] );
		$this->assertSame( array( 'p' => array( 'x' => 7 ) ), $this->run_row()['state']['phase_counts'] );
	}

	public function test_a_stale_session_is_closed_when_the_run_is_in_a_phase(): void {
		$this->seed_run( array( 'state' => '{"phase":"p","phases":["p"],"phase_state":{},"phase_counts":{}}' ) );
		$GLOBALS['importright_test_filters']['importright_run_phase_p'] = static fn() => array( 'done' => false );
		$session = $this->session( 1 );
		$reader  = $session->reader;

		$this->step( $session );

		$this->assertNull( $session );
		$this->assertTrue( $reader->closed );
	}

	public function test_batch_processed_reports_every_key_with_its_action(): void {
		$this->seed_run();
		$captured = null;
		$GLOBALS['importright_test_action_callbacks']['importright_batch_processed'] = static function ( array $run, array $keys, array $result, Session $session ) use ( &$captured ): void {
			$captured = array( $run['id'], $keys, $result['counts']['processed'], $session->run_id );
		};
		$session = $this->session( 3 );

		$this->step( $session );

		$this->assertSame(
			array(
				5,
				array(
					'k0' => 'create',
					'k1' => 'create',
					'k2' => 'create',
				),
				3,
				5,
			),
			$captured
		);
	}

	public function test_numeric_unique_keys_reach_listeners_as_int_array_keys(): void {
		$session                                       = $this->session( 1, true );
		$session->definition['matching']['unique_key'] = '42{missing}';
		$session->mapper                               = new Mapper( array(), '42{missing}' );

		$result = Batch::process( $session->take( 10 ), $session );

		$this->assertSame( array( 42 ), array_keys( $result['keys'] ) );
		$this->assertSame( array( '42' ), array_map( 'strval', array_keys( $result['keys'] ) ) );
	}

	public function test_batch_returns_keys_for_dry_runs_and_skips_empty_keys(): void {
		$session                                       = $this->session( 2, true );
		$session->definition['matching']['unique_key'] = '{missing}';
		$session->mapper                               = new Mapper( array(), '{missing}' );

		$result = Batch::process( $session->take( 10 ), $session );
		$this->assertSame( array(), $result['keys'] );
		$this->assertSame( 2, $result['counts']['failed'] );

		$session = $this->session( 2, true );
		$result  = Batch::process( $session->take( 10 ), $session );
		$this->assertSame(
			array(
				'k0' => 'create',
				'k1' => 'create',
			),
			$result['keys']
		);
	}

	public function test_an_object_found_by_matching_is_saved_as_adopted(): void {
		$session = $this->session( 2, false, array( 'k1' => 77 ) );

		Batch::process( $session->take( 10 ), $session );

		$this->assertCount( 2, $this->db->record_inserts );
		$this->assertStringContainsString( "VALUES (9, 'k0', 'post', 100, 0, ", $this->db->record_inserts[0] );
		$this->assertStringContainsString( "VALUES (9, 'k1', 'post', 77, 1, ", $this->db->record_inserts[1] );
	}

	public function test_adopted_defaults_to_zero_and_only_follows_a_changed_object(): void {
		Tracker::save( 9, 'k', 'post', 5, 'h', 3 );
		Tracker::save( 9, 'k', 'post', 5, 'h', 3, true );

		$this->assertStringContainsString( "VALUES (9, 'k', 'post', 5, 0, 'h', 3, 3,", $this->db->record_inserts[0] );
		$this->assertStringContainsString( "VALUES (9, 'k', 'post', 5, 1, 'h', 3, 3,", $this->db->record_inserts[1] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE adopted = IF(object_id = VALUES(object_id), adopted, VALUES(adopted)), object_type', $this->db->record_inserts[1] );
	}

	public function test_schema_is_version_three_with_the_adopted_column(): void {
		$this->assertSame( '3', Schema::VERSION );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Database/Schema.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		$this->assertStringContainsString( 'adopted tinyint(1) unsigned NOT NULL DEFAULT 0', $source );
	}

	public function test_run_description_passes_through_the_filter(): void {
		$this->seed_run(
			array(
				'dry_run' => 1,
				'user_id' => 0,
			)
		);
		$run = $this->run_row();

		$plain = History::describe( $run );
		$this->assertSame( 5, $plain['id'] );
		$this->assertArrayNotHasKey( 'extra_counts', $plain );

		$GLOBALS['importright_test_filters']['importright_run_description'] = static function ( array $description, array $run ): array {
			$description['extra_counts'] = array(
				array(
					'key'   => 'deleted',
					'label' => 'Deleted',
					'value' => $run['id'],
				),
			);
			return $description;
		};
		$this->assertSame( 'deleted', History::describe( $run )['extra_counts'][0]['key'] );
	}
}

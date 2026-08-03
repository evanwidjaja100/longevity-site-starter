<?php

use Longevity\Core\Advisory_Lock;
use PHPUnit\Framework\TestCase;

final class AdvisoryLockTest extends TestCase {
	private $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'];
		unset( $GLOBALS['lel_test_get_lock_result'] );
		$GLOBALS['lel_test_release_lock_calls'] = 0;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		unset( $GLOBALS['lel_test_get_lock_result'], $GLOBALS['lel_test_release_lock_calls'] );
	}

	public function test_scalar_one_is_acquired(): void {
		$GLOBALS['lel_test_get_lock_result'] = '1';
		self::assertSame( Advisory_Lock::ACQUIRED, Advisory_Lock::acquire( 'lel_test_lock' ) );
	}

	public function test_scalar_zero_is_contended_not_acquired(): void {
		$GLOBALS['lel_test_get_lock_result'] = 0;
		self::assertSame( Advisory_Lock::CONTENDED, Advisory_Lock::acquire( 'lel_test_lock' ) );
	}

	public function test_null_result_is_error(): void {
		$GLOBALS['lel_test_get_lock_result'] = null;
		self::assertSame( Advisory_Lock::ERROR, Advisory_Lock::acquire( 'lel_test_lock' ) );
	}

	public function test_unexpected_scalar_is_error(): void {
		$GLOBALS['lel_test_get_lock_result'] = '2';
		self::assertSame( Advisory_Lock::ERROR, Advisory_Lock::acquire( 'lel_test_lock' ) );
	}

	public function test_missing_wpdb_methods_is_unsupported(): void {
		$GLOBALS['wpdb'] = new stdClass();
		self::assertSame( Advisory_Lock::UNSUPPORTED, Advisory_Lock::acquire( 'lel_test_lock' ) );
	}

	public function test_with_lock_runs_callback_and_releases(): void {
		$GLOBALS['lel_test_get_lock_result'] = '1';
		$outcome = Advisory_Lock::with_lock( 'lel_test_lock', 0, static fn (): string => 'done' );
		self::assertSame( Advisory_Lock::ACQUIRED, $outcome['status'] );
		self::assertSame( 'done', $outcome['result'] );
		self::assertSame( Advisory_Lock::RELEASED, $outcome['release_status'] );
		self::assertSame( 1, $GLOBALS['lel_test_release_lock_calls'] );
	}

	public function test_with_lock_releases_when_callback_throws(): void {
		$GLOBALS['lel_test_get_lock_result'] = '1';
		try {
			Advisory_Lock::with_lock(
				'lel_test_lock',
				0,
				static function (): void {
					throw new RuntimeException( 'boom' );
				}
			);
			self::fail( 'Expected exception to propagate.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'boom', $error->getMessage() );
		}
		self::assertSame( 1, $GLOBALS['lel_test_release_lock_calls'] );
	}

	public function test_with_lock_contended_skips_callback_and_release(): void {
		$GLOBALS['lel_test_get_lock_result'] = '0';
		$ran     = false;
		$outcome = Advisory_Lock::with_lock(
			'lel_test_lock',
			0,
			static function () use ( &$ran ): void {
				$ran = true;
			}
		);
		self::assertSame( Advisory_Lock::CONTENDED, $outcome['status'] );
		self::assertNull( $outcome['result'] );
		self::assertFalse( $ran );
		self::assertSame( 0, $GLOBALS['lel_test_release_lock_calls'] );
	}

	public function test_with_lock_error_skips_callback(): void {
		$GLOBALS['lel_test_get_lock_result'] = null;
		$ran     = false;
		$outcome = Advisory_Lock::with_lock(
			'lel_test_lock',
			0,
			static function () use ( &$ran ): void {
				$ran = true;
			}
		);
		self::assertSame( Advisory_Lock::ERROR, $outcome['status'] );
		self::assertFalse( $ran );
	}

	public function test_namespaced_name_is_stable_and_within_mysql_limit(): void {
		$name = Advisory_Lock::namespaced_name( 'migration' );
		self::assertSame( $name, Advisory_Lock::namespaced_name( 'migration' ) );
		self::assertStringStartsWith( 'lel_migration_', $name );
		self::assertLessThanOrEqual( 64, strlen( $name ) );
		self::assertNotSame( $name, Advisory_Lock::namespaced_name( 'freshness' ) );
		self::assertLessThanOrEqual( 64, strlen( Advisory_Lock::namespaced_name( str_repeat( 'long-purpose-', 10 ) ) ) );
	}

	public function test_release_scalar_zero_is_not_held(): void {
		$GLOBALS['wpdb'] = $this->releaseWpdb( 0 );
		self::assertSame( Advisory_Lock::NOT_HELD, Advisory_Lock::release( 'lel_test_lock' ) );
	}

	public function test_release_null_is_error(): void {
		$GLOBALS['wpdb'] = $this->releaseWpdb( null );
		self::assertSame( Advisory_Lock::ERROR, Advisory_Lock::release( 'lel_test_lock' ) );
	}

	private function releaseWpdb( $result ): object {
		return new class( $result ) {
			public string $prefix = 'wp_';
			private $result;

			public function __construct( $result ) {
				$this->result = $result;
			}

			public function prepare( string $query, ...$args ): string {
				unset( $args );
				return $query;
			}

			public function get_var( string $query ) {
				unset( $query );
				return $this->result;
			}
		};
	}
}

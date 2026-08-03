<?php

use Longevity\Core\Platform_Requirements;
use Longevity\Core\System_Readiness;
use PHPUnit\Framework\TestCase;

final class PreflightTest extends TestCase {
	public function test_declared_tested_runtime_meets_requirements(): void {
		$results = Platform_Requirements::evaluate_runtime(
			'8.3.0',
			Platform_Requirements::REQUIRED_EXTENSIONS,
			'7.0.2',
			array(
				'version'            => '8.0.40',
				'engine'             => 'InnoDB',
				'charset'            => 'utf8mb4',
				'advisory_locks'     => true,
				'information_schema' => true,
			),
			true
		);
		self::assertIsArray( $results );
		self::assertNotEmpty( $results );
		foreach ( $results as $result ) {
			self::assertTrue( $result['satisfied'], $result['requirement'] . ': ' . $result['detail'] );
		}
	}

	public function test_evaluate_rejects_old_php(): void {
		$results = Platform_Requirements::evaluate( '8.0.30', array( 'json', 'mbstring', 'hash', 'filter', 'pcre' ) );
		$php     = self::result_for( $results, 'php' );
		self::assertFalse( $php['satisfied'] );
	}

	public function test_evaluate_rejects_php_81_and_82(): void {
		foreach ( array( '8.1.31', '8.2.27' ) as $version ) {
			$results = Platform_Requirements::evaluate( $version, array( 'json', 'mbstring', 'hash', 'filter', 'pcre' ) );
			self::assertFalse( self::result_for( $results, 'php' )['satisfied'], "PHP $version must be rejected" );
		}
	}

	public function test_evaluate_accepts_exact_and_higher_php_minimum(): void {
		foreach ( array( Platform_Requirements::MIN_PHP, '8.4.1' ) as $version ) {
			$results = Platform_Requirements::evaluate( $version, array( 'json', 'mbstring', 'hash', 'filter', 'pcre' ) );
			self::assertTrue( self::result_for( $results, 'php' )['satisfied'], "PHP $version must be accepted" );
		}
	}

	public function test_runtime_rejects_wordpress_701(): void {
		$results = Platform_Requirements::evaluate_runtime(
			'8.3.0',
			Platform_Requirements::REQUIRED_EXTENSIONS,
			'7.0.1',
			array(
				'version'            => '8.0.40',
				'engine'             => 'InnoDB',
				'charset'            => 'utf8mb4',
				'advisory_locks'     => true,
				'information_schema' => true,
			)
		);
		self::assertFalse( self::result_for( $results, 'wordpress' )['satisfied'], 'WordPress 7.0.1 predates the security baseline and must be rejected' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- requirement key is lowercase.
	}

	public function test_runtime_rejects_malformed_wordpress_version(): void {
		foreach ( array( '', 'unknown', 'seven', '7.0.2-src-injected' ) as $version ) {
			$results = Platform_Requirements::evaluate_runtime(
				'8.3.0',
				Platform_Requirements::REQUIRED_EXTENSIONS,
				$version,
				array(
					'version'            => '8.0.40',
					'engine'             => 'InnoDB',
					'charset'            => 'utf8mb4',
					'advisory_locks'     => true,
					'information_schema' => true,
				)
			);
			self::assertFalse( self::result_for( $results, 'wordpress' )['satisfied'], "WordPress version '$version' must be rejected" ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- requirement key is lowercase.
		}
	}

	public function test_runtime_accepts_wordpress_at_and_above_minimum(): void {
		foreach ( array( Platform_Requirements::MIN_WORDPRESS, '7.1' ) as $version ) {
			$results = Platform_Requirements::evaluate_runtime(
				'8.3.0',
				Platform_Requirements::REQUIRED_EXTENSIONS,
				$version,
				array(
					'version'            => '8.0.40',
					'engine'             => 'InnoDB',
					'charset'            => 'utf8mb4',
					'advisory_locks'     => true,
					'information_schema' => true,
				)
			);
			self::assertTrue( self::result_for( $results, 'wordpress' )['satisfied'], "WordPress $version must be accepted" ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- requirement key is lowercase.
		}
	}

	public function test_evaluate_rejects_missing_extension(): void {
		$results = Platform_Requirements::evaluate( PHP_VERSION, array( 'json', 'hash', 'filter', 'pcre' ) );
		$missing = self::result_for( $results, 'ext-mbstring' );
		self::assertFalse( $missing['satisfied'] );

		$present = self::result_for( $results, 'ext-json' );
		self::assertTrue( $present['satisfied'] );
	}

	public function test_runtime_rejects_old_wordpress_mysql_and_missing_capabilities(): void {
		$results = Platform_Requirements::evaluate_runtime(
			'8.3.0',
			Platform_Requirements::REQUIRED_EXTENSIONS,
			'6.9.9',
			array(
				'version'            => '5.7.44',
				'engine'             => 'MyISAM',
				'charset'            => 'utf8',
				'advisory_locks'     => false,
				'information_schema' => false,
			),
			false
		);
		foreach ( array( 'wordpress', 'mysql', 'mysql-innodb', 'mysql-utf8mb4', 'mysql-advisory-locks', 'mysql-information-schema', 'wp-cli' ) as $requirement ) {
			self::assertFalse( self::result_for( $results, $requirement )['satisfied'], $requirement );
		}
	}

	public function test_unqualified_mariadb_is_rejected_without_provider_assumptions(): void {
		$results = Platform_Requirements::evaluate_runtime(
			'8.3.0',
			Platform_Requirements::REQUIRED_EXTENSIONS,
			'7.0.1',
			array(
				'version'            => '10.11.8-MariaDB',
				'engine'             => 'InnoDB',
				'charset'            => 'utf8mb4',
				'advisory_locks'     => true,
				'information_schema' => true,
			)
		);
		self::assertFalse( self::result_for( $results, 'mysql' )['satisfied'] );
	}

	public function test_requirements_match_composer_declaration(): void {
		$composer = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), true );
		self::assertIsArray( $composer['require'] ?? null, 'composer.json must declare a require block' );

		$declared_php = (string) ( $composer['require']['php'] ?? '' );
		self::assertNotSame( '', $declared_php, 'composer.json must constrain the PHP version' );
		self::assertStringContainsString( Platform_Requirements::MIN_PHP, $declared_php );

		foreach ( Platform_Requirements::REQUIRED_EXTENSIONS as $extension ) {
			self::assertArrayHasKey( 'ext-' . $extension, $composer['require'], "composer.json must require ext-$extension" );
		}
	}

	public function test_readiness_includes_environment_check(): void {
		$report = System_Readiness::report();
		self::assertArrayHasKey( 'environment', $report['checks'] );
		self::assertContains( $report['checks']['environment']['status'], System_Readiness::CHECK_STATES );
	}

	private static function result_for( array $results, string $requirement ): array {
		foreach ( $results as $result ) {
			if ( $result['requirement'] === $requirement ) {
				return $result;
			}
		}
		self::fail( "requirement '$requirement' missing from results" );
	}
}

<?php

namespace Longevity\Core\Tests\Support;

/** Shared architecture checks executed by PHPUnit and the fallback runner. */
final class ArchitectureAssertions {
	/** @return string[] */
	public static function failures(): array {
		$failures = array();
		$root     = defined( 'LONGEVITY_CORE_PATH' ) ? LONGEVITY_CORE_PATH : dirname( __DIR__, 3 ) . '/wp-content/mu-plugins/longevity-core/';
		$bootstrap_path = $root . 'bootstrap.php';
		$bootstrap = is_readable( $bootstrap_path ) ? (string) file_get_contents( $bootstrap_path ) : '';
		if ( '' === $bootstrap ) {
			return array( 'Cannot read longevity-core/bootstrap.php.' );
		}

		preg_match( '/\$longevity_core_files\s*=\s*array\((.*?)\);/s', $bootstrap, $file_match );
		if ( empty( $file_match[1] ) ) {
			return array( 'Cannot extract service file list from bootstrap.php.' );
		}
		preg_match_all( "/'([^']+)'/", $file_match[1], $files );
		$listed = $files[1];
		foreach ( $listed as $file ) {
			if ( ! is_file( $root . $file ) ) {
				$failures[] = "Service file {$file} is listed but missing.";
			}
		}
		if ( count( $listed ) !== count( array_unique( $listed ) ) ) {
			$failures[] = 'bootstrap.php contains duplicate service file entries.';
		}

		preg_match_all( '/\b([A-Z][A-Za-z_]+)::init\(\)/', $bootstrap, $init_calls );
		foreach ( array_unique( $init_calls[1] ) as $class ) {
			if ( 'Bootstrap' === $class ) {
				continue;
			}
			$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
			if ( ! in_array( $file, $listed, true ) ) {
				$failures[] = "{$class}::init() is called but {$file} is not listed.";
				continue;
			}
			$source = (string) file_get_contents( $root . $file );
			if ( ! preg_match( '/function\s+init\s*\(/', $source ) ) {
				$failures[] = "{$file} is initialized but has no init() method.";
			}
		}

		$routes_path = $root . 'class-routes.php';
		$routes = is_readable( $routes_path ) ? (string) file_get_contents( $routes_path ) : '';
		preg_match_all( "/'slug'\s*=>\s*'([^']+)'/", $routes, $slug_matches );
		$slugs = array_filter( $slug_matches[1], static fn( string $slug ): bool => '' !== $slug );
		$duplicates = array_keys( array_filter( array_count_values( $slugs ), static fn( int $count ): bool => $count > 1 ) );
		if ( $duplicates ) {
			$failures[] = 'Duplicate route slugs: ' . implode( ', ', $duplicates );
		}

		$definitions = (string) file_get_contents( $root . 'class-meta-registry.php' );
		if ( ! str_contains( $definitions, "'write_policy'" ) || ! str_contains( $definitions, 'field_policy_map' ) ) {
			$failures[] = 'Editorial metadata definitions are not visibly bound to explicit write policies.';
		}
		if ( preg_match( "/'show_in_rest'\s*=>\s*true/", (string) file_get_contents( $root . 'class-claims.php' ) ) ) {
			$failures[] = 'Claim registry exposes raw metadata through REST.';
		}
		if ( preg_match( "/'show_in_rest'\s*=>\s*true/", (string) file_get_contents( $root . 'class-affiliate-registry.php' ) ) ) {
			$failures[] = 'Affiliate registry exposes raw metadata through REST.';
		}

		$methodology = (string) file_get_contents( $root . 'class-review-methodology.php' );
		foreach ( array( 'approve_test_record', 'approve_protocol', 'protocol_fingerprint', 'approval_snapshot_hash', 'separation_of_duties', 'invalidate_test_record_approval' ) as $required ) {
			if ( ! str_contains( $methodology, $required ) ) {
				$failures[] = "Test-record approval enforcement is missing {$required}.";
			}
		}
		foreach ( array( 'class-public-content.php', 'class-public-trust.php', 'class-public-rankings.php', 'class-schema.php' ) as $public_file ) {
			$source = (string) file_get_contents( $root . $public_file );
			if ( ! str_contains( $source, 'Approval_Service::is_current' ) ) {
				$failures[] = "{$public_file} does not visibly require current approval snapshots.";
			}
		}

		$admin_ui = (string) file_get_contents( $root . 'class-admin-ui.php' );
		foreach ( array( 'Publication_Gates::service_only_meta', 'longevity_approve_testing', 'longevity_approve_commercial', 'longevity_approve_editorial', 'Approval_Service::approve' ) as $required ) {
			if ( ! str_contains( $admin_ui, $required ) ) {
				$failures[] = "Classic editor final-state enforcement is missing {$required}.";
			}
		}

		$repo_root = dirname( $root, 3 );
		$cli_path  = $root . 'class-cli.php';
		$cli       = is_readable( $cli_path ) ? (string) file_get_contents( $cli_path ) : '';
		if ( ! str_contains( $cli, "array( 'verified_by', 'verification_date', 'verification_status' )" ) || ! str_contains( $cli, 'Claims::verify' ) ) {
			$failures[] = 'Claim CLI import does not visibly separate editable data from verifier-controlled state.';
		}
		$fixtures_path = $repo_root . '/scripts/create-test-fixtures.php';
		$fixtures      = is_readable( $fixtures_path ) ? (string) file_get_contents( $fixtures_path ) : '';
		foreach ( array( 'Approval_Service::approve', 'Claims::verify', 'Reviewer_Credentials::verify', 'Review_Methodology::approve_test_record', 'Review_Methodology::approve_protocol' ) as $required ) {
			if ( ! str_contains( $fixtures, $required ) ) {
				$failures[] = "Synthetic integration fixtures bypass required service {$required}.";
			}
		}
		if ( str_contains( $fixtures, "'editorial_approval_status'    => 'ready'" ) ) {
			$failures[] = 'Synthetic fixtures manufacture editorial ready state instead of using approval snapshots.';
		}
		if ( ! str_contains( $fixtures, "in_array( \$lel_fixture_environment, array( 'local', 'development' ), true )" ) ) {
			$failures[] = 'Synthetic fixtures lost the local/development environment allowlist; staging and production must refuse fixtures before any write.';
		}
		if ( ! str_contains( $fixtures, 'Trust_Pages::approve' ) ) {
			$failures[] = 'CI fixture route projection no longer routes trust-page publication through Trust_Pages::approve.';
		}
		if ( ! str_contains( $fixtures, '_lel_ci_fixture_published' ) ) {
			$failures[] = 'CI fixture route projection lost its _lel_ci_fixture_published watermark; fixture-published pages must stay detectable.';
		}
		return $failures;
	}
}

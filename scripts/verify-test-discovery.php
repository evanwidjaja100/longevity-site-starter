<?php
/**
 * Verify that security-critical PHPUnit suites are discoverable by PHPUnit itself.
 *
 * @package LongevityCore
 */

$required = array(
	'ArchitectureTest',
	'MetaAuthorizationTest',
	'ReviewerCredentialsTest',
	'ApprovalSnapshotTest',
	'TestRecordApprovalTest',
	'RestPublicBoundaryTest',
	'PublicationGatesTest',
	'FreshnessTest',
	'RegressionPublicationBypassTest',
	'ClaimVerificationTest',
	'ProtocolApprovalTest',
	'AffiliateLifecycleTest',
	'ContactPrivacyTest',
	'ContactPersistenceTest',
	'LegalHoldTest',
	'CspReportTest',
	'EvidenceStoreTest',
	'TrustPagesTest',
	'AdvisoryLockTest',
	'MigrationsLockTest',
	'OverrideIntentTest',
	'RolesTest',
	'FingerprintCompletenessTest',
	'InvalidationQueueTest',
	'DependencyIndexTest',
	'FreshnessLockTest',
	'AuditLogTransactionTest',
	'PreflightTest',
);

$phpunit = dirname( __DIR__ ) . '/vendor/phpunit/phpunit/phpunit';
if ( ! is_file( $phpunit ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "PHPUnit is unavailable; install locked Composer dependencies before discovery.\n" );
	exit( 1 );
}

$php     = PHP_BINARY;
$command = sprintf(
	'%s %s --list-tests --testsuite %s --no-coverage 2>&1',
	escapeshellarg( $php ),
	escapeshellarg( $phpunit ),
	escapeshellarg( 'Longevity Core' )
);

$output    = array();
$exit_code = 0;
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
exec( $command, $output, $exit_code );
if ( 0 !== $exit_code ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "PHPUnit test discovery failed:\n" . implode( PHP_EOL, $output ) . PHP_EOL );
	exit( 1 );
}

$listed  = implode( PHP_EOL, $output );
$missing = array();
foreach ( $required as $class ) {
	if ( false === strpos( $listed, $class . '::' ) ) {
		$missing[] = $class;
	}
}
if ( $missing ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, 'Missing required PHPUnit suites: ' . implode( ', ', $missing ) . PHP_EOL );
	exit( 1 );
}

preg_match_all( '/^\s*-\s+.+::.+$/m', $listed, $tests );
$methods = count( $tests[0] );
if ( $methods < 100 ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "Only {$methods} PHPUnit tests were discovered; expected at least 100.\n" );
	exit( 1 );
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
printf( "PHPUnit discovered %d test cases; all critical suites are present.\n", $methods );

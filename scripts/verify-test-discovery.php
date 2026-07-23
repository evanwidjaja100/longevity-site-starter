<?php
/** Verify that security-critical PHPUnit suites are discoverable by PHPUnit itself. */
$required = array(
	'ArchitectureTest', 'MetaAuthorizationTest', 'ReviewerCredentialsTest',
	'ApprovalSnapshotTest', 'TestRecordApprovalTest', 'RestPublicBoundaryTest',
	'PublicationGatesTest', 'FreshnessTest', 'RegressionPublicationBypassTest',
);

$phpunit = dirname( __DIR__ ) . '/vendor/bin/phpunit';
if ( ! is_file( $phpunit ) ) {
	fwrite( STDERR, "PHPUnit is unavailable; install locked Composer dependencies before discovery.\n" );
	exit( 1 );
}

$output = array();
$status = 0;
exec( escapeshellarg( $phpunit ) . ' --list-tests --testsuite ' . escapeshellarg( 'Longevity Core' ) . ' --no-coverage 2>&1', $output, $status );
if ( 0 !== $status ) {
	fwrite( STDERR, "PHPUnit test discovery failed:\n" . implode( PHP_EOL, $output ) . PHP_EOL );
	exit( 1 );
}

$listed = implode( PHP_EOL, $output );
$missing = array();
foreach ( $required as $class ) {
	if ( false === strpos( $listed, $class . '::' ) ) {
		$missing[] = $class;
	}
}
if ( $missing ) {
	fwrite( STDERR, 'Missing required PHPUnit suites: ' . implode( ', ', $missing ) . PHP_EOL );
	exit( 1 );
}

preg_match_all( '/^\s*-\s+.+::.+$/m', $listed, $tests );
$methods = count( $tests[0] );
if ( $methods < 50 ) {
	fwrite( STDERR, "Only {$methods} PHPUnit tests were discovered; expected at least 50.\n" );
	exit( 1 );
}
echo sprintf( "PHPUnit discovered %d test cases; all critical suites are present.\n", $methods );

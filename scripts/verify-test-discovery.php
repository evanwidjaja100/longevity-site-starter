<?php
/** Verify that security-critical PHPUnit suites are discoverable. */
$required = array(
	'ArchitectureTest', 'MetaAuthorizationTest', 'ReviewerCredentialsTest',
	'ApprovalSnapshotTest', 'TestRecordApprovalTest', 'RestPublicBoundaryTest', 'PublicationGatesTest', 'FreshnessTest',
);
$files = glob( dirname( __DIR__ ) . '/tests/php/*Test.php' ) ?: array();
$classes = array();
$methods = 0;
foreach ( $files as $file ) {
	$source = (string) file_get_contents( $file );
	if ( preg_match_all( '/final\s+class\s+([A-Za-z0-9_]+Test)\s+extends\s+TestCase/', $source, $matches ) ) {
		$classes = array_merge( $classes, $matches[1] );
	}
	$methods += preg_match_all( '/function\s+test_[A-Za-z0-9_]+\s*\(/', $source );
}
$missing = array_values( array_diff( $required, $classes ) );
if ( $missing ) {
	fwrite( STDERR, 'Missing required test classes: ' . implode( ', ', $missing ) . PHP_EOL );
	exit( 1 );
}
if ( $methods < 50 ) {
	fwrite( STDERR, "Only {$methods} test methods were discovered; expected at least 50." . PHP_EOL );
	exit( 1 );
}
echo sprintf( "Discovered %d test classes and %d test methods; all critical suites are present.\n", count( array_unique( $classes ) ), $methods );

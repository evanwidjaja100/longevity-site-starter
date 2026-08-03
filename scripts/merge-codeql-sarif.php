#!/usr/bin/env php
<?php
/**
 * Merge CodeQL SARIF runs into a single evidence file, keeping only the
 * rule metadata referenced by results. Static rule documentation (help,
 * descriptions, unused rules) is excluded so evidence reflects findings.
 * The raw per-language SARIF files are preserved alongside.
 *
 * @package LongevityEvidenceLab
 */

declare(strict_types=1);

$runs  = array();
$files = glob( dirname( __DIR__ ) . '/reports/codeql/raw/*.sarif' );
if ( ! $files ) {
	fwrite( STDERR, "No CodeQL SARIF found\n" );
	exit( 1 );
}
foreach ( $files as $file ) {
	$doc = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $doc ) || ( $doc['version'] ?? '' ) !== '2.1.0' || ! is_array( $doc['runs'] ?? null ) ) {
		fwrite( STDERR, "Malformed SARIF: $file\n" );
		exit( 1 );
	}
	$runs = array_merge( $runs, $doc['runs'] );
}
foreach ( $runs as $i => $unused ) {
	$used = array();
	foreach ( $runs[ $i ]['results'] ?? array() as $result ) {
		$used[ $result['ruleId'] ?? '' ] = 1;
	}
	foreach ( $runs[ $i ]['tool']['extensions'] ?? array() as $j => $unused2 ) {
		$kept = array();
		foreach ( $runs[ $i ]['tool']['extensions'][ $j ]['rules'] ?? array() as $rule ) {
			if ( isset( $used[ $rule['id'] ?? '' ] ) ) {
				$kept[] = $rule;
			}
		}
		$runs[ $i ]['tool']['extensions'][ $j ]['rules'] = $kept;
	}
}
$out = array(
	'$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
	'version' => '2.1.0',
	'runs'    => $runs,
);
file_put_contents(
	dirname( __DIR__ ) . '/reports/codeql/codeql.sarif',
	json_encode( $out, JSON_UNESCAPED_SLASHES ) . "\n"
);
echo 'Merged ' . count( $runs ) . ' SARIF run(s).' . PHP_EOL;

<?php
/**
 * Real-database audit-chain integration assertions.
 *
 * Executed inside WordPress via `wp eval-file` (see audit-chain.sh). Verifies
 * append-only inserts, chain verification, tamper detection, and that the
 * fork-preventing unique constraint is present and enforced.
 *
 * @package LongevityCore
 */

use Longevity\Core\Audit_Log;

if ( ! class_exists( Audit_Log::class ) ) {
	fwrite( STDERR, "Audit_Log class unavailable\n" );
	exit( 1 );
}

/** @var \wpdb $wpdb */
global $wpdb;

function lel_fail( string $message ): void {
	fwrite( STDERR, 'AUDIT CHAIN FAIL: ' . $message . "\n" );
	exit( 1 );
}

// Ensure schema + fork constraint exist (idempotent).
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
Audit_Log::install();
if ( ! Audit_Log::ensure_fork_constraint() ) {
	lel_fail( 'fork constraint could not be ensured' );
}
if ( ! Audit_Log::ensure_idempotency_constraint() ) {
	lel_fail( 'idempotency constraint could not be ensured' );
}

$table = Audit_Log::table_name();

// 1. Append-only inserts return increasing IDs.
$object_id = 900000 + wp_rand( 1, 99999 );
$first_id  = Audit_Log::record( 'integration_probe', 'system', $object_id, array( 'n' => 1 ), 0, 'integration', true );
$second_id = Audit_Log::record( 'integration_probe', 'system', $object_id, array( 'n' => 2 ), 0, 'integration', true );
if ( $first_id <= 0 || $second_id <= 0 ) {
	lel_fail( 'mandatory append-only inserts did not return positive IDs' );
}
$delivery_key = 'audit-chain-invalidation:' . $object_id;
$once_id      = Audit_Log::record( 'approval_invalidated', 'post', $object_id, array( 'reason' => 'integration' ), 0, 'integration', true, $delivery_key );
$replay_id    = Audit_Log::record( 'approval_invalidated', 'post', $object_id, array( 'reason' => 'integration' ), 0, 'integration', true, $delivery_key );
if ( $once_id <= 0 || $once_id !== $replay_id ) {
	lel_fail( 'idempotent audit replay appended a duplicate event' );
}

// 2. Chain verifies clean.
$verify = Audit_Log::verify_chain();
if ( true !== $verify['valid'] ) {
	lel_fail( 'verify_chain reported invalid on a clean chain: ' . wp_json_encode( $verify['errors'] ) );
}
$clean_checked = (int) $verify['checked'];

// 3. Tamper detection: mutate a row's payload, expect a hash mutation error.
$target = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY sequence DESC LIMIT 1" );
$original_payload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload_json FROM {$table} WHERE id = %d", $target ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET payload_json = %s WHERE id = %d", '{"tampered":true}', $target ) );

$tampered = Audit_Log::verify_chain();
if ( false !== $tampered['valid'] ) {
	// Restore before failing so we do not leave the chain broken.
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET payload_json = %s WHERE id = %d", $original_payload, $target ) );
	lel_fail( 'verify_chain did not detect a tampered row' );
}
$has_mutation_error = false;
foreach ( $tampered['errors'] as $error ) {
	if ( 0 === strpos( (string) $error, 'hash_mutation_at_sequence_' ) ) {
		$has_mutation_error = true;
	}
}
// Restore the original payload to leave a valid chain.
$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET payload_json = %s WHERE id = %d", $original_payload, $target ) );
if ( ! $has_mutation_error ) {
	lel_fail( 'tamper detection did not report a hash_mutation error' );
}

$restored = Audit_Log::verify_chain();
if ( true !== $restored['valid'] ) {
	lel_fail( 'chain did not return to valid after restoring the tampered row' );
}

// 4. The fork-preventing unique constraint is enforced at the DB layer.
$index_count = (int) $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
	$table,
	'previous_event_hash'
) );
if ( $index_count <= 0 ) {
	lel_fail( 'unique previous_event_hash index is missing' );
}

// Attempt to insert a row whose predecessor duplicates an existing one: must be rejected.
$dup_predecessor = (string) $wpdb->get_var( "SELECT previous_event_hash FROM {$table} ORDER BY sequence ASC LIMIT 1" );
$suppressed = $wpdb->suppress_errors( true );
$dup_result = $wpdb->query( $wpdb->prepare(
	"INSERT INTO {$table} (sequence, occurred_at, event_type, actor_user_id, object_type, object_id, request_id, source_channel, payload_json, previous_event_hash, event_hash, schema_version)
	 VALUES (%d, %s, %s, %d, %s, %d, %s, %s, %s, %s, %s, %s)",
	999999999,
	gmdate( 'Y-m-d H:i:s' ),
	'fork_attempt',
	0,
	'system',
	0,
	'fork-test',
	'integration',
	'{}',
	$dup_predecessor,
	str_repeat( 'a', 64 ),
	Audit_Log::SCHEMA_VERSION
) );
$wpdb->suppress_errors( $suppressed );
if ( false !== $dup_result ) {
	// Clean up the erroneously inserted fork row before failing.
	$wpdb->query( "DELETE FROM {$table} WHERE sequence = 999999999" );
	lel_fail( 'duplicate previous_event_hash insert was accepted (fork was possible)' );
}

echo wp_json_encode( array(
	'append_only'      => true,
	'clean_checked'    => $clean_checked,
	'tamper_detected'  => true,
	'fork_constraint'  => true,
	'idempotent_replay' => true,
) ) . "\n";
echo "Audit-chain deterministic integration assertions passed.\n";

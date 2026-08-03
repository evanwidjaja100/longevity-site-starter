<?php
/**
 * PR-02 — Approval activation must be atomic with mandatory audit durability.
 *
 * An approval may not be current, render, satisfy a publication gate, or
 * supersede another approval unless its mandatory audit event is durably
 * confirmed. These tests pin the fail-closed invariants and the bounded
 * reconciliation path.
 *
 * @package LongevityCore
 */

use Longevity\Core\Approval_Fingerprint;
use Longevity\Core\Approval_Repository;
use Longevity\Core\Approval_Service;
use Longevity\Core\Audit_Log;
use Longevity\Core\System_Readiness;
use PHPUnit\Framework\TestCase;

final class ApprovalActivationAtomicityTest extends TestCase {
	private int $postId   = 720;
	private int $editorId = 71;

	protected function setUp(): void {
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_approval_snapshots', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_audit_events', array() );
		$GLOBALS['wpdb']->lel_query_log      = array();
		$GLOBALS['wpdb']->lel_audit_sequence = 0;
		$GLOBALS['wpdb']->last_error         = '';
		$GLOBALS['lel_test_options']         = array();
		unset(
			$GLOBALS['lel_test_fail_commit'],
			$GLOBALS['lel_test_fail_activation'],
			$GLOBALS['lel_test_throw_on_insert'],
			$GLOBALS['lel_test_fail_insert']
		);
		Audit_Log::set_test_mode( true );
		$this->seedEditorialPost();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['lel_test_fail_commit'],
			$GLOBALS['lel_test_fail_activation'],
			$GLOBALS['lel_test_throw_on_insert'],
			$GLOBALS['lel_test_fail_insert'],
			$GLOBALS['lel_test_posts'][ $this->postId ],
			$GLOBALS['lel_test_meta'][ $this->postId ],
			$GLOBALS['lel_test_user_caps'][ $this->editorId ],
			$GLOBALS['lel_test_current_user_id']
		);
		$GLOBALS['wpdb']->last_error = '';
		Audit_Log::set_test_mode( true );
	}

	/** Build a post that satisfies editorial approvability. */
	private function seedEditorialPost(): void {
		$post              = new WP_Post();
		$post->ID          = $this->postId;
		$post->post_type   = 'post';
		$post->post_title  = 'Atomic approval title';
		$post->post_excerpt = 'Atomic approval excerpt';
		$post->post_content = 'Content that has passed editorial review.';
		$post->post_author  = (string) $this->editorId;
		$GLOBALS['lel_test_posts'][ $this->postId ]     = $post;
		$GLOBALS['lel_test_current_user_id']            = $this->editorId;
		$GLOBALS['lel_test_user_caps'][ $this->editorId ] = array( 'approve_publication', 'edit_post' );
		$GLOBALS['lel_test_meta'][ $this->postId ]      = array(
			'content_summary'          => 'A concise test summary.',
			'content_limitations'      => 'Test-specific limitations noted.',
			'next_content_review_date' => ( new DateTimeImmutable( '+180 days' ) )->format( 'Y-m-d' ),
			'editorial_approval_status' => 'ready',
			'region_scope'             => 'Global',
			'original_contribution'    => 'Original analysis approach.',
		);
	}

	private function snapshotRows(): array {
		return $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_approval_snapshots' );
	}

	/** Healthy approval must link a durable audit event and activate. */
	public function test_healthy_approval_activates_with_audit_linkage(): void {
		$record = Approval_Service::approve( $this->postId, 'editorial', $this->editorId );

		self::assertIsArray( $record, 'A healthy approval must return the activated snapshot.' );
		self::assertSame( 'approved', (string) $record['approval_status'] );
		self::assertArrayHasKey( 'audit_event_id', $record );
		self::assertGreaterThan( 0, (int) $record['audit_event_id'], 'Activated approval must carry a durable audit-event ID.' );
		self::assertNotEmpty( $record['activated_at'] ?? '', 'Activated approval must record activation time.' );

		$current = Approval_Repository::current( $this->postId, 'editorial' );
		self::assertIsArray( $current );
		self::assertSame( 'approved', (string) $current['approval_status'] );
		self::assertTrue( Approval_Service::is_current( $this->postId, 'editorial' ) );
	}

	/**
	 * Canonical fail-closed test: an unknown audit COMMIT outcome must leave no
	 * usable approval. The snapshot must remain quarantined as pending_audit and
	 * must never be current.
	 */
	public function test_unknown_audit_commit_leaves_no_usable_approval(): void {
		Audit_Log::set_test_mode( false );
		$GLOBALS['lel_test_fail_commit'] = true;

		$record = Approval_Service::approve( $this->postId, 'editorial', $this->editorId );

		self::assertNull( $record, 'An unknown audit commit must not report approval success.' );
		self::assertNull( Approval_Repository::current( $this->postId, 'editorial' ), 'No approved snapshot may survive an unknown audit commit.' );
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ) );

		$rows = $this->snapshotRows();
		self::assertCount( 1, $rows, 'Exactly one quarantined snapshot must exist.' );
		self::assertSame( 'pending_audit', (string) $rows[0]['approval_status'], 'The snapshot must be quarantined as pending_audit, never approved.' );
		self::assertFalse( Audit_Log::request_is_healthy(), 'An unknown commit must quarantine the request.' );
	}

	/** A mandatory audit write failure (server did not commit) must reject the pending snapshot. */
	public function test_audit_write_failure_rejects_pending_snapshot(): void {
		Audit_Log::set_test_fail_events( array( 'approval_completed' ) );

		$record = Approval_Service::approve( $this->postId, 'editorial', $this->editorId );

		self::assertNull( $record, 'A failed mandatory audit write must not report success.' );
		self::assertNull( Approval_Repository::current( $this->postId, 'editorial' ) );

		$rows = $this->snapshotRows();
		self::assertCount( 1, $rows );
		self::assertSame( 'rejected', (string) $rows[0]['approval_status'], 'A pending snapshot whose audit failed must be rejected, not left approved.' );
	}

	/** A pending_audit row must never be treated as current under any query path. */
	public function test_pending_audit_row_is_never_current(): void {
		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_approval_snapshots',
			array(
				array(
					'id'              => 1,
					'post_id'         => $this->postId,
					'approval_type'   => 'editorial',
					'approval_status' => 'pending_audit',
					'combined_hash'   => str_repeat( 'a', 64 ),
					'invalidated_at'  => null,
					'audit_event_id'  => null,
					'activated_at'    => null,
				),
			)
		);

		self::assertNull( Approval_Repository::current( $this->postId, 'editorial' ) );
		self::assertFalse( Approval_Service::is_current( $this->postId, 'editorial' ) );
	}

	/** Activation update failure must leave the snapshot unusable (pending_audit), not approved. */
	public function test_activation_update_failure_leaves_pending(): void {
		$GLOBALS['lel_test_fail_activation'] = true;

		$record = Approval_Service::approve( $this->postId, 'editorial', $this->editorId );

		self::assertNull( $record, 'A failed activation update must not report success.' );
		self::assertNull( Approval_Repository::current( $this->postId, 'editorial' ) );
		$rows = $this->snapshotRows();
		self::assertCount( 1, $rows );
		self::assertNotSame( 'approved', (string) $rows[0]['approval_status'], 'A failed activation must never leave an approved row.' );
	}

	/** Reconciliation dry-run must detect a committed-but-unacknowledged audit event without mutating. */
	public function test_reconcile_dry_run_reports_without_mutation(): void {
		$this->seedCommittedButUnacknowledged();

		$result = Approval_Service::reconcile( true, 100 );

		self::assertIsArray( $result );
		self::assertSame( 1, (int) ( $result['recoverable'] ?? 0 ), 'Dry-run must count the recoverable pending snapshot.' );
		self::assertSame( 'pending_audit', (string) $this->snapshotRows()[0]['approval_status'], 'Dry-run must not mutate state.' );
	}

	/** Reconciliation must activate a committed-but-unacknowledged audit event. */
	public function test_reconcile_activates_committed_but_unacknowledged(): void {
		$this->seedCommittedButUnacknowledged();

		$result = Approval_Service::reconcile( false, 100 );

		self::assertSame( 1, (int) ( $result['activated'] ?? 0 ) );
		self::assertSame( 'approved', (string) $this->snapshotRows()[0]['approval_status'], 'A confirmed audit event must activate its pending snapshot.' );
	}

	/** Reconciliation must reject a pending snapshot that has no matching audit event. */
	public function test_reconcile_rejects_pending_without_audit(): void {
		$combined = str_repeat( 'b', 64 );
		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_approval_snapshots',
			array(
				array(
					'id'              => 1,
					'post_id'         => $this->postId,
					'approval_type'   => 'editorial',
					'approval_status' => 'pending_audit',
					'combined_hash'   => $combined,
					'invalidated_at'  => null,
					'audit_event_id'  => null,
					'activated_at'    => null,
					'approved_at'     => '2026-01-01 00:00:00',
				),
			)
		);

		$result = Approval_Service::reconcile( false, 100 );

		self::assertSame( 1, (int) ( $result['rejected'] ?? 0 ) );
		self::assertNotSame( 'approved', (string) $this->snapshotRows()[0]['approval_status'] );
	}

	/** An approved snapshot missing audit linkage must produce a blocking readiness state. */
	public function test_orphaned_approved_snapshot_blocks_readiness(): void {
		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_approval_snapshots',
			array(
				array(
					'id'              => 1,
					'post_id'         => $this->postId,
					'approval_type'   => 'editorial',
					'approval_status' => 'approved',
					'combined_hash'   => str_repeat( 'c', 64 ),
					'invalidated_at'  => null,
					'audit_event_id'  => null,
					'activated_at'    => null,
					'activation_error' => null,
				),
			)
		);

		$report = System_Readiness::report();
		self::assertArrayHasKey( 'approval_integrity', $report['checks'] );
		self::assertSame( 'blocked', $report['checks']['approval_integrity']['status'] );
		self::assertNotSame( 'ok', $report['status'] );
	}

	/**
	 * Insert a pending_audit snapshot plus a durably committed audit event whose
	 * deterministic idempotency key references that snapshot, simulating an
	 * unacknowledged-but-committed audit write.
	 */
	private function seedCommittedButUnacknowledged(): void {
		$combined  = str_repeat( 'd', 64 );
		$raw_key   = Approval_Service::mandatory_audit_key( 1, 'editorial', $combined );
		$hashed    = hash( 'sha256', $raw_key );
		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_approval_snapshots',
			array(
				array(
					'id'              => 1,
					'post_id'         => $this->postId,
					'approval_type'   => 'editorial',
					'approval_status' => 'pending_audit',
					'combined_hash'   => $combined,
					'invalidated_at'  => null,
					'audit_event_id'  => null,
					'activated_at'    => null,
					'approved_at'     => '2026-01-01 00:00:00',
				),
			)
		);
		$GLOBALS['wpdb']->lel_test_set_rows(
			'wp_lel_audit_events',
			array(
				array(
					'id'              => 5,
					'sequence'        => 1,
					'event_type'      => 'approval_completed',
					'object_type'     => 'post',
					'object_id'       => $this->postId,
					'idempotency_key' => $hashed,
					'event_hash'      => str_repeat( 'e', 64 ),
				),
			)
		);
	}
}

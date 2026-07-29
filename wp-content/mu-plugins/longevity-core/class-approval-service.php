<?php
/**
 * Approval snapshot workflow and invalidation.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Routes all governed approvals through immutable snapshots. */
final class Approval_Service {
	/** @var bool Prevent recursive invalidation. */
	private static bool $mutating = false;

	/** @var bool Suppress the per-field credential hook during batched writes. */
	private static bool $suppress_credential_hook = false;

	/** Register material-change invalidation hooks. */
	public static function init(): void {
		register_shutdown_function( array( Publication_Lock::class, 'release_all' ) );
		add_action( 'post_updated', array( self::class, 'on_post_updated' ), 20, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_meta_changed' ), 20, 4 );
		add_action( 'added_post_meta', array( self::class, 'on_meta_changed' ), 20, 4 );
		add_action( 'deleted_post_meta', array( self::class, 'on_meta_deleted' ), 20, 4 );

		// R2.4 — transitive dependency cascade
		add_action( 'added_post_meta', array( self::class, 'on_claim_post_id_set' ), 20, 4 );
		add_action( 'updated_post_meta', array( self::class, 'on_claim_post_id_set' ), 20, 4 );
		add_action( 'save_post_lel_claim', array( self::class, 'on_save_dependency' ), 20, 3 );
		add_action( 'save_post_lel_source', array( self::class, 'on_save_dependency' ), 20, 3 );
		add_action( 'save_post_lel_test_record', array( self::class, 'on_save_dependency' ), 20, 3 );
		add_action( 'save_post_lel_protocol', array( self::class, 'on_save_dependency' ), 20, 3 );
		add_action( 'save_post_lel_affiliate', array( self::class, 'on_save_dependency' ), 20, 3 );
		add_action( 'updated_user_meta', array( self::class, 'on_credential_changed' ), 20, 4 );
		add_action( 'added_user_meta', array( self::class, 'on_credential_changed' ), 20, 4 );
	}

	/** Cascade invalidation when a dependency post is saved (created or updated). */
	public static function on_save_dependency( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );
		if ( self::$mutating || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$type       = $post->post_type;
		$parent_ids = array();
		if ( 'lel_claim' === $type ) {
			$previous_parents = Dependency_Index::find_parents( 'lel_claim', $post_id );
			$pid = (int) get_post_meta( $post_id, 'post_id', true );
			Dependency_Index::remove_all_for_dependency( 'lel_claim', $post_id );
			$parent_ids = $previous_parents;
			if ( $pid > 0 ) {
				$parent_ids[] = $pid;
			}
		} elseif ( 'lel_source' === $type ) {
			$parent_ids = Dependency_Index::find_parents( 'lel_source', $post_id );
		} elseif ( 'lel_test_record' === $type ) {
			$parent_ids = Dependency_Index::find_parents( 'lel_test_record', $post_id );
		} elseif ( 'lel_protocol' === $type ) {
			$parent_ids = Dependency_Index::find_parents( 'lel_protocol', $post_id );
		} elseif ( 'lel_affiliate' === $type ) {
			$parent_ids = Dependency_Index::find_parents( 'lel_affiliate', $post_id );
		}
		$parent_ids = array_unique( array_filter( array_map( 'intval', $parent_ids ) ) );
		foreach ( $parent_ids as $parent_id ) {
			Dependency_Index::reindex_parent( $parent_id );
		}
		$actor = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( ! empty( $parent_ids ) ) {
			self::enqueue_durably( $parent_ids, 'dependency_changed:' . $type, $actor );
		}
	}

	/** Cascade when claim post_id meta is first set (post-insert gap). */
	public static function on_claim_post_id_set( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		unset( $meta_id );
		if ( self::$mutating || 'post_id' !== $meta_key || 'lel_claim' !== get_post_type( $post_id ) ) {
			return;
		}
		$pid        = (int) $meta_value;
		$parent_ids = Dependency_Index::find_parents( 'lel_claim', $post_id );
		$actor = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( $pid > 0 ) {
			$parent_ids[] = $pid;
		}
		$parent_ids = array_values( array_unique( array_filter( array_map( 'intval', $parent_ids ) ) ) );
		foreach ( $parent_ids as $parent_id ) {
			Dependency_Index::reindex_parent( $parent_id );
		}
		if ( ! empty( $parent_ids ) ) {
			self::enqueue_durably( $parent_ids, 'dependency_changed:claim_post_id', $actor );
		}
	}

	/** Toggle suppression of the per-field credential hook during batched writes. */
	public static function suppress_credential_hook( bool $suppress ): void {
		self::$suppress_credential_hook = $suppress;
	}

	/** Cascade invalidation when any verified reviewer-credential field changes. */
	public static function on_credential_changed( int $meta_id, int $user_id, string $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );
		if ( self::$mutating || self::$suppress_credential_hook || ! in_array( $meta_key, Reviewer_Credentials::verified_fields(), true ) ) {
			return;
		}
		$actor = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		Reviewer_Credentials::detect_and_cascade( $user_id, 'meta:' . $meta_key, $actor );
	}

	/** Resolve the approvals that depend on a reviewer credential and enqueue them. */
	public static function invalidate_dependents_of_credential( int $reviewer_id, string $reason, int $actor_id ): void {
		$parent_ids = Dependency_Index::find_parents( 'credential', $reviewer_id );
		if ( ! empty( $parent_ids ) ) {
			self::enqueue_durably( $parent_ids, $reason, $actor_id );
		}
	}

	/**
	 * Enqueue a cascade batch atomically, falling back to best-effort enqueue.
	 *
	 * The transactional path guarantees all-or-nothing queue rows; on failure
	 * the best-effort path (idempotent via the unique open-row key) retries so
	 * a save is never aborted, and the failure is logged for observability.
	 */
	private static function enqueue_durably( array $parent_ids, string $reason, int $actor_id ): void {
		if ( ! Audit_Log::request_is_healthy() ) {
			throw new \RuntimeException( 'Invalidation enqueue blocked: ' . Audit_Log::unhealthy_reason() );
		}
		try {
			Invalidation_Queue::enqueue_in_transaction( $parent_ids, $reason, $actor_id );
		} catch ( \Throwable $error ) {
			Logger::warning( 'invalidation_enqueue_transaction_failed', array( 'reason' => $reason, 'error' => substr( $error->getMessage(), 0, 200 ) ) );
			Invalidation_Queue::enqueue( $parent_ids, $reason, $actor_id );
		}
	}

	/** Create an immutable approval bound to current fingerprints. */
	public static function approve( int $post_id, string $approval_type, int $actor_id, array $approval_payload = array() ): ?array {
		if ( ! Audit_Log::request_is_healthy() ) {
			return null;
		}
		if ( ! Publication_Lock::acquire( $post_id ) ) {
			Audit_Log::record( 'approval_rejected', 'post', $post_id, array( 'approval_type' => $approval_type, 'reason' => 'publication_lock_unavailable' ), $actor_id, 'workflow' );
			return null;
		}
		try {
			if ( ! self::actor_can_approve( $post_id, $approval_type, $actor_id ) ) {
				Audit_Log::record( 'metadata_write_denied', 'post', $post_id, array( 'approval_type' => $approval_type ), $actor_id, 'workflow' );
				return null;
			}
			if ( ! self::state_is_approvable( $post_id, $approval_type ) ) {
				Audit_Log::record( 'approval_rejected', 'post', $post_id, array( 'approval_type' => $approval_type, 'reason' => 'invalid_or_incomplete_state' ), $actor_id, 'workflow' );
				return null;
			}
			$fingerprint = Approval_Fingerprint::build( $post_id, $approval_type );
			$current     = Approval_Repository::current( $post_id, $approval_type );
			// Insert quarantined: a snapshot is unusable until its mandatory audit
			// event is durably confirmed and the row is atomically activated.
			$record      = array(
				'post_id'                => $post_id,
				'approval_type'          => $approval_type,
				'approval_status'        => 'pending_audit',
				'revision_id'            => function_exists( 'wp_get_post_revisions' ) ? self::latest_revision_id( $post_id ) : null,
				'content_hash'           => $fingerprint['content_hash'],
				'governed_meta_hash'     => $fingerprint['governed_meta_hash'],
				'dependency_hash'        => $fingerprint['dependency_hash'],
				'combined_hash'          => $fingerprint['combined_hash'],
				'approver_user_id'       => $actor_id,
				'approved_at'            => gmdate( 'Y-m-d H:i:s' ),
				'schema_version'         => Approval_Fingerprint::SCHEMA_VERSION,
				'payload_json'           => (string) wp_json_encode( array( 'approval' => self::sanitize_payload( $approval_payload ), 'fingerprint' => $fingerprint['payload'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'invalidated_at'         => null,
				'invalidated_by_user_id' => null,
				'invalidation_reason'    => null,
				'supersedes_approval_id' => $current ? (int) $current['id'] : null,
				'audit_event_id'         => null,
				'activated_at'           => null,
				'activation_error'       => null,
			);
			$id = Approval_Repository::insert( $record );
			if ( $id <= 0 ) {
				return null;
			}
			$record['id'] = $id;

			// Fail-closed: an approval without a durable audit trail must not stand.
			$audit_key      = self::mandatory_audit_key( $id, $approval_type, $fingerprint['combined_hash'] );
			$audit_event_id = 0;
			try {
				$audit_event_id = Audit_Log::record( 'approval_completed', 'post', $post_id, array( 'approval_type' => $approval_type, 'approval_id' => $id, 'combined_hash' => $fingerprint['combined_hash'], 'synthetic_probe' => '1' === (string) get_post_meta( $post_id, '_lel_acceptance_probe', true ) ), $actor_id, 'workflow', true, $audit_key );
			} catch ( \Throwable $error ) {
				// A known-failed audit (server did not commit) rejects the pending
				// snapshot. An unknown COMMIT outcome quarantines the request and
				// leaves the snapshot pending for reconciliation — never guessed.
				if ( '' === Audit_Log::unhealthy_reason() ) {
					Approval_Repository::reject_pending( $id, 'audit_write_failed' );
					Audit_Log::record( 'approval_rejected', 'post', $post_id, array( 'approval_type' => $approval_type, 'reason' => 'audit_write_failed', 'approval_id' => $id ), $actor_id, 'workflow' );
				}
				return null;
			}
			if ( $audit_event_id <= 0 ) {
				if ( '' === Audit_Log::unhealthy_reason() ) {
					Approval_Repository::reject_pending( $id, 'audit_not_durable' );
				}
				return null;
			}
			// Atomic activation: the conditional update is the sole promotion gate.
			if ( Approval_Repository::activate( $id, $fingerprint['combined_hash'], $audit_event_id ) < 1 ) {
				Approval_Repository::note_activation_error( $id, 'activation_conditional_update_failed' );
				Audit_Log::record( 'approval_activation_failed', 'post', $post_id, array( 'approval_type' => $approval_type, 'approval_id' => $id ), $actor_id, 'workflow' );
				return null;
			}
			$record['approval_status']  = 'approved';
			$record['audit_event_id']   = $audit_event_id;
			$record['activated_at']     = gmdate( 'Y-m-d H:i:s' );
			$record['activation_error'] = null;

			// Only after confirmed activation may compatibility state be projected.
			self::project_legacy_status( $post_id, $approval_type, $actor_id );
			return $record;
		} finally {
			Publication_Lock::release( $post_id );
		}
	}

	/**
	 * Deterministic idempotency key for an approval's mandatory audit event.
	 *
	 * Derived from the snapshot ID, approval type, and combined fingerprint so
	 * that a retry of the same approval reuses the same audit event and
	 * reconciliation can locate the event for a pending snapshot.
	 */
	public static function mandatory_audit_key( int $snapshot_id, string $approval_type, string $combined_hash ): string {
		return 'approval_completed:' . $snapshot_id . ':' . $approval_type . ':' . $combined_hash;
	}

	/**
	 * Reconcile pending approval snapshots against the durable audit log.
	 *
	 * Bounded and idempotent: activates snapshots whose mandatory audit event is
	 * confirmed and correctly linked, rejects snapshots that have no matching
	 * event, and counts (never guesses) mismatched linkage as an integrity
	 * error. Never prints private payloads.
	 *
	 * @return array{scanned:int, recoverable:int, rejectable:int, activated:int, rejected:int, orphaned:int, errors:int, dry_run:bool, complete:bool}
	 */
	public static function reconcile( bool $dry_run = true, int $batch = 200 ): array {
		$batch       = max( 1, min( 500, $batch ) );
		$after       = 0;
		$scanned     = 0;
		$recoverable = 0;
		$rejectable  = 0;
		$activated   = 0;
		$rejected    = 0;
		$errors      = 0;
		do {
			$rows = Approval_Repository::pending_batch( $after, $batch );
			foreach ( $rows as $row ) {
				++$scanned;
				$id       = (int) ( $row['id'] ?? 0 );
				$after    = max( $after, $id );
				$type     = (string) ( $row['approval_type'] ?? '' );
				$combined = (string) ( $row['combined_hash'] ?? '' );
				$post_id  = (int) ( $row['post_id'] ?? 0 );
				$event    = Audit_Log::event_for_idempotency_key( self::mandatory_audit_key( $id, $type, $combined ) );
				if ( is_array( $event ) && (int) $event['id'] > 0 ) {
					if ( 'approval_completed' !== (string) $event['event_type'] || (int) $event['object_id'] !== $post_id ) {
						// Ambiguous or mismatched linkage: never guess. Leave pending
						// and surface as an integrity error for human review.
						++$errors;
						continue;
					}
					++$recoverable;
					if ( ! $dry_run && Approval_Repository::activate( $id, $combined, (int) $event['id'] ) > 0 ) {
						++$activated;
					}
					continue;
				}
				++$rejectable;
				if ( ! $dry_run && Approval_Repository::reject_pending( $id, 'reconcile_no_audit_event' ) > 0 ) {
					++$rejected;
				}
			}
		} while ( count( $rows ) === $batch );
		$orphaned = Approval_Repository::count_orphaned_approved();
		return array(
			'scanned'     => $scanned,
			'recoverable' => $recoverable,
			'rejectable'  => $rejectable,
			'activated'   => $activated,
			'rejected'    => $rejected,
			'orphaned'    => $orphaned,
			'errors'      => $errors,
			'dry_run'     => $dry_run,
			'complete'    => 0 === $errors && 0 === $orphaned,
		);
	}

	/** Whether the current snapshot still matches all material state. */
	public static function is_current( int $post_id, string $approval_type ): bool {
		$current = Approval_Repository::current( $post_id, $approval_type );
		if ( ! $current ) {
			return false;
		}
		if ( function_exists( 'wp_get_post_revisions' ) && null !== ( $current['revision_id'] ?? null ) && (int) $current['revision_id'] !== (int) self::latest_revision_id( $post_id ) ) {
			return false;
		}
		$fingerprint = Approval_Fingerprint::build( $post_id, $approval_type );
		return hash_equals( (string) $current['combined_hash'], (string) $fingerprint['combined_hash'] );
	}

	/** Whether the current snapshot matches the complete prospective request state. */
	public static function is_current_for_state( int $post_id, string $approval_type, array $prospective ): bool {
		$current = Approval_Repository::current( $post_id, $approval_type );
		if ( ! $current ) {
			return false;
		}
		if ( function_exists( 'wp_get_post_revisions' ) && null !== ( $current['revision_id'] ?? null ) && (int) $current['revision_id'] !== (int) self::latest_revision_id( $post_id ) ) {
			return false;
		}
		$fingerprint = Approval_Fingerprint::build( $post_id, $approval_type, $prospective );
		return hash_equals( (string) $current['combined_hash'], (string) $fingerprint['combined_hash'] );
	}

	/** Invalidate an approval type and project an explicit stale state. */
	public static function invalidate( int $post_id, string $approval_type, string $reason, int $actor_id = 0 ): void {
		if ( ! Audit_Log::request_is_healthy() ) {
			throw new \RuntimeException( 'Approval invalidation blocked: ' . Audit_Log::unhealthy_reason() );
		}
		if ( self::$mutating ) {
			return;
		}
		if ( ! Publication_Lock::acquire( $post_id ) ) {
			// Enqueue for retry instead of silently dropping.
			Invalidation_Queue::enqueue( array( $post_id ), $reason . ':lock_retry', $actor_id );
			return;
		}
		self::$mutating = true;
		$intent_id      = 0;
		try {
			$intent_id = self::record_invalidation_intent( $post_id, $approval_type, $reason, $actor_id );
			$changed = self::invalidate_snapshots( $post_id, $approval_type, $reason, $actor_id );
			if ( $changed > 0 ) {
				$status_key = self::status_key( $approval_type );
				if ( $status_key ) {
					Meta_Authorization::enter_trusted_scope();
					try {
						self::persist_stale_status( $post_id, $status_key );
					} finally {
						Meta_Authorization::exit_trusted_scope();
					}
				}
			}
			Audit_Log::record( 'approval_invalidated', 'post', $post_id, array( 'approval_type' => $approval_type, 'reason' => $reason, 'intent_event_id' => $intent_id, 'changed' => $changed ), $actor_id, 'system', true );
		} catch ( \Throwable $error ) {
			self::reconcile_invalidation_failure( $post_id, $reason, $actor_id, $intent_id, '', $error );
			throw $error;
		} finally {
			self::$mutating = false;
			Publication_Lock::release( $post_id );
		}
	}

	/** Invalidate snapshots when post content materially changes. */
	public static function invalidate_direct( int $post_id, string $reason, int $actor_id = 0, bool $check_current = false, string $idempotency_key = '' ): int {
		if ( ! Audit_Log::request_is_healthy() ) {
			throw new \RuntimeException( 'Approval invalidation blocked: ' . Audit_Log::unhealthy_reason() );
		}
		if ( ! Publication_Lock::acquire( $post_id ) ) {
			throw new \RuntimeException( sprintf( 'Could not acquire publication lock for post %d.', $post_id ) );
		}
		self::$mutating = true;
		$intent_id      = 0;
		try {
			$intent_id = self::record_invalidation_intent( $post_id, 'all', $reason, $actor_id, $idempotency_key );
			Meta_Authorization::enter_trusted_scope();
			try {
				foreach ( array( 'fact_check', 'medical', 'testing', 'commercial', 'editorial' ) as $type ) {
					if ( $check_current && self::is_current( $post_id, $type ) ) {
						continue;
					}
					$changed    = self::invalidate_snapshots( $post_id, $type, $reason, $actor_id );
					$status_key = self::status_key( $type );
					if ( $status_key && ( $changed > 0 || '' !== (string) get_post_meta( $post_id, $status_key, true ) ) ) {
						self::persist_stale_status( $post_id, $status_key );
					}
				}
			} finally {
				Meta_Authorization::exit_trusted_scope();
			}
			$completion_key = '' === $idempotency_key ? '' : $idempotency_key . ':completed';
			$event_id       = Audit_Log::record( 'approval_invalidated', 'post', $post_id, array( 'reason' => $reason, 'source' => '' === $idempotency_key ? 'direct' : 'queue', 'intent_event_id' => $intent_id ), $actor_id, 'system', true, $completion_key );
			Rankings::invalidate_review( $post_id, 'approval_invalidated' );
			return $event_id;
		} catch ( \Throwable $error ) {
			self::reconcile_invalidation_failure( $post_id, $reason, $actor_id, $intent_id, $idempotency_key, $error );
			throw $error;
		} finally {
			self::$mutating = false;
			Publication_Lock::release( $post_id );
		}
	}

	/** Invalidate snapshots when post content materially changes. */
	public static function on_post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( self::$mutating || ! in_array( $post_after->post_type, array( 'post', 'review' ), true ) ) {
			return;
		}
		$before = array( $post_before->post_title, $post_before->post_excerpt, $post_before->post_content, $post_before->post_author );
		$after  = array( $post_after->post_title, $post_after->post_excerpt, $post_after->post_content, $post_after->post_author );
		if ( $before !== $after ) {
			Dependency_Index::reindex_parent( $post_id );
			self::invalidate_direct( $post_id, 'content_changed', function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0, false );
		}
	}

	/** Invalidate only when governed metadata changes. */
	public static function on_meta_changed( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );
		if ( self::$mutating ) {
			return;
		}
		if ( in_array( $meta_key, array( 'test_record_id', 'medical_reviewer_user_id', '_lel_has_affiliate_links' ), true ) ) {
			Dependency_Index::reindex_parent( $post_id );
		}
		$defs = Meta_Registry::definitions();
		if ( empty( $defs ) ) {
			self::invalidate_all( $post_id, 'governed_meta_changed:registry_unavailable', function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
			return;
		}
		if ( ! isset( $defs[ $meta_key ] ) ) {
			return;
		}
		$policy = Meta_Authorization::policy_for( $meta_key );
		if ( 'post_editor' === $policy && ! in_array( $meta_key, array( 'content_summary', 'content_scope', 'content_limitations', 'region_scope', 'original_contribution' ), true ) ) {
			return;
		}
		self::invalidate_all( $post_id, 'governed_meta_changed:' . $meta_key, function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
	}

	/** Deleted metadata callback has a different fourth argument shape. */
	public static function on_meta_deleted( array $meta_ids, int $post_id, string $meta_key, $meta_value ): void {
		unset( $meta_ids );
		self::on_meta_changed( 0, $post_id, $meta_key, $meta_value );
	}

	/** Actor/capability and separation-of-duty checks. */
	private static function actor_can_approve( int $post_id, string $type, int $actor_id ): bool {
		if ( $actor_id <= 0 || ! function_exists( 'user_can' ) ) {
			return false;
		}
		if ( 'medical' === $type ) {
			$assigned = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
			$verifier = (int) get_user_meta( $actor_id, 'credential_verified_by_user_id', true );
			return $assigned === $actor_id && $verifier !== $actor_id && user_can( $actor_id, 'complete_medical_review' ) && Reviewer_Credentials::is_valid_for( $actor_id, (string) get_post_meta( $post_id, 'medical_review_scope', true ), (string) get_post_meta( $post_id, 'region_scope', true ) );
		}
		if ( 'fact_check' === $type ) {
			return user_can( $actor_id, 'complete_fact_check' );
		}
		if ( 'testing' === $type ) {
			$record_id = (int) get_post_meta( $post_id, 'test_record_id', true );
			$testers   = preg_split( '/[\s,]+/', (string) get_post_meta( $record_id, 'tester_user_ids', true ) ) ?: array();
			$submitter = (int) get_post_meta( $record_id, 'submitted_by', true );
			$record    = get_post( $record_id );
			return user_can( $actor_id, 'approve_test_records' )
				&& ! in_array( (string) $actor_id, $testers, true )
				&& $submitter !== $actor_id
				&& ( ! $record || (int) $record->post_author !== $actor_id );
		}
		if ( 'commercial' === $type ) {
			if ( ! user_can( $actor_id, 'approve_commercial_disclosure' ) ) {
				return false;
			}
			$post = get_post( $post_id );
			$require_independent = (bool) get_option( 'lel_require_independent_commercial_approval', true );
			$owners = $post ? Affiliate_Registry::relationship_owners_for_content( (string) $post->post_content ) : array();
			return ! $require_independent || ! in_array( $actor_id, $owners, true );
		}
		if ( 'editorial' === $type ) {
			return user_can( $actor_id, 'approve_publication' );
		}
		if ( 'trust_page' === $type ) {
			return user_can( $actor_id, 'approve_trust_pages' );
		}
		return false;
	}

	/** Validate semantic dates and approval-specific dependencies before snapshotting. */
	private static function state_is_approvable( int $post_id, string $type ): bool {
		$today = Date_Validator::today();
		if ( 'medical' === $type ) {
			$next = (string) get_post_meta( $post_id, 'next_medical_review_date', true );
			return Date_Validator::is_valid( $next ) && Date_Validator::after( $next, $today );
		}
		if ( 'fact_check' === $type ) {
			$next = (string) get_post_meta( $post_id, 'next_fact_check_date', true );
			return Date_Validator::is_valid( $next ) && Date_Validator::after( $next, $today );
		}
		if ( 'testing' === $type ) {
			$start     = (string) get_post_meta( $post_id, 'testing_start_date', true );
			$end       = (string) get_post_meta( $post_id, 'testing_end_date', true );
			$record_id = (int) get_post_meta( $post_id, 'test_record_id', true );
			$version   = (string) get_post_meta( $post_id, 'testing_protocol_version', true );
			if ( ! Date_Validator::is_valid( $start ) || ! Date_Validator::is_valid( $end ) || Date_Validator::compare( $end, $start ) < 0 || ! Review_Methodology::valid_test_record( $record_id, $version ) ) {
				return false;
			}
			foreach ( array( 'price_checked_date', 'warranty_checked_date', 'return_policy_checked_date', 'privacy_policy_checked_date' ) as $field ) {
				$value = (string) get_post_meta( $post_id, $field, true );
				if ( '' !== $value && ( ! Date_Validator::is_valid( $value ) || Date_Validator::after( $value, $today ) ) ) {
					return false;
				}
			}
			return true;
		}
		if ( 'commercial' === $type ) {
			$post = get_post( $post_id );
			return $post && Affiliate_Registry::all_destinations_registered( (string) $post->post_content );
		}
		if ( 'editorial' === $type ) {
			$next = (string) get_post_meta( $post_id, 'next_content_review_date', true );
			return Date_Validator::is_valid( $next ) && Date_Validator::after( $next, $today );
		}
		if ( 'trust_page' === $type ) {
			$post = get_post( $post_id );
			return $post
				&& Trust_Pages::is_trust_page( $post_id )
				&& '' !== trim( (string) $post->post_content )
				&& ! Trust_Pages::has_placeholders( (string) $post->post_content );
		}
		return false;
	}

	/** Project snapshot success into existing metadata for compatibility. */
	private static function project_legacy_status( int $post_id, string $type, int $actor_id ): void {
		self::$mutating = true;
		try {
			$today = Date_Validator::today();
			Meta_Authorization::enter_trusted_scope();
			try {
				switch ( $type ) {
					case 'medical':
						update_post_meta( $post_id, 'medical_review_status', 'complete' );
						update_post_meta( $post_id, 'medical_review_attested', true );
						update_post_meta( $post_id, 'medical_review_date', $today );
						break;
					case 'fact_check':
						update_post_meta( $post_id, 'fact_check_status', 'complete' );
						update_post_meta( $post_id, 'fact_checked_by', $actor_id );
						update_post_meta( $post_id, 'fact_checked_date', $today );
						break;
					case 'testing':
						update_post_meta( $post_id, 'testing_status', 'approved' );
						break;
					case 'commercial':
						update_post_meta( $post_id, 'affiliate_disclosure_status', 'approved' );
						break;
					case 'editorial':
						update_post_meta( $post_id, 'editorial_approval_status', 'ready' );
						break;
					case 'trust_page':
						update_post_meta( $post_id, 'trust_reviewed_by', $actor_id );
						update_post_meta( $post_id, 'trust_last_reviewed_date', $today );
						break;
				}
			} finally {
				Meta_Authorization::exit_trusted_scope();
			}
		} finally {
			self::$mutating = false;
		}
	}

	/** Invalidate every approval type via the async queue. */
	private static function invalidate_all( int $post_id, string $reason, int $actor_id ): void {
		Invalidation_Queue::enqueue( array( $post_id ), $reason, $actor_id );
	}

	/** Legacy status key. */
	private static function status_key( string $type ): string {
		return array(
			'fact_check' => 'fact_check_status',
			'medical'    => 'medical_review_status',
			'testing'    => 'testing_status',
			'commercial' => 'affiliate_disclosure_status',
			'editorial'  => 'editorial_approval_status',
		)[ $type ] ?? '';
	}

	/** Latest revision ID. */
	private static function latest_revision_id( int $post_id ): ?int {
		$revisions = wp_get_post_revisions( $post_id, array( 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'DESC' ) );
		$revision  = $revisions ? reset( $revisions ) : false;
		return $revision ? (int) $revision->ID : null;
	}

	/** Bounded approval payload. */
	private static function sanitize_payload( array $payload ): array {
		$out = array();
		foreach ( array_slice( $payload, 0, 20, true ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || in_array( $key, array( 'credential_verification_evidence_ref', 'raw_observations', 'evidence_references' ), true ) ) {
				continue;
			}
			$out[ $key ] = is_scalar( $value ) ? substr( sanitize_text_field( (string) $value ), 0, 255 ) : null;
		}
		return $out;
	}

	/** Invalidate snapshots without allowing a database error to look like no-op. */
	private static function invalidate_snapshots( int $post_id, string $type, string $reason, int $actor_id ): int {
		global $wpdb;
		if ( is_object( $wpdb ) ) {
			$wpdb->last_error = '';
		}
		$changed = Approval_Repository::invalidate( $post_id, $type, $reason, $actor_id );
		if ( is_object( $wpdb ) && '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			throw new \RuntimeException( 'Approval invalidation persistence failed: ' . substr( (string) $wpdb->last_error, 0, 200 ) );
		}
		return $changed;
	}

	/** Idempotently project stale status and surface metadata storage errors. */
	private static function persist_stale_status( int $post_id, string $status_key ): void {
		global $wpdb;
		if ( is_object( $wpdb ) ) {
			$wpdb->last_error = '';
		}
		update_post_meta( $post_id, $status_key, 'stale' );
		if ( is_object( $wpdb ) && '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			throw new \RuntimeException( 'Approval status projection failed: ' . substr( (string) $wpdb->last_error, 0, 200 ) );
		}
	}

	/** Durably audit intent before any approval or compatibility state changes. */
	private static function record_invalidation_intent( int $post_id, string $approval_type, string $reason, int $actor_id, string $idempotency_key = '' ): int {
		$intent_key = '' === $idempotency_key ? '' : $idempotency_key . ':intent';
		return Audit_Log::record( 'approval_invalidation_intent', 'post', $post_id, array( 'approval_type' => $approval_type, 'reason' => $reason ), $actor_id, 'system', true, $intent_key );
	}

	/** Leave durable retry work when a post-intent invalidation step fails. */
	private static function reconcile_invalidation_failure( int $post_id, string $reason, int $actor_id, int $intent_id, string $idempotency_key, \Throwable $error ): void {
		if ( $intent_id <= 0 || '' !== $idempotency_key ) {
			return; // Queue jobs retain their existing outbox row and lease for retry.
		}
		try {
			Invalidation_Queue::enqueue_reconciliation( $post_id, 'reconcile_intent:' . $intent_id . ':' . $reason, $actor_id );
		} catch ( \Throwable $queue_error ) {
			Logger::error(
				'invalidation_reconciliation_enqueue_failed',
				array(
					'post_id'         => $post_id,
					'intent_event_id' => $intent_id,
					'error'           => substr( $error->getMessage() . '; ' . $queue_error->getMessage(), 0, 200 ),
				)
			);
		}
	}
}

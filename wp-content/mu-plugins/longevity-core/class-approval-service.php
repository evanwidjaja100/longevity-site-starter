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
			$pid = (int) get_post_meta( $post_id, 'post_id', true );
			if ( $pid > 0 ) {
				$parent_ids[] = $pid;
				Dependency_Index::register( 'lel_claim', $post_id, $pid );
			}
		} elseif ( 'lel_source' === $type ) {
			// Use indexed lookup instead of unbounded meta query.
			$parent_ids = Dependency_Index::find_parents( 'lel_source', $post_id );
			if ( empty( $parent_ids ) ) {
				// Fallback for pre-index data: bounded query.
				$claims = get_posts( array( 'post_type' => 'lel_claim', 'fields' => 'ids', 'posts_per_page' => 200, 'meta_key' => 'source_id', 'meta_value' => $post_id ) );
				foreach ( $claims as $claim_id ) {
					$pid = (int) get_post_meta( $claim_id, 'post_id', true );
					if ( $pid > 0 ) {
						$parent_ids[] = $pid;
						Dependency_Index::register( 'lel_source', $post_id, $pid );
					}
				}
			}
		} elseif ( 'lel_test_record' === $type ) {
			$parent_ids = Dependency_Index::find_parents( 'lel_test_record', $post_id );
			if ( empty( $parent_ids ) ) {
				$posts = get_posts( array( 'post_type' => array( 'post', 'review' ), 'fields' => 'ids', 'posts_per_page' => 200, 'meta_key' => 'test_record_id', 'meta_value' => $post_id ) );
				foreach ( $posts as $pid ) {
					Dependency_Index::register( 'lel_test_record', $post_id, (int) $pid );
				}
				$parent_ids = $posts;
			}
		} elseif ( 'lel_protocol' === $type ) {
			$parent_ids = Dependency_Index::find_parents( 'lel_protocol', $post_id );
			if ( empty( $parent_ids ) ) {
				$records = get_posts( array( 'post_type' => 'lel_test_record', 'fields' => 'ids', 'posts_per_page' => 200, 'meta_key' => 'protocol_id', 'meta_value' => $post_id ) );
				foreach ( $records as $record_id ) {
					$child_posts = get_posts( array( 'post_type' => array( 'post', 'review' ), 'fields' => 'ids', 'posts_per_page' => 200, 'meta_key' => 'test_record_id', 'meta_value' => $record_id ) );
					foreach ( $child_posts as $pid ) {
						$parent_ids[] = (int) $pid;
						Dependency_Index::register( 'lel_protocol', $post_id, (int) $pid );
					}
				}
			}
		} elseif ( 'lel_affiliate' === $type ) {
			// Use precomputed meta flag instead of full content scan.
			$parent_ids = Dependency_Index::find_parents( 'lel_affiliate', $post_id );
			if ( empty( $parent_ids ) ) {
				$posts = get_posts( array( 'post_type' => array( 'post', 'review' ), 'post_status' => 'any', 'posts_per_page' => 200, 'meta_key' => '_lel_has_affiliate_links', 'meta_value' => '1' ) );
				foreach ( $posts as $candidate_id ) {
					$parent_ids[] = (int) $candidate_id;
					Dependency_Index::register( 'lel_affiliate', $post_id, (int) $candidate_id );
				}
			}
		}
		$actor = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$parent_ids = array_unique( array_filter( array_map( 'intval', $parent_ids ) ) );
		if ( ! empty( $parent_ids ) ) {
			Invalidation_Queue::enqueue( $parent_ids, 'dependency_changed:' . $type, $actor );
		}
	}

	/** Cascade when claim post_id meta is first set (post-insert gap). */
	public static function on_claim_post_id_set( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		unset( $meta_id );
		if ( self::$mutating || 'post_id' !== $meta_key || 'lel_claim' !== get_post_type( $post_id ) ) {
			return;
		}
		$pid   = (int) $meta_value;
		$actor = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( $pid > 0 ) {
			self::invalidate_all( $pid, 'dependency_changed:claim_post_id', $actor );
		}
	}

	/** Cascade invalidation when reviewer credentials change. */
	public static function on_credential_changed( int $meta_id, int $user_id, string $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );
		if ( self::$mutating || ! in_array( $meta_key, array( 'credential_verification_status', 'credential_verified_by_user_id' ), true ) ) {
			return;
		}
		// Use indexed lookup instead of unbounded meta query.
		$parent_ids = Dependency_Index::find_parents( 'credential', $user_id );
		if ( empty( $parent_ids ) ) {
			// Fallback for pre-index data: bounded query.
			$posts = get_posts( array( 'post_type' => array( 'post', 'review' ), 'fields' => 'ids', 'posts_per_page' => 200, 'meta_key' => 'medical_reviewer_user_id', 'meta_value' => $user_id ) );
			foreach ( $posts as $pid ) {
				Dependency_Index::register( 'credential', $user_id, (int) $pid );
			}
			$parent_ids = array_map( 'intval', $posts );
		}
		$actor = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( ! empty( $parent_ids ) ) {
			Invalidation_Queue::enqueue( $parent_ids, 'dependency_changed:credential:' . $meta_key, $actor );
		}
	}

	/** Create an immutable approval bound to current fingerprints. */
	public static function approve( int $post_id, string $approval_type, int $actor_id, array $approval_payload = array() ): ?array {
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
			$record      = array(
				'post_id'                => $post_id,
				'approval_type'          => $approval_type,
				'approval_status'        => 'approved',
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
			);
			$id = Approval_Repository::insert( $record );
			if ( $id <= 0 ) {
				return null;
			}
			$record['id'] = $id;
			// Fail-closed: an approval without a durable audit trail must not stand.
			try {
				Audit_Log::record( 'approval_completed', 'post', $post_id, array( 'approval_type' => $approval_type, 'approval_id' => $id, 'combined_hash' => $fingerprint['combined_hash'] ), $actor_id, 'workflow', true );
			} catch ( \Throwable $error ) {
				Approval_Repository::invalidate( $post_id, $approval_type, 'audit_write_failed', $actor_id );
				Audit_Log::record( 'approval_rejected', 'post', $post_id, array( 'approval_type' => $approval_type, 'reason' => 'audit_write_failed', 'approval_id' => $id ), $actor_id, 'workflow' );
				return null;
			}
			self::project_legacy_status( $post_id, $approval_type, $actor_id );
			return $record;
		} finally {
			Publication_Lock::release( $post_id );
		}
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
		if ( self::$mutating ) {
			return;
		}
		if ( ! Publication_Lock::acquire( $post_id ) ) {
			// Enqueue for retry instead of silently dropping.
			Invalidation_Queue::enqueue( array( $post_id ), $reason . ':lock_retry', $actor_id );
			return;
		}
		self::$mutating = true;
		try {
			$changed = Approval_Repository::invalidate( $post_id, $approval_type, $reason, $actor_id );
			if ( $changed > 0 ) {
				$status_key = self::status_key( $approval_type );
				if ( $status_key ) {
					Meta_Authorization::enter_trusted_scope();
					try {
						update_post_meta( $post_id, $status_key, 'stale' );
					} finally {
						Meta_Authorization::exit_trusted_scope();
					}
				}
				Audit_Log::record( 'approval_invalidated', 'post', $post_id, array( 'approval_type' => $approval_type, 'reason' => $reason ), $actor_id, 'system' );
			}
		} finally {
			self::$mutating = false;
			Publication_Lock::release( $post_id );
		}
	}

	/** Invalidate snapshots when post content materially changes. */
	public static function invalidate_direct( int $post_id, string $reason, int $actor_id = 0, bool $check_current = false ): void {
		if ( ! Publication_Lock::acquire( $post_id ) ) {
			throw new \RuntimeException( sprintf( 'Could not acquire publication lock for post %d.', $post_id ) );
		}
		self::$mutating = true;
		try {
			Meta_Authorization::enter_trusted_scope();
			try {
				foreach ( array( 'fact_check', 'medical', 'testing', 'commercial', 'editorial' ) as $type ) {
					if ( $check_current && self::is_current( $post_id, $type ) ) {
						continue;
					}
					$changed = Approval_Repository::invalidate( $post_id, $type, $reason, $actor_id );
					if ( $changed > 0 ) {
						$status_key = self::status_key( $type );
						if ( $status_key ) {
							update_post_meta( $post_id, $status_key, 'stale' );
						}
					}
				}
			} finally {
				Meta_Authorization::exit_trusted_scope();
			}
			Audit_Log::record( 'approval_invalidated', 'post', $post_id, array( 'reason' => $reason, 'source' => 'queue' ), $actor_id, 'system' );
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
			self::invalidate_direct( $post_id, 'content_changed', function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0, false );
		}
	}

	/** Invalidate only when governed metadata changes. */
	public static function on_meta_changed( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );
		if ( self::$mutating ) {
			return;
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
}

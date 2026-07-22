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
		add_action( 'post_updated', array( self::class, 'on_post_updated' ), 20, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_meta_changed' ), 20, 4 );
		add_action( 'added_post_meta', array( self::class, 'on_meta_changed' ), 20, 4 );
		add_action( 'deleted_post_meta', array( self::class, 'on_meta_deleted' ), 20, 4 );
	}

	/** Create an immutable approval bound to current fingerprints. */
	public static function approve( int $post_id, string $approval_type, int $actor_id, array $approval_payload = array() ): ?array {
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
			'post_id'                 => $post_id,
			'approval_type'           => $approval_type,
			'approval_status'         => 'approved',
			'revision_id'             => function_exists( 'wp_get_post_revisions' ) ? self::latest_revision_id( $post_id ) : null,
			'content_hash'            => $fingerprint['content_hash'],
			'governed_meta_hash'      => $fingerprint['governed_meta_hash'],
			'dependency_hash'         => $fingerprint['dependency_hash'],
			'combined_hash'           => $fingerprint['combined_hash'],
			'approver_user_id'        => $actor_id,
			'approved_at'             => gmdate( 'Y-m-d H:i:s' ),
			'schema_version'          => Approval_Fingerprint::SCHEMA_VERSION,
			'payload_json'            => (string) wp_json_encode( array( 'approval' => self::sanitize_payload( $approval_payload ), 'fingerprint' => $fingerprint['payload'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'invalidated_at'          => null,
			'invalidated_by_user_id'  => null,
			'invalidation_reason'     => null,
			'supersedes_approval_id'  => $current ? (int) $current['id'] : null,
		);
		$id = Approval_Repository::insert( $record );
		if ( $id <= 0 ) {
			return null;
		}
		$record['id'] = $id;
		self::project_legacy_status( $post_id, $approval_type, $actor_id );
		Audit_Log::record( 'approval_completed', 'post', $post_id, array( 'approval_type' => $approval_type, 'approval_id' => $id, 'combined_hash' => $fingerprint['combined_hash'] ), $actor_id, 'workflow' );
		return $record;
	}

	/** Current snapshot. */
	public static function current( int $post_id, string $approval_type ): ?array {
		return Approval_Repository::current( $post_id, $approval_type );
	}

	/** Whether the current snapshot still matches all material state. */
	public static function is_current( int $post_id, string $approval_type ): bool {
		$current = Approval_Repository::current( $post_id, $approval_type );
		if ( ! $current ) {
			return false;
		}
		$fingerprint = Approval_Fingerprint::build( $post_id, $approval_type );
		return hash_equals( (string) $current['combined_hash'], (string) $fingerprint['combined_hash'] );
	}

	/** Invalidate an approval type and project an explicit stale state. */
	public static function invalidate( int $post_id, string $approval_type, string $reason, int $actor_id = 0 ): void {
		if ( self::$mutating ) {
			return;
		}
		self::$mutating = true;
		try {
			$changed = Approval_Repository::invalidate( $post_id, $approval_type, $reason, $actor_id );
			if ( $changed > 0 ) {
				$status_key = self::status_key( $approval_type );
				if ( $status_key ) {
					update_post_meta( $post_id, $status_key, 'stale' );
				}
				Audit_Log::record( 'approval_invalidated', 'post', $post_id, array( 'approval_type' => $approval_type, 'reason' => $reason ), $actor_id, 'system' );
			}
		} finally {
			self::$mutating = false;
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
			self::invalidate_all( $post_id, 'content_changed', function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		}
	}

	/** Invalidate only when governed metadata changes. */
	public static function on_meta_changed( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );
		if ( self::$mutating || ! isset( Meta_Registry::definitions()[ $meta_key ] ) ) {
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
			self::$mutating = false;
		}
	}

	/** Invalidate every approval type. */
	private static function invalidate_all( int $post_id, string $reason, int $actor_id ): void {
		foreach ( array( 'fact_check', 'medical', 'testing', 'commercial', 'editorial' ) as $type ) {
			self::invalidate( $post_id, $type, $reason, $actor_id );
		}
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

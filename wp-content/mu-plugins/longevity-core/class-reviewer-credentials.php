<?php
/**
 * Independent reviewer credential verification.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Separates claimed reviewer profile data from verified credential snapshots. */
final class Reviewer_Credentials {
	public const VERSION = '1.0.0';

	/** Daily bounded expiration sweep cron hook. */
	private const EXPIRATION_HOOK = 'lel_credential_expiration_sweep';

	/** Immutable fingerprint of the last cascaded verified snapshot. */
	public const FINGERPRINT_META = 'credential_snapshot_fingerprint';

	/** Register the scheduled expiration worker. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'schedule' ), 30 );
		add_action( self::EXPIRATION_HOOK, array( self::class, 'run_scheduled' ) );
	}

	/** Schedule the daily bounded expiration sweep if not already scheduled. */
	public static function schedule(): void {
		if ( ! Migrations::wordpress_ready() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::EXPIRATION_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EXPIRATION_HOOK );
		}
	}

	/** Cron adapter that expires overdue verified credentials as of today. */
	public static function run_scheduled(): void {
		self::run_expiration_sweep();
	}

	/** Reviewer-editable profile fields. */
	public static function claimed_fields(): array {
		return array( 'professional_credentials', 'professional_profile_url', 'review_scope', 'jurisdictions', 'conflict_disclosure' );
	}

	/** Independently controlled verification fields. */
	public static function verified_fields(): array {
		return array(
			'credential_verification_status',
			'credential_verification_date',
			'credential_expiration_date',
			'credential_verified_by_user_id',
			'credential_verification_evidence_ref',
			'verified_professional_credentials',
			'verified_review_scope',
			'verified_jurisdictions',
			'credential_verification_version',
		);
	}

	/**
	 * Whether an actor may verify another reviewer.
	 *
	 * @param int $actor_id    Acting user ID.
	 * @param int $reviewer_id Reviewer user ID.
	 */
	public static function can_verify( int $actor_id, int $reviewer_id ): bool {
		return $actor_id > 0 && $reviewer_id > 0 && $actor_id !== $reviewer_id && function_exists( 'user_can' ) && user_can( $actor_id, 'verify_reviewer_credentials' );
	}

	/**
	 * Persist a verified snapshot. This method never infers or invents credential data.
	 *
	 * @param int   $reviewer_id Reviewer user ID.
	 * @param array $data        Submitted verification field values.
	 * @param int   $actor_id    Acting verifier user ID.
	 */
	public static function verify( int $reviewer_id, array $data, int $actor_id ): bool {
		if ( ! self::can_verify( $actor_id, $reviewer_id ) ) {
			return false;
		}
		$verified_on = Date_Validator::normalize( $data['credential_verification_date'] ?? '' );
		$expires_on  = Date_Validator::normalize( $data['credential_expiration_date'] ?? '' );
		$credentials = trim( sanitize_textarea_field( (string) ( $data['verified_professional_credentials'] ?? '' ) ) );
		$scope       = trim( sanitize_textarea_field( (string) ( $data['verified_review_scope'] ?? '' ) ) );
		$regions     = trim( sanitize_textarea_field( (string) ( $data['verified_jurisdictions'] ?? '' ) ) );
		$evidence    = trim( sanitize_text_field( (string) ( $data['credential_verification_evidence_ref'] ?? '' ) ) );
		if ( '' === $verified_on || Date_Validator::after( $verified_on, Date_Validator::today() ) || '' === $credentials || '' === $scope || '' === $regions || '' === $evidence ) {
			return false;
		}
		if ( '' !== $expires_on && ! Date_Validator::after( $expires_on, $verified_on ) ) {
			return false;
		}

		$values = array(
			'credential_verification_status'       => 'verified',
			'credential_verification_date'         => $verified_on,
			'credential_expiration_date'           => $expires_on,
			'credential_verified_by_user_id'       => $actor_id,
			'credential_verification_evidence_ref' => $evidence,
			'verified_professional_credentials'    => $credentials,
			'verified_review_scope'                => $scope,
			'verified_jurisdictions'               => $regions,
			'credential_verification_version'      => self::VERSION,
		);
		// Coalesce the nine per-field meta hooks into one cascade after the audit.
		Approval_Service::suppress_credential_hook( true );
		try {
			foreach ( $values as $key => $value ) {
				update_user_meta( $reviewer_id, $key, $value );
			}
			// Fail-closed: a verification without a durable audit trail must not stand.
			try {
				Audit_Log::record( 'credential_verified', 'user', $reviewer_id, array( 'version' => self::VERSION ), $actor_id, 'admin', true );
			} catch ( \Throwable $error ) {
				update_user_meta( $reviewer_id, 'credential_verification_status', 'unverified' );
				Audit_Log::record( 'credential_verification_rejected', 'user', $reviewer_id, array( 'reason' => 'audit_write_failed' ), $actor_id, 'admin' );
				return false;
			}
			self::detect_and_cascade( $reviewer_id, 'verified', $actor_id );
			return true;
		} finally {
			Approval_Service::suppress_credential_hook( false );
		}
	}

	/**
	 * Mark a previously verified snapshot stale without deleting history.
	 *
	 * @param int    $reviewer_id Reviewer user ID.
	 * @param string $reason      Human-readable invalidation reason.
	 * @param int    $actor_id    Acting user ID.
	 */
	public static function invalidate( int $reviewer_id, string $reason, int $actor_id = 0 ): void {
		Approval_Service::suppress_credential_hook( true );
		try {
			update_user_meta( $reviewer_id, 'credential_verification_status', 'stale' );
			Audit_Log::record( 'credential_invalidated', 'user', $reviewer_id, array( 'reason' => sanitize_text_field( $reason ) ), $actor_id, 'system' );
			self::detect_and_cascade( $reviewer_id, 'invalidated', $actor_id );
		} finally {
			Approval_Service::suppress_credential_hook( false );
		}
	}

	/**
	 * The full governed verified snapshot treated as a single aggregate.
	 *
	 * @param int $reviewer_id Reviewer user ID.
	 */
	public static function verified_snapshot( int $reviewer_id ): array {
		$snapshot = array();
		foreach ( self::verified_fields() as $field ) {
			$snapshot[ $field ] = (string) get_user_meta( $reviewer_id, $field, true );
		}
		return $snapshot;
	}

	/**
	 * Canonical fingerprint of the entire verified snapshot.
	 *
	 * @param int $reviewer_id Reviewer user ID.
	 */
	public static function fingerprint( int $reviewer_id ): string {
		return Approval_Fingerprint::hash( self::verified_snapshot( $reviewer_id ) );
	}

	/**
	 * Cascade to dependent approvals only when the governed snapshot changed.
	 *
	 * Compares the current fingerprint against the last cascaded fingerprint so
	 * that any of the verified fields — not only status or verifier — trigger
	 * exactly one invalidation, and repeated callbacks over an unchanged
	 * snapshot are coalesced. An absent stored fingerprint is treated as changed
	 * so a first observation always fails closed toward invalidation.
	 *
	 * @param int    $reviewer_id Reviewer user ID.
	 * @param string $reason      Change reason recorded on the cascade.
	 * @param int    $actor_id    Acting user ID.
	 */
	public static function detect_and_cascade( int $reviewer_id, string $reason, int $actor_id ): bool {
		if ( $reviewer_id <= 0 ) {
			return false;
		}
		$current = self::fingerprint( $reviewer_id );
		$stored  = (string) get_user_meta( $reviewer_id, self::FINGERPRINT_META, true );
		if ( '' !== $stored && hash_equals( $stored, $current ) ) {
			return false;
		}
		update_user_meta( $reviewer_id, self::FINGERPRINT_META, $current );
		Approval_Service::invalidate_dependents_of_credential( $reviewer_id, 'dependency_changed:credential:' . $reason, $actor_id );
		return true;
	}

	/**
	 * Expire a verified credential and cascade to dependent approvals.
	 *
	 * @param int $reviewer_id Reviewer user ID.
	 * @param int $actor_id    Acting user ID.
	 */
	public static function mark_expired( int $reviewer_id, int $actor_id = 0 ): void {
		Approval_Service::suppress_credential_hook( true );
		try {
			update_user_meta( $reviewer_id, 'credential_verification_status', 'expired' );
			Audit_Log::record( 'credential_expired', 'user', $reviewer_id, array( 'expired_on' => Date_Validator::today() ), $actor_id, 'system', true );
			self::detect_and_cascade( $reviewer_id, 'expired', $actor_id );
		} finally {
			Approval_Service::suppress_credential_hook( false );
		}
	}

	/**
	 * Verified reviewers whose credentials are due for expiration as of a date.
	 *
	 * @param string $as_of Cutoff date (YYYY-MM-DD).
	 * @param int    $limit Bounded batch size.
	 * @return list<int> Reviewer IDs.
	 */
	public static function find_expired_verified( string $as_of, int $limit ): array {
		$as_of = Date_Validator::normalize( $as_of );
		if ( '' === $as_of || ! function_exists( 'get_users' ) ) {
			return array();
		}
		$limit   = max( 1, min( 500, $limit ) );
		$ids     = get_users(
			array(
				'fields'     => 'ids',
				'number'     => $limit,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => 'credential_verification_status',
						'value'   => 'verified',
						'compare' => '=',
					),
					array(
						'key'     => 'credential_expiration_date',
						'value'   => $as_of,
						'compare' => '<=',
						'type'    => 'DATE',
					),
				),
			)
		);
		$expired = array();
		foreach ( (array) $ids as $id ) {
			$id      = (int) $id;
			$expires = Date_Validator::normalize( get_user_meta( $id, 'credential_expiration_date', true ) );
			// Defense in depth: only a valid expiration on/before the cutoff expires;
			// empty (never-expiring) or malformed dates are ignored.
			if ( '' !== $expires && ! Date_Validator::after( $expires, $as_of ) ) {
				$expired[] = $id;
			}
		}
		return $expired;
	}

	/**
	 * Bounded, restart-safe sweep that expires overdue verified credentials.
	 *
	 * Restart safety: expiring a credential removes it from the verified set, so
	 * a later run resumes with fresh work. Each reviewer is fault-isolated so one
	 * audit failure never aborts the batch.
	 *
	 * @param string $as_of Cutoff date; defaults to today (UTC).
	 * @param int    $limit Bounded batch size.
	 * @return array{as_of:string, scanned:int, expired:int, failed:int}
	 */
	public static function run_expiration_sweep( string $as_of = '', int $limit = 50 ): array {
		$as_of  = Date_Validator::normalize( '' === $as_of ? Date_Validator::today() : $as_of );
		$limit  = max( 1, min( 500, $limit ) );
		$report = array(
			'as_of'   => $as_of,
			'scanned' => 0,
			'expired' => 0,
			'failed'  => 0,
		);
		if ( '' === $as_of ) {
			return $report;
		}
		foreach ( self::find_expired_verified( $as_of, $limit ) as $reviewer_id ) {
			++$report['scanned'];
			try {
				self::mark_expired( (int) $reviewer_id, 0 );
				++$report['expired'];
			} catch ( \Throwable $error ) {
				++$report['failed'];
				error_log( sprintf( '[longevity-core] credential expiration failed for reviewer %d: %s', (int) $reviewer_id, $error->getMessage() ) );
			}
		}
		update_option( 'lel_credential_expiration_last_run', $as_of, false );
		update_option( 'lel_credential_expiration_failures', $report['failed'], false );
		return $report;
	}

	/**
	 * Determine whether the current verified snapshot covers a requested scope and region.
	 *
	 * @param int         $reviewer_id    Reviewer user ID.
	 * @param string      $required_scope Scope the reviewer must cover.
	 * @param string      $region         Region the reviewer must cover.
	 * @param string|null $as_of          Evaluation date; defaults to today.
	 */
	public static function is_valid_for( int $reviewer_id, string $required_scope, string $region, ?string $as_of = null ): bool {
		if ( $reviewer_id <= 0 || ! function_exists( 'user_can' ) || ! user_can( $reviewer_id, 'complete_medical_review' ) ) {
			return false;
		}
		$status      = (string) get_user_meta( $reviewer_id, 'credential_verification_status', true );
		$verified_on = Date_Validator::normalize( get_user_meta( $reviewer_id, 'credential_verification_date', true ) );
		$expires_on  = Date_Validator::normalize( get_user_meta( $reviewer_id, 'credential_expiration_date', true ) );
		$verifier    = (int) get_user_meta( $reviewer_id, 'credential_verified_by_user_id', true );
		$credentials = trim( (string) get_user_meta( $reviewer_id, 'verified_professional_credentials', true ) );
		$scope       = (string) get_user_meta( $reviewer_id, 'verified_review_scope', true );
		$regions     = (string) get_user_meta( $reviewer_id, 'verified_jurisdictions', true );
		$version     = (string) get_user_meta( $reviewer_id, 'credential_verification_version', true );
		$as_of       = Date_Validator::normalize( $as_of ?? Date_Validator::today() );
		if ( 'verified' !== $status || '' === $verified_on || '' === $as_of || $verifier <= 0 || $verifier === $reviewer_id || '' === $credentials || self::VERSION !== $version ) {
			return false;
		}
		if ( Date_Validator::after( $verified_on, $as_of ) || ( '' !== $expires_on && Date_Validator::after( $as_of, $expires_on ) ) ) {
			return false;
		}
		return self::covers( $scope, $required_scope ) && self::covers( $regions, $region );
	}

	/**
	 * Public allowlisted reviewer snapshot.
	 *
	 * @param int $reviewer_id Reviewer user ID.
	 */
	public static function public_snapshot( int $reviewer_id ): array {
		if ( ! self::is_valid_for( $reviewer_id, '', '' ) ) {
			return array();
		}
		$user = function_exists( 'get_userdata' ) ? get_userdata( $reviewer_id ) : null;
		return array(
			'display_name'  => $user ? (string) $user->display_name : '',
			'credentials'   => (string) get_user_meta( $reviewer_id, 'verified_professional_credentials', true ),
			'scope'         => (string) get_user_meta( $reviewer_id, 'verified_review_scope', true ),
			'jurisdictions' => (string) get_user_meta( $reviewer_id, 'verified_jurisdictions', true ),
			'verified_on'   => (string) get_user_meta( $reviewer_id, 'credential_verification_date', true ),
			'expires_on'    => (string) get_user_meta( $reviewer_id, 'credential_expiration_date', true ),
			'version'       => (string) get_user_meta( $reviewer_id, 'credential_verification_version', true ),
		);
	}

	/**
	 * Match a comma/newline-separated allowlist. Blank requirements are always covered.
	 *
	 * @param string $allowlist Allowlist of accepted values.
	 * @param string $required  Value that must be covered.
	 */
	private static function covers( string $allowlist, string $required ): bool {
		$required = strtolower( trim( $required ) );
		if ( '' === $required ) {
			return true;
		}
		$split = preg_split( '/[\r\n,;]+/', strtolower( $allowlist ) );
		$items = $split ? $split : array();
		$items = array_filter( array_map( 'trim', $items ) );
		return in_array( '*', $items, true ) || in_array( 'all', $items, true ) || in_array( $required, $items, true );
	}
}

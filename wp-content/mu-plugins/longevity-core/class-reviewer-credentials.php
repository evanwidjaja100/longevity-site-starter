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

	/** Whether an actor may verify another reviewer. */
	public static function can_verify( int $actor_id, int $reviewer_id ): bool {
		return $actor_id > 0 && $reviewer_id > 0 && $actor_id !== $reviewer_id && function_exists( 'user_can' ) && user_can( $actor_id, 'verify_reviewer_credentials' );
	}

	/** Persist a verified snapshot. This method never infers or invents credential data. */
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
			'credential_verification_status'     => 'verified',
			'credential_verification_date'       => $verified_on,
			'credential_expiration_date'         => $expires_on,
			'credential_verified_by_user_id'     => $actor_id,
			'credential_verification_evidence_ref'=> $evidence,
			'verified_professional_credentials'  => $credentials,
			'verified_review_scope'              => $scope,
			'verified_jurisdictions'             => $regions,
			'credential_verification_version'    => self::VERSION,
		);
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
		return true;
	}

	/** Mark a previously verified snapshot stale without deleting history. */
	public static function invalidate( int $reviewer_id, string $reason, int $actor_id = 0 ): void {
		update_user_meta( $reviewer_id, 'credential_verification_status', 'stale' );
		Audit_Log::record( 'credential_invalidated', 'user', $reviewer_id, array( 'reason' => sanitize_text_field( $reason ) ), $actor_id, 'system' );
	}

	/** Determine whether the current verified snapshot covers a requested scope and region. */
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

	/** Public allowlisted reviewer snapshot. */
	public static function public_snapshot( int $reviewer_id ): array {
		if ( ! self::is_valid_for( $reviewer_id, '', '' ) ) {
			return array();
		}
		$user = function_exists( 'get_userdata' ) ? get_userdata( $reviewer_id ) : null;
		return array(
			'display_name' => $user ? (string) $user->display_name : '',
			'credentials'  => (string) get_user_meta( $reviewer_id, 'verified_professional_credentials', true ),
			'scope'        => (string) get_user_meta( $reviewer_id, 'verified_review_scope', true ),
			'jurisdictions'=> (string) get_user_meta( $reviewer_id, 'verified_jurisdictions', true ),
			'verified_on'  => (string) get_user_meta( $reviewer_id, 'credential_verification_date', true ),
			'expires_on'   => (string) get_user_meta( $reviewer_id, 'credential_expiration_date', true ),
			'version'      => (string) get_user_meta( $reviewer_id, 'credential_verification_version', true ),
		);
	}

	/** Match a comma/newline-separated allowlist. Blank requirements are always covered. */
	private static function covers( string $allowlist, string $required ): bool {
		$required = strtolower( trim( $required ) );
		if ( '' === $required ) {
			return true;
		}
		$items = preg_split( '/[\r\n,;]+/', strtolower( $allowlist ) ) ?: array();
		$items = array_filter( array_map( 'trim', $items ) );
		return in_array( '*', $items, true ) || in_array( 'all', $items, true ) || in_array( $required, $items, true );
	}
}

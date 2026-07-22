<?php
/**
 * Explicit editorial metadata authorization policies.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Applies one deny-by-default policy service to every metadata mutation channel. */
final class Meta_Authorization {
	/** Trusted channels used only by migrations and first-party workflow services. */
	private const TRUSTED_CHANNELS = array( 'system', 'migration', 'workflow' );

	/** Return the declared policy for a registered field. */
	public static function policy_for( string $meta_key ): string {
		$definitions = Meta_Registry::definitions();
		return isset( $definitions[ $meta_key ]['write_policy'] ) ? (string) $definitions[ $meta_key ]['write_policy'] : 'deny';
	}

	/** Determine whether an actor may mutate a field on an object. */
	public static function can_write( string $meta_key, int $object_id, int $user_id, string $channel = 'generic' ): bool {
		$policy = self::policy_for( $meta_key );
		if ( 'deny' === $policy ) {
			return false;
		}
		if ( in_array( $channel, self::TRUSTED_CHANNELS, true ) ) {
			return true;
		}
		if ( 'system_only' === $policy || $user_id <= 0 ) {
			return false;
		}
		// Completion state is created only by first-party approval services.
		// REST may edit supporting fields but cannot forge final workflow state.
		if ( 'rest' === $channel && in_array( $meta_key, array( 'fact_check_status', 'medical_review_status', 'medical_review_attested', 'testing_status', 'affiliate_disclosure_status', 'editorial_approval_status' ), true ) ) {
			return false;
		}

		switch ( $policy ) {
			case 'post_editor':
				return self::user_can( $user_id, 'edit_post', $object_id );
			case 'evidence_manager':
				return self::user_can( $user_id, 'edit_claims' ) || self::user_can( $user_id, 'manage_claims' );
			case 'risk_classifier':
			case 'medical_assigner':
			case 'editorial_approver':
				return self::user_can( $user_id, 'approve_publication' );
			case 'assigned_medical_reviewer':
				$assigned = (int) get_post_meta( $object_id, 'medical_reviewer_user_id', true );
				return $assigned === $user_id && self::user_can( $user_id, 'complete_medical_review' );
			case 'fact_checker':
				return self::user_can( $user_id, 'complete_fact_check' );
			case 'testing_editor':
				return self::user_can( $user_id, 'manage_test_records' ) || self::user_can( $user_id, 'manage_test_protocols' );
			case 'testing_approver':
				return self::user_can( $user_id, 'approve_test_records' );
			case 'commercial_approver':
				return self::user_can( $user_id, 'approve_commercial_disclosure' );
			case 'corrections_manager':
				return self::user_can( $user_id, 'manage_corrections' );
			default:
				return false;
		}
	}

	/** Return fields editable by an actor for a post and channel. */
	public static function editable_fields( int $object_id, int $user_id, string $channel = 'classic' ): array {
		$editable = array();
		foreach ( Meta_Registry::definitions() as $key => $definition ) {
			if ( self::can_write( $key, $object_id, $user_id, $channel ) ) {
				$editable[] = $key;
			}
		}
		return $editable;
	}

	/** Wrapper that is testable without relying on the current user. */
	private static function user_can( int $user_id, string $capability, ...$args ): bool {
		return function_exists( 'user_can' ) && user_can( $user_id, $capability, ...$args );
	}
}

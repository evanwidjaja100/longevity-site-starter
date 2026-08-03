<?php
/**
 * Legal hold lifecycle for private contact records.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Authorized, audited, and checked legal-hold state transitions. */
final class Legal_Hold {
	public const META_KEY                = 'lel_legal_hold_state';
	public const LEGACY_META_KEY         = 'contact_legal_hold';
	public const CAPABILITY              = 'lel_manage_legal_holds';
	public const STATE_ACTIVE            = 'active';
	public const STATE_RELEASED          = 'released';
	public const CASE_META_KEY           = 'lel_legal_hold_case_ref';
	public const REASON_META_KEY         = 'lel_legal_hold_reason';
	public const ACTOR_META_KEY          = 'lel_legal_hold_actor_id';
	public const PLACED_AT_META_KEY      = 'lel_legal_hold_placed_at';
	public const REVIEW_AT_META_KEY      = 'lel_legal_hold_review_at';
	public const EXPIRES_AT_META_KEY     = 'lel_legal_hold_expires_at';
	public const RELEASE_ACTOR_META_KEY  = 'lel_legal_hold_release_actor_id';
	public const RELEASE_REASON_META_KEY = 'lel_legal_hold_release_reason';
	public const RELEASED_AT_META_KEY    = 'lel_legal_hold_released_at';

	/**
	 * Shared per-contact lock name for hold transitions and retention.
	 *
	 * @param int $post_id Contact message ID.
	 */
	public static function lock_name( int $post_id ): string {
		return Advisory_Lock::namespaced_name( 'contact_' . $post_id );
	}

	/**
	 * Place a hold. Review defaults to the configured cadence so older CLI
	 * callers still create a complete state; a case reference never defaults.
	 *
	 * @param int    $post_id   Contact message ID.
	 * @param int    $actor_id  Acting user.
	 * @param string $reason    Placement reason.
	 * @param string $case_ref  Required case/reference ID.
	 * @param string $channel   Audit source.
	 * @param string $review_at Review date (YYYY-MM-DD).
	 * @param string $expires_at Optional expiry date (YYYY-MM-DD).
	 * @return true|\WP_Error
	 */
	public static function place( int $post_id, int $actor_id, string $reason, string $case_ref = '', string $channel = 'cli', string $review_at = '', string $expires_at = '' ) {
		$outcome = Advisory_Lock::with_lock(
			self::lock_name( $post_id ),
			5,
			static fn() => self::place_locked( $post_id, $actor_id, $reason, $case_ref, $channel, $review_at, $expires_at )
		);
		if ( Advisory_Lock::ACQUIRED !== $outcome['status'] ) {
			return new \WP_Error( 'lel_hold_lock_unavailable', 'Legal hold refused: the contact record is locked or serialization is unavailable.' );
		}
		return $outcome['result'];
	}

	/**
	 * Place a hold while the per-contact advisory lock is held.
	 *
	 * @param int    $post_id    Contact message ID.
	 * @param int    $actor_id   Acting user.
	 * @param string $reason     Placement reason.
	 * @param string $case_ref   Case/reference ID.
	 * @param string $channel    Audit source.
	 * @param string $review_at  Review date.
	 * @param string $expires_at Optional expiry date.
	 * @return true|\WP_Error
	 */
	private static function place_locked( int $post_id, int $actor_id, string $reason, string $case_ref, string $channel, string $review_at, string $expires_at ) {
		$review_at = '' !== trim( $review_at ) ? trim( $review_at ) : gmdate( 'Y-m-d', time() + ( self::review_days() * DAY_IN_SECONDS ) );
		$error     = self::validate( $post_id, $actor_id, $reason, $case_ref, $review_at, $expires_at );
		if ( null !== $error ) {
			return $error;
		}
		if ( self::is_held( $post_id ) ) {
			return new \WP_Error( 'lel_hold_already_active', 'A legal hold is already active on this record.' );
		}

		$fields = array(
			self::CASE_META_KEY       => trim( $case_ref ),
			self::REASON_META_KEY     => trim( $reason ),
			self::ACTOR_META_KEY      => (string) $actor_id,
			self::PLACED_AT_META_KEY  => gmdate( DATE_ATOM ),
			self::REVIEW_AT_META_KEY  => $review_at,
			self::EXPIRES_AT_META_KEY => trim( $expires_at ),
			self::META_KEY            => self::STATE_ACTIVE,
		);
		$before = self::snapshot( $post_id, array_keys( $fields ) );
		try {
			Audit_Log::record(
				'legal_hold_requested',
				'contact',
				$post_id,
				array(
					'reason'     => trim( $reason ),
					'case_ref'   => trim( $case_ref ),
					'review_at'  => $review_at,
					'expires_at' => trim( $expires_at ),
				),
				$actor_id,
				$channel,
				true
			);
		} catch ( \RuntimeException $error ) {
			unset( $error );
			return new \WP_Error( 'lel_hold_audit_failed', 'Legal hold refused: the mandatory audit record could not be written.' );
		}
		if ( ! self::persist_checked( $post_id, $fields ) ) {
			return new \WP_Error( 'lel_hold_persistence_failed', 'Legal hold refused: the complete authoritative state could not be saved.' );
		}
		try {
			Audit_Log::record(
				'legal_hold_placed',
				'contact',
				$post_id,
				array(
					'reason'     => trim( $reason ),
					'case_ref'   => trim( $case_ref ),
					'review_at'  => $review_at,
					'expires_at' => trim( $expires_at ),
				),
				$actor_id,
				$channel,
				true
			);
		} catch ( \RuntimeException $error ) {
			unset( $error );
			self::restore( $post_id, $before );
			return new \WP_Error( 'lel_hold_audit_failed', 'Legal hold refused: the completed state could not be audited.' );
		}
		return true;
	}

	/**
	 * Release an active hold with checked release actor/time/reason metadata.
	 *
	 * @param int    $post_id  Contact message ID.
	 * @param int    $actor_id Acting user.
	 * @param string $reason   Release reason.
	 * @param string $case_ref Case/reference ID; defaults to the active hold.
	 * @param string $channel  Audit source.
	 * @return true|\WP_Error
	 */
	public static function release( int $post_id, int $actor_id, string $reason, string $case_ref = '', string $channel = 'cli' ) {
		$outcome = Advisory_Lock::with_lock(
			self::lock_name( $post_id ),
			5,
			static fn() => self::release_locked( $post_id, $actor_id, $reason, $case_ref, $channel )
		);
		if ( Advisory_Lock::ACQUIRED !== $outcome['status'] ) {
			return new \WP_Error( 'lel_hold_lock_unavailable', 'Legal hold release refused: the contact record is locked or serialization is unavailable.' );
		}
		return $outcome['result'];
	}

	/**
	 * Release a hold while the per-contact advisory lock is held.
	 *
	 * @param int    $post_id  Contact message ID.
	 * @param int    $actor_id Acting user.
	 * @param string $reason   Release reason.
	 * @param string $case_ref Case/reference ID.
	 * @param string $channel  Audit source.
	 * @return true|\WP_Error
	 */
	private static function release_locked( int $post_id, int $actor_id, string $reason, string $case_ref, string $channel ) {
		$case_ref = '' !== trim( $case_ref ) ? trim( $case_ref ) : (string) get_post_meta( $post_id, self::CASE_META_KEY, true );
		$error    = self::validate_transition( $post_id, $actor_id, $reason, $case_ref );
		if ( null !== $error ) {
			return $error;
		}
		if ( ! self::is_held( $post_id ) ) {
			return new \WP_Error( 'lel_hold_not_active', 'No valid active legal hold exists on this record.' );
		}
		$fields = array(
			self::RELEASE_ACTOR_META_KEY  => (string) $actor_id,
			self::RELEASE_REASON_META_KEY => trim( $reason ),
			self::RELEASED_AT_META_KEY    => gmdate( DATE_ATOM ),
			self::META_KEY                => self::STATE_RELEASED,
		);
		$before = self::snapshot( $post_id, array_keys( $fields ) );
		$legacy = array(
			'exists' => metadata_exists( 'post', $post_id, self::LEGACY_META_KEY ),
			'value'  => get_post_meta( $post_id, self::LEGACY_META_KEY, true ),
		);
		try {
			Audit_Log::record(
				'legal_hold_release_requested',
				'contact',
				$post_id,
				array(
					'reason'   => trim( $reason ),
					'case_ref' => $case_ref,
				),
				$actor_id,
				$channel,
				true
			);
		} catch ( \RuntimeException $error ) {
			unset( $error );
			return new \WP_Error( 'lel_hold_audit_failed', 'Legal hold release refused: the mandatory audit record could not be written.' );
		}

		$released = self::persist_checked( $post_id, $fields );
		if ( ! $released ) {
			return new \WP_Error( 'lel_hold_persistence_failed', 'Legal hold release refused: the complete release state could not be saved.' );
		}
		if ( metadata_exists( 'post', $post_id, self::LEGACY_META_KEY ) ) {
			if ( ! delete_post_meta( $post_id, self::LEGACY_META_KEY ) ) {
				self::restore( $post_id, $before );
				return new \WP_Error( 'lel_hold_legacy_cleanup_failed', 'The explicit release was saved, but legacy hold metadata still requires privacy-owner review.' );
			}
		}
		try {
			Audit_Log::record(
				'legal_hold_released',
				'contact',
				$post_id,
				array(
					'reason'   => trim( $reason ),
					'case_ref' => $case_ref,
				),
				$actor_id,
				$channel,
				true
			);
		} catch ( \RuntimeException $error ) {
			unset( $error );
			self::restore( $post_id, $before );
			if ( $legacy['exists'] ) {
				update_post_meta( $post_id, self::LEGACY_META_KEY, $legacy['value'] );
			}
			return new \WP_Error( 'lel_hold_audit_failed', 'Legal hold release refused: the completed state could not be audited.' );
		}
		return true;
	}

	/**
	 * Only complete, current active states stop retention.
	 *
	 * @param int $post_id Contact message ID.
	 */
	public static function is_held( int $post_id ): bool {
		if ( self::STATE_ACTIVE !== self::state( $post_id ) ) {
			return false;
		}
		$case_ref = trim( (string) get_post_meta( $post_id, self::CASE_META_KEY, true ) );
		$reason   = trim( (string) get_post_meta( $post_id, self::REASON_META_KEY, true ) );
		$actor_id = (int) get_post_meta( $post_id, self::ACTOR_META_KEY, true );
		$placed   = self::valid_timestamp( (string) get_post_meta( $post_id, self::PLACED_AT_META_KEY, true ) );
		$review   = Date_Validator::normalize( get_post_meta( $post_id, self::REVIEW_AT_META_KEY, true ) );
		$expiry   = trim( (string) get_post_meta( $post_id, self::EXPIRES_AT_META_KEY, true ) );
		if ( '' === $case_ref || '' === $reason || $actor_id < 1 || null === $placed || '' === $review || $review < Date_Validator::today() ) {
			return false;
		}
		return '' === $expiry || ( Date_Validator::is_valid( $expiry ) && $expiry >= Date_Validator::today() );
	}

	/**
	 * Active holds and ambiguous legacy values both block retention deletion.
	 *
	 * @param int $post_id Contact message ID.
	 */
	public static function protects_from_retention( int $post_id ): bool {
		if ( self::is_held( $post_id ) ) {
			return true;
		}
		if ( ! metadata_exists( 'post', $post_id, self::LEGACY_META_KEY ) ) {
			return false;
		}
		return ! in_array( self::legacy_value( $post_id ), array( '', '0', 'false', 'no', 'off', 'released' ), true );
	}

	/**
	 * Explicit state, including malformed values for reporting.
	 *
	 * @param int $post_id Contact message ID.
	 */
	public static function state( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META_KEY, true );
	}

	/**
	 * Return IDs protected by valid active holds.
	 *
	 * @return array<int, int>
	 */
	public static function held_message_ids(): array {
		return array_values( array_filter( self::all_message_ids(), array( self::class, 'is_held' ) ) );
	}

	/**
	 * Inventory legacy values without granting any of them active-hold status.
	 * Explicit false/released values are separated from ambiguous quarantine.
	 *
	 * @return array{post_ids: array<int, int>, count: int, quarantined: array<int, int>, non_active: array<int, int>}
	 */
	public static function legacy_report(): array {
		$ids         = array();
		$quarantined = array();
		$non_active  = array();
		foreach ( self::all_message_ids() as $id ) {
			if ( ! metadata_exists( 'post', $id, self::LEGACY_META_KEY ) ) {
				continue;
			}
			$ids[] = $id;
			$value = self::legacy_value( $id );
			if ( in_array( $value, array( '', '0', 'false', 'no', 'off', 'released' ), true ) ) {
				$non_active[] = $id;
			} else {
				$quarantined[] = $id;
			}
		}
		return array(
			'post_ids'    => $ids,
			'count'       => count( $ids ),
			'quarantined' => $quarantined,
			'non_active'  => $non_active,
		);
	}

	/**
	 * Normalize one legacy hold value for quarantine classification.
	 *
	 * @param int $post_id Contact message ID.
	 */
	private static function legacy_value( int $post_id ): string {
		return strtolower( trim( (string) get_post_meta( $post_id, self::LEGACY_META_KEY, true ) ) );
	}

	/**
	 * Validate placement-specific dates after shared authorization checks.
	 *
	 * @param int    $post_id    Contact message ID.
	 * @param int    $actor_id   Acting user.
	 * @param string $reason     Transition reason.
	 * @param string $case_ref   Case/reference ID.
	 * @param string $review_at  Review date.
	 * @param string $expires_at Optional expiry date.
	 */
	private static function validate( int $post_id, int $actor_id, string $reason, string $case_ref, string $review_at, string $expires_at ): ?\WP_Error {
		$error = self::validate_transition( $post_id, $actor_id, $reason, $case_ref );
		if ( null !== $error ) {
			return $error;
		}
		if ( ! Date_Validator::is_valid( $review_at ) || $review_at < Date_Validator::today() ) {
			return new \WP_Error( 'lel_hold_review_invalid', 'A current or future YYYY-MM-DD review date is required.' );
		}
		if ( '' !== trim( $expires_at ) && ( ! Date_Validator::is_valid( $expires_at ) || $expires_at < Date_Validator::today() || $expires_at < $review_at ) ) {
			return new \WP_Error( 'lel_hold_expiry_invalid', 'Expiry must be a valid date on or after the review date.' );
		}
		return null;
	}

	/**
	 * Shared authorization and required-reference checks.
	 *
	 * @param int    $post_id  Contact message ID.
	 * @param int    $actor_id Acting user.
	 * @param string $reason   Transition reason.
	 * @param string $case_ref Case/reference ID.
	 */
	private static function validate_transition( int $post_id, int $actor_id, string $reason, string $case_ref ): ?\WP_Error {
		if ( ! user_can( $actor_id, self::CAPABILITY ) ) {
			return new \WP_Error( 'lel_hold_forbidden', 'This action requires the lel_manage_legal_holds capability.' );
		}
		if ( '' === trim( $reason ) ) {
			return new \WP_Error( 'lel_hold_reason_required', 'A reason is required for every legal hold transition.' );
		}
		if ( '' === trim( $case_ref ) ) {
			return new \WP_Error( 'lel_hold_case_required', 'A case/reference ID is required for every legal hold transition.' );
		}
		if ( 'longevity_message' !== get_post_type( $post_id ) ) {
			return new \WP_Error( 'lel_hold_invalid_target', 'Legal holds apply only to private contact records.' );
		}
		return null;
	}

	/**
	 * Persist all fields and restore their prior values if any readback fails.
	 *
	 * @param int   $post_id Contact message ID.
	 * @param array $fields  Metadata fields.
	 */
	private static function persist_checked( int $post_id, array $fields ): bool {
		$before = self::snapshot( $post_id, array_keys( $fields ) );
		foreach ( $fields as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
			if ( (string) get_post_meta( $post_id, $key, true ) !== (string) $value ) {
				self::restore( $post_id, $before );
				return false;
			}
		}
		return true;
	}

	/**
	 * Snapshot metadata for compensation.
	 *
	 * @param int   $post_id Contact message ID.
	 * @param array $keys    Metadata keys.
	 */
	private static function snapshot( int $post_id, array $keys ): array {
		$before = array();
		foreach ( $keys as $key ) {
			$before[ $key ] = array(
				'exists' => metadata_exists( 'post', $post_id, $key ),
				'value'  => get_post_meta( $post_id, $key, true ),
			);
		}
		return $before;
	}

	/**
	 * Restore a metadata snapshot after a failed state transition.
	 *
	 * @param int   $post_id Contact message ID.
	 * @param array $before  Metadata snapshot.
	 */
	private static function restore( int $post_id, array $before ): void {
		foreach ( $before as $key => $snapshot ) {
			if ( $snapshot['exists'] ) {
				update_post_meta( $post_id, $key, $snapshot['value'] );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * Parse an ISO timestamp without allowing future placement times.
	 *
	 * @param string $value Candidate timestamp.
	 */
	private static function valid_timestamp( string $value ): ?\DateTimeImmutable {
		try {
			$date = new \DateTimeImmutable( $value );
		} catch ( \Exception $error ) {
			unset( $error );
			return null;
		}
		return $date->getTimestamp() <= time() + 60 ? $date : null;
	}

	/** Configured review cadence, bounded to one year. */
	private static function review_days(): int {
		return min( 365, max( 1, (int) get_option( 'lel_legal_hold_review_days', 30 ) ) );
	}

	/**
	 * Return all private contact message IDs.
	 *
	 * @return array<int, int>
	 */
	private static function all_message_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'longevity_message',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			)
		);
	}
}

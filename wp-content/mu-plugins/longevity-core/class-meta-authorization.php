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

	/** Workflow state fields that only first-party services may write. */
	private const WORKFLOW_STATE_FIELDS = array(
		'fact_check_status',
		'medical_review_status',
		'medical_review_attested',
		'testing_status',
		'affiliate_disclosure_status',
		'editorial_approval_status',
	);

	/**
	 * Depth counter for the trusted scope.
	 *
	 * @var int
	 */
	private static int $trusted_depth = 0;

	/**
	 * Return the declared policy for a registered field.
	 *
	 * @param string $meta_key Registered meta key.
	 * @return string Declared write policy, or 'deny' when undefined.
	 */
	public static function policy_for( string $meta_key ): string {
		$definitions = Meta_Registry::definitions();
		return isset( $definitions[ $meta_key ]['write_policy'] ) ? (string) $definitions[ $meta_key ]['write_policy'] : 'deny';
	}

	/**
	 * Determine whether an actor may mutate a field on an object.
	 *
	 * @param string $meta_key  Registered meta key.
	 * @param int    $object_id Object (post) ID.
	 * @param int    $user_id   Acting user ID.
	 * @param string $channel   Write channel (e.g. generic, rest, workflow).
	 * @return bool True when the actor may write the field.
	 */
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
		// REST and generic channels cannot forge final workflow state.
		if ( in_array( $channel, array( 'rest', 'generic' ), true ) && in_array( $meta_key, self::WORKFLOW_STATE_FIELDS, true ) ) {
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

	/**
	 * Return fields editable by an actor for a post and channel.
	 *
	 * @param int    $object_id Object (post) ID.
	 * @param int    $user_id   Acting user ID.
	 * @param string $channel   Write channel (e.g. classic, rest).
	 * @return array List of editable meta keys.
	 */
	public static function editable_fields( int $object_id, int $user_id, string $channel = 'classic' ): array {
		$editable = array();
		foreach ( Meta_Registry::definitions() as $key => $definition ) {
			if ( self::can_write( $key, $object_id, $user_id, $channel ) ) {
				$editable[] = $key;
			}
		}
		return $editable;
	}

	/** Enter a trusted scope, allowing workflow/migration services to bypass authorization. */
	public static function enter_trusted_scope(): void {
		++self::$trusted_depth;
	}

	/** Exit a trusted scope. */
	public static function exit_trusted_scope(): void {
		self::$trusted_depth = max( 0, self::$trusted_depth - 1 );
	}

	/** Whether the current execution is within a trusted scope. */
	public static function in_trusted_scope(): bool {
		return self::$trusted_depth > 0;
	}

	/**
	 * Persistence-layer guard for add_post_metadata.
	 * Denies writes to governed keys unless the actor is authorized or in a trusted scope.
	 *
	 * @param bool|null $check      Short-circuit value passed through when not denied.
	 * @param int       $object_id  Object (post) ID receiving the metadata.
	 * @param string    $meta_key   Meta key being written.
	 * @param mixed     $meta_value Meta value (unused).
	 * @param bool      $unique     Whether the key must be unique (unused).
	 * @return bool|null Unchanged $check to allow, or false to deny.
	 */
	public static function guard_add( ?bool $check, int $object_id, string $meta_key, $meta_value, bool $unique ): ?bool {
		unset( $meta_value, $unique );
		if ( self::in_trusted_scope() ) {
			return $check;
		}
		$definitions = Meta_Registry::definitions();
		if ( ! isset( $definitions[ $meta_key ] ) ) {
			return $check;
		}
		$post_type = function_exists( 'get_post_type' ) ? get_post_type( $object_id ) : '';
		if ( ! in_array( $post_type, $definitions[ $meta_key ]['post_types'], true ) ) {
			return $check;
		}
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( ! self::can_write( $meta_key, $object_id, (int) $user_id, 'generic' ) ) {
			self::audit_denial( 'add', $meta_key, $object_id, $user_id );
			return false;
		}
		return $check;
	}

	/**
	 * Persistence-layer guard for update_post_metadata.
	 * Denies writes to governed keys unless the actor is authorized or in a trusted scope.
	 *
	 * @param bool|null $check      Short-circuit value passed through when not denied.
	 * @param int       $object_id  Object (post) ID whose metadata is updated.
	 * @param string    $meta_key   Meta key being written.
	 * @param mixed     $meta_value Meta value (unused).
	 * @param mixed     $prev_value Previous meta value (unused).
	 * @return bool|null Unchanged $check to allow, or false to deny.
	 */
	public static function guard_update( ?bool $check, int $object_id, string $meta_key, $meta_value, $prev_value ): ?bool {
		unset( $meta_value, $prev_value );
		if ( self::in_trusted_scope() ) {
			return $check;
		}
		$definitions = Meta_Registry::definitions();
		if ( ! isset( $definitions[ $meta_key ] ) ) {
			return $check;
		}
		$post_type = function_exists( 'get_post_type' ) ? get_post_type( $object_id ) : '';
		if ( ! in_array( $post_type, $definitions[ $meta_key ]['post_types'], true ) ) {
			return $check;
		}
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( ! self::can_write( $meta_key, $object_id, (int) $user_id, 'generic' ) ) {
			self::audit_denial( 'update', $meta_key, $object_id, $user_id );
			return false;
		}
		return $check;
	}

	/**
	 * Persistence-layer guard for delete_post_metadata.
	 * Denies deletions of governed keys unless the actor is authorized or in a trusted scope.
	 *
	 * @param bool|null $check         Short-circuit value passed through when not denied.
	 * @param int       $object_id     Object (post) ID whose metadata is deleted.
	 * @param string    $meta_key      Meta key being deleted.
	 * @param mixed     $meta_value    Meta value (unused).
	 * @param int|null  $object_id_ref Object ID reference (unused).
	 * @return bool|null Unchanged $check to allow, or false to deny.
	 */
	public static function guard_delete( ?bool $check, int $object_id, string $meta_key, $meta_value, ?int $object_id_ref = null ): ?bool {
		unset( $meta_value, $object_id_ref );
		if ( self::in_trusted_scope() ) {
			return $check;
		}
		$definitions = Meta_Registry::definitions();
		if ( ! isset( $definitions[ $meta_key ] ) ) {
			return $check;
		}
		$post_type = function_exists( 'get_post_type' ) ? get_post_type( $object_id ) : '';
		if ( ! in_array( $post_type, $definitions[ $meta_key ]['post_types'], true ) ) {
			return $check;
		}
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( ! self::can_write( $meta_key, $object_id, (int) $user_id, 'generic' ) ) {
			self::audit_denial( 'delete', $meta_key, $object_id, $user_id );
			return false;
		}
		return $check;
	}

	/**
	 * Audit a denied write without exposing sensitive attempted values.
	 *
	 * @param string $operation Attempted operation (add, update, or delete).
	 * @param string $meta_key  Meta key that was denied.
	 * @param int    $object_id Object (post) ID.
	 * @param int    $user_id   Acting user ID.
	 */
	private static function audit_denial( string $operation, string $meta_key, int $object_id, int $user_id ): void {
		if ( class_exists( Audit_Log::class ) ) {
			Audit_Log::record(
				'metadata_write_denied',
				'post',
				$object_id,
				array(
					'operation' => $operation,
					'meta_key'  => substr( sanitize_key( $meta_key ), 0, 64 ),
					'actor'     => $user_id,
				),
				$user_id,
				'persistence'
			);
		}
	}

	/**
	 * Wrapper that is testable without relying on the current user.
	 *
	 * @param int    $user_id    Acting user ID.
	 * @param string $capability Capability to check.
	 * @param mixed  ...$args    Optional extra arguments forwarded to user_can().
	 * @return bool True when the user has the capability.
	 */
	private static function user_can( int $user_id, string $capability, ...$args ): bool {
		return function_exists( 'user_can' ) && user_can( $user_id, $capability, ...$args );
	}
}

<?php
/**
 * Correction records and public notices.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Correction lifecycle service. */
final class Corrections {
	private const VALID_TRANSITIONS = array(
		'reported'     => array( 'investigating', 'rejected' ),
		'investigating' => array( 'in_progress', 'rejected' ),
		'in_progress'  => array( 'complete', 'rejected' ),
		'rejected'     => array(),
		'complete'     => array(),
	);

	/** Register hooks. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_meta' ), 12 );
		add_filter( 'update_post_metadata', array( self::class, 'prevent_direct_status_write' ), 10, 4 );
		add_filter( 'add_post_metadata', array( self::class, 'prevent_direct_status_add' ), 10, 5 );
		add_filter( 'delete_post_metadata', array( self::class, 'prevent_direct_status_delete' ), 10, 5 );
	}

	/** Block direct writes to correction_status. Use transition() instead. */
	public static function prevent_direct_status_write( ?bool $check, int $object_id, string $meta_key, $meta_value ): ?bool {
		if ( 'correction_status' === $meta_key && 'lel_correction' === get_post_type( $object_id ) ) {
			$current = get_post_meta( $object_id, 'correction_status', true );
			if ( ! self::transition_is_allowed( $current, (string) $meta_value ) ) {
				return false;
			}
		}
		return $check;
	}

	/** Check if a status transition is valid. */
	public static function transition_is_allowed( string $from, string $to ): bool {
		return in_array( $to, self::VALID_TRANSITIONS[ $from ] ?? array(), true );
	}

	/** Block direct add of correction_status. Use transition() instead. */
	public static function prevent_direct_status_add( ?bool $check, int $object_id, string $meta_key, $meta_value, bool $unique ): ?bool {
		unset( $unique );
		if ( 'correction_status' === $meta_key && 'lel_correction' === get_post_type( $object_id ) ) {
			return false;
		}
		return $check;
	}

	/** Block direct delete of correction_status. Use transition() instead. */
	public static function prevent_direct_status_delete( ?bool $check, int $object_id, string $meta_key, $meta_value, ?int $object_id_ref = null ): ?bool {
		unset( $meta_value, $object_id_ref );
		if ( 'correction_status' === $meta_key && 'lel_correction' === get_post_type( $object_id ) ) {
			return false;
		}
		return $check;
	}

	/** Return the list of allowed next statuses. */
	public static function allowed_next_statuses( ?string $current = null ): array {
		if ( null === $current ) {
			return array_keys( self::VALID_TRANSITIONS );
		}
		return self::VALID_TRANSITIONS[ $current ] ?? array();
	}

	/** Register correction metadata. */
	public static function register_meta(): void {
		$fields = array(
			'corrected_post_id'      => 'absint',
			'reported_date'          => 'date',
			'reported_by'            => 'text',
			'issue_category'         => 'category',
			'issue_description'      => 'textarea',
			'severity'               => 'severity',
			'public_impact'          => 'textarea',
			'assigned_editor_user_id'=> 'absint',
			'correction_status'      => 'status',
			'resolution'             => 'textarea',
			'corrected_date'         => 'date',
			'public_correction_note' => 'textarea',
			'claim_ids_affected'     => 'csv_ids',
			'reviewer_required'      => 'boolean',
			'medical_rereviewed'     => 'boolean',
			'conclusion_changed'     => 'boolean',
		);
		foreach ( $fields as $key => $rule ) {
			$show_in_rest = 'correction_status' === $key || 'resolution' === $key || 'corrected_date' === $key;
			register_post_meta(
				'lel_correction',
				$key,
				array(
					'type'              => 'absint' === $rule ? 'integer' : ( 'boolean' === $rule ? 'boolean' : 'string' ),
					'single'            => true,
					'show_in_rest'      => $show_in_rest,
					'sanitize_callback' => static fn( $value ) => self::sanitize( $rule, $value ),
					'auth_callback'     => static fn() => current_user_can( 'manage_corrections' ),
				)
			);
		}
	}

	/** Transition a correction to a new status with validation. */
	public static function transition( int $post_id, string $new_status, int $actor_id ): bool {
		if ( $actor_id <= 0 || ! function_exists( 'user_can' ) || ! user_can( $actor_id, 'manage_corrections' ) ) {
			Audit_Log::record( 'correction_transition', 'correction', $post_id, array( 'to' => $new_status, 'actor' => $actor_id, 'reason' => 'unauthorized' ), $actor_id, 'workflow' );
			return false;
		}
		$current = (string) get_post_meta( $post_id, 'correction_status', true );
		if ( '' === $current ) {
			$current = 'reported';
		}
		if ( ! self::transition_is_allowed( $current, $new_status ) ) {
			return false;
		}
		if ( 'complete' === $new_status ) {
			$post       = get_post( $post_id );
			$post_id_ref = (int) get_post_meta( $post_id, 'corrected_post_id', true );
			$description = (string) get_post_meta( $post_id, 'issue_description', true );
			$severity   = (string) get_post_meta( $post_id, 'severity', true );
			$resolution = (string) get_post_meta( $post_id, 'resolution', true );
			$public_note = (string) get_post_meta( $post_id, 'public_correction_note', true );
			$corrected_date = (string) get_post_meta( $post_id, 'corrected_date', true );

			if ( ! $post || $post_id_ref <= 0 || '' === $description || '' === $resolution || '' === $public_note || '' === $corrected_date ) {
				return false;
			}
			if ( in_array( $severity, array( 'material', 'critical' ), true ) || 'medical_safety' === get_post_meta( $post_id, 'issue_category', true ) ) {
				$rereviewed = get_post_meta( $post_id, 'medical_rereviewed', true );
				if ( ! $rereviewed ) {
					return false;
				}
			}
			if ( get_post_meta( $post_id, 'conclusion_changed', true ) && '' === get_post_meta( $post_id, 'claim_ids_affected', true ) ) {
				return false;
			}
			// Build immutable completion snapshot.
			$snapshot = array(
				'corrected_post_id'      => $post_id_ref,
				'issue_description'      => $description,
				'severity'               => $severity,
				'resolution'             => $resolution,
				'public_correction_note' => $public_note,
				'corrected_date'         => $corrected_date,
				'conclusion_changed'     => (bool) get_post_meta( $post_id, 'conclusion_changed', true ),
				'claim_ids_affected'     => (string) get_post_meta( $post_id, 'claim_ids_affected', true ),
				'medical_rereviewed'     => (bool) get_post_meta( $post_id, 'medical_rereviewed', true ),
				'completed_by'           => $actor_id,
				'completed_at'           => gmdate( DATE_ATOM ),
			);
			$snapshot_hash = hash( 'sha256', Approval_Fingerprint::canonical_json( $snapshot ) );
			update_post_meta( $post_id, 'completion_snapshot_hash', $snapshot_hash );
			update_post_meta( $post_id, 'completion_snapshot_by', $actor_id );
			update_post_meta( $post_id, 'completion_snapshot_at', gmdate( DATE_ATOM ) );
		}
		Meta_Authorization::enter_trusted_scope();
		try {
			update_post_meta( $post_id, 'correction_status', $new_status );
			Audit_Log::record( 'correction_transition', 'correction', $post_id, array( 'from' => $current, 'to' => $new_status, 'actor' => $actor_id ), $actor_id, 'workflow', true );
			if ( 'complete' === $new_status ) {
				$parent_id = (int) get_post_meta( $post_id, 'corrected_post_id', true );
				if ( $parent_id > 0 ) {
					Approval_Service::invalidate_direct( $parent_id, 'correction_completed', $actor_id, false );
					Rankings::invalidate();
				}
			}
		} catch ( \Throwable $error ) {
			update_post_meta( $post_id, 'correction_status', $current );
			Audit_Log::record( 'correction_transition', 'correction', $post_id, array( 'from' => $new_status, 'to' => $current, 'actor' => $actor_id, 'reason' => 'audit_write_failed' ), $actor_id, 'workflow' );
			Meta_Authorization::exit_trusted_scope();
			return false;
		}
		Meta_Authorization::exit_trusted_scope();
		return true;
	}

	/** Whether a completed correction's snapshot still matches its stored state. */
	public static function completion_snapshot_valid( int $post_id ): bool {
		$stored_hash = (string) get_post_meta( $post_id, 'completion_snapshot_hash', true );
		if ( '' === $stored_hash ) {
			return false;
		}
		$snapshot = array(
			'corrected_post_id'      => (int) get_post_meta( $post_id, 'corrected_post_id', true ),
			'issue_description'      => (string) get_post_meta( $post_id, 'issue_description', true ),
			'severity'               => (string) get_post_meta( $post_id, 'severity', true ),
			'resolution'             => (string) get_post_meta( $post_id, 'resolution', true ),
			'public_correction_note' => (string) get_post_meta( $post_id, 'public_correction_note', true ),
			'corrected_date'         => (string) get_post_meta( $post_id, 'corrected_date', true ),
			'conclusion_changed'     => (bool) get_post_meta( $post_id, 'conclusion_changed', true ),
			'claim_ids_affected'     => (string) get_post_meta( $post_id, 'claim_ids_affected', true ),
			'medical_rereviewed'     => (bool) get_post_meta( $post_id, 'medical_rereviewed', true ),
			'completed_by'           => (int) get_post_meta( $post_id, 'completion_snapshot_by', true ),
			'completed_at'           => (string) get_post_meta( $post_id, 'completion_snapshot_at', true ),
		);
		return hash_equals( $stored_hash, hash( 'sha256', Approval_Fingerprint::canonical_json( $snapshot ) ) );
	}

	/** Get completed material corrections for a post with valid snapshots. */
	public static function public_records( int $post_id ): array {
		$records = get_posts(
			array(
				'post_type'      => 'lel_correction',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'orderby'        => 'meta_value',
				'meta_key'       => 'corrected_date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array( 'key' => 'corrected_post_id', 'value' => $post_id, 'type' => 'NUMERIC' ),
					array( 'key' => 'correction_status', 'value' => 'complete' ),
					array( 'key' => 'public_correction_note', 'compare' => 'EXISTS' ),
				),
			)
		);
		// Only project corrections whose immutable completion snapshot is intact.
		return array_values( array_filter( $records, static fn( $record ) => self::completion_snapshot_valid( $record->ID ) ) );
	}

	/** Render public correction history. */
	public static function render( int $post_id ): string {
		$records = self::public_records( $post_id );
		if ( empty( $records ) ) {
			return '';
		}
		$heading_id = wp_unique_id( 'longevity-corrections-' );
		$html = '<section class="longevity-update-history" aria-labelledby="' . esc_attr( $heading_id ) . '"><h2 id="' . esc_attr( $heading_id ) . '">' . esc_html__( 'Corrections and material updates', 'longevity-core' ) . '</h2><ol>';
		foreach ( $records as $record ) {
			$date = get_post_meta( $record->ID, 'corrected_date', true );
			$note = get_post_meta( $record->ID, 'public_correction_note', true );
			$changed = get_post_meta( $record->ID, 'conclusion_changed', true ) ? __( 'The conclusion changed.', 'longevity-core' ) : __( 'The overall conclusion did not change.', 'longevity-core' );
			$rereview = get_post_meta( $record->ID, 'medical_rereviewed', true ) ? __( 'Medical re-review was completed.', 'longevity-core' ) : '';
			$html .= '<li><time datetime="' . esc_attr( $date ) . '">' . esc_html( $date ) . '</time><p>' . esc_html( $note ) . '</p><p class="longevity-small">' . esc_html( trim( $changed . ' ' . $rereview ) ) . '</p></li>';
		}
		return $html . '</ol></section>';
	}

	/** Sanitize correction values. */
	private static function sanitize( string $rule, $value ) {
		if ( 'category' === $rule ) {
			$value = sanitize_key( (string) $value );
			return in_array( $value, array( 'typographical', 'clarification', 'material_factual', 'medical_safety', 'commercial_disclosure', 'product_specification', 'privacy_legal' ), true ) ? $value : 'clarification';
		}
		if ( 'severity' === $rule ) {
			$value = sanitize_key( (string) $value );
			return in_array( $value, array( 'minor', 'moderate', 'material', 'critical' ), true ) ? $value : 'moderate';
		}
		if ( 'status' === $rule ) {
			$value = sanitize_key( (string) $value );
			return in_array( $value, array( 'reported', 'investigating', 'in_progress', 'complete', 'rejected' ), true ) ? $value : 'reported';
		}
		return Meta_Registry::sanitize_value( $rule, $value );
	}
}

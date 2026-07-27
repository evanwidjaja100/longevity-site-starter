<?php
/**
 * Product test protocols and reproducible scoring.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/** Product-testing service. */
final class Review_Methodology {
	/** Prevent approval projection hooks from invalidating themselves. */
	private static bool $mutating_approval = false;
	/** Register metadata. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_meta' ), 12 );
		add_action( 'updated_post_meta', array( self::class, 'invalidate_test_record_approval' ), 20, 4 );
		add_action( 'added_post_meta', array( self::class, 'invalidate_test_record_approval' ), 20, 4 );
		add_action( 'deleted_post_meta', array( self::class, 'invalidate_test_record_approval' ), 20, 4 );
	}

	/** Register test protocol and record metadata. */
	public static function register_meta(): void {
		$protocol_fields = self::protocol_fields();
		foreach ( $protocol_fields as $key => $rule ) {
			self::register_private_meta( 'lel_protocol', $key, $rule );
		}
		$record_fields = self::test_record_fields();
		foreach ( $record_fields as $key => $rule ) {
			self::register_private_meta( 'lel_test_record', $key, $rule );
		}
	}

	/** Sanitize raw scoring dimensions. */
	public static function sanitize_dimensions( $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$sanitized = array();
		foreach ( $value as $dimension ) {
			if ( ! is_array( $dimension ) ) {
				continue;
			}
			$name   = sanitize_text_field( (string) ( $dimension['name'] ?? '' ) );
			$score  = min( 5.0, max( 0.0, (float) ( $dimension['score'] ?? 0 ) ) );
			$weight = min( 100.0, max( 0.0, (float) ( $dimension['weight'] ?? 0 ) ) );
			if ( '' === $name ) {
				continue;
			}
			$sanitized[] = array( 'name' => $name, 'score' => $score, 'weight' => $weight );
		}
		return $sanitized;
	}

	/** Sanitize a bounded public projection of approved test-record observations. */
	public static function sanitize_public_results( $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$allowed_statuses = array( 'meets', 'partially_meets', 'does_not_meet', 'informational', 'not_applicable' );
		$sanitized        = array();
		foreach ( array_slice( $value, 0, 30 ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label    = substr( sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 0, 100 );
			$observed = substr( sanitize_text_field( (string) ( $row['observed_value'] ?? '' ) ), 0, 120 );
			$status   = sanitize_key( (string) ( $row['status'] ?? 'informational' ) );
			if ( '' === $label || '' === $observed || ! in_array( $status, $allowed_statuses, true ) ) {
				continue;
			}
			$sanitized[] = array(
				'label'           => $label,
				'observed_value'  => $observed,
				'unit'            => substr( sanitize_text_field( (string) ( $row['unit'] ?? '' ) ), 0, 40 ),
				'reference_label' => substr( sanitize_text_field( (string) ( $row['reference_label'] ?? '' ) ), 0, 100 ),
				'reference_value' => substr( sanitize_text_field( (string) ( $row['reference_value'] ?? '' ) ), 0, 120 ),
				'status'          => $status,
				'note'            => substr( sanitize_textarea_field( (string) ( $row['note'] ?? '' ) ), 0, 500 ),
				'display_order'   => min( 999, max( 0, absint( $row['display_order'] ?? ( $index + 1 ) * 10 ) ) ),
			);
		}
		usort( $sanitized, static fn( $left, $right ) => $left['display_order'] <=> $right['display_order'] );
		return $sanitized;
	}

	/** Load and validate the review model from config. */
	public static function model(): array {
		return Runtime_Config::scoring_model();
	}

	/** Minimum score difference required for a meaningful ranking distinction. */
	public static function minimum_meaningful_difference(): float {
		$model = self::model();
		return isset( $model['minimum_meaningful_difference'] ) ? (float) $model['minimum_meaningful_difference'] : 0.5;
	}

	/** Formatted scoring sensitivity disclosure for ranking pages. */
	public static function scoring_sensitivity_note(): string {
		$model      = self::model();
		$version    = (string) ( $model['version'] ?? '' );
		$threshold  = self::minimum_meaningful_difference();
		$parts      = array();
		if ( $version ) {
			$parts[] = sprintf( __( 'Scoring model version %s', 'longevity-core' ), $version );
		}
		$parts[] = sprintf( __( 'Minimum meaningful difference: %s points', 'longevity-core' ), number_format_i18n( $threshold, 1 ) );
		$parts[] = __( 'Missing dimensions block publication', 'longevity-core' );
		if ( ! empty( $model['rules']['commercial_relationship_may_change_score'] ) ) {
			$parts[] = __( 'Warning: commercial relationship may affect score', 'longevity-core' );
		}
		$parts[] = __( 'Manual override requires a documented reason', 'longevity-core' );
		return implode( ' · ', $parts );
	}

	/** Calculate a reproducible score. */
	public static function calculate_score( array $dimensions ): array {
		$dimensions = self::sanitize_dimensions( $dimensions );
		if ( empty( $dimensions ) ) {
			throw new InvalidArgumentException( 'At least one scoring dimension is required.' );
		}
		$total_weight = array_sum( array_column( $dimensions, 'weight' ) );
		if ( abs( 100.0 - $total_weight ) > 0.01 ) {
			throw new InvalidArgumentException( 'Scoring weights must total 100.' );
		}
		$weighted = 0.0;
		foreach ( $dimensions as $dimension ) {
			$weighted += $dimension['score'] * ( $dimension['weight'] / 100 );
		}
		return array(
			'score'      => round( $weighted, 2 ),
			'dimensions' => $dimensions,
		);
	}

	/** Approve an independently reviewed test record and bind it to its exact current state. */
	public static function approve_test_record( int $record_id, int $actor_id ): bool {
		if ( $record_id <= 0 || ! function_exists( 'user_can' ) || ! user_can( $actor_id, 'approve_test_records' ) || ! self::record_ready_for_approval( $record_id ) ) {
			return false;
		}
		$testers   = preg_split( '/[\s,]+/', (string) get_post_meta( $record_id, 'tester_user_ids', true ) ) ?: array();
		$submitter = (int) get_post_meta( $record_id, 'submitted_by', true );
		$post      = get_post( $record_id );
		if ( in_array( (string) $actor_id, $testers, true ) || $submitter === $actor_id || ( $post && (int) $post->post_author === $actor_id ) ) {
			Audit_Log::record( 'test_approval_denied', 'post', $record_id, array( 'reason' => 'separation_of_duties' ), $actor_id, 'workflow' );
			return false;
		}
		$hash = self::test_record_fingerprint( $record_id );
		if ( '' === $hash ) {
			return false;
		}
		Meta_Authorization::enter_trusted_scope();
		self::$mutating_approval = true;
		try {
			update_post_meta( $record_id, 'approval_status', 'approved' );
			update_post_meta( $record_id, 'approved_by', $actor_id );
			update_post_meta( $record_id, 'approval_date', Date_Validator::today() );
			update_post_meta( $record_id, 'approval_snapshot_hash', $hash );
			$audit_id = Audit_Log::record( 'test_record_approved', 'post', $record_id, array( 'snapshot_hash' => $hash ), $actor_id, 'workflow', true );
			update_post_meta( $record_id, 'approval_snapshot_id', $audit_id );
		} catch ( \Throwable $error ) {
			update_post_meta( $record_id, 'approval_status', 'pending' );
			Audit_Log::record( 'test_approval_denied', 'post', $record_id, array( 'reason' => 'audit_write_failed' ), $actor_id, 'workflow' );
			Meta_Authorization::exit_trusted_scope();
			return false;
		} finally {
			self::$mutating_approval = false;
			Meta_Authorization::exit_trusted_scope();
		}
		return true;
	}

	/** Approve a protocol independently and bind approval to its exact material state. */
	public static function approve_protocol( int $protocol_post_id, int $actor_id ): bool {
		$post = get_post( $protocol_post_id );
		if ( ! $post || 'lel_protocol' !== $post->post_type || ! function_exists( 'user_can' ) || ! user_can( $actor_id, 'approve_test_records' ) || (int) $post->post_author === $actor_id || ! self::protocol_ready_for_approval( $protocol_post_id ) ) {
			return false;
		}
		$hash = self::protocol_fingerprint( $protocol_post_id );
		if ( '' === $hash ) {
			return false;
		}
		Meta_Authorization::enter_trusted_scope();
		self::$mutating_approval = true;
		try {
			update_post_meta( $protocol_post_id, 'approval_status', 'approved' );
			update_post_meta( $protocol_post_id, 'protocol_reviewer_user_id', $actor_id );
			update_post_meta( $protocol_post_id, 'approval_date', Date_Validator::today() );
			update_post_meta( $protocol_post_id, 'approval_snapshot_hash', $hash );
			$audit_id = Audit_Log::record( 'test_protocol_approved', 'post', $protocol_post_id, array( 'snapshot_hash' => $hash ), $actor_id, 'workflow', true );
			update_post_meta( $protocol_post_id, 'approval_snapshot_id', $audit_id );
		} catch ( \Throwable $error ) {
			update_post_meta( $protocol_post_id, 'approval_status', 'pending' );
			Audit_Log::record( 'test_approval_denied', 'post', $protocol_post_id, array( 'reason' => 'audit_write_failed' ), $actor_id, 'workflow' );
			Meta_Authorization::exit_trusted_scope();
			return false;
		} finally {
			self::$mutating_approval = false;
			Meta_Authorization::exit_trusted_scope();
		}
		return true;
	}

	/** Mark an approved test record stale after any material field changes. */
	public static function invalidate_test_record_approval( $meta_id, int $record_id, string $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );
		$post_type = get_post_type( $record_id );
		if ( self::$mutating_approval || ! in_array( $post_type, array( 'lel_test_record', 'lel_protocol' ), true ) ) {
			return;
		}
		$approval_fields = array( 'approval_status', 'approved_by', 'protocol_reviewer_user_id', 'approval_date', 'approval_snapshot_id', 'approval_snapshot_hash' );
		$material_fields = 'lel_protocol' === $post_type ? array_keys( self::protocol_fields() ) : array_keys( self::test_record_fields() );
		if ( in_array( $meta_key, $approval_fields, true ) || ! in_array( $meta_key, $material_fields, true ) ) {
			return;
		}
		if ( 'approved' === get_post_meta( $record_id, 'approval_status', true ) ) {
			self::$mutating_approval = true;
			try {
				update_post_meta( $record_id, 'approval_status', 'stale' );
				Audit_Log::record( 'lel_protocol' === $post_type ? 'test_protocol_approval_invalidated' : 'test_record_approval_invalidated', 'post', $record_id, array( 'changed_field' => $meta_key ), function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0, 'system' );
			} finally {
				self::$mutating_approval = false;
			}
		}
	}

	/** Canonical hash of all material test record and protocol state. */
	public static function test_record_fingerprint( int $record_id ): string {
		if ( $record_id <= 0 || 'lel_test_record' !== get_post_type( $record_id ) ) {
			return '';
		}
		$data = array();
		foreach ( self::test_record_fields() as $key => $rule ) {
			if ( in_array( $key, array( 'approval_status', 'approved_by', 'approval_date', 'approval_snapshot_id', 'approval_snapshot_hash' ), true ) ) {
				continue;
			}
			$data[ $key ] = get_post_meta( $record_id, $key, true );
		}
		$data['protocol_approved'] = self::approved_protocol_exists(
			(string) get_post_meta( $record_id, 'protocol_id', true ),
			(string) get_post_meta( $record_id, 'protocol_version', true ),
			(string) get_post_meta( $record_id, 'test_end_date', true )
		);
		return hash( 'sha256', Approval_Fingerprint::canonical_json( $data ) );
	}

	/** Canonical hash of all material protocol fields. */
	public static function protocol_fingerprint( int $protocol_post_id ): string {
		if ( $protocol_post_id <= 0 || 'lel_protocol' !== get_post_type( $protocol_post_id ) ) {
			return '';
		}
		$data = array();
		foreach ( self::protocol_fields() as $key => $rule ) {
			unset( $rule );
			if ( in_array( $key, array( 'protocol_reviewer_user_id', 'approval_status', 'approval_date', 'approval_snapshot_id', 'approval_snapshot_hash' ), true ) ) {
				continue;
			}
			$data[ $key ] = get_post_meta( $protocol_post_id, $key, true );
		}
		return hash( 'sha256', Approval_Fingerprint::canonical_json( $data ) );
	}

	/** Whether a test record has complete material facts before independent approval. */
	private static function record_ready_for_approval( int $record_id ): bool {
		if ( $record_id <= 0 || 'lel_test_record' !== get_post_type( $record_id ) || 'trash' === get_post_status( $record_id ) ) {
			return false;
		}
		foreach ( array( 'product_name', 'unit_identifier', 'acquisition_method', 'tester_user_ids', 'test_start_date', 'test_end_date', 'protocol_id', 'protocol_version', 'raw_observations', 'evidence_references', 'submitted_by', 'submitted_at' ) as $field ) {
			if ( '' === trim( (string) get_post_meta( $record_id, $field, true ) ) ) {
				return false;
			}
		}
		if ( empty( self::sanitize_public_results( get_post_meta( $record_id, 'public_test_results', true ) ) ) ) {
			return false;
		}
		$start = (string) get_post_meta( $record_id, 'test_start_date', true );
		$end   = (string) get_post_meta( $record_id, 'test_end_date', true );
		return Date_Validator::is_valid( $start ) && Date_Validator::is_valid( $end ) && Date_Validator::compare( $end, $start ) >= 0 && self::approved_protocol_exists( (string) get_post_meta( $record_id, 'protocol_id', true ), (string) get_post_meta( $record_id, 'protocol_version', true ), $end );
	}

	/** Whether a protocol has complete, semantically valid material fields. */
	private static function protocol_ready_for_approval( int $protocol_post_id ): bool {
		foreach ( array( 'protocol_id', 'protocol_version', 'product_category', 'effective_date', 'minimum_test_duration', 'required_observations', 'required_comparison_methods', 'required_environmental_conditions', 'required_disclosure_fields', 'known_limitations' ) as $field ) {
			if ( '' === trim( (string) get_post_meta( $protocol_post_id, $field, true ) ) ) {
				return false;
			}
		}
		if ( empty( self::sanitize_dimensions( get_post_meta( $protocol_post_id, 'scoring_dimensions', true ) ) ) ) {
			return false;
		}
		$effective = (string) get_post_meta( $protocol_post_id, 'effective_date', true );
		$retired   = (string) get_post_meta( $protocol_post_id, 'retired_date', true );
		return Date_Validator::is_valid( $effective ) && ( '' === $retired || ( Date_Validator::is_valid( $retired ) && Date_Validator::compare( $retired, $effective ) >= 0 ) );
	}

	/** Registered protocol fields, kept in one exact inventory. */
	private static function protocol_fields(): array {
		return array(
			'protocol_id' => 'text', 'protocol_version' => 'version', 'product_category' => 'text', 'effective_date' => 'date', 'retired_date' => 'date', 'minimum_test_duration' => 'text', 'required_observations' => 'textarea', 'required_comparison_methods' => 'textarea', 'required_environmental_conditions' => 'textarea', 'required_disclosure_fields' => 'textarea', 'scoring_dimensions' => 'dimensions', 'known_limitations' => 'textarea', 'protocol_reviewer_user_id' => 'absint', 'approval_date' => 'date', 'approval_status' => 'text', 'approval_snapshot_id' => 'absint', 'approval_snapshot_hash' => 'text',
		);
	}

	/** Registered test-record fields, kept in one exact inventory. */
	private static function test_record_fields(): array {
		return array(
			'product_name' => 'text', 'unit_identifier' => 'text', 'acquisition_method' => 'acquisition', 'tester_user_ids' => 'csv_ids', 'test_start_date' => 'date', 'test_end_date' => 'date', 'protocol_id' => 'text', 'protocol_version' => 'version', 'raw_observations' => 'textarea', 'public_test_results' => 'public_results', 'measurement_equipment' => 'textarea', 'failures' => 'textarea', 'deviations' => 'textarea', 'comparison_devices' => 'textarea', 'environment' => 'textarea', 'evidence_references' => 'textarea', 'conflicts' => 'textarea', 'approval_status' => 'text', 'submitted_by' => 'absint', 'submitted_at' => 'datetime', 'approved_by' => 'absint', 'approval_date' => 'date', 'approval_snapshot_id' => 'absint', 'approval_snapshot_hash' => 'text',
		);
	}

	/** Whether a linked test record is approved and protocol-versioned. */
	public static function valid_test_record( int $record_id, string $protocol_version ): bool {
		if ( $record_id <= 0 || 'lel_test_record' !== get_post_type( $record_id ) || 'trash' === get_post_status( $record_id ) ) {
			return false;
		}
		if ( 'approved' !== get_post_meta( $record_id, 'approval_status', true ) || $protocol_version !== get_post_meta( $record_id, 'protocol_version', true ) ) {
			return false;
		}
		$stored_hash = (string) get_post_meta( $record_id, 'approval_snapshot_hash', true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, self::test_record_fingerprint( $record_id ) ) ) {
			return false;
		}
		$approved_by = (int) get_post_meta( $record_id, 'approved_by', true );
		$testers     = preg_split( '/[\s,]+/', (string) get_post_meta( $record_id, 'tester_user_ids', true ) ) ?: array();
		if ( $approved_by <= 0 || in_array( (string) $approved_by, $testers, true ) || $approved_by === (int) get_post_meta( $record_id, 'submitted_by', true ) ) {
			return false;
		}

		$required_fields = array(
			'product_name',
			'unit_identifier',
			'acquisition_method',
			'tester_user_ids',
			'test_start_date',
			'test_end_date',
			'protocol_id',
			'raw_observations',
			'evidence_references',
			'approved_by',
			'approval_date',
		);
		foreach ( $required_fields as $field ) {
			if ( '' === trim( (string) get_post_meta( $record_id, $field, true ) ) ) {
				return false;
			}
		}

		$start = (string) get_post_meta( $record_id, 'test_start_date', true );
		$end   = (string) get_post_meta( $record_id, 'test_end_date', true );
		if ( ! Date_Validator::is_valid( $start ) || ! Date_Validator::is_valid( $end ) || Date_Validator::compare( $end, $start ) < 0 ) {
			return false;
		}

		return self::approved_protocol_exists(
			(string) get_post_meta( $record_id, 'protocol_id', true ),
			$protocol_version,
			$end
		);
	}

	/** Verify that the record points to an approved protocol version effective during testing. */
	private static function approved_protocol_exists( string $protocol_id, string $protocol_version, string $test_end_date ): bool {
		$protocols = get_posts(
			array(
				'post_type'      => 'lel_protocol',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array( 'key' => 'protocol_id', 'value' => $protocol_id ),
					array( 'key' => 'protocol_version', 'value' => $protocol_version ),
					array( 'key' => 'approval_status', 'value' => 'approved' ),
				),
			)
		);
		if ( empty( $protocols ) ) {
			return false;
		}

		$protocol_id_post = (int) $protocols[0];
		$stored_hash      = (string) get_post_meta( $protocol_id_post, 'approval_snapshot_hash', true );
		$reviewer_id      = (int) get_post_meta( $protocol_id_post, 'protocol_reviewer_user_id', true );
		$protocol_post    = get_post( $protocol_id_post );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, self::protocol_fingerprint( $protocol_id_post ) ) || $reviewer_id <= 0 || ( $protocol_post && (int) $protocol_post->post_author === $reviewer_id ) ) {
			return false;
		}
		$effective        = (string) get_post_meta( $protocol_id_post, 'effective_date', true );
		$retired          = (string) get_post_meta( $protocol_id_post, 'retired_date', true );
		if ( ! Date_Validator::is_valid( $effective ) || ! Date_Validator::is_valid( $test_end_date ) || Date_Validator::compare( $effective, $test_end_date ) > 0 ) {
			return false;
		}
		return '' === $retired || ( Date_Validator::is_valid( $retired ) && Date_Validator::compare( $retired, $test_end_date ) >= 0 );
	}

	/** Register private meta consistently. */
	private static function register_private_meta( string $post_type, string $key, string $rule ): void {
		$show_in_rest = false;

		register_post_meta(
			$post_type,
			$key,
			array(
				'type'              => 'absint' === $rule ? 'integer' : ( in_array( $rule, array( 'dimensions', 'public_results' ), true ) ? 'array' : 'string' ),
				'single'            => true,
				'show_in_rest'      => $show_in_rest,
				'sanitize_callback' => static fn( $value ) => 'public_results' === $rule ? self::sanitize_public_results( $value ) : Meta_Registry::sanitize_value( $rule, $value ),
				'auth_callback'     => static function () use ( $post_type, $key ): bool {
					if ( 'lel_protocol' === $post_type ) {
						if ( in_array( $key, array( 'protocol_reviewer_user_id', 'approval_date', 'approval_status', 'approval_snapshot_id', 'approval_snapshot_hash' ), true ) ) {
							return false;
						}
						return current_user_can( 'manage_test_protocols' );
					}
					if ( in_array( $key, array( 'approval_status', 'approved_by', 'approval_date', 'approval_snapshot_id', 'approval_snapshot_hash' ), true ) ) {
						return false;
					}
					if ( 'public_test_results' === $key ) {
						return current_user_can( 'approve_test_records' );
					}
					return current_user_can( 'manage_test_records' );
				},
			)
		);
	}
}

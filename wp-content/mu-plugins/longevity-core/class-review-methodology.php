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
	/** Register metadata. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_meta' ), 12 );
	}

	/** Register test protocol and record metadata. */
	public static function register_meta(): void {
		$protocol_fields = array(
			'protocol_id' => 'text', 'protocol_version' => 'version', 'product_category' => 'text', 'effective_date' => 'date', 'retired_date' => 'date', 'minimum_test_duration' => 'text', 'required_observations' => 'textarea', 'required_comparison_methods' => 'textarea', 'required_environmental_conditions' => 'textarea', 'required_disclosure_fields' => 'textarea', 'scoring_dimensions' => 'dimensions', 'known_limitations' => 'textarea', 'protocol_reviewer_user_id' => 'absint', 'approval_date' => 'date', 'approval_status' => 'text',
		);
		foreach ( $protocol_fields as $key => $rule ) {
			self::register_private_meta( 'lel_protocol', $key, $rule );
		}
		$record_fields = array(
			'product_name' => 'text', 'unit_identifier' => 'text', 'acquisition_method' => 'acquisition', 'tester_user_ids' => 'csv_ids', 'test_start_date' => 'date', 'test_end_date' => 'date', 'protocol_id' => 'text', 'protocol_version' => 'version', 'raw_observations' => 'textarea', 'public_test_results' => 'public_results', 'measurement_equipment' => 'textarea', 'failures' => 'textarea', 'deviations' => 'textarea', 'comparison_devices' => 'textarea', 'environment' => 'textarea', 'evidence_references' => 'textarea', 'conflicts' => 'textarea', 'approval_status' => 'text', 'approved_by' => 'absint', 'approval_date' => 'date',
		);
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
		$path = LONGEVITY_CORE_PATH . '../config/scoring/default-review-model.json';
		if ( ! file_exists( $path ) ) {
			return array();
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Minimum score difference required for a meaningful ranking distinction. */
	public static function minimum_meaningful_difference(): float {
		$model = self::model();
		return isset( $model['minimum_meaningful_difference'] ) ? (float) $model['minimum_meaningful_difference'] : 0.2;
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

	/** Whether a linked test record is approved and protocol-versioned. */
	public static function valid_test_record( int $record_id, string $protocol_version ): bool {
		if ( $record_id <= 0 || 'lel_test_record' !== get_post_type( $record_id ) || 'trash' === get_post_status( $record_id ) ) {
			return false;
		}
		if ( 'approved' !== get_post_meta( $record_id, 'approval_status', true ) || $protocol_version !== get_post_meta( $record_id, 'protocol_version', true ) ) {
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
		if ( $end < $start ) {
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
		$effective        = (string) get_post_meta( $protocol_id_post, 'effective_date', true );
		$retired          = (string) get_post_meta( $protocol_id_post, 'retired_date', true );
		if ( '' === $effective || $effective > $test_end_date ) {
			return false;
		}
		return '' === $retired || $retired >= $test_end_date;
	}

	/** Register private meta consistently. */
	private static function register_private_meta( string $post_type, string $key, string $rule ): void {
		$show_in_rest = true;
		if ( 'dimensions' === $rule ) {
			$show_in_rest = array(
				'schema' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'name'   => array( 'type' => 'string' ),
							'score'  => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 5 ),
							'weight' => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 100 ),
						),
					),
				),
			);
		} elseif ( 'public_results' === $rule ) {
			$show_in_rest = array(
				'schema' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'label'           => array( 'type' => 'string' ),
							'observed_value'  => array( 'type' => 'string' ),
							'unit'            => array( 'type' => 'string' ),
							'reference_label' => array( 'type' => 'string' ),
							'reference_value' => array( 'type' => 'string' ),
							'status'          => array( 'type' => 'string', 'enum' => array( 'meets', 'partially_meets', 'does_not_meet', 'informational', 'not_applicable' ) ),
							'note'            => array( 'type' => 'string' ),
							'display_order'   => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 999 ),
						),
					),
				),
			);
		}

		register_post_meta(
			$post_type,
			$key,
			array(
				'type'              => 'absint' === $rule ? 'integer' : ( in_array( $rule, array( 'dimensions', 'public_results' ), true ) ? 'array' : 'string' ),
				'single'            => true,
				'show_in_rest'      => $show_in_rest,
				'sanitize_callback' => static fn( $value ) => 'public_results' === $rule ? self::sanitize_public_results( $value ) : Meta_Registry::sanitize_value( $rule, $value ),
				'auth_callback'     => static fn() => current_user_can( 'manage_test_protocols' ),
			)
		);
	}
}

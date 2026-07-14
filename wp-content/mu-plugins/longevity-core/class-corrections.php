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
	/** Register hooks. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_meta' ), 12 );
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
			register_post_meta(
				'lel_correction',
				$key,
				array(
					'type'              => 'absint' === $rule ? 'integer' : ( 'boolean' === $rule ? 'boolean' : 'string' ),
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => static fn( $value ) => self::sanitize( $rule, $value ),
					'auth_callback'     => static fn() => current_user_can( 'manage_corrections' ),
				)
			);
		}
	}

	/** Get completed material corrections for a post. */
	public static function public_records( int $post_id ): array {
		return get_posts(
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
	}

	/** Render public correction history. */
	public static function render( int $post_id ): string {
		$records = self::public_records( $post_id );
		if ( empty( $records ) ) {
			return '';
		}
		$html = '<section class="longevity-update-history" aria-labelledby="longevity-corrections-title"><h2 id="longevity-corrections-title">' . esc_html__( 'Corrections and material updates', 'longevity-core' ) . '</h2><ol>';
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

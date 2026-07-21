<?php
/**
 * Explicit metadata registry.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Registers and sanitizes editorial metadata. */
final class Meta_Registry {
	/** Register hooks. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ), 10 );
		add_action( 'init', array( self::class, 'register_user_meta' ), 11 );
	}

	/** Register all post metadata. */
	public static function register(): void {
		foreach ( self::definitions() as $key => $definition ) {
			foreach ( $definition['post_types'] as $post_type ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'              => $definition['type'],
						'single'            => true,
						'default'           => $definition['default'],
						'description'       => $definition['description'],
						'show_in_rest'      => self::rest_visibility( $definition ),
						'sanitize_callback' => static fn( $value ) => self::sanitize_by_key( $key, $value ),
						'auth_callback'     => static fn( bool $allowed, string $meta_key, int $post_id, int $user_id ) => self::authorize( $meta_key, $post_id, $user_id ),
					)
				);
			}
		}
	}

	/** Register reviewer profile metadata. */
	public static function register_user_meta(): void {
		$fields = array(
			'professional_credentials'        => array( 'type' => 'string', 'sanitize' => 'textarea', 'description' => 'Public professional credentials.' ),
			'credential_verification_status'  => array( 'type' => 'string', 'sanitize' => 'credential_status', 'description' => 'Administrative credential verification status.' ),
			'credential_verification_date'    => array( 'type' => 'string', 'sanitize' => 'date', 'description' => 'Date credentials were checked.' ),
			'professional_profile_url'         => array( 'type' => 'string', 'sanitize' => 'url', 'description' => 'Public professional profile URL.' ),
			'review_scope'                     => array( 'type' => 'string', 'sanitize' => 'textarea', 'description' => 'Topics this reviewer is qualified to review.' ),
			'jurisdictions'                    => array( 'type' => 'string', 'sanitize' => 'textarea', 'description' => 'Relevant professional jurisdictions.' ),
			'conflict_disclosure'              => array( 'type' => 'string', 'sanitize' => 'textarea', 'description' => 'Public conflicts disclosure.' ),
		);

		foreach ( $fields as $key => $definition ) {
			register_meta(
				'user',
				$key,
				array(
					'type'              => $definition['type'],
					'single'            => true,
					'description'       => $definition['description'],
					'show_in_rest'      => false,
					'sanitize_callback' => static fn( $value ) => self::sanitize_value( $definition['sanitize'], $value ),
					'auth_callback'     => static fn( bool $allowed, string $meta_key, int $user_id ) => current_user_can( 'edit_user', $user_id ),
				)
			);
		}
	}

	/** Metadata definitions. */
	public static function definitions(): array {
		$editorial = array( 'post', 'review' );
		$review    = array( 'review' );

		return array(
			'content_summary'                  => self::field( 'string', '', 'textarea', $editorial, true, true, 'A concise public scope or answer summary.' ),
			'content_scope'                    => self::field( 'string', '', 'textarea', $editorial, true, true, 'What the content covers.' ),
			'content_limitations'              => self::field( 'string', '', 'textarea', $editorial, true, true, 'Material limitations and uncertainty.' ),
			'original_contribution'            => self::field( 'string', '', 'textarea', $editorial, true, true, 'Original data, testing, interview, framework, or tool.' ),
			'evidence_grade'                   => self::field( 'string', '', 'evidence_grade', $editorial, true, true, 'Conservative evidence grade A, B, C, D, or U.' ),
			'evidence_grade_rationale'         => self::field( 'string', '', 'textarea', $editorial, true, true, 'Human rationale for the evidence grade.' ),
			'material_health_claims'           => self::field( 'boolean', false, 'boolean', $editorial, true, false, 'Whether material health claims are present.' ),
			'medical_review_required'          => self::field( 'boolean', false, 'boolean', $editorial, true, false, 'Whether medical review is required.' ),
			'medical_review_status'            => self::field( 'string', 'not_required', 'medical_status', $editorial, true, true, 'Medical-review workflow status.' ),
			'medical_reviewer_user_id'         => self::field( 'integer', 0, 'absint', $editorial, true, true, 'WordPress user ID of the reviewer.' ),
			'medical_reviewer_name_fallback'   => self::field( 'string', '', 'text', $editorial, true, true, 'Fallback public reviewer name for migrated content.' ),
			'medical_reviewer_credentials'     => self::field( 'string', '', 'textarea', $editorial, true, true, 'Public reviewer credentials; never invented.' ),
			'medical_review_scope'             => self::field( 'string', '', 'review_scope', $editorial, true, true, 'Exact scope of the medical review.' ),
			'medical_review_sections'          => self::field( 'string', '', 'textarea', $editorial, true, true, 'Sections included in review.' ),
			'medical_review_claim_ids'         => self::field( 'string', '', 'csv_ids', $editorial, true, true, 'Claim IDs included in review.' ),
			'medical_review_limitations'       => self::field( 'string', '', 'textarea', $editorial, true, true, 'Limitations of the recorded review.' ),
			'medical_review_required_revisions'=> self::field( 'string', '', 'textarea', $editorial, true, false, 'Required revisions from the reviewer.' ),
			'medical_review_revision_status'   => self::field( 'string', 'not_applicable', 'revision_status', $editorial, true, false, 'Status of required review revisions.' ),
			'medical_review_conflicts'         => self::field( 'string', '', 'textarea', $editorial, true, true, 'Reviewer conflicts of interest.' ),
			'medical_review_date'              => self::field( 'string', '', 'date', $editorial, true, true, 'Date medical review was completed.' ),
			'next_medical_review_date'         => self::field( 'string', '', 'date', $editorial, true, true, 'Next medical review date.' ),
			'medical_review_version'           => self::field( 'string', '', 'version', $editorial, true, true, 'Version of the content reviewed.' ),
			'medical_review_attested'          => self::field( 'boolean', false, 'boolean', $editorial, true, false, 'Authenticated reviewer attestation.' ),
			'fact_check_status'                => self::field( 'string', 'not_started', 'fact_status', $editorial, true, true, 'Fact-check workflow status.' ),
			'fact_checked_by'                  => self::field( 'integer', 0, 'absint', $editorial, true, true, 'WordPress user ID of fact checker.' ),
			'fact_checked_date'                => self::field( 'string', '', 'date', $editorial, true, true, 'Fact-check completion date.' ),
			'next_fact_check_date'             => self::field( 'string', '', 'date', $editorial, true, true, 'Next fact-check date.' ),
			'testing_required'                 => self::field( 'boolean', false, 'boolean', $editorial, true, false, 'Whether hands-on testing is required.' ),
			'testing_status'                   => self::field( 'string', 'not_required', 'testing_status', $editorial, true, true, 'Testing workflow status.' ),
			'testing_start_date'               => self::field( 'string', '', 'date', $editorial, true, true, 'Testing start date.' ),
			'testing_end_date'                 => self::field( 'string', '', 'date', $editorial, true, true, 'Testing end date.' ),
			'testing_duration'                 => self::field( 'string', '', 'text', $editorial, true, true, 'Human-readable test duration.' ),
			'testing_methodology_url'          => self::field( 'string', '', 'url', $editorial, true, true, 'Public methodology URL.' ),
			'testing_protocol_version'         => self::field( 'string', '', 'version', $editorial, true, true, 'Test protocol version.' ),
			'test_record_id'                   => self::field( 'integer', 0, 'absint', $review, true, false, 'Approved private test record ID.' ),
			'product_acquisition_method'       => self::field( 'string', '', 'acquisition', $editorial, true, true, 'How the product was obtained.' ),
			'commercial_relationship'          => self::field( 'string', 'none', 'commercial', $editorial, true, true, 'Commercial relationship for the content.' ),
			'affiliate_disclosure_required'    => self::field( 'boolean', false, 'boolean', $editorial, true, false, 'Whether affiliate disclosure is required.' ),
			'affiliate_disclosure_status'      => self::field( 'string', 'not_required', 'disclosure_status', $editorial, true, true, 'Affiliate disclosure completion status.' ),
			'affiliate_registry_verified'      => self::field( 'boolean', false, 'boolean', $editorial, true, false, 'Whether affiliate destinations are registered.' ),
			'editorial_approval_status'        => self::field( 'string', 'drafting', 'editorial_status', $editorial, true, false, 'Editorial workflow state.' ),
			'correction_status'                => self::field( 'string', 'none', 'correction_status', $editorial, true, true, 'Correction status.' ),
			'last_material_update'             => self::field( 'string', '', 'date', $editorial, true, true, 'Date of last material update.' ),
			'next_content_review_date'         => self::field( 'string', '', 'date', $editorial, true, true, 'Next content review date.' ),
			'_longevity_related_post_ids'      => self::field( 'string', '', 'csv_ids', $editorial, true, false, 'Ordered manual related-post IDs.' ),
			'region_scope'                     => self::field( 'string', '', 'text', $editorial, true, true, 'Jurisdiction or regional scope.' ),
			'evidence_cutoff_date'             => self::field( 'string', '', 'date', $editorial, true, true, 'Evidence search cutoff date.' ),
			'uncertainty_statement_present'    => self::field( 'boolean', false, 'boolean', $editorial, true, false, 'Whether explicit uncertainty appears in the article.' ),
			'review_score'                     => self::field( 'number', 0.0, 'score', $review, true, true, 'Calculated review score from zero to five.' ),
			'review_score_version'             => self::field( 'string', '', 'version', $review, true, true, 'Scoring model version.' ),
			'review_score_confidence'          => self::field( 'string', '', 'confidence', $review, true, true, 'Confidence in the review score.' ),
			'review_score_dimensions'          => self::field( 'array', array(), 'dimensions', $review, true, false, 'Raw dimensions and weights used for scoring.' ),
			'review_score_override_reason'     => self::field( 'string', '', 'textarea', $review, true, false, 'Reason for any manual score override.' ),
			'best_for'                         => self::field( 'string', '', 'text', $review, true, true, 'Best-fit audience.' ),
			'not_for'                          => self::field( 'string', '', 'text', $review, true, true, 'Readers for whom the product is not a good fit.' ),
			'price_checked_date'               => self::field( 'string', '', 'date', $review, true, true, 'Date price was checked.' ),
			'price_region'                     => self::field( 'string', '', 'text', $review, true, true, 'Region for price information.' ),
			'tested_product_model'             => self::field( 'string', '', 'text', $review, true, true, 'Exact product model tested.' ),
			'tested_firmware_version'          => self::field( 'string', '', 'text', $review, true, true, 'Firmware version observed.' ),
			'tested_app_version'               => self::field( 'string', '', 'text', $review, true, true, 'App version observed.' ),
			'test_unit_identifier'             => self::field( 'string', '', 'text', $review, true, false, 'Internal non-sensitive test unit identifier.' ),
			'comparison_set'                   => self::field( 'string', '', 'textarea', $review, true, true, 'Products or methods used for comparison.' ),
			'major_failures'                   => self::field( 'string', '', 'textarea', $review, true, true, 'Material failures or problems observed.' ),
			'data_export_available'            => self::field( 'boolean', false, 'boolean', $review, true, true, 'Whether the product supports data export.' ),
			'subscription_required'            => self::field( 'boolean', false, 'boolean', $review, true, true, 'Whether a subscription is required.' ),
			'warranty_checked_date'            => self::field( 'string', '', 'date', $review, true, true, 'Date warranty terms were checked.' ),
			'return_policy_checked_date'       => self::field( 'string', '', 'date', $review, true, true, 'Date return policy was checked.' ),
			'privacy_policy_checked_date'      => self::field( 'string', '', 'date', $review, true, true, 'Date privacy policy was checked.' ),
			'billing_interval'                 => self::field( 'string', '', 'text', $review, true, true, 'Subscription billing interval, e.g., monthly, yearly.' ),
			'product_brand'                    => self::field( 'string', '', 'text', $review, true, true, 'Product brand exactly as displayed by the manufacturer.' ),
			'product_variant'                  => self::field( 'string', '', 'text', $review, true, true, 'Tested color, size, capacity, or other material variant.' ),
			'product_price_amount'             => self::field( 'number', 0.0, 'price', $review, true, true, 'Observed non-negative price amount; never a best-price claim.' ),
			'product_price_currency'           => self::field( 'string', '', 'currency', $review, true, true, 'ISO 4217 currency code for the observed price.' ),
			// Backward-compatible aliases retained for existing content.
			'medical_reviewer'                 => self::field( 'string', '', 'text', $editorial, true, true, 'Deprecated fallback reviewer name.' ),
			'last_fact_checked'                 => self::field( 'string', '', 'date', $editorial, true, true, 'Deprecated fact-check date.' ),
			'evidence_level'                   => self::field( 'string', '', 'text', $editorial, true, true, 'Deprecated evidence level.' ),
		);
	}

	/** Construct a field definition. */
	private static function field( string $type, $default, string $sanitize, array $post_types, bool $rest, bool $public, string $description, string $required_when = 'none' ): array {
		return compact( 'type', 'default', 'sanitize', 'post_types', 'rest', 'public', 'description', 'required_when' );
	}

	/** Build REST visibility, including an item schema for structured score dimensions. */
	private static function rest_visibility( array $definition ) {
		if ( empty( $definition['rest'] ) ) {
			return false;
		}
		if ( 'dimensions' !== $definition['sanitize'] ) {
			return true;
		}

		return array(
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
	}

	/** Sanitize a metadata value using its registered rule. */
	public static function sanitize_by_key( string $key, $value ) {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $key ] ) ) {
			return null;
		}

		return self::sanitize_value( $definitions[ $key ]['sanitize'], $value );
	}

	/** Sanitize a value by rule. */
	public static function sanitize_value( string $rule, $value ) {
		switch ( $rule ) {
			case 'boolean':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			case 'absint':
				return absint( $value );
			case 'score':
				return min( 5.0, max( 0.0, (float) $value ) );
			case 'price':
				return min( 1000000.0, max( 0.0, round( (float) $value, 2 ) ) );
			case 'currency':
				$value = strtoupper( sanitize_text_field( (string) $value ) );
				return preg_match( '/^[A-Z]{3}$/', $value ) ? $value : '';
			case 'date':
				$value = sanitize_text_field( (string) $value );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
			case 'url':
				return esc_url_raw( (string) $value );
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'csv_ids':
				$ids = array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', (string) $value ) ?: array() ) );
				return implode( ',', array_values( array_unique( $ids ) ) );
			case 'dimensions':
				return Review_Methodology::sanitize_dimensions( $value );
			case 'version':
				$value = sanitize_text_field( (string) $value );
				return preg_match( '/^[0-9A-Za-z][0-9A-Za-z._-]{0,39}$/', $value ) ? $value : '';
			case 'evidence_grade':
				return self::enum( $value, array( '', 'A', 'B', 'C', 'D', 'U' ), '' );
			case 'medical_status':
				return self::enum( $value, array( 'not_required', 'not_started', 'assigned', 'in_review', 'revisions_required', 'complete' ), 'not_required' );
			case 'review_scope':
				return self::enum( $value, array( '', 'full_article', 'safety_only', 'contraindications_only', 'dosage_language_only', 'product_accuracy_only', 'medical_disclaimer_only', 'claim_ids' ), '' );
			case 'revision_status':
				return self::enum( $value, array( 'not_applicable', 'required', 'in_progress', 'complete' ), 'not_applicable' );
			case 'fact_status':
				return self::enum( $value, array( 'not_started', 'in_progress', 'revisions_required', 'complete', 'not_required' ), 'not_started' );
			case 'testing_status':
				return self::enum( $value, array( 'not_required', 'planned', 'in_progress', 'incomplete', 'complete', 'approved' ), 'not_required' );
			case 'acquisition':
				return self::enum( $value, array( '', 'purchased', 'product_supplied', 'loaned', 'service_access', 'independently_verified_only' ), '' );
			case 'commercial':
				return self::enum( $value, array( 'none', 'affiliate', 'product_supplied', 'sponsored' ), 'none' );
			case 'disclosure_status':
				return self::enum( $value, array( 'not_required', 'required', 'draft', 'approved', 'complete' ), 'not_required' );
			case 'editorial_status':
				return self::enum( $value, array( 'idea', 'assigned', 'researching', 'drafting', 'editorial_review', 'fact_check', 'medical_review', 'testing_incomplete', 'commercial_review', 'ready', 'published', 'update_due', 'correction_pending', 'archived' ), 'drafting' );
			case 'correction_status':
				return self::enum( $value, array( 'none', 'reported', 'investigating', 'pending', 'complete' ), 'none' );
			case 'confidence':
				return self::enum( $value, array( '', 'High confidence', 'Moderate confidence', 'Low confidence', 'Preliminary' ), '' );
			case 'credential_status':
				return self::enum( $value, array( '', 'unverified', 'pending', 'verified', 'expired' ), '' );
			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/** Determine whether a metadata field is public. */
	public static function is_public( string $key ): bool {
		$definitions = self::definitions();
		return ! empty( $definitions[ $key ]['public'] );
	}

	/** Authorize metadata writes by field sensitivity and post. */
	public static function authorize( string $meta_key, int $post_id, int $user_id ): bool {
		if ( in_array( $meta_key, array( 'medical_review_attested', 'medical_review_date' ), true ) ) {
			$assigned_user_id = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
			return $assigned_user_id === $user_id && user_can( $user_id, 'complete_medical_review' );
		}
		if ( in_array( $meta_key, array( 'fact_checked_by', 'fact_checked_date' ), true ) ) {
			return user_can( $user_id, 'complete_fact_check' );
		}
		if ( str_starts_with( $meta_key, 'medical_review_' ) || in_array( $meta_key, array( 'medical_reviewer_user_id', 'medical_reviewer_credentials', 'next_medical_review_date' ), true ) ) {
			return user_can( $user_id, 'complete_medical_review' ) || user_can( $user_id, 'edit_post', $post_id );
		}
		if ( str_starts_with( $meta_key, 'fact_check' ) || 'next_fact_check_date' === $meta_key ) {
			return user_can( $user_id, 'complete_fact_check' ) || user_can( $user_id, 'edit_post', $post_id );
		}
		if ( str_starts_with( $meta_key, 'affiliate_' ) || 'commercial_relationship' === $meta_key ) {
			return user_can( $user_id, 'approve_commercial_disclosure' ) || user_can( $user_id, 'edit_post', $post_id );
		}

		return user_can( $user_id, 'edit_post', $post_id );
	}

	/** Sanitize enum values. */
	private static function enum( $value, array $allowed, string $default ): string {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}
}

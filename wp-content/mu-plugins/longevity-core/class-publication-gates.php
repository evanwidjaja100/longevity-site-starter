<?php
/**
 * Publication readiness evaluation and enforcement.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Prevents incomplete high-risk content from being published. */
final class Publication_Gates {
	private const TRANSIENT_PREFIX = 'lel_gate_notice_';

	/** Register hooks. */
	public static function init(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'enforce_classic_publish' ), 99, 2 );
		add_filter( 'rest_pre_insert_post', array( self::class, 'enforce_rest_publish' ), 99, 2 );
		add_filter( 'rest_pre_insert_review', array( self::class, 'enforce_rest_publish' ), 99, 2 );
		add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
		add_action( 'transition_post_status', array( self::class, 'log_status_transition' ), 10, 3 );
		add_action( 'save_post_post', array( self::class, 'persist_override_audit' ), 100, 3 );
		add_action( 'save_post_review', array( self::class, 'persist_override_audit' ), 100, 3 );
	}

	/** Evaluate a post from persisted WordPress state. */
	public static function evaluate( int $post_id, array $overrides = array() ): Gate_Result {
		$post = get_post( $post_id );
		if ( ! $post ) {
			$result = new Gate_Result();
			$result->block( 'missing_post', __( 'The content record could not be loaded.', 'longevity-core' ) );
			return $result;
		}

		$context = array(
			'post_type'                     => $post->post_type,
			'content'                       => $post->post_content,
			'author_present'                => (int) $post->post_author > 0,
			'featured_image_alt_present'    => self::featured_image_alt_present( $post_id ),
			'claim_count'                   => Claims::count_for_post( $post_id ),
			'verified_claim_count'          => Claims::count_for_post( $post_id, 'verified' ),
		);
		foreach ( Meta_Registry::definitions() as $key => $definition ) {
			$context[ $key ] = get_post_meta( $post_id, $key, true );
		}
		$context['source_count'] = self::source_count_for_post( $post_id );
		$context                 = array_merge( $context, $overrides );
		$context['affiliate_links_present'] = Affiliate_Registry::content_has_affiliate_link( (string) $context['content'] );
		$context['affiliate_registry_verified'] = Affiliate_Registry::all_destinations_registered( (string) $context['content'] );
		$context['test_record_valid'] = Review_Methodology::valid_test_record(
			(int) ( $context['test_record_id'] ?? 0 ),
			(string) ( $context['testing_protocol_version'] ?? '' )
		);
		$context['medical_reviewer_valid'] = self::reviewer_is_valid( $context );

		return self::evaluate_values( $context );
	}

	/** Pure readiness evaluation for testability. */
	public static function evaluate_values( array $context ): Gate_Result {
		$result = new Gate_Result();
		self::required_text_check( $result, $context, 'content_summary', 'missing_summary', __( 'Add a concise content summary or direct answer.', 'longevity-core' ) );
		self::required_text_check( $result, $context, 'content_limitations', 'missing_limitations', __( 'Add a meaningful limitations and uncertainty section.', 'longevity-core' ) );
		self::required_text_check( $result, $context, 'next_content_review_date', 'missing_next_review', __( 'Set the next content review date.', 'longevity-core' ) );

		if ( empty( $context['author_present'] ) ) {
			$result->block( 'missing_author', __( 'Assign an accountable author.', 'longevity-core' ) );
		} else {
			$result->pass( 'author_present', __( 'An accountable author is assigned.', 'longevity-core' ) );
		}

		$relationship = (string) ( $context['commercial_relationship'] ?? '' );
		if ( ! in_array( $relationship, array( 'none', 'affiliate', 'product_supplied', 'sponsored' ), true ) ) {
			$result->block( 'commercial_relationship_missing', __( 'Select the applicable commercial relationship.', 'longevity-core' ) );
		} else {
			$result->pass( 'commercial_relationship_selected', __( 'Commercial relationship is selected.', 'longevity-core' ) );
		}

		$editorial_state = (string) ( $context['editorial_approval_status'] ?? '' );
		if ( ! in_array( $editorial_state, array( 'ready', 'published' ), true ) ) {
			$result->block( 'editorial_approval_incomplete', __( 'Move the editorial workflow to Ready for publication before publishing.', 'longevity-core' ) );
		} else {
			$result->pass( 'editorial_approval_complete', __( 'Editorial approval is complete.', 'longevity-core' ) );
		}

		$content = (string) ( $context['content'] ?? '' );
		if ( preg_match( '/\b(TODO|TBD|lorem ipsum|insert (content|name|citation)|placeholder)\b/i', wp_strip_all_tags( $content ) ) ) {
			$result->block( 'placeholder_content', __( 'Remove placeholder content before publication.', 'longevity-core' ) );
		} else {
			$result->pass( 'no_placeholders', __( 'No common placeholder markers were found.', 'longevity-core' ) );
		}

		$material_claims = ! empty( $context['material_health_claims'] ) || ! empty( $context['medical_review_required'] );
		if ( $material_claims ) {
			if ( 'complete' !== ( $context['fact_check_status'] ?? '' ) ) {
				$result->block( 'fact_check_incomplete', __( 'Complete fact-checking for material health claims.', 'longevity-core' ) );
			} else {
				$result->pass( 'fact_check_complete', __( 'Fact-checking is complete.', 'longevity-core' ) );
			}
			if ( empty( $context['fact_checked_by'] ) || empty( $context['fact_checked_date'] ) ) {
				$result->block( 'fact_check_identity_missing', __( 'Record the fact checker and completion date.', 'longevity-core' ) );
			}
			if ( empty( $context['claim_count'] ) ) {
				$result->block( 'claims_missing', __( 'Register each material claim and its source.', 'longevity-core' ) );
			} elseif ( (int) $context['verified_claim_count'] < (int) $context['claim_count'] ) {
				$result->block( 'claims_unverified', __( 'Verify or explicitly supersede every registered material claim.', 'longevity-core' ) );
			} else {
				$result->pass( 'claims_verified', __( 'Registered claims are verified.', 'longevity-core' ) );
			}
		} else {
			$result->skip( 'material_claim_checks', __( 'Material health-claim checks are not applicable.', 'longevity-core' ) );
		}

		if ( ! empty( $context['medical_review_required'] ) ) {
			$medical_fields = array(
				'medical_review_status'      => 'complete',
				'medical_review_scope'       => null,
				'medical_review_sections'    => null,
				'medical_review_limitations' => null,
				'medical_review_conflicts'   => null,
				'medical_review_date'        => null,
				'next_medical_review_date'   => null,
				'medical_review_version'     => null,
			);
			foreach ( $medical_fields as $field => $expected ) {
				$value = $context[ $field ] ?? '';
				if ( null === $expected ? empty( $value ) : $expected !== $value ) {
					$result->block( 'medical_' . $field, sprintf( /* translators: %s: metadata field */ __( 'Complete required medical-review field: %s.', 'longevity-core' ), $field ) );
				}
			}
			if ( 'claim_ids' === ( $context['medical_review_scope'] ?? '' ) && empty( $context['medical_review_claim_ids'] ) ) {
				$result->block( 'medical_claim_ids_missing', __( 'List the exact claim IDs covered by a claim-scoped medical review.', 'longevity-core' ) );
			}
			if ( empty( $context['medical_reviewer_valid'] ) ) {
				$result->block( 'medical_reviewer_invalid', __( 'Assign an authenticated reviewer with recorded, verified credentials.', 'longevity-core' ) );
			}
			if ( empty( $context['medical_review_attested'] ) ) {
				$result->block( 'medical_attestation_missing', __( 'The authenticated reviewer must complete the review attestation.', 'longevity-core' ) );
			} else {
				$result->pass( 'medical_review_complete', __( 'Scoped medical review and attestation are complete.', 'longevity-core' ) );
			}
			if ( 'required' === ( $context['medical_review_revision_status'] ?? '' ) || 'in_progress' === ( $context['medical_review_revision_status'] ?? '' ) ) {
				$result->block( 'medical_revisions_open', __( 'Resolve required medical-review revisions.', 'longevity-core' ) );
			}
		} else {
			$result->skip( 'medical_review', __( 'Medical review is not marked as required.', 'longevity-core' ) );
		}

		if ( ! empty( $context['testing_required'] ) ) {
			if ( ! in_array( (string) ( $context['testing_status'] ?? '' ), array( 'complete', 'approved' ), true ) ) {
				$result->block( 'testing_incomplete', __( 'Complete and approve the required product test.', 'longevity-core' ) );
			}
			foreach ( array( 'testing_start_date', 'testing_end_date', 'testing_protocol_version', 'testing_methodology_url', 'product_acquisition_method' ) as $field ) {
				if ( empty( $context[ $field ] ) ) {
					$result->block( 'testing_' . $field, sprintf( /* translators: %s: metadata field */ __( 'Complete required testing field: %s.', 'longevity-core' ), $field ) );
				}
			}
			if ( empty( $context['test_record_valid'] ) ) {
				$result->block( 'test_record_invalid', __( 'Link an approved test record using the same protocol version.', 'longevity-core' ) );
			} else {
				$result->pass( 'test_record_valid', __( 'The linked test record is approved and version-matched.', 'longevity-core' ) );
			}
		} else {
			$result->skip( 'testing', __( 'Hands-on testing is not required.', 'longevity-core' ) );
		}

		$affiliate_present = ! empty( $context['affiliate_links_present'] ) || 'affiliate' === $relationship;
		if ( $affiliate_present ) {
			if ( ! in_array( (string) ( $context['affiliate_disclosure_status'] ?? '' ), array( 'approved', 'complete' ), true ) ) {
				$result->block( 'affiliate_disclosure_incomplete', __( 'Approve the affiliate disclosure before publication.', 'longevity-core' ) );
			}
			if ( empty( $context['affiliate_registry_verified'] ) ) {
				$result->block( 'affiliate_registry_unverified', __( 'Verify every affiliate destination in the affiliate registry.', 'longevity-core' ) );
			} else {
				$result->pass( 'affiliate_controls_complete', __( 'Affiliate disclosure and registry checks are complete.', 'longevity-core' ) );
			}
		} else {
			$result->skip( 'affiliate_controls', __( 'No affiliate relationship or link is present.', 'longevity-core' ) );
		}

		if ( 'review' === ( $context['post_type'] ?? '' ) ) {
			foreach ( array( 'tested_product_model', 'comparison_set' ) as $field ) {
				if ( empty( $context[ $field ] ) ) {
					$result->block( 'review_' . $field, sprintf( /* translators: %s: metadata field */ __( 'Complete required review field: %s.', 'longevity-core' ), $field ) );
				}
			}
			$score = (float) ( $context['review_score'] ?? 0 );
			if ( $score > 0 && empty( $context['review_score_version'] ) ) {
				$result->block( 'score_version_missing', __( 'Record the scoring-model version for any review score.', 'longevity-core' ) );
			}
			if ( $score > 0 && empty( $context['review_score_confidence'] ) ) {
				$result->block( 'score_confidence_missing', __( 'Record confidence separately from the review score.', 'longevity-core' ) );
			}
			if ( $score > 0 ) {
				$dimensions = $context['review_score_dimensions'] ?? array();
				try {
					$calculated = Review_Methodology::calculate_score( is_array( $dimensions ) ? $dimensions : array() );
					if ( abs( (float) $calculated['score'] - $score ) > 0.01 ) {
						if ( empty( $context['review_score_override_reason'] ) ) {
							$result->block( 'score_not_reproducible', __( 'The public score must match the stored weighted dimensions or include a documented override reason.', 'longevity-core' ) );
						} else {
							$result->warn( 'score_override_used', __( 'A manual score override is documented; verify that the public methodology explains it.', 'longevity-core' ) );
						}
					} else {
						$result->pass( 'score_reproducible', __( 'The review score recalculates from the stored dimensions.', 'longevity-core' ) );
					}
				} catch ( \InvalidArgumentException $exception ) {
					$result->block( 'score_dimensions_invalid', sanitize_text_field( $exception->getMessage() ) );
				}
			}
			if ( ! empty( $context['price_region'] ) && empty( $context['price_checked_date'] ) ) {
				$result->block( 'price_date_missing', __( 'Add a checked date for regional price claims.', 'longevity-core' ) );
			}
		}

		if ( ! empty( $context['evidence_grade'] ) ) {
			if ( empty( $context['evidence_grade_rationale'] ) ) {
				$result->block( 'evidence_rationale_missing', __( 'Every evidence grade requires a human rationale.', 'longevity-core' ) );
			}
			if ( empty( $context['evidence_cutoff_date'] ) ) {
				$result->block( 'evidence_cutoff_missing', __( 'Record the evidence cutoff date.', 'longevity-core' ) );
			} else {
				$result->pass( 'evidence_metadata_complete', __( 'Evidence grade metadata is present.', 'longevity-core' ) );
			}
		}

		if ( empty( $context['original_contribution'] ) ) {
			$result->warn( 'original_contribution_missing', __( 'Record an authentic original contribution or explain why none applies.', 'longevity-core' ) );
		} else {
			$result->pass( 'original_contribution_present', __( 'Original contribution is recorded.', 'longevity-core' ) );
		}
		if ( empty( $context['region_scope'] ) ) {
			$result->warn( 'region_scope_missing', __( 'Add regional or jurisdiction scope when facts may vary by market.', 'longevity-core' ) );
		}
		if ( empty( $context['uncertainty_statement_present'] ) && $material_claims ) {
			$result->warn( 'uncertainty_statement_missing', __( 'Add an explicit uncertainty statement for material health claims.', 'longevity-core' ) );
		}
		if ( empty( $context['featured_image_alt_present'] ) ) {
			$result->warn( 'featured_image_alt_missing', __( 'Add meaningful alternative text if a featured image is used.', 'longevity-core' ) );
		}
		if ( empty( $context['source_count'] ) && ! empty( $context['claim_count'] ) ) {
			$result->warn( 'source_registry_empty', __( 'Link claims to source-registry records for reusable provenance.', 'longevity-core' ) );
		}

		return $result;
	}

	/** Enforce classic-editor publishing by preserving content as a draft. */
	public static function enforce_classic_publish( array $data, array $postarr ): array {
		if ( ! in_array( $data['post_type'] ?? '', array( 'post', 'review' ), true ) || ! in_array( $data['post_status'] ?? '', array( 'publish', 'future', 'private' ), true ) ) {
			return $data;
		}
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $post_id <= 0 ) {
			$data['post_status'] = 'draft';
			self::set_notice( array( __( 'Save the draft and complete editorial metadata before first publication.', 'longevity-core' ) ) );
			return $data;
		}
		$overrides = self::classic_request_overrides( $post_id );
		$overrides['content'] = $data['post_content'] ?? '';
		$result = self::evaluate( $post_id, $overrides );
		if ( ! $result->is_blocked() ) {
			return $data;
		}
		if ( self::override_allowed_from_request() ) {
			set_transient( 'lel_override_' . $post_id . '_' . get_current_user_id(), sanitize_textarea_field( wp_unslash( $_POST['longevity_override_reason'] ) ), MINUTE_IN_SECONDS );
			return $data;
		}
		$data['post_status'] = 'draft';
		self::set_notice( array_column( $result->blocking(), 'message' ) );
		return $data;
	}

	/** Enforce REST/block-editor publishing with a structured error. */
	public static function enforce_rest_publish( $prepared_post, \WP_REST_Request $request ) {
		$status = (string) ( $prepared_post->post_status ?? $request->get_param( 'status' ) );
		if ( ! in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			return $prepared_post;
		}
		$post_id = (int) $request->get_param( 'id' );
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'lel_gate_new_post', __( 'Save the draft before attempting first publication.', 'longevity-core' ), array( 'status' => 400 ) );
		}
		$overrides = array( 'content' => (string) ( $prepared_post->post_content ?? '' ) );
		$meta = $request->get_param( 'meta' );
		if ( is_array( $meta ) ) {
			$definitions = Meta_Registry::definitions();
			foreach ( $meta as $key => $value ) {
				if ( isset( $definitions[ $key ] ) && Meta_Registry::authorize( $key, $post_id, get_current_user_id() ) ) {
					$overrides[ $key ] = Meta_Registry::sanitize_by_key( $key, $value );
				}
			}
		}
		$result = self::evaluate( $post_id, $overrides );
		if ( ! $result->is_blocked() ) {
			return $prepared_post;
		}
		$reason = sanitize_textarea_field( (string) $request->get_param( 'longevity_override_reason' ) );
		if ( current_user_can( 'approve_publication_override' ) && '' !== $reason ) {
			set_transient( 'lel_override_' . $post_id . '_' . get_current_user_id(), $reason, MINUTE_IN_SECONDS );
			return $prepared_post;
		}
		return new \WP_Error(
			'lel_publication_blocked',
			__( 'Publication readiness checks failed.', 'longevity-core' ),
			array( 'status' => 400, 'readiness' => $result->to_array() )
		);
	}

	/** Display a one-use gate notice. */
	public static function admin_notice(): void {
		$user_id = get_current_user_id();
		$messages = get_transient( self::TRANSIENT_PREFIX . $user_id );
		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return;
		}
		delete_transient( self::TRANSIENT_PREFIX . $user_id );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Publication was blocked; the content remains a draft.', 'longevity-core' ) . '</strong></p><ul>';
		foreach ( $messages as $message ) {
			echo '<li>' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	/** Log status changes. */
	public static function log_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status === $old_status || ! in_array( $post->post_type, array( 'post', 'review' ), true ) ) {
			return;
		}
		self::log_event( $post->ID, 'status_changed', array( 'from' => $old_status, 'to' => $new_status ) );
	}

	/** Persist an override audit event. */
	public static function persist_override_audit( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $post, $update );
		$key = 'lel_override_' . $post_id . '_' . get_current_user_id();
		$reason = get_transient( $key );
		if ( ! is_string( $reason ) || '' === $reason ) {
			return;
		}
		delete_transient( $key );
		self::log_event( $post_id, 'publication_override_used', array( 'reason' => $reason ) );
	}

	/** Append a bounded, non-sensitive audit record. */
	public static function log_event( int $post_id, string $event, array $details = array() ): void {
		$log = get_post_meta( $post_id, '_longevity_audit_log', true );
		$log = is_array( $log ) ? $log : array();
		$log[] = array(
			'event'   => sanitize_key( $event ),
			'user_id' => get_current_user_id(),
			'time'    => gmdate( DATE_ATOM ),
			'details' => array_map( static fn( $value ) => sanitize_textarea_field( (string) $value ), $details ),
		);
		$log = array_slice( $log, -100 );
		update_post_meta( $post_id, '_longevity_audit_log', $log );
	}

	/** Add a required text check. */
	private static function required_text_check( Gate_Result $result, array $context, string $field, string $code, string $message ): void {
		if ( '' === trim( (string) ( $context[ $field ] ?? '' ) ) ) {
			$result->block( $code, $message );
		} else {
			$result->pass( $field, sprintf( /* translators: %s: metadata field */ __( '%s is complete.', 'longevity-core' ), $field ) );
		}
	}

	/** Read authorized metadata submitted by the classic editor for same-request evaluation. */
	private static function classic_request_overrides( int $post_id ): array {
		if ( empty( $_POST['longevity_editorial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['longevity_editorial_nonce'] ) ), 'longevity_save_editorial' ) ) {
			return array();
		}
		$overrides   = array();
		$definitions = Meta_Registry::definitions();
		foreach ( $definitions as $key => $definition ) {
			if ( ! Meta_Registry::authorize( $key, $post_id, get_current_user_id() ) ) {
				continue;
			}
			if ( 'boolean' === $definition['type'] ) {
				$overrides[ $key ] = isset( $_POST[ $key ] );
			} elseif ( isset( $_POST[ $key ] ) ) {
				$overrides[ $key ] = Meta_Registry::sanitize_by_key( $key, wp_unslash( $_POST[ $key ] ) );
			}
		}
		return $overrides;
	}

	/** Store a user-scoped notice. */
	private static function set_notice( array $messages ): void {
		set_transient( self::TRANSIENT_PREFIX . get_current_user_id(), array_values( array_unique( array_map( 'sanitize_text_field', $messages ) ) ), MINUTE_IN_SECONDS );
	}

	/** Check a classic request for an authorized written override reason. */
	private static function override_allowed_from_request(): bool {
		if ( ! current_user_can( 'approve_publication_override' ) || empty( $_POST['longevity_editorial_nonce'] ) || empty( $_POST['longevity_override_reason'] ) ) {
			return false;
		}
		return wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['longevity_editorial_nonce'] ) ), 'longevity_save_editorial' );
	}

	/** Verify reviewer identity and credentials. */
	private static function reviewer_is_valid( array $context ): bool {
		$user_id = (int) ( $context['medical_reviewer_user_id'] ?? 0 );
		if ( $user_id <= 0 || ! user_can( $user_id, 'complete_medical_review' ) ) {
			return false;
		}
		$status      = get_user_meta( $user_id, 'credential_verification_status', true );
		$credentials = get_user_meta( $user_id, 'professional_credentials', true );
		return 'verified' === $status && '' !== trim( (string) $credentials );
	}

	/** Check featured image alternative text only when an image exists. */
	private static function featured_image_alt_present( int $post_id ): bool {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return true;
		}
		return '' !== trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) );
	}

	/** Count source records linked through claims. */
	private static function source_count_for_post( int $post_id ): int {
		$claims = get_posts(
			array(
				'post_type'      => 'lel_claim',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => 'post_id',
				'meta_value'     => $post_id,
			)
		);
		$source_ids = array();
		foreach ( $claims as $claim_id ) {
			$source_id = get_post_meta( $claim_id, 'source_id', true );
			if ( $source_id ) {
				$source_ids[] = $source_id;
			}
		}
		return count( array_unique( $source_ids ) );
	}
}

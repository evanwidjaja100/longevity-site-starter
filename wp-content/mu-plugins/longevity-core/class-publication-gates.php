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

	/** @var array<int, string> Authorized correlations awaiting WordPress's actual transition hook. */
	private static array $pending_overrides = array();

	/** Prevent nested compensation transitions from finalizing the original intent twice. */
	private static bool $compensating = false;

	/** Register hooks. */
	public static function init(): void {
		register_shutdown_function( array( Publication_Lock::class, 'release_all' ) );
		add_filter( 'wp_insert_post_data', array( self::class, 'enforce_classic_publish' ), 99, 2 );
		add_filter( 'rest_pre_insert_post', array( self::class, 'enforce_rest_publish' ), 99, 2 );
		add_filter( 'rest_pre_insert_review', array( self::class, 'enforce_rest_publish' ), 99, 2 );
		add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
		add_action( 'transition_post_status', array( self::class, 'log_status_transition' ), 10, 3 );
		add_action( 'transition_post_status', array( self::class, 'finalize_override' ), 999, 3 );
		add_action( 'wp_after_insert_post', array( self::class, 'release_after_post_update' ), 999, 1 );
	}

	/** Evaluate a post from persisted WordPress state. */
	public static function evaluate( int $post_id, array $overrides = array() ): Gate_Result {
		$post = get_post( $post_id );
		if ( ! $post ) {
			$result = new Gate_Result();
			$result->block( 'missing_post', __( 'The content record could not be loaded.', 'longevity-core' ) );
			return $result;
		}

		$prospective = self::prospective_state( $overrides );
		$context     = array(
			'post_type'                  => $post->post_type,
			'content'                    => $post->post_content,
			'author_present'             => (int) ( $prospective['post_author'] ?? $post->post_author ) > 0,
			'featured_image_alt_present' => self::featured_image_alt_present( $post_id, $prospective['featured_image_id'] ?? null ),
			'claim_count'                => Claims::count_for_post( $post_id ),
			'verified_claim_count'       => Claims::count_for_post( $post_id, 'verified' ),
		);
		foreach ( Meta_Registry::definitions() as $key => $definition ) {
			$context[ $key ] = get_post_meta( $post_id, $key, true );
		}
		$context['source_count']                = self::source_count_for_post( $post_id );
		$context                                = array_merge( $context, $overrides );
		$context['affiliate_links_present']     = Affiliate_Registry::content_has_affiliate_link( (string) $context['content'] );
		$context['affiliate_registry_verified'] = Affiliate_Registry::all_destinations_registered( (string) $context['content'] );
		$context['test_record_valid']           = Review_Methodology::valid_test_record(
			(int) ( $context['test_record_id'] ?? 0 ),
			(string) ( $context['testing_protocol_version'] ?? '' )
		);
		$context['medical_reviewer_valid']      = self::reviewer_is_valid( $context );
		foreach ( array( 'fact_check', 'medical', 'testing', 'commercial', 'editorial' ) as $approval_type ) {
			$context[ $approval_type . '_approval_current' ] = $prospective
				? Approval_Service::is_current_for_state( $post_id, $approval_type, $prospective )
				: Approval_Service::is_current( $post_id, $approval_type );
		}
		$scoring_status                 = Runtime_Config::scoring_model_status();
		$context['scoring_model_valid'] = ! empty( $scoring_status['valid'] );

		$result = self::evaluate_values( $context );
		if ( ! empty( $overrides['__governance_request_denied'] ) ) {
			$result->block( 'governed_metadata_unauthorized', __( 'The request included governed metadata that this channel cannot write.', 'longevity-core' ) );
		}
		return $result;
	}

	/** Pure readiness evaluation for testability. */
	public static function evaluate_values( array $context ): Gate_Result {
		$result = new Gate_Result();
		$today  = Date_Validator::is_valid( (string) ( $context['as_of_date'] ?? '' ) ) ? (string) $context['as_of_date'] : Date_Validator::today();
		self::required_text_check( $result, $context, 'content_summary', 'missing_summary', __( 'Add a concise content summary or direct answer.', 'longevity-core' ) );
		self::required_text_check( $result, $context, 'content_limitations', 'missing_limitations', __( 'Add a meaningful limitations and uncertainty section.', 'longevity-core' ) );
		self::required_text_check( $result, $context, 'next_content_review_date', 'missing_next_review', __( 'Set the next content review date.', 'longevity-core' ) );
		if ( ! empty( $context['next_content_review_date'] ) && ! self::is_date_relative( (string) $context['next_content_review_date'], $today, true ) ) {
			$result->block( 'next_review_due', __( 'Set the next content review date to a valid future date.', 'longevity-core' ) );
		}

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
		} elseif ( empty( $context['editorial_approval_current'] ) ) {
			$result->block( 'editorial_approval_stale', __( 'Editorial approval is missing or no longer matches the current content and dependent approvals.', 'longevity-core' ) );
		} else {
			$result->pass( 'editorial_approval_complete', __( 'Editorial approval is current.', 'longevity-core' ) );
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
			} elseif ( empty( $context['fact_check_approval_current'] ) ) {
				$result->block( 'fact_check_stale', __( 'Fact-check approval is missing or stale for the current claim and source state.', 'longevity-core' ) );
			} else {
				$result->pass( 'fact_check_complete', __( 'Fact-checking is complete and current.', 'longevity-core' ) );
			}
			if ( empty( $context['fact_checked_by'] ) || empty( $context['fact_checked_date'] ) ) {
				$result->block( 'fact_check_identity_missing', __( 'Record the fact checker and completion date.', 'longevity-core' ) );
			} elseif ( ! self::is_date_relative( (string) $context['fact_checked_date'], $today, false ) ) {
				$result->block( 'fact_check_date_invalid', __( 'The fact-check completion date must be a valid date no later than today.', 'longevity-core' ) );
			}
			if ( ! self::is_date_relative( (string) ( $context['next_fact_check_date'] ?? '' ), $today, true ) ) {
				$result->block( 'next_fact_check_due', __( 'Set the next fact-check date to a valid future date.', 'longevity-core' ) );
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
			if ( ! empty( $context['medical_review_date'] ) && ! self::is_date_relative( (string) $context['medical_review_date'], $today, false ) ) {
				$result->block( 'medical_review_date_invalid', __( 'The medical-review date must be a valid date no later than today.', 'longevity-core' ) );
			}
			if ( ! self::is_date_relative( (string) ( $context['next_medical_review_date'] ?? '' ), $today, true ) ) {
				$result->block( 'next_medical_review_due', __( 'Set the next medical-review date to a valid future date.', 'longevity-core' ) );
			}
			if ( 'claim_ids' === ( $context['medical_review_scope'] ?? '' ) && empty( $context['medical_review_claim_ids'] ) ) {
				$result->block( 'medical_claim_ids_missing', __( 'List the exact claim IDs covered by a claim-scoped medical review.', 'longevity-core' ) );
			}
			if ( empty( $context['medical_reviewer_valid'] ) ) {
				$result->block( 'medical_reviewer_invalid', __( 'Assign an authenticated reviewer with recorded, verified credentials.', 'longevity-core' ) );
			}
			if ( empty( $context['medical_review_attested'] ) ) {
				$result->block( 'medical_attestation_missing', __( 'The authenticated reviewer must complete the review attestation.', 'longevity-core' ) );
			} elseif ( empty( $context['medical_approval_current'] ) ) {
				$result->block( 'medical_review_stale', __( 'Medical approval is missing or stale for the current reviewed state.', 'longevity-core' ) );
			} else {
				$result->pass( 'medical_review_complete', __( 'Scoped medical review and attestation are complete and current.', 'longevity-core' ) );
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
			} elseif ( empty( $context['testing_approval_current'] ) ) {
				$result->block( 'testing_stale', __( 'Testing approval is missing or stale for the current test record and scoring inputs.', 'longevity-core' ) );
			}
			foreach ( array( 'testing_start_date', 'testing_end_date', 'testing_protocol_version', 'testing_methodology_url', 'product_acquisition_method' ) as $field ) {
				if ( empty( $context[ $field ] ) ) {
					$result->block( 'testing_' . $field, sprintf( /* translators: %s: metadata field */ __( 'Complete required testing field: %s.', 'longevity-core' ), $field ) );
				}
			}
			$testing_start = (string) ( $context['testing_start_date'] ?? '' );
			$testing_end   = (string) ( $context['testing_end_date'] ?? '' );
			if ( ! Date_Validator::is_valid( $testing_start ) || ! self::is_date_relative( $testing_end, $today, false ) || Date_Validator::compare( $testing_end, $testing_start ) < 0 ) {
				$result->block( 'testing_dates_invalid', __( 'Testing dates must be valid, completed, and ordered from start to end.', 'longevity-core' ) );
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
			} elseif ( empty( $context['commercial_approval_current'] ) ) {
				$result->block( 'affiliate_disclosure_stale', __( 'Commercial approval is missing or stale for the current destinations and disclosure state.', 'longevity-core' ) );
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
			if ( $score > 0 && ! empty( $context['review_score_version'] ) && ! Review_Methodology::score_version_matches( (string) $context['review_score_version'] ) ) {
				$result->block( 'score_version_mismatch', __( 'The recorded scoring-model version does not match the installed model.', 'longevity-core' ) );
			}
			if ( $score > 0 && empty( $context['review_score_confidence'] ) ) {
				$result->block( 'score_confidence_missing', __( 'Record confidence separately from the review score.', 'longevity-core' ) );
			}
			if ( $score > 0 && empty( $context['scoring_model_valid'] ) ) {
				$result->block( 'scoring_model_unavailable', __( 'The configured scoring model is unavailable or invalid; public scores fail closed.', 'longevity-core' ) );
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
			foreach ( array( 'price_checked_date', 'warranty_checked_date', 'return_policy_checked_date', 'privacy_policy_checked_date' ) as $checked_field ) {
				if ( ! empty( $context[ $checked_field ] ) && ! self::is_date_relative( (string) $context[ $checked_field ], $today, false ) ) {
					$result->block( $checked_field . '_invalid', __( 'Review fact-check dates must be valid dates no later than today.', 'longevity-core' ) );
				}
			}
		}

		if ( ! empty( $context['evidence_grade'] ) ) {
			if ( empty( $context['evidence_grade_rationale'] ) ) {
				$result->block( 'evidence_rationale_missing', __( 'Every evidence grade requires a human rationale.', 'longevity-core' ) );
			}
			if ( empty( $context['evidence_cutoff_date'] ) ) {
				$result->block( 'evidence_cutoff_missing', __( 'Record the evidence cutoff date.', 'longevity-core' ) );
			} elseif ( ! self::is_date_relative( (string) $context['evidence_cutoff_date'], $today, false ) ) {
				$result->block( 'evidence_cutoff_invalid', __( 'The evidence cutoff must be a valid date no later than today.', 'longevity-core' ) );
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

	/** Compute the prospective combined hash for a post with pending changes. */
	private static function prospective_hash( int $post_id, array $prospective ): string {
		return (string) Approval_Fingerprint::build( $post_id, 'editorial', $prospective )['combined_hash'];
	}

	/** Verify editorial approval covers the prospective state (DB + pending changes). */
	private static function prospective_state_matches_approval( int $post_id, array $prospective ): bool {
		return Approval_Service::is_current_for_state( $post_id, 'editorial', $prospective );
	}

	/** Convert gate overrides into the canonical state used by every approval. */
	private static function prospective_state( array $overrides ): array {
		$state = array();
		foreach ( array( 'post_title', 'post_excerpt', 'post_content', 'post_author', 'featured_image_id' ) as $key ) {
			if ( array_key_exists( $key, $overrides ) ) {
				$state[ $key ] = $overrides[ $key ];
			}
		}
		if ( ! array_key_exists( 'post_content', $state ) && array_key_exists( 'content', $overrides ) ) {
			$state['post_content'] = $overrides['content'];
		}
		$meta = array();
		foreach ( Meta_Registry::definitions() as $key => $definition ) {
			if ( array_key_exists( $key, $overrides ) ) {
				$meta[ $key ] = $overrides[ $key ];
			}
		}
		if ( ! empty( $meta ) ) {
			$state['meta'] = $meta;
		}
		return $state;
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
		if ( ! Publication_Lock::acquire( $post_id ) ) {
			$data['post_status'] = 'draft';
			self::set_notice( array( __( 'Publication is temporarily blocked while governance state is being checked.', 'longevity-core' ) ) );
			return $data;
		}
		$overrides                 = array_merge( self::postarr_meta_overrides( $postarr ), self::classic_request_overrides( $post_id ) );
		$overrides['content']      = wp_unslash( (string) ( $data['post_content'] ?? '' ) );
		$overrides['post_title']   = wp_unslash( (string) ( $data['post_title'] ?? '' ) );
		$overrides['post_excerpt'] = wp_unslash( (string) ( $data['post_excerpt'] ?? '' ) );
		$overrides['post_author']  = wp_unslash( (string) ( $data['post_author'] ?? '' ) );
		$featured_image_id         = self::classic_featured_image( $postarr );
		if ( null !== $featured_image_id ) {
			$overrides['featured_image_id'] = $featured_image_id;
		}
		$prospective = self::prospective_state( $overrides );
		$result      = self::evaluate( $post_id, $overrides );
		$matches     = self::prospective_state_matches_approval( $post_id, $prospective );
		$blocked     = $result->is_blocked() || ! $matches;
		if ( ! $blocked ) {
			return $data;
		}
		if ( $matches && self::override_allowed_from_request() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in override_allowed_from_request() before this override branch runs.
			$reason          = isset( $_POST['longevity_override_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['longevity_override_reason'] ) ) : '';
			$correlation_id  = self::classic_correlation_id();
			$previous_status = get_post_status( $post_id );
			$previous_status = is_string( $previous_status ) && '' !== $previous_status ? $previous_status : 'draft';
			$state           = self::authorize_override( $post_id, $previous_status, (string) $data['post_status'], self::prospective_hash( $post_id, $prospective ), $reason, 'classic', true, $correlation_id );
			if ( Override_Intent::STATE_AUTHORIZED === $state ) {
				return $data;
			}
			$intent = Override_Intent::intent( $correlation_id );
			if ( $intent && in_array( (string) $intent['state'], array( Override_Intent::STATE_APPLIED, Override_Intent::STATE_FAILED, Override_Intent::STATE_COMPENSATED ), true ) ) {
				Publication_Lock::release( $post_id );
				$data['post_status'] = $previous_status;
				return $data;
			}
			// Fail-closed: the override could not be durably audited and recorded.
			Publication_Lock::release( $post_id );
			$data['post_status'] = 'draft';
			self::set_notice( array( __( 'Publication override refused: the override could not be durably recorded. Nothing was published.', 'longevity-core' ) ) );
			Audit_Log::record(
				'publication_blocked',
				'post',
				$post_id,
				array(
					'channel'        => 'classic',
					'blocking_codes' => 'override_authorization_failed',
				),
				get_current_user_id(),
				'classic'
			);
			return $data;
		}
		Publication_Lock::release( $post_id );
		$data['post_status'] = 'draft';
		$codes               = $result->is_blocked() ? array_column( $result->blocking(), 'code' ) : array( 'prospective_fingerprint_mismatch' );
		self::set_notice( $result->is_blocked() ? array_column( $result->blocking(), 'message' ) : array( __( 'Publication blocked: the content or metadata has changed since the last editorial approval.', 'longevity-core' ) ) );
		Audit_Log::record(
			'publication_blocked',
			'post',
			$post_id,
			array(
				'channel'        => 'classic',
				'blocking_codes' => implode( ',', $codes ),
			),
			get_current_user_id(),
			'classic'
		);
		return $data;
	}

	/** Enforce REST/block-editor publishing with a structured error. */
	public static function enforce_rest_publish( $prepared_post, \WP_REST_Request $request ) {
		$status_param = $request->get_param( 'status' );
		$status       = (string) ( null !== $status_param ? $status_param : ( $prepared_post->post_status ?? '' ) );
		if ( ! in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			return $prepared_post;
		}
		$post_id = (int) $request->get_param( 'id' );
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'lel_gate_new_post', __( 'Save the draft before attempting first publication.', 'longevity-core' ), array( 'status' => 400 ) );
		}
		if ( ! Publication_Lock::acquire( $post_id ) ) {
			return new \WP_Error( 'lel_publication_lock_unavailable', __( 'Publication is temporarily blocked while governance state is being checked.', 'longevity-core' ), array( 'status' => 409 ) );
		}
		$overrides = array(
			'content'      => (string) ( $prepared_post->post_content ?? '' ),
			'post_title'   => (string) ( $prepared_post->post_title ?? '' ),
			'post_excerpt' => (string) ( $prepared_post->post_excerpt ?? '' ),
			'post_author'  => (int) ( $prepared_post->post_author ?? 0 ),
		);
		if ( null !== $request->get_param( 'featured_media' ) ) {
			$overrides['featured_image_id'] = absint( $request->get_param( 'featured_media' ) );
		}
		$meta = $request->get_param( 'meta' );
		if ( is_array( $meta ) ) {
			$definitions = Meta_Registry::definitions();
			foreach ( $meta as $key => $value ) {
				if ( isset( $definitions[ $key ] ) && Meta_Authorization::can_write( $key, $post_id, get_current_user_id(), 'rest' ) ) {
					$overrides[ $key ] = Meta_Registry::sanitize_by_key( $key, $value );
				} elseif ( isset( $definitions[ $key ] ) ) {
					$overrides['__governance_request_denied'] = true;
				}
			}
		}
		$prospective = self::prospective_state( $overrides );
		$result      = self::evaluate( $post_id, $overrides );
		$matches     = self::prospective_state_matches_approval( $post_id, $prospective );
		$blocked     = $result->is_blocked() || ! $matches;
		if ( ! $blocked ) {
			return $prepared_post;
		}
		$reason = sanitize_textarea_field( (string) $request->get_param( 'longevity_override_reason' ) );
		if ( $matches && current_user_can( 'approve_publication_override' ) && '' !== $reason ) {
			$correlation_param = $request->get_param( 'longevity_override_correlation_id' );
			$correlation_id    = sanitize_text_field( (string) ( is_string( $correlation_param ) && '' !== $correlation_param ? $correlation_param : Logger::request_id() ) );
			$previous_status   = get_post_status( $post_id );
			$previous_status   = is_string( $previous_status ) && '' !== $previous_status ? $previous_status : 'draft';
			$state             = self::authorize_override( $post_id, $previous_status, $status, self::prospective_hash( $post_id, $prospective ), $reason, 'rest', true, $correlation_id );
			if ( Override_Intent::STATE_AUTHORIZED === $state ) {
				return $prepared_post;
			}
			$intent = Override_Intent::intent( $correlation_id );
			if ( $intent && in_array( (string) $intent['state'], array( Override_Intent::STATE_APPLIED, Override_Intent::STATE_FAILED, Override_Intent::STATE_COMPENSATED ), true ) ) {
				Publication_Lock::release( $post_id );
				return new \WP_Error(
					'lel_override_already_finalized',
					__( 'This publication override has already been finalized.', 'longevity-core' ),
					array(
						'status'         => 409,
						'override_state' => (string) $intent['state'],
					)
				);
			}
			// Fail-closed: the override could not be durably audited and recorded.
			Publication_Lock::release( $post_id );
			Audit_Log::record(
				'publication_blocked',
				'post',
				$post_id,
				array(
					'channel'        => 'rest',
					'blocking_codes' => 'override_authorization_failed',
				),
				get_current_user_id(),
				'rest'
			);
			return new \WP_Error(
				'lel_override_refused',
				__( 'Publication override refused: the override could not be durably recorded. Nothing was published.', 'longevity-core' ),
				array( 'status' => 503 )
			);
		}
		Publication_Lock::release( $post_id );
		$codes = $result->is_blocked() ? array_column( $result->blocking(), 'code' ) : array( 'prospective_fingerprint_mismatch' );
		Audit_Log::record(
			'publication_blocked',
			'post',
			$post_id,
			array(
				'channel'        => 'rest',
				'blocking_codes' => implode( ',', $codes ),
			),
			get_current_user_id(),
			'rest'
		);
		return new \WP_Error(
			'lel_publication_blocked',
			__( 'Publication readiness checks failed. Contact an editor for details.', 'longevity-core' ),
			array( 'status' => 400 )
		);
	}

	/** Release the publication lock after WordPress has saved the post. */
	public static function release_after_post_update( int $post_id ): void {
		Publication_Lock::release( $post_id );
	}

	/** Finalize an authorized intent only after WordPress reports the real status transition. */
	public static function finalize_override( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( self::$compensating || ! isset( self::$pending_overrides[ $post->ID ] ) ) {
			return;
		}
		$correlation_id = self::$pending_overrides[ $post->ID ];
		$intent         = Override_Intent::intent( $correlation_id );
		$state          = Override_Intent::finalize( $correlation_id, $post->ID, $new_status, $old_status );
		if ( Override_Intent::STATE_FAILED === $state && $intent ) {
			$previous_status = (string) $intent['previous_status'];
			if ( $new_status === $previous_status ) {
				Override_Intent::compensate( $correlation_id, $post->ID, $new_status );
			} elseif ( function_exists( 'wp_update_post' ) ) {
				self::$compensating      = true;
				$compensation_lock_level = false;
				try {
					// Keep one re-entrant level for the outer save while the nested
					// compensation save releases its own level.
					$compensation_lock_level = Publication_Lock::acquire( $post->ID );
					$restored                = wp_update_post(
						array(
							'ID'          => $post->ID,
							'post_status' => $previous_status,
						),
						true
					);
					if ( ! is_wp_error( $restored ) ) {
						$compensation_lock_level = false;
						Override_Intent::compensate( $correlation_id, $post->ID, $previous_status );
					}
				} finally {
					if ( $compensation_lock_level ) {
						Publication_Lock::release( $post->ID );
					}
					self::$compensating = false;
				}
			}
		}
		unset( self::$pending_overrides[ $post->ID ] );
	}



	/** Display a one-use gate notice. */
	public static function admin_notice(): void {
		$user_id  = get_current_user_id();
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
		self::log_event(
			$post->ID,
			'status_changed',
			array(
				'from' => $old_status,
				'to'   => $new_status,
			)
		);
	}

	/** Persist a non-sensitive append-only governance event. */
	public static function log_event( int $post_id, string $event, array $details = array() ): void {
		Audit_Log::record( $event, 'post', $post_id, $details, get_current_user_id(), 'workflow' );
	}

	/** Add a required text check. */
	private static function required_text_check( Gate_Result $result, array $context, string $field, string $code, string $message ): void {
		if ( '' === trim( (string) ( $context[ $field ] ?? '' ) ) ) {
			$result->block( $code, $message );
		} else {
			$result->pass( $field, sprintf( /* translators: %s: metadata field */ __( '%s is complete.', 'longevity-core' ), $field ) );
		}
	}

	/** Whether a value is a valid date relative to the reference (future or non-future). */
	private static function is_date_relative( string $value, string $today, bool $future ): bool {
		if ( ! Date_Validator::is_valid( $value ) ) {
			return false;
		}
		$cmp = Date_Validator::compare( $value, $today );
		return $future ? $cmp > 0 : $cmp <= 0;
	}

	/** Include metadata that WordPress will write after the post data filter. */
	private static function postarr_meta_overrides( array $postarr ): array {
		$input       = isset( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) ? $postarr['meta_input'] : array();
		$overrides   = array();
		$definitions = Meta_Registry::definitions();
		foreach ( $input as $key => $value ) {
			if ( ! isset( $definitions[ $key ] ) ) {
				continue;
			}
			$overrides[ $key ] = Meta_Registry::sanitize_by_key( $key, $value );
			if ( self::service_only_meta( $key, $overrides[ $key ] ) ) {
				$overrides['__governance_request_denied'] = true;
			}
		}
		return $overrides;
	}

	/** Read a classic request's prospective featured-image relationship. */
	private static function classic_featured_image( array $postarr ): ?int {
		if ( isset( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) && array_key_exists( '_thumbnail_id', $postarr['meta_input'] ) ) {
			return absint( $postarr['meta_input']['_thumbnail_id'] );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Runs inside wp_insert_post_data during a classic-editor save; WordPress core verifies the edit-form nonce upstream.
		return array_key_exists( '_thumbnail_id', $_POST ) ? absint( wp_unslash( $_POST['_thumbnail_id'] ) ) : null;
	}

	/** Read authorized metadata submitted by the classic editor for same-request evaluation. */
	private static function classic_request_overrides( int $post_id ): array {
		if ( empty( $_POST['longevity_editorial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['longevity_editorial_nonce'] ) ), 'longevity_save_editorial' ) ) {
			return array();
		}
		$overrides   = array();
		$definitions = Meta_Registry::definitions();
		$present     = isset( $_POST['lel_present'] ) && is_array( $_POST['lel_present'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['lel_present'] ) ) : array();
		foreach ( $definitions as $key => $definition ) {
			if ( empty( $present[ $key ] ) ) {
				continue;
			}
			if ( ! Meta_Authorization::can_write( $key, $post_id, get_current_user_id(), 'classic' ) ) {
				$overrides['__governance_request_denied'] = true;
				continue;
			}
			if ( self::service_only_meta( $key, isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '' ) ) {
				continue;
			}
			if ( isset( $_POST[ $key ] ) ) {
				$overrides[ $key ] = Meta_Registry::sanitize_by_key( $key, wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Meta_Registry::sanitize_by_key() per the registered meta definition.
			}
		}
		if ( in_array( 'review_score_dimensions', array_keys( $present ), true ) && isset( $_POST['review_score_dimensions_rows'] ) && is_array( $_POST['review_score_dimensions_rows'] ) ) {
			if ( Meta_Authorization::can_write( 'review_score_dimensions', $post_id, get_current_user_id(), 'classic' ) && Meta_Authorization::can_write( 'review_score', $post_id, get_current_user_id(), 'classic' ) ) {
				$dimensions                           = Review_Methodology::sanitize_dimensions( wp_unslash( $_POST['review_score_dimensions_rows'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Review_Methodology::sanitize_dimensions().
				$overrides['review_score_dimensions'] = $dimensions;
				try {
					$overrides['review_score'] = Review_Methodology::calculate_score( $dimensions )['score'];
				} catch ( \InvalidArgumentException $exception ) {
					unset( $exception );
				}
			}
		}
		return $overrides;
	}

	/** Values that are projected only by an approval service, never by a request. */
	public static function service_only_meta( string $key, $value ): bool {
		$value = is_scalar( $value ) ? (string) $value : '';
		$final = array(
			'fact_check_status'           => array( 'complete' ),
			'medical_review_status'       => array( 'complete' ),
			'testing_status'              => array( 'approved' ),
			'affiliate_disclosure_status' => array( 'approved', 'complete' ),
			'editorial_approval_status'   => array( 'ready', 'published' ),
		);
		return ( 'medical_review_attested' === $key && '1' === $value ) || ( isset( $final[ $key ] ) && in_array( $value, $final[ $key ], true ) );
	}

	/** Create or replay a durable authorization and remember it for post-transition finalization. */
	private static function authorize_override( int $post_id, string $previous_status, string $requested_status, string $fingerprint, string $reason, string $channel, bool $nonce_verified, string $correlation_id ): string {
		$user_id          = get_current_user_id();
		$current_approval = Approval_Repository::current( $post_id, 'editorial' );
		$approval_state   = wp_json_encode(
			array(
				'id'            => (int) ( $current_approval['id'] ?? 0 ),
				'status'        => (string) ( $current_approval['approval_status'] ?? '' ),
				'combined_hash' => (string) ( $current_approval['combined_hash'] ?? '' ),
			)
		);
		$state            = Override_Intent::authorize(
			$correlation_id,
			array(
				'post_id'             => $post_id,
				'previous_status'     => $previous_status,
				'requested_status'    => $requested_status,
				'user_id'             => $user_id,
				'capability_snapshot' => array( 'approve_publication_override' => current_user_can( 'approve_publication_override' ) ),
				'nonce_verified'      => $nonce_verified,
				'reason'              => $reason,
				'fingerprint'         => $fingerprint,
				'approval_state'      => $approval_state,
				'channel'             => $channel,
				'source_sha'          => defined( 'LEL_RELEASE_SHA' ) ? (string) LEL_RELEASE_SHA : 'unavailable',
				'plugin_version'      => defined( 'LONGEVITY_CORE_VERSION' ) ? (string) LONGEVITY_CORE_VERSION : 'unknown',
			)
		);
		if ( Override_Intent::STATE_AUTHORIZED === $state && Override_Intent::authorizes_transition( $correlation_id, $post_id ) ) {
			self::$pending_overrides[ $post_id ] = $correlation_id;
		} elseif ( Override_Intent::STATE_AUTHORIZED === $state ) {
			return Override_Intent::STATE_FAILED;
		}
		return $state;
	}

	/** Stable idempotency key supplied by the classic caller, or this request's correlation ID. */
	private static function classic_correlation_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only reached from the enforce_classic_publish override branch after override_allowed_from_request() verifies the nonce.
		$value = isset( $_POST['longevity_override_correlation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['longevity_override_correlation_id'] ) ) : Logger::request_id();
		return sanitize_text_field( (string) $value );
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
		return Reviewer_Credentials::is_valid_for(
			$user_id,
			(string) ( $context['medical_review_scope'] ?? '' ),
			(string) ( $context['region_scope'] ?? '' )
		);
	}

	/** Check featured image alternative text only when an image exists. */
	private static function featured_image_alt_present( int $post_id, ?int $prospective_thumbnail_id = null ): bool {
		$thumbnail_id = null === $prospective_thumbnail_id ? get_post_thumbnail_id( $post_id ) : $prospective_thumbnail_id;
		if ( ! $thumbnail_id ) {
			return true;
		}
		return '' !== trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) );
	}

	/** Count source records linked through claims. */
	private static function source_count_for_post( int $post_id ): int {
		// Complete retrieval: a capped page could hide missing sources on claim 201+.
		$claims     = Governed_Query::ids_by_meta( array( 'lel_claim' ), 'post_id', (string) $post_id );
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

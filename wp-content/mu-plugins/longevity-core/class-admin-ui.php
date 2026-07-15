<?php
/**
 * Editorial administration interface.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Adds readiness and governance controls to the editor. */
final class Admin_UI {
	/** Register hooks. */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_boxes' ) );
		add_action( 'save_post_post', array( self::class, 'save_editorial_meta' ), 20, 3 );
		add_action( 'save_post_review', array( self::class, 'save_editorial_meta' ), 20, 3 );
		add_action( 'save_post_lel_test_record', array( self::class, 'save_public_test_results' ), 20, 3 );
		add_action( 'show_user_profile', array( self::class, 'render_reviewer_profile' ) );
		add_action( 'edit_user_profile', array( self::class, 'render_reviewer_profile' ) );
		add_action( 'personal_options_update', array( self::class, 'save_reviewer_profile' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_reviewer_profile' ) );
		add_filter( 'manage_post_posts_columns', array( self::class, 'add_readiness_column' ) );
		add_filter( 'manage_review_posts_columns', array( self::class, 'add_readiness_column' ) );
		add_action( 'manage_post_posts_custom_column', array( self::class, 'render_readiness_column' ), 10, 2 );
		add_action( 'manage_review_posts_custom_column', array( self::class, 'render_readiness_column' ), 10, 2 );
	}

	/** Add editor meta boxes. */
	public static function add_meta_boxes(): void {
		foreach ( array( 'post', 'review' ) as $post_type ) {
			add_meta_box( 'lel-readiness', __( 'Publication readiness', 'longevity-core' ), array( self::class, 'render_readiness' ), $post_type, 'side', 'high' );
			add_meta_box( 'lel-governance', __( 'Evidence, review, testing, and disclosure', 'longevity-core' ), array( self::class, 'render_governance' ), $post_type, 'normal', 'high' );
		}
		add_meta_box( 'lel-public-test-results', __( 'Approved public test results', 'longevity-core' ), array( self::class, 'render_public_test_results_editor' ), 'lel_test_record', 'normal', 'high' );
	}

	/** Render readiness summary. */
	public static function render_readiness( \WP_Post $post ): void {
		$result = Publication_Gates::evaluate( $post->ID );
		printf( '<div class="lel-readiness-summary" role="status"><p><strong>%s%%</strong> %s</p><p>%s &middot; %s &middot; %s</p></div>', esc_html( (string) $result->completion_percentage() ), esc_html__( 'complete across applicable checks', 'longevity-core' ), esc_html( sprintf( _n( '%d blocker', '%d blockers', count( $result->blocking() ), 'longevity-core' ), count( $result->blocking() ) ) ), esc_html( sprintf( _n( '%d warning', '%d warnings', count( $result->warnings() ), 'longevity-core' ), count( $result->warnings() ) ) ), esc_html( sprintf( _n( '%d passed check', '%d passed checks', count( $result->passed() ), 'longevity-core' ), count( $result->passed() ) ) ) );
		self::render_result_group( __( 'Blocking', 'longevity-core' ), $result->blocking(), 'lel-gate-blocking' );
		self::render_result_group( __( 'Warnings', 'longevity-core' ), $result->warnings(), 'lel-gate-warning' );
		self::render_result_group( __( 'Passed', 'longevity-core' ), $result->passed(), 'lel-gate-passed' );
		if ( ! $result->is_blocked() ) {
			echo '<p class="lel-gate-passed"><strong>' . esc_html__( 'No blocking failures.', 'longevity-core' ) . '</strong></p>';
		}
		if ( current_user_can( 'approve_publication_override' ) ) {
			echo '<p><label for="longevity_override_reason"><strong>' . esc_html__( 'Emergency override reason', 'longevity-core' ) . '</strong></label></p>';
			echo '<textarea id="longevity_override_reason" name="longevity_override_reason" rows="3" class="widefat" aria-describedby="lel-override-help"></textarea>';
			echo '<p id="lel-override-help" class="description">' . esc_html__( 'Required only for an emergency override. The reason and user are logged.', 'longevity-core' ) . '</p>';
		}
	}

	/** Render governance fields. */
	public static function render_governance( \WP_Post $post ): void {
		wp_nonce_field( 'longevity_save_editorial', 'longevity_editorial_nonce' );
		echo '<div class="lel-editorial-grid">';
		echo '<details class="lel-governance-section" open><summary><strong>' . esc_html__( 'Publication overview', 'longevity-core' ) . '</strong><span>' . esc_html__( 'Scope, limitations, ownership, and lifecycle', 'longevity-core' ) . '</span></summary><div class="lel-governance-fields">';
		self::textarea( $post->ID, 'content_summary', __( 'Direct answer / summary', 'longevity-core' ) );
		self::textarea( $post->ID, 'content_scope', __( 'Scope', 'longevity-core' ) );
		self::textarea( $post->ID, 'content_limitations', __( 'Limitations and uncertainty', 'longevity-core' ) );
		self::textarea( $post->ID, 'original_contribution', __( 'Original contribution', 'longevity-core' ) );
		self::text( $post->ID, 'region_scope', __( 'Region or jurisdiction scope', 'longevity-core' ) );
		self::date( $post->ID, 'next_content_review_date', __( 'Next content review date', 'longevity-core' ) );
		self::select( $post->ID, 'editorial_approval_status', __( 'Editorial workflow state', 'longevity-core' ), array( 'idea' => 'Idea', 'assigned' => 'Assigned', 'researching' => 'Researching', 'drafting' => 'Drafting', 'editorial_review' => 'Editorial review', 'fact_check' => 'Fact-check', 'medical_review' => 'Medical review', 'testing_incomplete' => 'Testing incomplete', 'commercial_review' => 'Commercial review', 'ready' => 'Ready for publication', 'published' => 'Published', 'update_due' => 'Update due', 'correction_pending' => 'Correction pending', 'archived' => 'Archived' ) );
		self::checkbox( $post->ID, 'uncertainty_statement_present', __( 'Explicit uncertainty statement is present', 'longevity-core' ) );

		echo '</div></details><details class="lel-governance-section" data-lel-section="evidence"><summary><strong>' . esc_html__( 'Evidence and claims', 'longevity-core' ) . '</strong><span>' . esc_html__( 'Evidence grade, linked claims, and fact-checking', 'longevity-core' ) . '</span></summary><div class="lel-governance-fields">';
		self::checkbox( $post->ID, 'material_health_claims', __( 'Contains material health claims', 'longevity-core' ) );
		self::select( $post->ID, 'evidence_grade', __( 'Evidence grade', 'longevity-core' ), array( '' => 'Not assigned', 'A' => 'A — Strong', 'B' => 'B — Moderate', 'C' => 'C — Limited', 'D' => 'D — Mechanistic/anecdotal', 'U' => 'U — Unclear' ) );
		self::textarea( $post->ID, 'evidence_grade_rationale', __( 'Evidence-grade rationale', 'longevity-core' ) );
		self::date( $post->ID, 'evidence_cutoff_date', __( 'Evidence cutoff date', 'longevity-core' ) );
		self::select( $post->ID, 'fact_check_status', __( 'Fact-check status', 'longevity-core' ), array( 'not_started' => 'Not started', 'in_progress' => 'In progress', 'revisions_required' => 'Revisions required', 'complete' => 'Complete', 'not_required' => 'Not required' ) );
		self::date( $post->ID, 'next_fact_check_date', __( 'Next fact-check date', 'longevity-core' ) );
		if ( current_user_can( 'manage_claims' ) ) {
			echo '<p class="description"><a href="' . esc_url( admin_url( 'edit.php?post_type=lel_claim' ) ) . '">' . esc_html__( 'Manage linked claims', 'longevity-core' ) . '</a> &middot; <a href="' . esc_url( admin_url( 'edit.php?post_type=lel_source' ) ) . '">' . esc_html__( 'Manage source records', 'longevity-core' ) . '</a></p>';
		}

		echo '</div></details><details class="lel-governance-section" data-lel-conditional="medical"><summary><strong>' . esc_html__( 'Medical review', 'longevity-core' ) . '</strong><span>' . esc_html__( 'Assignment, exact scope, dates, revisions, and attestation', 'longevity-core' ) . '</span></summary><div class="lel-governance-fields">';
		self::checkbox( $post->ID, 'medical_review_required', __( 'Medical review is required', 'longevity-core' ) );
		self::reviewer_select( $post->ID );
		self::select( $post->ID, 'medical_review_status', __( 'Medical review status', 'longevity-core' ), array( 'not_required' => 'Not required', 'not_started' => 'Not started', 'assigned' => 'Assigned', 'in_review' => 'In review', 'revisions_required' => 'Revisions required', 'complete' => 'Complete' ) );
		self::select( $post->ID, 'medical_review_scope', __( 'Review scope', 'longevity-core' ), array( '' => 'Select scope', 'full_article' => 'Full article', 'safety_only' => 'Safety sections only', 'contraindications_only' => 'Contraindications only', 'dosage_language_only' => 'Dosage language only', 'product_accuracy_only' => 'Product accuracy language only', 'medical_disclaimer_only' => 'Medical disclaimer only', 'claim_ids' => 'Claims listed by ID' ) );
		self::text( $post->ID, 'medical_review_claim_ids', __( 'Claim IDs reviewed', 'longevity-core' ) );
		self::textarea( $post->ID, 'medical_review_sections', __( 'Sections reviewed', 'longevity-core' ) );
		self::textarea( $post->ID, 'medical_review_limitations', __( 'Review limitations', 'longevity-core' ) );
		self::textarea( $post->ID, 'medical_review_required_revisions', __( 'Required revisions', 'longevity-core' ) );
		self::textarea( $post->ID, 'medical_review_conflicts', __( 'Reviewer conflicts', 'longevity-core' ) );
		self::select( $post->ID, 'medical_review_revision_status', __( 'Required revision status', 'longevity-core' ), array( 'not_applicable' => 'Not applicable', 'required' => 'Required', 'in_progress' => 'In progress', 'complete' => 'Complete' ) );
		self::date( $post->ID, 'next_medical_review_date', __( 'Next medical review date', 'longevity-core' ) );
		self::text( $post->ID, 'medical_review_version', __( 'Reviewed content version', 'longevity-core' ) );
		self::attestation( $post->ID );

		echo '</div></details><details class="lel-governance-section" data-lel-conditional="testing"><summary><strong>' . esc_html__( 'Testing', 'longevity-core' ) . '</strong><span>' . esc_html__( 'Protocol, approved record, dates, acquisition, and limitations', 'longevity-core' ) . '</span></summary><div class="lel-governance-fields">';
		self::checkbox( $post->ID, 'testing_required', __( 'Hands-on testing is required', 'longevity-core' ) );
		self::select( $post->ID, 'testing_status', __( 'Testing status', 'longevity-core' ), array( 'not_required' => 'Not required', 'planned' => 'Planned', 'in_progress' => 'In progress', 'incomplete' => 'Incomplete', 'complete' => 'Complete', 'approved' => 'Approved' ) );
		self::date( $post->ID, 'testing_start_date', __( 'Testing start date', 'longevity-core' ) );
		self::date( $post->ID, 'testing_end_date', __( 'Testing end date', 'longevity-core' ) );
		self::text( $post->ID, 'testing_duration', __( 'Testing duration', 'longevity-core' ) );
		self::text( $post->ID, 'testing_protocol_version', __( 'Protocol version', 'longevity-core' ) );
		self::url( $post->ID, 'testing_methodology_url', __( 'Public methodology URL', 'longevity-core' ) );
		self::test_record_select( $post->ID );
		self::select( $post->ID, 'product_acquisition_method', __( 'Product acquisition', 'longevity-core' ), array( '' => 'Select', 'purchased' => 'Purchased', 'product_supplied' => 'Product supplied', 'loaned' => 'Loaned', 'service_access' => 'Service access', 'independently_verified_only' => 'Independently verified specifications only' ) );
		echo '</div></details><details class="lel-governance-section" data-lel-conditional="commercial"><summary><strong>' . esc_html__( 'Commercial disclosure', 'longevity-core' ) . '</strong><span>' . esc_html__( 'Relationship, disclosure approval, and destination registry', 'longevity-core' ) . '</span></summary><div class="lel-governance-fields">';
		self::select( $post->ID, 'commercial_relationship', __( 'Commercial relationship', 'longevity-core' ), array( 'none' => 'None', 'affiliate' => 'Affiliate', 'product_supplied' => 'Product supplied', 'sponsored' => 'Sponsored' ) );
		self::select( $post->ID, 'affiliate_disclosure_status', __( 'Affiliate disclosure status', 'longevity-core' ), array( 'not_required' => 'Not required', 'required' => 'Required', 'draft' => 'Draft', 'approved' => 'Approved', 'complete' => 'Complete' ) );
		self::checkbox( $post->ID, 'affiliate_registry_verified', __( 'Affiliate destinations verified in registry', 'longevity-core' ) );

		if ( 'review' === $post->post_type ) {
			echo '</div></details><details class="lel-governance-section" data-lel-section="review-score"><summary><strong>' . esc_html__( 'Review scoring and decision', 'longevity-core' ) . '</strong><span>' . esc_html__( 'Product identity, fit, failures, dimensions, and confidence', 'longevity-core' ) . '</span></summary><div class="lel-governance-fields">';
			self::text( $post->ID, 'tested_product_model', __( 'Tested product model', 'longevity-core' ) );
			self::text( $post->ID, 'product_brand', __( 'Product brand', 'longevity-core' ) );
			self::text( $post->ID, 'product_variant', __( 'Tested variant', 'longevity-core' ) );
			self::text( $post->ID, 'tested_firmware_version', __( 'Firmware version', 'longevity-core' ) );
			self::text( $post->ID, 'tested_app_version', __( 'App version', 'longevity-core' ) );
			self::textarea( $post->ID, 'comparison_set', __( 'Comparison set', 'longevity-core' ) );
			self::textarea( $post->ID, 'major_failures', __( 'Major failures', 'longevity-core' ) );
			self::score_dimensions_editor( $post->ID );
			self::number( $post->ID, 'review_score', __( 'Calculated review score (0–5)', 'longevity-core' ), '0.1', 0, 5 );
			self::text( $post->ID, 'review_score_version', __( 'Scoring model version', 'longevity-core' ) );
			self::textarea( $post->ID, 'review_score_override_reason', __( 'Manual score override reason (only when calculated score differs)', 'longevity-core' ) );
			self::select( $post->ID, 'review_score_confidence', __( 'Score confidence', 'longevity-core' ), array( '' => 'Select', 'High confidence' => 'High confidence', 'Moderate confidence' => 'Moderate confidence', 'Low confidence' => 'Low confidence', 'Preliminary' => 'Preliminary' ) );
			self::text( $post->ID, 'best_for', __( 'Best for', 'longevity-core' ) );
			self::text( $post->ID, 'not_for', __( 'Not for', 'longevity-core' ) );
			self::date( $post->ID, 'price_checked_date', __( 'Price checked date', 'longevity-core' ) );
			self::number( $post->ID, 'product_price_amount', __( 'Observed price amount', 'longevity-core' ), '0.01', 0, 1000000 );
			self::text( $post->ID, 'product_price_currency', __( 'Price currency (ISO 4217)', 'longevity-core' ) );
			self::text( $post->ID, 'price_region', __( 'Price region', 'longevity-core' ) );
			self::date( $post->ID, 'warranty_checked_date', __( 'Warranty checked date', 'longevity-core' ) );
		}
		echo '</div></details><details class="lel-governance-section"><summary><strong>' . esc_html__( 'Lifecycle and corrections', 'longevity-core' ) . '</strong><span>' . esc_html__( 'Updates and protected correction records', 'longevity-core' ) . '</span></summary><div class="lel-governance-fields"><p>' . esc_html__( 'Material corrections are managed as append-oriented protected records and rendered publicly only after completion.', 'longevity-core' ) . '</p>';
		if ( current_user_can( 'manage_corrections' ) ) {
			echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=lel_correction' ) ) . '">' . esc_html__( 'Manage correction records', 'longevity-core' ) . '</a></p>';
		}
		echo '</div></details></div>';
	}

	/** Save editor metadata without accepting unauthorized attestations. */
	public static function save_editorial_meta( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( empty( $_POST['longevity_editorial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['longevity_editorial_nonce'] ) ), 'longevity_save_editorial' ) ) {
			return;
		}
		if ( 'review' === $post->post_type && isset( $_POST['review_score_dimensions_rows'] ) && is_array( $_POST['review_score_dimensions_rows'] ) ) {
			$dimensions = Review_Methodology::sanitize_dimensions( wp_unslash( $_POST['review_score_dimensions_rows'] ) );
			update_post_meta( $post_id, 'review_score_dimensions', $dimensions );
			try {
				$calculated = Review_Methodology::calculate_score( $dimensions );
				update_post_meta( $post_id, 'review_score', $calculated['score'] );
			} catch ( \InvalidArgumentException $exception ) {
				// Publication readiness remains blocked until weights total 100.
			}
		}

		$allowed_fields = array_keys( Meta_Registry::definitions() );
		$boolean_fields = array_filter(
			$allowed_fields,
			static fn( string $key ) => 'boolean' === ( Meta_Registry::definitions()[ $key ]['type'] ?? '' )
		);

		foreach ( $allowed_fields as $key ) {
			if ( isset( $_POST['review_score_dimensions_rows'] ) && in_array( $key, array( 'review_score_dimensions', 'review_score' ), true ) ) {
				continue;
			}
			if ( ! in_array( $post->post_type, Meta_Registry::definitions()[ $key ]['post_types'], true ) ) {
				continue;
			}
			if ( in_array( $key, array( 'medical_review_attested', 'medical_review_date', 'fact_checked_by', 'fact_checked_date' ), true ) ) {
				continue;
			}
			if ( 'fact_check_status' === $key && isset( $_POST[ $key ] ) && 'complete' === sanitize_key( (string) wp_unslash( $_POST[ $key ] ) ) && ! current_user_can( 'complete_fact_check' ) ) {
				continue;
			}
			if ( 'medical_review_status' === $key && isset( $_POST[ $key ] ) && 'complete' === sanitize_key( (string) wp_unslash( $_POST[ $key ] ) ) && ! current_user_can( 'complete_medical_review' ) ) {
				continue;
			}
			if ( in_array( $key, array( 'affiliate_disclosure_status', 'affiliate_registry_verified' ), true ) && ! current_user_can( 'approve_commercial_disclosure' ) ) {
				continue;
			}
			if ( in_array( $key, $boolean_fields, true ) ) {
				$value = isset( $_POST[ $key ] );
			} elseif ( isset( $_POST[ $key ] ) ) {
				$value = wp_unslash( $_POST[ $key ] );
			} else {
				continue;
			}
			$sanitized = Meta_Registry::sanitize_by_key( $key, $value );
			$old       = get_post_meta( $post_id, $key, true );
			if ( $old !== $sanitized ) {
				update_post_meta( $post_id, $key, $sanitized );
				Publication_Gates::log_event( $post_id, 'metadata_changed', array( 'field' => $key ) );
			}
		}

		self::save_fact_check_completion( $post_id );
		self::save_medical_attestation( $post_id );
	}

	/** Render a structured editor so operational staff never need to hand-write JSON. */
	public static function render_public_test_results_editor( \WP_Post $post ): void {
		wp_nonce_field( 'longevity_save_public_results', 'longevity_public_results_nonce' );
		$rows = Review_Methodology::sanitize_public_results( get_post_meta( $post->ID, 'public_test_results', true ) );
		if ( empty( $rows ) ) {
			$rows[] = array( 'label' => '', 'observed_value' => '', 'unit' => '', 'reference_label' => '', 'reference_value' => '', 'status' => 'informational', 'note' => '', 'display_order' => 10 );
		}
		echo '<p>' . esc_html__( 'Only approved, non-sensitive observations belong here. Raw notes, tester identities, account data, serial numbers, and private evidence locations must remain in the protected record.', 'longevity-core' ) . '</p>';
		echo '<div class="lel-results-editor"><div class="table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Metric', 'longevity-core' ) . '</th><th>' . esc_html__( 'Observed', 'longevity-core' ) . '</th><th>' . esc_html__( 'Unit', 'longevity-core' ) . '</th><th>' . esc_html__( 'Reference label', 'longevity-core' ) . '</th><th>' . esc_html__( 'Reference value', 'longevity-core' ) . '</th><th>' . esc_html__( 'Status', 'longevity-core' ) . '</th><th>' . esc_html__( 'Interpretation note', 'longevity-core' ) . '</th><th>' . esc_html__( 'Order', 'longevity-core' ) . '</th><th>' . esc_html__( 'Actions', 'longevity-core' ) . '</th></tr></thead><tbody data-lel-result-rows>';
		foreach ( $rows as $index => $row ) {
			self::public_result_row( $row, $index );
		}
		echo '</tbody></table></div><p><button type="button" class="button" data-lel-add-result>' . esc_html__( 'Add result row', 'longevity-core' ) . '</button></p></div>';
	}

	/** Save structured public rows with nonce and least-privilege capability checks. */
	public static function save_public_test_results( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $post, $update );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'manage_test_protocols' ) ) {
			return;
		}
		if ( empty( $_POST['longevity_public_results_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['longevity_public_results_nonce'] ) ), 'longevity_save_public_results' ) ) {
			return;
		}
		$rows = isset( $_POST['public_test_results_rows'] ) && is_array( $_POST['public_test_results_rows'] ) ? wp_unslash( $_POST['public_test_results_rows'] ) : array();
		update_post_meta( $post_id, 'public_test_results', Review_Methodology::sanitize_public_results( $rows ) );
	}

	/** Render reviewer profile fields. */
	public static function render_reviewer_profile( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$fields = self::reviewer_profile_fields();
		echo '<h2>' . esc_html__( 'Reviewer profile', 'longevity-core' ) . '</h2><table class="form-table" role="presentation">';
		foreach ( $fields as $key => $label ) {
			$value = get_user_meta( $user->ID, $key, true );
			echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( in_array( $key, array( 'professional_credentials', 'review_scope', 'jurisdictions', 'conflict_disclosure' ), true ) ) {
				echo '<textarea class="regular-text" rows="4" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
			} elseif ( 'credential_verification_status' === $key ) {
				echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
				foreach ( array( '' => 'Not set', 'unverified' => 'Unverified', 'pending' => 'Pending', 'verified' => 'Verified', 'expired' => 'Expired' ) as $option => $option_label ) {
					echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $option_label ) . '</option>';
				}
				echo '</select>';
			} else {
				$type = str_contains( $key, 'date' ) ? 'date' : ( str_contains( $key, 'url' ) ? 'url' : 'text' );
				echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	/** Save reviewer profile fields. */
	public static function save_reviewer_profile( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		foreach ( self::reviewer_profile_fields() as $key => $label ) {
			unset( $label );
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$rule = match ( $key ) {
				'credential_verification_date' => 'date',
				'professional_profile_url' => 'url',
				'credential_verification_status' => 'credential_status',
				'professional_credentials', 'review_scope', 'jurisdictions', 'conflict_disclosure' => 'textarea',
				default => 'text',
			};
			update_user_meta( $user_id, $key, Meta_Registry::sanitize_value( $rule, wp_unslash( $_POST[ $key ] ) ) );
		}
	}

	/** Add a readiness column. */
	public static function add_readiness_column( array $columns ): array {
		$columns['lel_readiness'] = __( 'Readiness', 'longevity-core' );
		return $columns;
	}

	/** Render a readiness column. */
	public static function render_readiness_column( string $column, int $post_id ): void {
		if ( 'lel_readiness' !== $column ) {
			return;
		}
		$result = Publication_Gates::evaluate( $post_id );
		printf( '<strong>%d%%</strong><br>%s', esc_html( (string) $result->completion_percentage() ), $result->is_blocked() ? esc_html__( 'Blocked', 'longevity-core' ) : esc_html__( 'Ready', 'longevity-core' ) );
	}

	/** Save authenticated fact-check completion. */
	private static function save_fact_check_completion( int $post_id ): void {
		if ( 'complete' !== get_post_meta( $post_id, 'fact_check_status', true ) || ! current_user_can( 'complete_fact_check' ) ) {
			return;
		}
		update_post_meta( $post_id, 'fact_checked_by', get_current_user_id() );
		update_post_meta( $post_id, 'fact_checked_date', gmdate( 'Y-m-d' ) );
		Publication_Gates::log_event( $post_id, 'fact_check_completed' );
	}

	/** Save authenticated reviewer attestation. */
	private static function save_medical_attestation( int $post_id ): void {
		if ( empty( $_POST['medical_review_attested'] ) || ! current_user_can( 'complete_medical_review' ) ) {
			return;
		}
		$reviewer_id = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
		if ( $reviewer_id !== get_current_user_id() ) {
			return;
		}
		$scope = get_post_meta( $post_id, 'medical_review_scope', true );
		if ( '' === $scope ) {
			return;
		}
		update_post_meta( $post_id, 'medical_review_attested', true );
		update_post_meta( $post_id, 'medical_review_status', 'complete' );
		update_post_meta( $post_id, 'medical_review_date', gmdate( 'Y-m-d' ) );
		Publication_Gates::log_event( $post_id, 'medical_review_completed', array( 'scope' => $scope ) );
	}

	/** Render an attestation checkbox only to the assigned reviewer. */
	private static function attestation( int $post_id ): void {
		$reviewer_id = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
		if ( $reviewer_id !== get_current_user_id() || ! current_user_can( 'complete_medical_review' ) ) {
			$attested = get_post_meta( $post_id, 'medical_review_attested', true );
			echo '<p><strong>' . esc_html__( 'Reviewer attestation:', 'longevity-core' ) . '</strong> ' . ( $attested ? esc_html__( 'Completed', 'longevity-core' ) : esc_html__( 'Not completed by assigned reviewer', 'longevity-core' ) ) . '</p>';
			return;
		}
		self::checkbox( $post_id, 'medical_review_attested', __( 'I reviewed the stated scope and confirm that the reviewed language is appropriate for general educational publication as of the recorded review date.', 'longevity-core' ) );
	}

	/** Render a reviewer selector. */
	private static function reviewer_select( int $post_id ): void {
		$value = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
		$users = get_users( array( 'capability' => 'complete_medical_review', 'orderby' => 'display_name' ) );
		echo '<p><label for="medical_reviewer_user_id"><strong>' . esc_html__( 'Medical reviewer', 'longevity-core' ) . '</strong></label><br><select class="widefat" id="medical_reviewer_user_id" name="medical_reviewer_user_id"><option value="0">' . esc_html__( 'Select reviewer', 'longevity-core' ) . '</option>';
		foreach ( $users as $user ) {
			$status = (string) get_user_meta( $user->ID, 'credential_verification_status', true );
			echo '<option value="' . esc_attr( (string) $user->ID ) . '" ' . selected( $value, $user->ID, false ) . '>' . esc_html( sprintf( '%1$s (ID %2$d; %3$s)', $user->display_name, $user->ID, $status ?: __( 'unverified', 'longevity-core' ) ) ) . '</option>';
		}
		echo '</select></p><p class="description">' . esc_html__( 'Only an assigned authenticated reviewer with verified public credentials can complete the attestation.', 'longevity-core' ) . '</p>';
	}

	/** Render approved test records with human-readable titles and stable IDs. */
	private static function test_record_select( int $post_id ): void {
		$value   = (int) get_post_meta( $post_id, 'test_record_id', true );
		$records = get_posts( array( 'post_type' => 'lel_test_record', 'post_status' => 'any', 'posts_per_page' => 100, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ) ) );
		echo '<p><label for="test_record_id"><strong>' . esc_html__( 'Test record', 'longevity-core' ) . '</strong></label><br><select class="widefat" id="test_record_id" name="test_record_id"><option value="0">' . esc_html__( 'Select an approved record', 'longevity-core' ) . '</option>';
		foreach ( $records as $record ) {
			$status = (string) get_post_meta( $record->ID, 'approval_status', true );
			echo '<option value="' . esc_attr( (string) $record->ID ) . '" ' . selected( $value, $record->ID, false ) . '>' . esc_html( sprintf( '%1$s (ID %2$d; %3$s)', get_the_title( $record ), $record->ID, $status ?: __( 'not approved', 'longevity-core' ) ) ) . '</option>';
		}
		echo '</select></p><p class="description">' . esc_html__( 'Publication requires an approved record whose protocol version matches this review.', 'longevity-core' ) . '</p>';
	}

	/** Render result list. */
	private static function render_result_group( string $title, array $items, string $class ): void {
		if ( empty( $items ) ) {
			return;
		}
		echo '<div class="' . esc_attr( $class ) . '"><strong>' . esc_html( $title ) . '</strong><ul>';
		foreach ( $items as $item ) {
			$field = self::readiness_field( (string) ( $item['code'] ?? '' ) );
			echo '<li>' . esc_html( $item['message'] );
			if ( $field ) {
				echo ' <a href="#' . esc_attr( $field ) . '" class="lel-readiness-link">' . esc_html__( 'Go to field', 'longevity-core' ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/** Map gate codes to the nearest actionable field. */
	private static function readiness_field( string $code ): string {
		$map = array(
			'missing_summary'                 => 'content_summary',
			'missing_limitations'             => 'content_limitations',
			'claims_unverified'                => 'material_health_claims',
			'fact_check_incomplete'            => 'fact_check_status',
			'medical_review_incomplete'        => 'medical_review_status',
			'test_record_invalid'              => 'test_record_id',
			'score_not_reproducible'           => 'review_score_dimensions',
			'commercial_disclosure_incomplete' => 'affiliate_disclosure_status',
			'missing_review_date'              => 'next_content_review_date',
		);
		return $map[ $code ] ?? '';
	}

	/** Render an accessible repeatable dimension editor backed by the existing meta shape. */
	private static function score_dimensions_editor( int $post_id ): void {
		$dimensions = get_post_meta( $post_id, 'review_score_dimensions', true );
		$dimensions = is_array( $dimensions ) && $dimensions ? $dimensions : array( array( 'name' => '', 'score' => '', 'weight' => '' ) );
		echo '<fieldset id="review_score_dimensions" class="lel-score-editor"><legend><strong>' . esc_html__( 'Score dimensions', 'longevity-core' ) . '</strong></legend><p class="description">' . esc_html__( 'Weights must total 100%. The calculated score is saved server-side; confidence remains a separate editorial judgment.', 'longevity-core' ) . '</p><div class="lel-score-table-wrap"><table><thead><tr><th scope="col">' . esc_html__( 'Dimension', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Score (0–5)', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Weight %', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Action', 'longevity-core' ) . '</th></tr></thead><tbody data-lel-score-rows>';
		foreach ( $dimensions as $index => $dimension ) {
			self::score_dimension_row( (int) $index, is_array( $dimension ) ? $dimension : array() );
		}
		echo '</tbody></table></div><p><button type="button" class="button" data-lel-add-dimension>' . esc_html__( 'Add dimension', 'longevity-core' ) . '</button></p><p class="lel-score-totals" aria-live="polite"><strong>' . esc_html__( 'Weight total:', 'longevity-core' ) . '</strong> <span data-lel-weight-total>0</span>% &middot; <strong>' . esc_html__( 'Calculated score:', 'longevity-core' ) . '</strong> <span data-lel-calculated-score>0.00</span>/5</p><details><summary>' . esc_html__( 'Raw JSON debug view', 'longevity-core' ) . '</summary><pre class="lel-score-json">' . esc_html( wp_json_encode( Review_Methodology::sanitize_dimensions( $dimensions ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details></fieldset>';
	}

	/** Render one dimension row. */
	private static function score_dimension_row( int $index, array $dimension ): void {
		$name   = (string) ( $dimension['name'] ?? '' );
		$score  = (string) ( $dimension['score'] ?? '' );
		$weight = (string) ( $dimension['weight'] ?? '' );
		echo '<tr data-lel-score-row><td><label class="screen-reader-text" for="lel-dimension-name-' . esc_attr( (string) $index ) . '">' . esc_html__( 'Dimension name', 'longevity-core' ) . '</label><input id="lel-dimension-name-' . esc_attr( (string) $index ) . '" type="text" name="review_score_dimensions_rows[' . esc_attr( (string) $index ) . '][name]" value="' . esc_attr( $name ) . '"></td><td><label class="screen-reader-text" for="lel-dimension-score-' . esc_attr( (string) $index ) . '">' . esc_html__( 'Dimension score', 'longevity-core' ) . '</label><input id="lel-dimension-score-' . esc_attr( (string) $index ) . '" type="number" min="0" max="5" step="0.1" name="review_score_dimensions_rows[' . esc_attr( (string) $index ) . '][score]" value="' . esc_attr( $score ) . '"></td><td><label class="screen-reader-text" for="lel-dimension-weight-' . esc_attr( (string) $index ) . '">' . esc_html__( 'Dimension weight', 'longevity-core' ) . '</label><input id="lel-dimension-weight-' . esc_attr( (string) $index ) . '" type="number" min="0" max="100" step="0.1" name="review_score_dimensions_rows[' . esc_attr( (string) $index ) . '][weight]" value="' . esc_attr( $weight ) . '"></td><td><button type="button" class="button-link-delete" data-lel-remove-dimension>' . esc_html__( 'Remove', 'longevity-core' ) . '</button></td></tr>';
	}

	private static function textarea( int $post_id, string $key, string $label ): void {
		$value = get_post_meta( $post_id, $key, true );
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><textarea class="widefat" rows="3" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" aria-describedby="' . esc_attr( $key ) . '-help">' . esc_textarea( (string) $value ) . '</textarea>' . self::field_help( $key ) . '</p>';
	}

	/** Render one structured public test-result row. */
	private static function public_result_row( array $row, int $index ): void {
		$fields = array( 'label', 'observed_value', 'unit', 'reference_label', 'reference_value', 'note', 'display_order' );
		echo '<tr data-lel-result-row>';
		foreach ( array_slice( $fields, 0, 5 ) as $field ) {
			echo '<td><label class="screen-reader-text" for="lel-result-' . esc_attr( $field . '-' . $index ) . '">' . esc_html( str_replace( '_', ' ', ucfirst( $field ) ) ) . '</label><input id="lel-result-' . esc_attr( $field . '-' . $index ) . '" type="text" name="public_test_results_rows[' . esc_attr( (string) $index ) . '][' . esc_attr( $field ) . ']" value="' . esc_attr( (string) ( $row[ $field ] ?? '' ) ) . '"></td>';
		}
		echo '<td><label class="screen-reader-text" for="lel-result-status-' . esc_attr( (string) $index ) . '">' . esc_html__( 'Status', 'longevity-core' ) . '</label><select id="lel-result-status-' . esc_attr( (string) $index ) . '" name="public_test_results_rows[' . esc_attr( (string) $index ) . '][status]">';
		foreach ( array( 'meets' => 'Meets reference', 'partially_meets' => 'Partially meets', 'does_not_meet' => 'Does not meet', 'informational' => 'Informational', 'not_applicable' => 'Not applicable' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $row['status'] ?? 'informational', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td><td><label class="screen-reader-text" for="lel-result-note-' . esc_attr( (string) $index ) . '">' . esc_html__( 'Interpretation note', 'longevity-core' ) . '</label><textarea id="lel-result-note-' . esc_attr( (string) $index ) . '" name="public_test_results_rows[' . esc_attr( (string) $index ) . '][note]" rows="2">' . esc_textarea( (string) ( $row['note'] ?? '' ) ) . '</textarea></td><td><label class="screen-reader-text" for="lel-result-order-' . esc_attr( (string) $index ) . '">' . esc_html__( 'Display order', 'longevity-core' ) . '</label><input id="lel-result-order-' . esc_attr( (string) $index ) . '" type="number" min="0" max="999" name="public_test_results_rows[' . esc_attr( (string) $index ) . '][display_order]" value="' . esc_attr( (string) ( $row['display_order'] ?? ( $index + 1 ) * 10 ) ) . '"></td><td><button type="button" class="button-link" data-lel-result-up aria-label="' . esc_attr__( 'Move row up', 'longevity-core' ) . '">↑</button> <button type="button" class="button-link" data-lel-result-down aria-label="' . esc_attr__( 'Move row down', 'longevity-core' ) . '">↓</button> <button type="button" class="button-link-delete" data-lel-remove-result>' . esc_html__( 'Remove', 'longevity-core' ) . '</button></td></tr>';
	}

	private static function json_textarea( int $post_id, string $key, string $label ): void {
		$value = get_post_meta( $post_id, $key, true );
		$value = is_array( $value ) ? wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : (string) $value;
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><textarea class="widefat code" rows="8" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea></p>';
	}

	private static function text( int $post_id, string $key, string $label ): void {
		self::input( $post_id, $key, $label, 'text' );
	}

	private static function url( int $post_id, string $key, string $label ): void {
		self::input( $post_id, $key, $label, 'url' );
	}

	private static function date( int $post_id, string $key, string $label ): void {
		self::input( $post_id, $key, $label, 'date' );
	}

	private static function number( int $post_id, string $key, string $label, $step = 1, $min = null, $max = null ): void {
		$value = get_post_meta( $post_id, $key, true );
		$attrs = '';
		if ( null !== $min ) {
			$attrs .= ' min="' . esc_attr( (string) $min ) . '"';
		}
		if ( null !== $max ) {
			$attrs .= ' max="' . esc_attr( (string) $max ) . '"';
		}
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><input class="widefat" type="number" step="' . esc_attr( (string) $step ) . '"' . $attrs . ' id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '"></p>';
	}

	private static function input( int $post_id, string $key, string $label, string $type ): void {
		$value = get_post_meta( $post_id, $key, true );
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><input class="widefat" type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" aria-describedby="' . esc_attr( $key ) . '-help">' . self::field_help( $key ) . '</p>';
	}

	private static function checkbox( int $post_id, string $key, string $label ): void {
		$value = (bool) get_post_meta( $post_id, $key, true );
		echo '<p><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( $value, true, false ) . '> ' . esc_html( $label ) . '</label></p>';
	}

	private static function select( int $post_id, string $key, string $label, array $options ): void {
		$value = (string) get_post_meta( $post_id, $key, true );
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><select class="widefat" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" aria-describedby="' . esc_attr( $key ) . '-help">';
		foreach ( $options as $option => $option_label ) {
			echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $option_label ) . '</option>';
		}
		echo '</select>' . self::field_help( $key ) . '</p>';
	}

	/** Render registered field help without duplicating governance definitions. */
	private static function field_help( string $key ): string {
		$definitions = Meta_Registry::definitions();
		$description = (string) ( $definitions[ $key ]['description'] ?? '' );
		return $description ? '<span class="description" id="' . esc_attr( $key ) . '-help">' . esc_html( $description ) . '</span>' : '';
	}

	/** Reviewer profile field labels. */
	private static function reviewer_profile_fields(): array {
		return array(
			'professional_credentials'       => __( 'Professional credentials', 'longevity-core' ),
			'credential_verification_status' => __( 'Credential verification status', 'longevity-core' ),
			'credential_verification_date'   => __( 'Credential verification date', 'longevity-core' ),
			'professional_profile_url'        => __( 'Professional profile URL', 'longevity-core' ),
			'review_scope'                    => __( 'Qualified review scope', 'longevity-core' ),
			'jurisdictions'                   => __( 'Jurisdictions', 'longevity-core' ),
			'conflict_disclosure'             => __( 'Conflict disclosure', 'longevity-core' ),
		);
	}
}

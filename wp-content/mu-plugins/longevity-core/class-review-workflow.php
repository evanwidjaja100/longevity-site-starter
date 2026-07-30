<?php
/**
 * Dedicated fact-check and medical-review work queues.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Provides specialist workflows without granting broad post-editing access. */
final class Review_Workflow {
	/** Register hooks. */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_pages' ) );
		add_action( 'admin_post_lel_submit_medical_review', array( self::class, 'submit_medical_review' ) );
		add_action( 'admin_post_lel_submit_fact_check', array( self::class, 'submit_fact_check' ) );
	}

	/** Register role-specific administration pages. */
	public static function register_pages(): void {
		add_menu_page(
			__( 'Medical review queue', 'longevity-core' ),
			__( 'Medical Reviews', 'longevity-core' ),
			'complete_medical_review',
			'lel-medical-review-queue',
			array( self::class, 'render_medical_queue' ),
			'dashicons-shield-alt',
			26
		);
		add_menu_page(
			__( 'Fact-check queue', 'longevity-core' ),
			__( 'Fact Checks', 'longevity-core' ),
			'complete_fact_check',
			'lel-fact-check-queue',
			array( self::class, 'render_fact_queue' ),
			'dashicons-search',
			27
		);
	}

	/** Render articles assigned to the authenticated medical reviewer. */
	public static function render_medical_queue(): void {
		if ( ! current_user_can( 'complete_medical_review' ) ) {
			wp_die( esc_html__( 'You are not authorized to complete medical reviews.', 'longevity-core' ) );
		}
		$user_id = get_current_user_id();
		$posts   = get_posts(
			array(
				'post_type'      => array( 'post', 'review' ),
				'post_status'    => array( 'draft', 'pending', 'private', 'future', 'publish', 'lel_medical_review', 'lel_correction_pending', 'lel_update_due' ),
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_key'       => 'medical_reviewer_user_id',
				'meta_value'     => (string) $user_id,
			)
		);
		$selected_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; value is sanitized and causes no state change.

		echo '<div class="wrap"><h1>' . esc_html__( 'Assigned medical reviews', 'longevity-core' ) . '</h1>';
		self::render_notice();
		self::render_reviewer_identity_status( $user_id );
		self::render_queue_table( $posts, 'lel-medical-review-queue', $selected_id, 'medical_review_status' );
		if ( $selected_id ) {
			self::render_medical_form( $selected_id, $user_id );
		}
		echo '</div>';
	}

	/** Render material-claim articles awaiting fact-checking. */
	public static function render_fact_queue(): void {
		if ( ! current_user_can( 'complete_fact_check' ) ) {
			wp_die( esc_html__( 'You are not authorized to complete fact checks.', 'longevity-core' ) );
		}
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'review' ),
				'post_status'    => array( 'draft', 'pending', 'private', 'future', 'publish', 'lel_fact_check', 'lel_correction_pending', 'lel_update_due' ),
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array( 'key' => 'material_health_claims', 'value' => '1' ),
					array( 'key' => 'fact_check_status', 'value' => array( 'in_progress', 'revisions_required', 'not_started' ), 'compare' => 'IN' ),
				),
			)
		);
		$selected_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; value is sanitized and causes no state change.

		echo '<div class="wrap"><h1>' . esc_html__( 'Fact-check queue', 'longevity-core' ) . '</h1>';
		self::render_notice();
		self::render_queue_table( $posts, 'lel-fact-check-queue', $selected_id, 'fact_check_status' );
		if ( $selected_id ) {
			self::render_fact_form( $selected_id, $posts );
		}
		echo '</div>';
	}

	/** Process a medical review by the assigned, credential-verified reviewer. */
	public static function submit_medical_review(): void {
		if ( ! current_user_can( 'complete_medical_review' ) ) {
			wp_die( esc_html__( 'You are not authorized to complete medical reviews.', 'longevity-core' ) );
		}
		check_admin_referer( 'lel_medical_review', 'lel_review_nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$user_id = get_current_user_id();
		if ( ! $post_id || $user_id !== (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true ) ) {
			wp_die( esc_html__( 'This review is not assigned to your account.', 'longevity-core' ) );
		}
		if ( ! Reviewer_Credentials::is_valid_for( $user_id, (string) get_post_meta( $post_id, 'medical_review_scope', true ), (string) get_post_meta( $post_id, 'region_scope', true ) ) ) {
			self::redirect( 'lel-medical-review-queue', $post_id, 'credentials_required' );
			return;
		}

		$scope       = Meta_Registry::sanitize_value( 'review_scope', self::posted( 'medical_review_scope' ) );
		$sections    = Meta_Registry::sanitize_value( 'textarea', self::posted( 'medical_review_sections' ) );
		$claim_ids   = Meta_Registry::sanitize_value( 'csv_ids', self::posted( 'medical_review_claim_ids' ) );
		$limitations = Meta_Registry::sanitize_value( 'textarea', self::posted( 'medical_review_limitations' ) );
		$conflicts   = Meta_Registry::sanitize_value( 'textarea', self::posted( 'medical_review_conflicts' ) );
		$revisions   = Meta_Registry::sanitize_value( 'textarea', self::posted( 'medical_review_required_revisions' ) );
		$status      = Meta_Registry::sanitize_value( 'revision_status', self::posted( 'medical_review_revision_status' ) );
		$next_date   = Meta_Registry::sanitize_value( 'date', self::posted( 'next_medical_review_date' ) );
		$version     = Meta_Registry::sanitize_value( 'version', self::posted( 'medical_review_version' ) );

		if ( '' === $scope || '' === $sections || '' === $limitations || '' === $conflicts || '' === $next_date || ! Date_Validator::after( $next_date, Date_Validator::today() ) || '' === $version || ( 'claim_ids' === $scope && '' === $claim_ids ) ) {
			self::redirect( 'lel-medical-review-queue', $post_id, 'required_fields' );
		}
		$values = array(
			'medical_review_scope'              => $scope,
			'medical_review_sections'           => $sections,
			'medical_review_claim_ids'          => $claim_ids,
			'medical_review_limitations'        => $limitations,
			'medical_review_conflicts'          => $conflicts,
			'medical_review_required_revisions' => $revisions,
			'medical_review_revision_status'    => $status,
			'next_medical_review_date'          => $next_date,
			'medical_review_version'            => $version,
		);
		foreach ( $values as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		if ( in_array( $status, array( 'required', 'in_progress' ), true ) ) {
			update_post_meta( $post_id, 'medical_review_status', 'revisions_required' );
			update_post_meta( $post_id, 'medical_review_attested', false );
			delete_post_meta( $post_id, 'medical_review_date' );
			Publication_Gates::log_event( $post_id, 'medical_review_revisions_required', array( 'scope' => $scope ) );
			self::redirect( 'lel-medical-review-queue', $post_id, 'revisions_recorded' );
		}
		if ( empty( $_POST['medical_review_attested'] ) ) {
			self::redirect( 'lel-medical-review-queue', $post_id, 'attestation_required' );
		}

		$approval = Approval_Service::approve( $post_id, 'medical', $user_id, array( 'scope' => $scope, 'version' => $version ) );
		if ( ! $approval ) {
			self::redirect( 'lel-medical-review-queue', $post_id, 'credentials_required' );
			return;
		}
		self::redirect( 'lel-medical-review-queue', $post_id, 'medical_complete' );
	}

	/** Process a fact-check outcome. */
	public static function submit_fact_check(): void {
		if ( ! current_user_can( 'complete_fact_check' ) ) {
			wp_die( esc_html__( 'You are not authorized to complete fact checks.', 'longevity-core' ) );
		}
		check_admin_referer( 'lel_fact_check', 'lel_fact_nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! in_array( get_post_type( $post_id ), array( 'post', 'review' ), true ) || ! get_post_meta( $post_id, 'material_health_claims', true ) ) {
			wp_die( esc_html__( 'This record is not eligible for the fact-check queue.', 'longevity-core' ) );
		}
		$outcome   = sanitize_key( self::posted( 'fact_check_outcome' ) );
		$next_date = Meta_Registry::sanitize_value( 'date', self::posted( 'next_fact_check_date' ) );
		$notes     = Meta_Registry::sanitize_value( 'textarea', self::posted( 'fact_check_notes' ) );
		if ( ! in_array( $outcome, array( 'complete', 'revisions_required' ), true ) ) {
			self::redirect( 'lel-fact-check-queue', $post_id, 'required_fields' );
		}
		if ( 'revisions_required' === $outcome ) {
			update_post_meta( $post_id, 'fact_check_status', 'revisions_required' );
			delete_post_meta( $post_id, 'fact_checked_by' );
			delete_post_meta( $post_id, 'fact_checked_date' );
			Publication_Gates::log_event( $post_id, 'fact_check_revisions_required', array( 'notes' => $notes ) );
			self::redirect( 'lel-fact-check-queue', $post_id, 'revisions_recorded' );
		}

		$claim_count    = Claims::count_for_post( $post_id );
		$verified_count = Claims::count_for_post( $post_id, 'verified' );
		if ( $claim_count <= 0 || $verified_count !== $claim_count || '' === $next_date || ! Date_Validator::after( $next_date, Date_Validator::today() ) ) {
			self::redirect( 'lel-fact-check-queue', $post_id, 'claims_incomplete' );
		}
		update_post_meta( $post_id, 'next_fact_check_date', $next_date );
		$approval = Approval_Service::approve( $post_id, 'fact_check', get_current_user_id(), array( 'claims' => $claim_count, 'notes_summary' => $notes ? 'recorded' : 'none' ) );
		if ( ! $approval ) {
			self::redirect( 'lel-fact-check-queue', $post_id, 'claims_incomplete' );
			return;
		}
		self::redirect( 'lel-fact-check-queue', $post_id, 'fact_complete' );
	}

	/** Render a table shared by both queues. */
	private static function render_queue_table( array $posts, string $page, int $selected_id, string $status_key ): void {
		if ( empty( $posts ) ) {
			echo '<p>' . esc_html__( 'No records are currently in this queue.', 'longevity-core' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Title', 'longevity-core' ) . '</th><th>' . esc_html__( 'Type', 'longevity-core' ) . '</th><th>' . esc_html__( 'Status', 'longevity-core' ) . '</th><th>' . esc_html__( 'Action', 'longevity-core' ) . '</th></tr></thead><tbody>';
		foreach ( $posts as $post ) {
			$url = add_query_arg( array( 'page' => $page, 'post_id' => $post->ID ), admin_url( 'admin.php' ) );
			echo '<tr' . ( $selected_id === $post->ID ? ' aria-current="true"' : '' ) . '><td>' . esc_html( get_the_title( $post ) ) . '</td><td>' . esc_html( get_post_type_object( $post->post_type )->labels->singular_name ) . '</td><td>' . esc_html( (string) get_post_meta( $post->ID, $status_key, true ) ) . '</td><td><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Open review form', 'longevity-core' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Render the selected medical review form. */
	private static function render_medical_form( int $post_id, int $user_id ): void {
		if ( $user_id !== (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This review is not assigned to you.', 'longevity-core' ) . '</p></div>';
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		echo '<hr><h2>' . esc_html( get_the_title( $post ) ) . '</h2>';
		echo '<p><a href="' . esc_url( get_preview_post_link( $post ) ?: get_permalink( $post ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open preview in a new tab', 'longevity-core' ) . '</a></p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ), 180 ) ) . '</p></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lel_medical_review', 'lel_review_nonce' );
		echo '<input type="hidden" name="action" value="lel_submit_medical_review"><input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '">';
		self::select_field( 'medical_review_scope', __( 'Review scope', 'longevity-core' ), array( 'full_article' => 'Full article', 'safety_only' => 'Safety sections only', 'contraindications_only' => 'Contraindications only', 'dosage_language_only' => 'Dosage language only', 'product_accuracy_only' => 'Product accuracy language only', 'medical_disclaimer_only' => 'Medical disclaimer only', 'claim_ids' => 'Claims listed by ID' ), get_post_meta( $post_id, 'medical_review_scope', true ) );
		self::textarea_field( 'medical_review_sections', __( 'Sections reviewed', 'longevity-core' ), get_post_meta( $post_id, 'medical_review_sections', true ) );
		self::text_field( 'medical_review_claim_ids', __( 'Claim IDs reviewed (required for claim-ID scope)', 'longevity-core' ), get_post_meta( $post_id, 'medical_review_claim_ids', true ) );
		self::textarea_field( 'medical_review_limitations', __( 'Limitations of this review', 'longevity-core' ), get_post_meta( $post_id, 'medical_review_limitations', true ) );
		self::textarea_field( 'medical_review_conflicts', __( 'Conflicts of interest (enter “None known” when accurate)', 'longevity-core' ), get_post_meta( $post_id, 'medical_review_conflicts', true ) );
		self::textarea_field( 'medical_review_required_revisions', __( 'Required revisions', 'longevity-core' ), get_post_meta( $post_id, 'medical_review_required_revisions', true ) );
		self::select_field( 'medical_review_revision_status', __( 'Revision status', 'longevity-core' ), array( 'not_applicable' => 'Not applicable', 'required' => 'Required', 'in_progress' => 'In progress', 'complete' => 'Complete' ), get_post_meta( $post_id, 'medical_review_revision_status', true ) );
		self::text_field( 'next_medical_review_date', __( 'Next medical review date', 'longevity-core' ), get_post_meta( $post_id, 'next_medical_review_date', true ), 'date' );
		self::text_field( 'medical_review_version', __( 'Reviewed content version', 'longevity-core' ), get_post_meta( $post_id, 'medical_review_version', true ) );
		echo '<p><label><input type="checkbox" name="medical_review_attested" value="1"> ' . esc_html__( 'I reviewed the stated scope and confirm that the reviewed language is appropriate for general educational publication as of today. I understand that this is not a digital signature and does not cover material outside the recorded scope.', 'longevity-core' ) . '</label></p>';
		submit_button( __( 'Submit medical review', 'longevity-core' ) );
		echo '</form>';
	}

	/** Render the selected fact-check form. */
	private static function render_fact_form( int $post_id, array $eligible_posts ): void {
		$eligible_ids = array_map( static fn( $post ) => (int) $post->ID, $eligible_posts );
		if ( ! in_array( $post_id, $eligible_ids, true ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This record is not currently eligible for this queue.', 'longevity-core' ) . '</p></div>';
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$claims   = Claims::count_for_post( $post_id );
		$verified = Claims::count_for_post( $post_id, 'verified' );
		echo '<hr><h2>' . esc_html( get_the_title( $post ) ) . '</h2><p><strong>' . esc_html__( 'Claims:', 'longevity-core' ) . '</strong> ' . esc_html( (string) $verified ) . '/' . esc_html( (string) $claims ) . ' ' . esc_html__( 'verified', 'longevity-core' ) . '</p>';
		echo '<p><a href="' . esc_url( get_preview_post_link( $post ) ?: get_permalink( $post ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open preview in a new tab', 'longevity-core' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lel_fact_check', 'lel_fact_nonce' );
		echo '<input type="hidden" name="action" value="lel_submit_fact_check"><input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '">';
		self::select_field( 'fact_check_outcome', __( 'Outcome', 'longevity-core' ), array( 'complete' => 'Complete', 'revisions_required' => 'Revisions required' ), '' );
		self::text_field( 'next_fact_check_date', __( 'Next fact-check date (required for completion)', 'longevity-core' ), get_post_meta( $post_id, 'next_fact_check_date', true ), 'date' );
		self::textarea_field( 'fact_check_notes', __( 'Notes or required revisions', 'longevity-core' ), '' );
		submit_button( __( 'Submit fact-check outcome', 'longevity-core' ) );
		echo '</form>';
	}

	/** Show reviewer credential readiness without exposing private fields. */
	private static function render_reviewer_identity_status( int $user_id ): void {
		$status      = (string) get_user_meta( $user_id, 'credential_verification_status', true );
		$is_valid    = Reviewer_Credentials::is_valid_for( $user_id, '', '' );
		$class       = $is_valid ? 'notice-success' : 'notice-warning';
		$message     = 'notice-success' === $class ? __( 'Your reviewer credentials are recorded and verified.', 'longevity-core' ) : __( 'An administrator must record and verify your public credentials before a review can be completed.', 'longevity-core' );
		echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $message ) . '</p></div>';
	}

	/** Display a redirected result message. */
	private static function render_notice(): void {
		$message = isset( $_GET['lel_message'] ) ? sanitize_key( $_GET['lel_message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; value is sanitized and causes no state change.
		$messages = array(
			'credentials_required' => array( 'error', __( 'Verified credentials are required before completion.', 'longevity-core' ) ),
			'required_fields'      => array( 'error', __( 'Complete every required field before submitting.', 'longevity-core' ) ),
			'attestation_required' => array( 'error', __( 'Completion requires the reviewer attestation.', 'longevity-core' ) ),
			'claims_incomplete'    => array( 'error', __( 'Every registered claim must be verified and a next fact-check date supplied.', 'longevity-core' ) ),
			'revisions_recorded'   => array( 'warning', __( 'Required revisions were recorded; the review remains incomplete.', 'longevity-core' ) ),
			'medical_complete'     => array( 'success', __( 'The scoped medical review was completed and audited.', 'longevity-core' ) ),
			'fact_complete'        => array( 'success', __( 'Fact-check completion was recorded and audited.', 'longevity-core' ) ),
		);
		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}
		list( $type, $text ) = $messages[ $message ];
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/** Safely redirect back to a queue. */
	private static function redirect( string $page, int $post_id, string $message ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'post_id' => $post_id, 'lel_message' => $message ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Read an unslashed scalar POST value. */
	private static function posted( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw POST reader; callers submit_medical_review()/submit_fact_check() verify the nonce via check_admin_referer() and sanitize each value with Meta_Registry::sanitize_value()/sanitize_key().
		return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** Render a text input. */
	private static function text_field( string $name, string $label, $value, string $type = 'text' ): void {
		echo '<p><label for="' . esc_attr( $name ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"></p>';
	}

	/** Render a textarea. */
	private static function textarea_field( string $name, string $label, $value ): void {
		echo '<p><label for="' . esc_attr( $name ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><textarea class="large-text" rows="4" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( (string) $value ) . '</textarea></p>';
	}

	/** Render a select. */
	private static function select_field( string $name, string $label, array $options, $value ): void {
		echo '<p><label for="' . esc_attr( $name ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $option => $option_label ) {
			echo '<option value="' . esc_attr( $option ) . '" ' . selected( (string) $value, (string) $option, false ) . '>' . esc_html( $option_label ) . '</option>';
		}
		echo '</select></p>';
	}
}

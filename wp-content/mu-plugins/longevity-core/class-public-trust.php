<?php
/**
 * Public trust/editorial components.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Renders article meta, trust summary, medical disclaimer, reviewer card. */
class Public_Trust {
	/** Render author and editorial dates. */
	public static function render_article_meta( int $post_id ): string {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return '';
		}
		$author_id       = (int) get_post_field( 'post_author', $post_id );
		$reviewer_id     = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
		$review_date     = (string) get_post_meta( $post_id, 'medical_review_date', true );
		$review_attested = Approval_Service::is_current( $post_id, 'medical' );
		$fact_date       = (string) get_post_meta( $post_id, 'fact_checked_date', true );
		$fact_user       = (int) get_post_meta( $post_id, 'fact_checked_by', true );
		$cutoff          = (string) get_post_meta( $post_id, 'evidence_cutoff_date', true );
		$correction      = (string) get_post_meta( $post_id, 'correction_status', true );
		$word_count      = str_word_count( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ) );
		$reading_time    = max( 1, (int) ceil( $word_count / 200 ) );

		$html = '<div class="longevity-article-meta">';

		$html .= '<div class="longevity-meta-group longevity-meta-identities">';
		if ( $author_id ) {
			$html .= '<span class="longevity-meta-author">' . sprintf( '<span class="longevity-meta-label">%s</span> <a href="%s">%s</a>', esc_html__( 'By', 'longevity-core' ), esc_url( get_author_posts_url( $author_id ) ), esc_html( get_the_author_meta( 'display_name', $author_id ) ) ) . '</span>';
		}
		if ( $reviewer_id && $review_date && $review_attested ) {
			$html .= '<span class="longevity-meta-reviewer">' . sprintf( '<span class="longevity-meta-label">%s</span> %s <time datetime="%s">%s</time>', esc_html__( 'Medically reviewed by', 'longevity-core' ), esc_html( get_the_author_meta( 'display_name', $reviewer_id ) ), esc_attr( $review_date ), esc_html( $review_date ) ) . '</span>';
		}
		$html .= '</div>';

		$html .= '<div class="longevity-meta-group longevity-meta-dates">';
		$html .= '<span class="longevity-meta-published">' . sprintf( '<span class="longevity-meta-label">%s</span> <time datetime="%s">%s</time>', esc_html__( 'Published', 'longevity-core' ), esc_attr( get_the_date( DATE_W3C, $post_id ) ), esc_html( get_the_date( '', $post_id ) ) ) . '</span>';
		if ( get_the_modified_time( 'U', $post_id ) > get_the_time( 'U', $post_id ) ) {
			$html .= '<span class="longevity-meta-updated">' . sprintf( '<span class="longevity-meta-label">%s</span> <time datetime="%s">%s</time>', esc_html__( 'Updated', 'longevity-core' ), esc_attr( get_the_modified_date( DATE_W3C, $post_id ) ), esc_html( get_the_modified_date( '', $post_id ) ) ) . '</span>';
		}
		if ( $cutoff ) {
			$html .= '<span class="longevity-meta-cutoff">' . sprintf( '<span class="longevity-meta-label">%s</span> <time datetime="%s">%s</time>', esc_html__( 'Evidence cutoff', 'longevity-core' ), esc_attr( $cutoff ), esc_html( $cutoff ) ) . '</span>';
		}
		$html .= '</div>';

		$html .= '<div class="longevity-meta-group longevity-meta-verification">';
		if ( $fact_date && $fact_user && Approval_Service::is_current( $post_id, 'fact_check' ) ) {
			$html .= '<span class="longevity-meta-factcheck">' . sprintf( '<span class="longevity-meta-label">%s</span> <a href="%s">%s</a> <time datetime="%s">%s</time>', esc_html__( 'Fact-checked by', 'longevity-core' ), esc_url( get_author_posts_url( $fact_user ) ), esc_html( get_the_author_meta( 'display_name', $fact_user ) ), esc_attr( $fact_date ), esc_html( $fact_date ) ) . '</span>';
		}
		$html .= '<span class="longevity-meta-reading">' . sprintf( '<span class="longevity-meta-label">%s</span> %s', esc_html__( 'Reading time', 'longevity-core' ), esc_html( sprintf( _n( '%s min', '%s min', $reading_time, 'longevity-core' ), number_format_i18n( $reading_time ) ) ) ) . '</span>';
		if ( 'none' !== $correction ) {
			$labels = array(
				'reported'      => __( 'Correction reported', 'longevity-core' ),
				'investigating' => __( 'Correction under review', 'longevity-core' ),
				'pending'       => __( 'Correction pending', 'longevity-core' ),
				'complete'      => __( 'Correction published', 'longevity-core' ),
			);
			$html  .= '<span class="longevity-meta-correction">' . esc_html( $labels[ $correction ] ?? $correction ) . '</span>';
		}
		$html .= '</div>';

		return $html . '</div>';
	}

	/** Render evidence, scope, limitations, and commercial relationship. */
	public static function render_trust_summary( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		$summary      = trim( (string) get_post_meta( $post_id, 'content_summary', true ) );
		$scope        = trim( (string) get_post_meta( $post_id, 'content_scope', true ) );
		$limitations  = trim( (string) get_post_meta( $post_id, 'content_limitations', true ) );
		$grade        = (string) get_post_meta( $post_id, 'evidence_grade', true );
		$rationale    = trim( (string) get_post_meta( $post_id, 'evidence_grade_rationale', true ) );
		$relationship = (string) get_post_meta( $post_id, 'commercial_relationship', true );
		if ( '' === $summary && '' === $scope && '' === $limitations && '' === $grade && in_array( $relationship, array( '', 'none' ), true ) ) {
			return '';
		}
		$id   = Public_Content::unique_id( 'trust', $post_id );
		$html = '<section class="longevity-trust-summary" aria-labelledby="' . esc_attr( $id ) . '" data-lel-event="evidence_summary_open"><h2 id="' . esc_attr( $id ) . '">' . esc_html__( 'At a glance', 'longevity-core' ) . '</h2>';
		if ( $summary ) {
			$html .= '<div class="longevity-bottom-line"><h3>' . esc_html__( 'Bottom line', 'longevity-core' ) . '</h3><p>' . esc_html( $summary ) . '</p></div>';
		}
		if ( $scope ) {
			$html .= '<p><strong>' . esc_html__( 'Scope:', 'longevity-core' ) . '</strong> ' . esc_html( $scope ) . '</p>';
		}
		if ( $grade ) {
			$grade_labels = array(
				'A' => __( 'Strong', 'longevity-core' ),
				'B' => __( 'Moderate', 'longevity-core' ),
				'C' => __( 'Limited', 'longevity-core' ),
				'D' => __( 'Mechanistic or anecdotal', 'longevity-core' ),
				'U' => __( 'Unclear', 'longevity-core' ),
			);
			$html        .= '<div class="longevity-evidence-grade"><span class="longevity-badge" data-grade="' . esc_attr( $grade ) . '">' . esc_html( sprintf( __( 'Confidence in the main conclusion: %s', 'longevity-core' ), $grade_labels[ $grade ] ?? __( 'Unclassified', 'longevity-core' ) ) ) . '</span>';
			if ( $rationale ) {
				$html .= '<p>' . esc_html( $rationale ) . '</p>';
			}
			$html .= '</div>';
		}
		if ( $limitations ) {
			$html .= '<div class="longevity-limitations" role="note"><h3>' . esc_html__( 'Limitations and uncertainty', 'longevity-core' ) . '</h3><p>' . esc_html( $limitations ) . '</p></div>';
		}
		if ( ! in_array( $relationship, array( '', 'none' ), true ) ) {
			$labels = array(
				'affiliate'        => __( 'This page contains affiliate relationships.', 'longevity-core' ),
				'product_supplied' => __( 'A product or access was supplied for evaluation.', 'longevity-core' ),
				'sponsored'        => __( 'This content has a disclosed sponsorship relationship.', 'longevity-core' ),
			);
			$html  .= '<p class="longevity-disclosure"><strong>' . esc_html__( 'Commercial disclosure:', 'longevity-core' ) . '</strong> ' . esc_html( $labels[ $relationship ] ?? $relationship ) . ' ' . esc_html__( 'Commercial relationships do not determine editorial conclusions.', 'longevity-core' ) . '</p>';
		}
		return $html . '</section>';
	}

	/** Render verified scoped reviewer identity. */
	public static function render_reviewer_card( int $post_id ): string {
		if ( $post_id <= 0 || ! Approval_Service::is_current( $post_id, 'medical' ) ) {
			return '';
		}
		$user_id  = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
		$snapshot = $user_id > 0 ? Reviewer_Credentials::public_snapshot( $user_id ) : array();
		if ( empty( $snapshot ) ) {
			return '';
		}
		$name        = (string) ( $snapshot['display_name'] ?? '' );
		$credentials = trim( (string) ( $snapshot['credentials'] ?? '' ) );
		if ( '' === $name || '' === $credentials ) {
			return '';
		}
		$id    = Public_Content::unique_id( 'reviewer', $post_id );
		$scope = self::scope_label( (string) get_post_meta( $post_id, 'medical_review_scope', true ) );
		$date  = (string) get_post_meta( $post_id, 'medical_review_date', true );
		$next  = (string) get_post_meta( $post_id, 'next_medical_review_date', true );
		$html  = '<aside class="longevity-reviewer-card" aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html__( 'Medical review', 'longevity-core' ) . '</h2><p><strong><a href="' . esc_url( get_author_posts_url( $user_id ) ) . '">' . esc_html( $name ) . '</a></strong><br>' . esc_html( $credentials ) . '</p><p>' . esc_html( $scope ) . '</p>';
		if ( $date ) {
			$html .= '<p><strong>' . esc_html__( 'Reviewed:', 'longevity-core' ) . '</strong> <time datetime="' . esc_attr( $date ) . '">' . esc_html( $date ) . '</time>';
			if ( $next ) {
				$html .= '<br><strong>' . esc_html__( 'Next review:', 'longevity-core' ) . '</strong> <time datetime="' . esc_attr( $next ) . '">' . esc_html( $next ) . '</time>';
			}
			$html .= '</p>';
		}
		return $html . '</aside>';
	}

	/** Render the educational medical disclaimer. */
	public static function render_medical_disclaimer(): string {
		return '<aside class="longevity-medical-disclaimer" role="note"><strong>' . esc_html__( 'Medical disclaimer:', 'longevity-core' ) . '</strong> ' . esc_html__( 'This material is educational and does not replace individualized advice, diagnosis, or treatment from a qualified healthcare professional. Seek professional guidance before changing medication, supplements, diet, or exercise—especially if pregnant, managing a health condition, or preparing for a procedure.', 'longevity-core' ) . '</aside>';
	}

	/** Render the legacy review box without changing its public contract. */
	public static function render_legacy_review_box( array $atts ): string {
		$html = '<aside class="longevity-review-box" aria-label="' . esc_attr__( 'Review summary', 'longevity-core' ) . '">';
		if ( '' !== (string) ( $atts['score'] ?? '' ) ) {
			$score = Meta_Registry::sanitize_value( 'score', $atts['score'] );
			$html .= '<div class="longevity-review-score">' . esc_html( number_format_i18n( $score, 1 ) ) . '/5</div>';
		}
		if ( '' !== (string) ( $atts['best_for'] ?? '' ) ) {
			$html .= '<p><strong>' . esc_html__( 'Best for:', 'longevity-core' ) . '</strong> ' . esc_html( (string) $atts['best_for'] ) . '</p>';
		}
		if ( '' !== (string) ( $atts['tested'] ?? '' ) ) {
			$html .= '<p><strong>' . esc_html__( 'Testing period:', 'longevity-core' ) . '</strong> ' . esc_html( (string) $atts['tested'] ) . '</p>';
		}
		return $html . '</aside>';
	}

	/** Human-readable, deliberately scoped medical-review label. */
	private static function scope_label( string $scope ): string {
		$labels = array(
			'full_article'            => __( 'Medically reviewed for the full article scope recorded by the reviewer.', 'longevity-core' ),
			'safety_only'             => __( 'Medically reviewed for safety language.', 'longevity-core' ),
			'contraindications_only'  => __( 'Medically reviewed for contraindication language.', 'longevity-core' ),
			'dosage_language_only'    => __( 'Medically reviewed for dosage-language accuracy and boundaries.', 'longevity-core' ),
			'product_accuracy_only'   => __( 'Medically reviewed for product accuracy language and non-diagnostic limitations.', 'longevity-core' ),
			'medical_disclaimer_only' => __( 'Medically reviewed only for the medical disclaimer.', 'longevity-core' ),
			'claim_ids'               => __( 'Medically reviewed only for the recorded claim IDs.', 'longevity-core' ),
		);
		return $labels[ $scope ] ?? __( 'Medically reviewed for the scope recorded on this page.', 'longevity-core' );
	}
}

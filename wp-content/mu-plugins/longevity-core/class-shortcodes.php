<?php
/**
 * Public shortcodes and trust components.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Registers backward-compatible and trust-oriented shortcodes. */
final class Shortcodes {
	/** Register shortcodes. */
	public static function init(): void {
		add_shortcode( 'affiliate_link', array( self::class, 'affiliate_link' ) );
		add_shortcode( 'medical_disclaimer', array( self::class, 'medical_disclaimer' ) );
		add_shortcode( 'review_box', array( self::class, 'review_box' ) );
		add_shortcode( 'longevity_article_meta', array( self::class, 'article_meta' ) );
		add_shortcode( 'longevity_trust_summary', array( self::class, 'trust_summary' ) );
		add_shortcode( 'longevity_reviewer_card', array( self::class, 'reviewer_card' ) );
		add_shortcode( 'longevity_test_method', array( self::class, 'test_method' ) );
		add_shortcode( 'longevity_review_score', array( self::class, 'review_score' ) );
		add_shortcode( 'longevity_corrections', array( self::class, 'corrections' ) );
		add_shortcode( 'longevity_policy_links', array( self::class, 'policy_links' ) );
		add_shortcode( 'longevity_footer_meta', array( self::class, 'footer_meta' ) );
	}

	/** Render an affiliate link while preserving the original shortcode interface. */
	public static function affiliate_link( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'url'       => '',
				'label'     => __( 'Check current price', 'longevity-core' ),
				'placement' => 'article',
			),
			$atts,
			'affiliate_link'
		);
		return Affiliate_Registry::render_link( (string) $atts['url'], (string) $atts['label'], (string) $atts['placement'] );
	}

	/** Render the medical disclaimer. */
	public static function medical_disclaimer(): string {
		return '<aside class="longevity-medical-disclaimer" role="note"><strong>' . esc_html__( 'Medical disclaimer:', 'longevity-core' ) . '</strong> ' . esc_html__( 'This material is educational and does not replace individualized advice, diagnosis, or treatment from a qualified healthcare professional. Seek professional guidance before changing medication, supplements, diet, or exercise—especially if pregnant, managing a health condition, or preparing for a procedure.', 'longevity-core' ) . '</aside>';
	}

	/** Render the legacy review box. */
	public static function review_box( array $atts ): string {
		$atts = shortcode_atts( array( 'score' => '', 'best_for' => '', 'tested' => '' ), $atts, 'review_box' );
		$html = '<aside class="longevity-review-box" aria-label="' . esc_attr__( 'Review summary', 'longevity-core' ) . '">';
		if ( '' !== (string) $atts['score'] ) {
			$score = Meta_Registry::sanitize_value( 'score', $atts['score'] );
			$html .= '<div class="longevity-review-score">' . esc_html( number_format_i18n( $score, 1 ) ) . '/5</div>';
		}
		if ( '' !== (string) $atts['best_for'] ) {
			$html .= '<p><strong>' . esc_html__( 'Best for:', 'longevity-core' ) . '</strong> ' . esc_html( (string) $atts['best_for'] ) . '</p>';
		}
		if ( '' !== (string) $atts['tested'] ) {
			$html .= '<p><strong>' . esc_html__( 'Testing period:', 'longevity-core' ) . '</strong> ' . esc_html( (string) $atts['tested'] ) . '</p>';
		}
		return $html . '</aside>';
	}

	/** Render authored, updated, reviewed, and cutoff dates. */
	public static function article_meta(): string {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}
		$items = array();
		$author_id = (int) get_post_field( 'post_author', $post_id );
		if ( $author_id ) {
			$items[] = sprintf( '<span>%s <a href="%s">%s</a></span>', esc_html__( 'By', 'longevity-core' ), esc_url( get_author_posts_url( $author_id ) ), esc_html( get_the_author_meta( 'display_name', $author_id ) ) );
		}
		$items[] = '<span>' . esc_html__( 'Published', 'longevity-core' ) . ' <time datetime="' . esc_attr( get_the_date( DATE_W3C, $post_id ) ) . '">' . esc_html( get_the_date( '', $post_id ) ) . '</time></span>';
		if ( get_the_modified_time( 'U', $post_id ) > get_the_time( 'U', $post_id ) ) {
			$items[] = '<span>' . esc_html__( 'Updated', 'longevity-core' ) . ' <time datetime="' . esc_attr( get_the_modified_date( DATE_W3C, $post_id ) ) . '">' . esc_html( get_the_modified_date( '', $post_id ) ) . '</time></span>';
		}
		$fact_date = (string) get_post_meta( $post_id, 'fact_checked_date', true );
		$fact_user = (int) get_post_meta( $post_id, 'fact_checked_by', true );
		if ( $fact_date && $fact_user ) {
			$fact_name = get_the_author_meta( 'display_name', $fact_user );
			$items[] = '<span>' . esc_html__( 'Fact-checked by', 'longevity-core' ) . ' <a href="' . esc_url( get_author_posts_url( $fact_user ) ) . '">' . esc_html( $fact_name ) . '</a> <time datetime="' . esc_attr( $fact_date ) . '">' . esc_html( $fact_date ) . '</time></span>';
		}
		$review_date = (string) get_post_meta( $post_id, 'medical_review_date', true );
		if ( $review_date && 'complete' === get_post_meta( $post_id, 'medical_review_status', true ) ) {
			$items[] = '<span>' . esc_html__( 'Medically reviewed', 'longevity-core' ) . ' <time datetime="' . esc_attr( $review_date ) . '">' . esc_html( $review_date ) . '</time></span>';
		}
		$cutoff = (string) get_post_meta( $post_id, 'evidence_cutoff_date', true );
		if ( $cutoff ) {
			$items[] = '<span>' . esc_html__( 'Evidence cutoff', 'longevity-core' ) . ' <time datetime="' . esc_attr( $cutoff ) . '">' . esc_html( $cutoff ) . '</time></span>';
		}
		return '<div class="longevity-article-meta">' . implode( '<span aria-hidden="true">·</span>', $items ) . '</div>';
	}

	/** Render evidence, scope, limitations, and disclosure summary. */
	public static function trust_summary(): string {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}
		$summary = (string) get_post_meta( $post_id, 'content_summary', true );
		$scope = (string) get_post_meta( $post_id, 'content_scope', true );
		$limitations = (string) get_post_meta( $post_id, 'content_limitations', true );
		$grade = (string) get_post_meta( $post_id, 'evidence_grade', true );
		$rationale = (string) get_post_meta( $post_id, 'evidence_grade_rationale', true );
		$relationship = (string) get_post_meta( $post_id, 'commercial_relationship', true );
		if ( '' === $summary && '' === $scope && '' === $limitations && '' === $grade && in_array( $relationship, array( '', 'none' ), true ) ) {
			return '';
		}
		$html = '<section class="longevity-trust-summary" aria-labelledby="longevity-trust-title"><h2 id="longevity-trust-title">' . esc_html__( 'At a glance', 'longevity-core' ) . '</h2>';
		if ( $summary ) {
			$html .= '<div class="longevity-bottom-line"><h3>' . esc_html__( 'Bottom line', 'longevity-core' ) . '</h3><p>' . esc_html( $summary ) . '</p></div>';
		}
		if ( $scope ) {
			$html .= '<p><strong>' . esc_html__( 'Scope:', 'longevity-core' ) . '</strong> ' . esc_html( $scope ) . '</p>';
		}
		if ( $grade ) {
			$html .= '<div class="longevity-evidence-grade"><span class="longevity-badge" data-grade="' . esc_attr( $grade ) . '">' . esc_html( sprintf( __( 'Evidence grade %s', 'longevity-core' ), $grade ) ) . '</span>';
			if ( $rationale ) {
				$html .= '<p>' . esc_html( $rationale ) . '</p>';
			}
			$html .= '</div>';
		}
		if ( $limitations ) {
			$html .= '<div class="longevity-limitations" role="note"><h3>' . esc_html__( 'Limitations and uncertainty', 'longevity-core' ) . '</h3><p>' . esc_html( $limitations ) . '</p></div>';
		}
		if ( ! in_array( $relationship, array( '', 'none' ), true ) ) {
			$labels = array( 'affiliate' => __( 'This page contains affiliate relationships.', 'longevity-core' ), 'product_supplied' => __( 'A product or access was supplied for evaluation.', 'longevity-core' ), 'sponsored' => __( 'This content has a disclosed sponsorship relationship.', 'longevity-core' ) );
			$html .= '<p class="longevity-disclosure"><strong>' . esc_html__( 'Commercial disclosure:', 'longevity-core' ) . '</strong> ' . esc_html( $labels[ $relationship ] ?? $relationship ) . ' ' . esc_html__( 'Commercial relationships do not determine editorial conclusions.', 'longevity-core' ) . '</p>';
		}
		return $html . '</section>';
	}

	/** Render scoped reviewer identity. */
	public static function reviewer_card(): string {
		$post_id = get_the_ID();
		if ( ! $post_id || 'complete' !== get_post_meta( $post_id, 'medical_review_status', true ) || ! get_post_meta( $post_id, 'medical_review_attested', true ) ) {
			return '';
		}
		$user_id = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
		if ( $user_id <= 0 || 'verified' !== get_user_meta( $user_id, 'credential_verification_status', true ) ) {
			return '';
		}
		$name        = get_the_author_meta( 'display_name', $user_id );
		$credentials = (string) get_user_meta( $user_id, 'professional_credentials', true );
		$url         = get_author_posts_url( $user_id );
		if ( '' === $name || '' === $credentials ) {
			return '';
		}
		$scope = (string) get_post_meta( $post_id, 'medical_review_scope', true );
		$date = (string) get_post_meta( $post_id, 'medical_review_date', true );
		$next = (string) get_post_meta( $post_id, 'next_medical_review_date', true );
		$name_html = $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
		$html = '<aside class="longevity-reviewer-card" aria-labelledby="longevity-reviewer-title"><h2 id="longevity-reviewer-title">' . esc_html__( 'Medical review', 'longevity-core' ) . '</h2><p><strong>' . $name_html . '</strong><br>' . esc_html( $credentials ) . '</p><p>' . esc_html( self::scope_label( $scope ) ) . '</p><p><strong>' . esc_html__( 'Reviewed:', 'longevity-core' ) . '</strong> <time datetime="' . esc_attr( $date ) . '">' . esc_html( $date ) . '</time>';
		if ( $next ) {
			$html .= '<br><strong>' . esc_html__( 'Next review:', 'longevity-core' ) . '</strong> <time datetime="' . esc_attr( $next ) . '">' . esc_html( $next ) . '</time>';
		}
		return $html . '</p></aside>';
	}

	/** Render test method summary only when testing metadata exists. */
	public static function test_method(): string {
		$post_id = get_the_ID();
		if ( ! $post_id || ! get_post_meta( $post_id, 'testing_required', true ) ) {
			return '';
		}
		$status       = (string) get_post_meta( $post_id, 'testing_status', true );
		$version      = (string) get_post_meta( $post_id, 'testing_protocol_version', true );
		$record_id    = (int) get_post_meta( $post_id, 'test_record_id', true );
		$record_valid = Review_Methodology::valid_test_record( $record_id, $version );
		if ( ! in_array( $status, array( 'complete', 'approved' ), true ) || ! $record_valid ) {
			return '<aside class="longevity-testing-note" role="note"><strong>' . esc_html__( 'Testing incomplete:', 'longevity-core' ) . '</strong> ' . esc_html__( 'This page must not imply completed hands-on testing until a version-matched, approved test record exists.', 'longevity-core' ) . '</aside>';
		}

		$method_url = (string) get_post_meta( $post_id, 'testing_methodology_url', true );
		$fields = array(
			__( 'Product', 'longevity-core' )           => get_post_meta( $record_id, 'product_name', true ),
			__( 'Protocol version', 'longevity-core' ) => $version,
			__( 'Test dates', 'longevity-core' )       => trim( (string) get_post_meta( $record_id, 'test_start_date', true ) . ' – ' . (string) get_post_meta( $record_id, 'test_end_date', true ), ' –' ),
			__( 'Testing duration', 'longevity-core' ) => get_post_meta( $post_id, 'testing_duration', true ),
			__( 'Acquisition', 'longevity-core' )      => get_post_meta( $record_id, 'acquisition_method', true ),
			__( 'Measurement equipment', 'longevity-core' ) => get_post_meta( $record_id, 'measurement_equipment', true ),
			__( 'Comparison devices', 'longevity-core' )    => get_post_meta( $record_id, 'comparison_devices', true ),
			__( 'Environment', 'longevity-core' )           => get_post_meta( $record_id, 'environment', true ),
			__( 'Failures observed', 'longevity-core' )     => get_post_meta( $record_id, 'failures', true ),
		);
		$html = '<details class="longevity-test-method" data-lel-event="review_method_open"><summary>' . esc_html__( 'How this product was tested', 'longevity-core' ) . '</summary><dl>';
		foreach ( $fields as $label => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
			}
		}
		$html .= '</dl>';
		if ( $method_url ) {
			$html .= '<p><a href="' . esc_url( $method_url ) . '" data-lel-event="methodology_download">' . esc_html__( 'Read the full methodology', 'longevity-core' ) . '</a></p>';
		}
		return $html . '<p>' . esc_html__( 'Consumer testing describes this unit and protocol. It does not establish clinical accuracy or universal outcomes.', 'longevity-core' ) . '</p></details>';
	}

	/** Render a reproducible score explanation for review posts. */
	public static function review_score(): string {
		$post_id = get_the_ID();
		if ( ! $post_id || 'review' !== get_post_type( $post_id ) ) {
			return '';
		}
		$score      = (float) get_post_meta( $post_id, 'review_score', true );
		$version    = (string) get_post_meta( $post_id, 'review_score_version', true );
		$confidence = (string) get_post_meta( $post_id, 'review_score_confidence', true );
		$dimensions = get_post_meta( $post_id, 'review_score_dimensions', true );
		if ( $score <= 0 || '' === $version || '' === $confidence || ! is_array( $dimensions ) ) {
			return '';
		}
		try {
			$calculated = Review_Methodology::calculate_score( $dimensions );
		} catch ( \InvalidArgumentException $exception ) {
			return '';
		}
		$override = (string) get_post_meta( $post_id, 'review_score_override_reason', true );
		if ( abs( (float) $calculated['score'] - $score ) > 0.01 && '' === trim( $override ) ) {
			return '';
		}

		$html = '<section class="longevity-review-score-card" aria-labelledby="longevity-score-title"><h2 id="longevity-score-title">' . esc_html__( 'Review score and confidence', 'longevity-core' ) . '</h2><p class="longevity-review-score">' . esc_html( number_format_i18n( $score, 1 ) ) . '/5</p><p><strong>' . esc_html__( 'Confidence:', 'longevity-core' ) . '</strong> ' . esc_html( $confidence ) . '<br><strong>' . esc_html__( 'Scoring model:', 'longevity-core' ) . '</strong> ' . esc_html( $version ) . '</p><div class="wp-block-table"><table><thead><tr><th scope="col">' . esc_html__( 'Dimension', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Raw score', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Weight', 'longevity-core' ) . '</th></tr></thead><tbody>';
		foreach ( $calculated['dimensions'] as $dimension ) {
			$html .= '<tr><th scope="row">' . esc_html( $dimension['name'] ) . '</th><td>' . esc_html( number_format_i18n( (float) $dimension['score'], 1 ) ) . '/5</td><td>' . esc_html( number_format_i18n( (float) $dimension['weight'], 1 ) ) . '%</td></tr>';
		}
		$html .= '</tbody></table></div>';
		if ( '' !== trim( $override ) ) {
			$html .= '<p><strong>' . esc_html__( 'Documented score adjustment:', 'longevity-core' ) . '</strong> ' . esc_html( $override ) . '</p>';
		}
		return $html . '</section>';
	}

	/** Render correction history. */
	public static function corrections(): string {
		$post_id = get_the_ID();
		return $post_id ? Corrections::render( $post_id ) : '';
	}

	/** Render portable policy navigation from page slugs. */
	public static function policy_links(): string {
		$links = array(
			'about' => __( 'About', 'longevity-core' ),
			'editorial-policy' => __( 'Editorial Policy', 'longevity-core' ),
			'medical-disclaimer' => __( 'Medical Disclaimer', 'longevity-core' ),
			'affiliate-disclosure' => __( 'Affiliate Disclosure', 'longevity-core' ),
			'corrections' => __( 'Corrections', 'longevity-core' ),
			'testing-methodology' => __( 'Testing Methodology', 'longevity-core' ),
			'privacy' => __( 'Privacy', 'longevity-core' ),
			'terms' => __( 'Terms', 'longevity-core' ),
			'contact' => __( 'Contact', 'longevity-core' ),
		);
		$html = '<nav class="longevity-policy-nav" aria-label="' . esc_attr__( 'Publication policies', 'longevity-core' ) . '"><ul>';
		foreach ( $links as $slug => $label ) {
			$page = get_page_by_path( $slug );
			$url = $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
			$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		return $html . '</ul></nav>';
	}

	/** Render footer mission and policy review information. */
	public static function footer_meta(): string {
		$year = gmdate( 'Y' );
		$reviewed = (string) get_option( 'lel_policy_review_date', '' );
		$html = '<p>' . esc_html__( 'Longevity Evidence Lab helps adults evaluate health practices and consumer products using transparent evidence reviews, reproducible testing methods, and clearly stated uncertainty.', 'longevity-core' ) . '</p>';
		$html .= '<p class="longevity-small">&copy; ' . esc_html( $year ) . ' ' . esc_html( get_bloginfo( 'name' ) ) . '. ' . esc_html__( 'Educational information only; not individualized medical advice.', 'longevity-core' );
		if ( $reviewed ) {
			$html .= ' ' . esc_html__( 'Policies last reviewed:', 'longevity-core' ) . ' <time datetime="' . esc_attr( $reviewed ) . '">' . esc_html( $reviewed ) . '</time>.';
		}
		return $html . '</p>';
	}

	/** Human-readable review scope without overstatement. */
	private static function scope_label( string $scope ): string {
		$labels = array(
			'full_article' => __( 'Medically reviewed for the full article scope recorded by the reviewer.', 'longevity-core' ),
			'safety_only' => __( 'Medically reviewed for safety language.', 'longevity-core' ),
			'contraindications_only' => __( 'Medically reviewed for contraindication language.', 'longevity-core' ),
			'dosage_language_only' => __( 'Medically reviewed for dosage-language accuracy and boundaries.', 'longevity-core' ),
			'product_accuracy_only' => __( 'Medically reviewed for product accuracy language and non-diagnostic limitations.', 'longevity-core' ),
			'medical_disclaimer_only' => __( 'Medically reviewed only for the medical disclaimer.', 'longevity-core' ),
			'claim_ids' => __( 'Medically reviewed only for the recorded claim IDs.', 'longevity-core' ),
		);
		return $labels[ $scope ] ?? __( 'Medically reviewed for the scope recorded on this page.', 'longevity-core' );
	}
}

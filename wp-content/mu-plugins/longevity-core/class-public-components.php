<?php
/**
 * Shared server-rendered public components.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Facade for domain-specific public component classes.
 *
 * All business-logic methods have been moved to:
 * - Public_Nav
 * - Public_Contact
 * - Public_Content
 * - Public_Trust
 * - Public_Rankings
 */
final class Public_Components {
	/** Register content filters used by public components. */
	public static function init(): void {
		add_filter( 'the_content', array( self::class, 'add_heading_ids' ), 12 );
	}

	/**
	 * Render visible breadcrumbs.
	 *
	 * @deprecated Use Public_Nav::render_breadcrumbs() instead.
	 */
	public static function render_breadcrumbs( int $post_id = 0 ): string {
		return Public_Nav::render_breadcrumbs( $post_id );
	}

	/**
	 * Get deterministic visible breadcrumb facts.
	 *
	 * @deprecated Use Public_Nav::breadcrumb_items() instead.
	 */
	public static function breadcrumb_items( int $post_id = 0 ): array {
		return Public_Nav::breadcrumb_items( $post_id );
	}

	/**
	 * Render author and editorial dates.
	 *
	 * @deprecated Use Public_Trust::render_article_meta() instead.
	 */
	public static function render_article_meta( int $post_id ): string {
		return Public_Trust::render_article_meta( $post_id );
	}

	/**
	 * Render evidence, scope, limitations, and commercial relationship.
	 *
	 * @deprecated Use Public_Trust::render_trust_summary() instead.
	 */
	public static function render_trust_summary( int $post_id ): string {
		return Public_Trust::render_trust_summary( $post_id );
	}

	/**
	 * Render verified scoped reviewer identity.
	 *
	 * @deprecated Use Public_Trust::render_reviewer_card() instead.
	 */
	public static function render_reviewer_card( int $post_id ): string {
		return Public_Trust::render_reviewer_card( $post_id );
	}

	/**
	 * Render topic directory with guide counts and optional review counts.
	 *
	 * @deprecated Use Public_Content::render_topic_directory() instead.
	 */
	public static function render_topic_directory(): string {
		return Public_Content::render_topic_directory();
	}

	/**
	 * Render guide archive with topic filter and sort controls.
	 *
	 * @deprecated Use Public_Content::render_guide_directory() instead.
	 */
	public static function render_guide_directory(): string {
		return Public_Content::render_guide_directory();
	}

	/**
	 * Render only categories containing at least one fully eligible ranked report.
	 *
	 * @deprecated Use Public_Rankings::render_ranking_directory() instead.
	 */
	public static function render_ranking_directory(): string {
		return Public_Rankings::render_ranking_directory();
	}

	/**
	 * Render one category's eligible reviews with safe GET controls and stable order.
	 *
	 * @deprecated Use Public_Rankings::render_ranking_list() instead.
	 */
	public static function render_ranking_list(): string {
		return Public_Rankings::render_ranking_list();
	}

	/**
	 * Render the decision-dense header for a product report.
	 *
	 * @deprecated Use Public_Rankings::render_product_report_summary() instead.
	 */
	public static function render_product_report_summary( int $post_id ): string {
		return Public_Rankings::render_product_report_summary( $post_id );
	}

	/**
	 * Render approved public-result rows without private record identifiers or raw notes.
	 *
	 * @deprecated Use Public_Rankings::render_test_results() instead.
	 */
	public static function render_test_results( int $post_id ): string {
		return Public_Rankings::render_test_results( $post_id );
	}

	/**
	 * Render the review verdict and buying-decision context without blank rows.
	 *
	 * @deprecated Use Public_Rankings::render_review_decision() instead.
	 */
	public static function render_review_decision( int $post_id ): string {
		return Public_Rankings::render_review_decision( $post_id );
	}

	/**
	 * Render a reproducible score explanation.
	 *
	 * @deprecated Use Public_Rankings::render_review_score() instead.
	 */
	public static function render_review_score( int $post_id ): string {
		return Public_Rankings::render_review_score( $post_id );
	}

	/**
	 * Render a valid, version-matched test method.
	 *
	 * @deprecated Use Public_Rankings::render_test_method() instead.
	 */
	public static function render_test_method( int $post_id ): string {
		return Public_Rankings::render_test_method( $post_id );
	}

	/**
	 * Render safe, deduplicated public sources linked to verified claims.
	 *
	 * @deprecated Use Public_Rankings::render_source_list() instead.
	 */
	public static function render_source_list( int $post_id ): string {
		return Public_Rankings::render_source_list( $post_id );
	}

	/**
	 * Render verified material claims as a public evidence matrix.
	 *
	 * @deprecated Use Public_Rankings::render_claim_evidence_matrix() instead.
	 */
	public static function render_claim_evidence_matrix( int $post_id ): string {
		return Public_Rankings::render_claim_evidence_matrix( $post_id );
	}

	/**
	 * Render a TOC only for long articles with at least three H2 headings.
	 *
	 * @deprecated Use Public_Content::render_table_of_contents() instead.
	 */
	public static function render_table_of_contents( int $post_id ): string {
		return Public_Content::render_table_of_contents( $post_id );
	}

	/**
	 * Add the same stable IDs used by the TOC while preserving manual IDs.
	 *
	 * @deprecated Use Public_Content::add_heading_ids() instead.
	 */
	public static function add_heading_ids( string $content ): string {
		return Public_Content::add_heading_ids( $content );
	}

	/**
	 * Render deterministic related content.
	 *
	 * @deprecated Use Public_Content::render_related_content() instead.
	 */
	public static function render_related_content( int $post_id, int $limit = 3 ): string {
		return Public_Content::render_related_content( $post_id, $limit );
	}

	/**
	 * Render compact metadata for a query-loop card.
	 *
	 * @deprecated Use Public_Content::render_content_card_meta() instead.
	 */
	public static function render_content_card_meta( int $post_id ): string {
		return Public_Content::render_content_card_meta( $post_id );
	}

	/**
	 * Render an allowlisted GET search/filter form.
	 *
	 * @deprecated Use Public_Content::render_search_filters() instead.
	 */
	public static function render_search_filters(): string {
		return Public_Content::render_search_filters();
	}

	/**
	 * Render public author identity without private reviewer metadata.
	 *
	 * @deprecated Use Public_Content::render_author_profile() instead.
	 */
	public static function render_author_profile(): string {
		return Public_Content::render_author_profile();
	}

	/**
	 * Render correction history through the authoritative correction service.
	 *
	 * @deprecated Use Public_Content::render_corrections() instead.
	 */
	public static function render_corrections( int $post_id ): string {
		return Public_Content::render_corrections( $post_id );
	}

	/**
	 * Render the educational medical disclaimer.
	 *
	 * @deprecated Use Public_Trust::render_medical_disclaimer() instead.
	 */
	public static function render_medical_disclaimer(): string {
		return Public_Trust::render_medical_disclaimer();
	}

	/**
	 * Render the legacy review box without changing its public contract.
	 *
	 * @deprecated Use Public_Trust::render_legacy_review_box() instead.
	 */
	public static function render_legacy_review_box( array $atts ): string {
		return Public_Trust::render_legacy_review_box( $atts );
	}

	/**
	 * Render a contact form with abuse protection.
	 *
	 * @deprecated Use Public_Contact::render_contact_form() instead.
	 */
	public static function render_contact_form(): string {
		return Public_Contact::render_contact_form();
	}

	/**
	 * Handle contact form submission.
	 *
	 * @deprecated Use Public_Contact::handle_contact_submission() instead.
	 */
	public static function handle_contact_submission(): void {
		Public_Contact::handle_contact_submission();
	}

	/**
	 * Render portable policy links.
	 *
	 * @deprecated Use Public_Nav::render_policy_links() instead.
	 */
	public static function render_policy_links(): string {
		return Public_Nav::render_policy_links();
	}

	/**
	 * Render route-aware footer navigation.
	 *
	 * @deprecated Use Public_Nav::render_footer_nav() instead.
	 */
	public static function render_footer_nav(): string {
		return Public_Nav::render_footer_nav();
	}

	/**
	 * Render footer ownership and review context.
	 *
	 * @deprecated Use Public_Nav::render_footer_meta() instead.
	 */
	public static function render_footer_meta(): string {
		return Public_Nav::render_footer_meta();
	}
}

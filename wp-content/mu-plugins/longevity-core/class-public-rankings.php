<?php
/**
 * Public rankings/testing components.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Renders ranking directory, product reports, test results, source lists. */
class Public_Rankings {
	/** Render only categories containing at least one fully eligible ranked report. */
	public static function render_ranking_directory(): string {
		$groups = Rankings::directory();
		if ( empty( $groups ) ) {
			return '<section class="longevity-ranking-empty longevity-empty-state" aria-labelledby="lel-ranking-empty"><p class="longevity-kicker">Our testing program</p><h2 id="lel-ranking-empty">Protocols define how future testing is conducted</h2><p>A published protocol describes the observations, comparisons, conditions, and scoring model. It does not mean a product has been tested. Tested presentation appears only after the completed record is approved and version-matched.</p><p><a href="' . esc_url( Routes::public_page_url( 'testing_methodology' ) ?: home_url( '/testing-methodology/' ) ) . '">' . esc_html__( 'See how testing works', 'longevity-core' ) . '</a></p></section>';
		}
		$minimum = Rankings::minimum_ranking_size();
		$enough  = array_values( array_filter( $groups, static fn( $g ) => $g['count'] >= $minimum ) );
		if ( empty( $enough ) ) {
			$count = count( $groups );
			return '<section class="longevity-ranking-empty longevity-empty-state" aria-labelledby="lel-ranking-pre"><p class="longevity-kicker">Consumer Lab rankings</p><h2 id="lel-ranking-pre">Building our comparison inventory</h2><p>' . esc_html( sprintf( _n( 'We have %d eligible tested report so far. A minimum of %d comparable reports is required before a numbered ranking is produced. Browse individual reports below instead.', 'We have %d eligible tested reports so far. A minimum of %d comparable reports is required before a numbered ranking is produced. Browse individual reports below instead.', $count, 'longevity-core' ), $count, $minimum ) ) . '</p></section>';
		}
		$html = '<section class="longevity-ranking-directory" aria-labelledby="lel-ranking-directory-title"><div class="longevity-section-header"><div><p class="longevity-kicker">Consumer Lab rankings</p><h2 id="lel-ranking-directory-title">Compare protocol-complete product reports</h2></div><p>' . esc_html( sprintf( __( 'Categories that meet the minimum of %d eligible comparable reports for a numbered ranking.', 'longevity-core' ), $minimum ) ) . '</p></div><div class="longevity-ranking-category-grid">';
		foreach ( $enough as $group ) {
			$term = $group['term'];
			$html .= '<article class="longevity-ranking-category"><div class="longevity-category-mark" aria-hidden="true">' . esc_html( strtoupper( mb_substr( $term->name, 0, 1 ) ) ) . '</div><div><p class="longevity-kicker">' . esc_html( sprintf( _n( '%d eligible report', '%d eligible reports', $group['count'], 'longevity-core' ), $group['count'] ) ) . '</p><h3><a href="' . esc_url( get_category_link( $term->term_id ) ) . '" data-lel-event="topic_open" data-topic="' . esc_attr( $term->slug ) . '" data-placement="ranking-directory">' . esc_html( $term->name ) . '</a></h3><dl class="longevity-category-facts"><div><dt>' . esc_html__( 'Top score', 'longevity-core' ) . '</dt><dd>' . esc_html( number_format_i18n( $group['highest_score'], 1 ) ) . '/5</dd></div><div><dt>' . esc_html__( 'Updated', 'longevity-core' ) . '</dt><dd><time datetime="' . esc_attr( $group['latest'] ) . '">' . esc_html( $group['latest'] ) . '</time></dd></div></dl></div></article>';
		}
		return $html . '</div></section>';
	}

	/** Render one category's eligible reviews with safe GET controls and stable order. */
	public static function render_ranking_list(): string {
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term || 'category' !== $term->taxonomy ) {
			return '';
		}
		$eligible = Rankings::eligible_in_category( (int) $term->term_id );
		if ( empty( $eligible ) ) {
			return '';
		}
		$count   = count( $eligible );
		$minimum = Rankings::minimum_ranking_size();
		if ( $count < $minimum ) {
			$html = '<section class="longevity-ranking-pre-launch" aria-labelledby="lel-ranking-pre"><div class="longevity-section-header"><div><p class="longevity-kicker">Consumer Lab ranking</p><h2 id="lel-ranking-pre">' . esc_html( $term->name ) . ' — reports only</h2></div><p>' . esc_html( sprintf( __( '%d eligible tested product found. A minimum of %d comparable reports is required before a numbered ranking is produced. Below are individual reports in the order they were last updated.', 'longevity-core' ), $count, $minimum ) ) . '</p></div><ul class="longevity-report-list">';
			foreach ( Rankings::reviews( (int) $term->term_id, 'updated', array(), $count ) as $review ) {
				$html .= '<li><a href="' . esc_url( get_permalink( $review ) ) . '">' . esc_html( get_the_title( $review ) ) . '</a> <span class="longevity-small">' . esc_html( sprintf( __( 'Score: %s/5', 'longevity-core' ), number_format_i18n( (float) get_post_meta( $review->ID, 'review_score', true ), 1 ) ) ) . '</span></li>';
			}
			return $html . '</ul></section>';
		}
		$reviews = Rankings::reviews( (int) $term->term_id );
		$sort       = Rankings::requested_sort();
		$filters    = Rankings::requested_filters();
		$confidence = array_values( array_unique( array_map( static fn( int $id ) => (string) get_post_meta( $id, 'review_score_confidence', true ), $eligible ) ) );
		$subscriptions = array_values( array_unique( array_map( static fn( int $id ) => (bool) get_post_meta( $id, 'subscription_required', true ), $eligible ) ) );
		$html = '<section class="longevity-ranking-list" aria-labelledby="lel-ranking-list-title"><div class="longevity-section-header"><div><p class="longevity-kicker">Consumer Lab ranking</p><h2 id="lel-ranking-list-title">' . esc_html( $term->name ) . ' product reports</h2></div><p>' . esc_html( sprintf( _n( '%d eligible tested product', '%d eligible tested products', $count, 'longevity-core' ), $count ) ) . '</p></div>';
		$html .= '<form class="longevity-ranking-filters" method="get" action="' . esc_url( get_category_link( $term->term_id ) ) . '"><label>' . esc_html__( 'Sort rankings', 'longevity-core' ) . '<select name="ranking_sort" data-lel-event="ranking_sort" data-category="' . esc_attr( $term->slug ) . '">' . Public_Content::options( array( 'score' => __( 'Overall score', 'longevity-core' ), 'confidence' => __( 'Confidence', 'longevity-core' ), 'updated' => __( 'Recently updated', 'longevity-core' ), 'title' => __( 'Product name', 'longevity-core' ) ), $sort ) . '</select></label>';
		if ( count( $confidence ) > 1 ) {
			$options = array( '' => __( 'All confidence levels', 'longevity-core' ) );
			foreach ( $confidence as $value ) {
				$options[ $value ] = $value;
			}
			$html .= '<label>' . esc_html__( 'Confidence', 'longevity-core' ) . '<select name="confidence" data-lel-event="comparison_filter_use" data-category="' . esc_attr( $term->slug ) . '">' . Public_Content::options( $options, $filters['confidence'] ?? '' ) . '</select></label>';
		}
		if ( count( $subscriptions ) > 1 ) {
			$html .= '<label>' . esc_html__( 'Subscription', 'longevity-core' ) . '<select name="subscription" data-lel-event="comparison_filter_use" data-category="' . esc_attr( $term->slug ) . '">' . Public_Content::options( array( '' => __( 'Any subscription status', 'longevity-core' ), 'not_required' => __( 'No subscription required', 'longevity-core' ), 'required' => __( 'Subscription required', 'longevity-core' ) ), $filters['subscription'] ?? '' ) . '</select></label>';
		}
		$html .= '<button class="wp-element-button" type="submit" data-lel-event="ranking_filter">' . esc_html__( 'Apply', 'longevity-core' ) . '</button></form>';
		if ( empty( $reviews ) ) {
			return $html . '<div class="longevity-empty-state"><h3>' . esc_html__( 'No reports match these filters', 'longevity-core' ) . '</h3><p><a href="' . esc_url( get_category_link( $term->term_id ) ) . '">' . esc_html__( 'Clear ranking filters', 'longevity-core' ) . '</a></p></div></section>';
		}
		$html .= '<div class="longevity-ranking-table-wrap"><table class="longevity-ranking-table"><caption class="screen-reader-text">' . esc_html( sprintf( __( '%s Consumer Lab ranking', 'longevity-core' ), $term->name ) ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Rank', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Product and model', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Overall score', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Confidence', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Decision context', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Report', 'longevity-core' ) . '</th></tr></thead><tbody>';
		$bands  = Rankings::assign_bands( $reviews );
		$band_index = 0;
		$threshold  = Review_Methodology::minimum_meaningful_difference();
		foreach ( $bands as $band_posts ) {
			++$band_index;
			$band_label = 1 === $band_index ? __( 'Top band', 'longevity-core' ) : sprintf( __( 'Band %d', 'longevity-core' ), $band_index );
			$is_tie     = count( $band_posts ) > 1;
			foreach ( $band_posts as $review ) {
				$score = (float) get_post_meta( $review->ID, 'review_score', true );
				$confidence_label = (string) get_post_meta( $review->ID, 'review_score_confidence', true );
				$model = (string) get_post_meta( $review->ID, 'tested_product_model', true );
				$brand = (string) get_post_meta( $review->ID, 'product_brand', true );
				$best  = (string) get_post_meta( $review->ID, 'best_for', true );
				$date  = (string) get_post_meta( $review->ID, 'last_material_update', true );
				$rank_display = $is_tie ? $band_label : (string) $band_index;
				$html .= '<tr><td data-label="' . esc_attr__( 'Rank', 'longevity-core' ) . '"><span class="longevity-rank-number">' . esc_html( $rank_display ) . '</span></td><th scope="row" data-label="' . esc_attr__( 'Product', 'longevity-core' ) . '"><a href="' . esc_url( get_permalink( $review ) ) . '">' . esc_html( get_the_title( $review ) ) . '</a><span>' . esc_html( implode( ' · ', array_filter( array( $brand, $model ) ) ) ) . '</span></th><td data-label="' . esc_attr__( 'Overall score', 'longevity-core' ) . '"><strong class="longevity-score-value">' . esc_html( number_format_i18n( $score, 1 ) ) . '</strong><span>/5</span></td><td data-label="' . esc_attr__( 'Confidence', 'longevity-core' ) . '"><span class="longevity-confidence-badge" data-confidence="' . esc_attr( sanitize_title( $confidence_label ) ) . '">' . esc_html( $confidence_label ) . '</span><span class="longevity-status-badge is-complete">' . esc_html__( 'Testing complete', 'longevity-core' ) . '</span></td><td data-label="' . esc_attr__( 'Decision context', 'longevity-core' ) . '">' . ( $best ? '<strong>' . esc_html__( 'Best for:', 'longevity-core' ) . '</strong> ' . esc_html( $best ) : '' ) . '<span>' . esc_html__( 'Updated', 'longevity-core' ) . ' <time datetime="' . esc_attr( $date ) . '">' . esc_html( $date ) . '</time></span></td><td data-label="' . esc_attr__( 'Report', 'longevity-core' ) . '"><a class="longevity-report-link" data-lel-event="ranking_report_open" href="' . esc_url( get_permalink( $review ) ) . '">' . esc_html__( 'View report', 'longevity-core' ) . '</a></td></tr>';
			}
		}
		$threshold_display = number_format_i18n( $threshold, 1 );
		return $html . '</tbody></table></div><p class="longevity-ranking-note"><strong>' . esc_html__( 'How order is determined:', 'longevity-core' ) . '</strong> ' . esc_html( sprintf( __( 'Overall score, then confidence, most recent material update, and product title. Products within %s points of each other share a ranking band and are labelled "not meaningfully different." Commercial relationships never change the score or order.', 'longevity-core' ), $threshold_display ) ) . '</p><p class="longevity-sensitivity-note"><strong>' . esc_html__( 'Scoring model:', 'longevity-core' ) . '</strong> ' . esc_html( Review_Methodology::scoring_sensitivity_note() ) . '</p></section>';
	}

	/** Render the decision-dense header for a product report. */
	public static function render_product_report_summary( int $post_id ): string {
		if ( $post_id <= 0 || 'review' !== get_post_type( $post_id ) || ! Runtime_Config::scoring_model_status()['valid'] || ! Approval_Service::is_current( $post_id, 'testing' ) || ! Approval_Service::is_current( $post_id, 'editorial' ) ) {
			return '';
		}
		$version   = (string) get_post_meta( $post_id, 'testing_protocol_version', true );
		$record_id = (int) get_post_meta( $post_id, 'test_record_id', true );
		$complete  = Review_Methodology::valid_test_record( $record_id, $version );

		$acquisition_labels = array( 'purchased' => __( 'Purchased as consumer', 'longevity-core' ), 'product_supplied' => __( 'Product supplied for evaluation', 'longevity-core' ), 'loaned' => __( 'Loaned for testing', 'longevity-core' ), 'service_access' => __( 'Service access provided', 'longevity-core' ), 'independently_verified_only' => __( 'Independently verified only', 'longevity-core' ) );
		$relationship_labels = array( 'none' => __( 'None', 'longevity-core' ), 'affiliate' => __( 'Affiliate relationships', 'longevity-core' ), 'product_supplied' => __( 'Product supplied for evaluation', 'longevity-core' ), 'sponsored' => __( 'Sponsored content', 'longevity-core' ) );

		$price_amount   = (float) get_post_meta( $post_id, 'product_price_amount', true );
		$price_currency = (string) get_post_meta( $post_id, 'product_price_currency', true );
		$price_checked  = (string) get_post_meta( $post_id, 'price_checked_date', true );
		$price_region   = (string) get_post_meta( $post_id, 'price_region', true );
		$price_parts    = array_filter( array( $price_currency, $price_amount > 0 ? number_format_i18n( $price_amount, 2 ) : '' ) );

		$fields = array(
			__( 'Verdict', 'longevity-core' )                => get_post_meta( $post_id, 'content_summary', true ),
			__( 'Best for', 'longevity-core' )               => get_post_meta( $post_id, 'best_for', true ),
			__( 'Not for', 'longevity-core' )                => get_post_meta( $post_id, 'not_for', true ),
			__( 'Price', 'longevity-core' )                  => $price_parts ? implode( ' ', $price_parts ) . ( $price_checked ? ' (' . sprintf( __( 'checked %s', 'longevity-core' ), $price_checked ) . ')' : '' ) . ( $price_region ? ' · ' . $price_region : '' ) : '',
			__( 'Subscription', 'longevity-core' )           => ( get_post_meta( $post_id, 'subscription_required', true ) ? ( sprintf( __( 'Required (%s)', 'longevity-core' ), (string) get_post_meta( $post_id, 'billing_interval', true ) ) ?: __( 'Required', 'longevity-core' ) ) : __( 'Not required', 'longevity-core' ) ),
			__( 'Model', 'longevity-core' )                  => trim( implode( ' · ', array_filter( array( (string) get_post_meta( $post_id, 'tested_product_model', true ), (string) get_post_meta( $post_id, 'product_variant', true ) ) ) ) ),
			__( 'Firmware version', 'longevity-core' )       => get_post_meta( $post_id, 'tested_firmware_version', true ),
			__( 'App version', 'longevity-core' )            => get_post_meta( $post_id, 'tested_app_version', true ),
			__( 'Test dates', 'longevity-core' )             => trim( (string) get_post_meta( $post_id, 'testing_start_date', true ) . ' – ' . (string) get_post_meta( $post_id, 'testing_end_date', true ), ' –' ),
			__( 'Acquisition', 'longevity-core' )            => self::enum_label( get_post_meta( $post_id, 'product_acquisition_method', true ), $acquisition_labels ),
			__( 'Comparison set', 'longevity-core' )         => get_post_meta( $post_id, 'comparison_set', true ),
			__( 'Account required', 'longevity-core' )       => get_post_meta( $post_id, 'subscription_required', true ) ? __( 'Yes', 'longevity-core' ) : '',
			__( 'Data export', 'longevity-core' )            => get_post_meta( $post_id, 'data_export_available', true ) ? __( 'Available', 'longevity-core' ) : '',
			__( 'Warranty checked', 'longevity-core' )       => get_post_meta( $post_id, 'warranty_checked_date', true ),
			__( 'Return policy checked', 'longevity-core' )  => get_post_meta( $post_id, 'return_policy_checked_date', true ),
			__( 'Privacy policy checked', 'longevity-core' ) => get_post_meta( $post_id, 'privacy_policy_checked_date', true ),
			__( 'Commercial relationship', 'longevity-core' ) => self::enum_label( get_post_meta( $post_id, 'commercial_relationship', true ), $relationship_labels ),
			__( 'Limitations', 'longevity-core' )            => get_post_meta( $post_id, 'content_limitations', true ),
		);
		$score      = (float) get_post_meta( $post_id, 'review_score', true );
		$confidence = (string) get_post_meta( $post_id, 'review_score_confidence', true );
		$model      = (string) get_post_meta( $post_id, 'tested_product_model', true );
		$html = '<section class="longevity-product-summary" aria-labelledby="lel-product-summary"><div class="longevity-product-identity"><p class="longevity-kicker">' . esc_html__( 'Tested product', 'longevity-core' ) . '</p><h2 id="lel-product-summary">' . esc_html( $model ?: get_the_title( $post_id ) ) . '</h2><span class="longevity-status-badge ' . ( $complete ? 'is-complete' : 'is-incomplete' ) . '">' . esc_html( $complete ? __( 'Testing complete', 'longevity-core' ) : __( 'Testing in progress', 'longevity-core' ) ) . '</span></div>';
		if ( $complete && Rankings::is_eligible( $post_id ) ) {
			$html .= '<div class="longevity-score-panel"><span>' . esc_html__( 'Overall score', 'longevity-core' ) . '</span><strong>' . esc_html( number_format_i18n( $score, 1 ) ) . '</strong><span>/5</span><span class="longevity-confidence-badge">' . esc_html( $confidence ) . '</span></div>';
		}
		$html .= '<dl class="longevity-product-facts">';
		foreach ( $fields as $label => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
			}
		}
		$categories = get_the_category( $post_id );
		if ( $categories ) {
			$html .= '<div><dt>' . esc_html__( 'Ranking category', 'longevity-core' ) . '</dt><dd><a href="' . esc_url( get_category_link( $categories[0]->term_id ) ) . '">' . esc_html( $categories[0]->name ) . '</a></dd></div>';
		}
		return $html . '</dl></section>';
	}

	/** Map an internal enum value to a reader-facing label, or return the raw value. */
	private static function enum_label( string $value, array $labels ): string {
		return $labels[ $value ] ?? $value;
	}

	/** Render approved public-result rows without private record identifiers or raw notes. */
	public static function render_test_results( int $post_id ): string {
		if ( $post_id <= 0 || 'review' !== get_post_type( $post_id ) || ! Approval_Service::is_current( $post_id, 'testing' ) ) {
			return '';
		}
		$version   = (string) get_post_meta( $post_id, 'testing_protocol_version', true );
		$record_id = (int) get_post_meta( $post_id, 'test_record_id', true );
		if ( ! Review_Methodology::valid_test_record( $record_id, $version ) ) {
			return '';
		}
		$projection = Review_Methodology::sanitize_public_results( get_post_meta( $record_id, 'public_test_results', true ) );
		$rows = $projection['rows'] ?? array();
		if ( empty( $rows ) ) {
			return '';
		}
		$deviations = trim( (string) ( $projection['deviations'] ?? '' ) );
		$failures   = trim( (string) ( $projection['failures'] ?? '' ) );
		$labels = array( 'meets' => __( 'Meets reference', 'longevity-core' ), 'partially_meets' => __( 'Partially meets', 'longevity-core' ), 'does_not_meet' => __( 'Does not meet', 'longevity-core' ), 'informational' => __( 'Informational', 'longevity-core' ), 'not_applicable' => __( 'Not applicable', 'longevity-core' ) );
		$html = '<section class="longevity-test-results" aria-labelledby="lel-test-results"><div class="longevity-section-header"><div><p class="longevity-kicker">Recorded observations</p><h2 id="lel-test-results">Structured test results</h2></div><p>' . esc_html( sprintf( __( 'These are product-unit observations recorded under protocol version %s. They are not clinical validation or health recommendations. Private notes and identifiers are not exposed.', 'longevity-core' ), $version ) ) . '</p></div><div class="longevity-table-wrap"><table><thead><tr><th scope="col">' . esc_html__( 'Metric', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Observed', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Reference', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Result', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Interpretation', 'longevity-core' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$reference = implode( ': ', array_filter( array( $row['reference_label'], $row['reference_value'] ) ) );
			$html .= '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th><td>' . esc_html( trim( $row['observed_value'] . ' ' . $row['unit'] ) ) . '</td><td>' . esc_html( $reference ?: '—' ) . '</td><td><span class="longevity-result-status" data-status="' . esc_attr( $row['status'] ) . '">' . esc_html( $labels[ $row['status'] ] ) . '</span></td><td>' . esc_html( $row['note'] ?: '—' ) . '</td></tr>';
		}
		$html .= '</tbody></table></div>';
		if ( $deviations ) {
			$html .= '<div class="longevity-test-deviations" role="note"><h3>' . esc_html__( 'Deviations from protocol', 'longevity-core' ) . '</h3><p>' . esc_html( $deviations ) . '</p></div>';
		}
		if ( $failures ) {
			$html .= '<div class="longevity-test-failures" role="alert"><h3>' . esc_html__( 'Observed failures', 'longevity-core' ) . '</h3><p>' . esc_html( $failures ) . '</p></div>';
		}
		return $html . '</section>';
	}

	/** Render the review verdict and buying-decision context without blank rows. */
	public static function render_review_decision( int $post_id ): string {
		if ( $post_id <= 0 || 'review' !== get_post_type( $post_id ) || ! Approval_Service::is_current( $post_id, 'testing' ) || ! Approval_Service::is_current( $post_id, 'editorial' ) ) {
			return '';
		}
		$fields = array(
			__( 'Verdict', 'longevity-core' )             => get_post_meta( $post_id, 'content_summary', true ),
			__( 'Best for', 'longevity-core' )            => get_post_meta( $post_id, 'best_for', true ),
			__( 'Not for', 'longevity-core' )             => get_post_meta( $post_id, 'not_for', true ),
			__( 'Tested model', 'longevity-core' )        => get_post_meta( $post_id, 'tested_product_model', true ),
			__( 'Firmware / app version', 'longevity-core' ) => trim( (string) get_post_meta( $post_id, 'tested_firmware_version', true ) . ' / ' . (string) get_post_meta( $post_id, 'tested_app_version', true ), ' /' ),
			__( 'Acquisition', 'longevity-core' )         => get_post_meta( $post_id, 'product_acquisition_method', true ),
			__( 'Test period', 'longevity-core' )         => trim( (string) get_post_meta( $post_id, 'testing_start_date', true ) . ' – ' . (string) get_post_meta( $post_id, 'testing_end_date', true ), ' –' ),
			__( 'Testing duration', 'longevity-core' )    => get_post_meta( $post_id, 'testing_duration', true ),
			__( 'Price context', 'longevity-core' )       => Public_Content::checked_context( $post_id, 'price_checked_date', 'price_region' ),
			__( 'Warranty checked', 'longevity-core' )    => get_post_meta( $post_id, 'warranty_checked_date', true ),
			__( 'Major failures', 'longevity-core' )      => get_post_meta( $post_id, 'major_failures', true ),
		);
		$fields = array_filter( $fields, static fn( $value ) => '' !== trim( (string) $value ) );
		if ( empty( $fields ) ) {
			return '';
		}
		$id   = Public_Content::unique_id( 'decision', $post_id );
		$html = '<section class="longevity-review-decision" aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html__( 'Review decision summary', 'longevity-core' ) . '</h2><dl class="longevity-review-decision-grid">';
		foreach ( $fields as $label => $value ) {
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
		}
		return $html . '</dl></section>';
	}

	/** Render a reproducible score explanation. */
	public static function render_review_score( int $post_id ): string {
		if ( $post_id <= 0 || 'review' !== get_post_type( $post_id ) || ! Runtime_Config::scoring_model_status()['valid'] || ! Approval_Service::is_current( $post_id, 'testing' ) || ! Approval_Service::is_current( $post_id, 'editorial' ) ) {
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
		$override = trim( (string) get_post_meta( $post_id, 'review_score_override_reason', true ) );
		if ( abs( (float) $calculated['score'] - $score ) > 0.01 && '' === $override ) {
			return '';
		}
		$id   = Public_Content::unique_id( 'score', $post_id );
		$html = '<section class="longevity-review-score-card" aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html__( 'Review score and confidence', 'longevity-core' ) . '</h2><p class="longevity-review-score">' . esc_html( number_format_i18n( $score, 1 ) ) . '<span class="longevity-score-scale"> / 5</span></p><p><strong>' . esc_html__( 'Confidence:', 'longevity-core' ) . '</strong> ' . esc_html( $confidence ) . '<br><strong>' . esc_html__( 'Scoring model:', 'longevity-core' ) . '</strong> ' . esc_html( $version ) . '</p><div class="longevity-table-wrap"><table><caption class="screen-reader-text">' . esc_html__( 'Weighted review score dimensions', 'longevity-core' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Dimension', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Raw score', 'longevity-core' ) . '</th><th scope="col">' . esc_html__( 'Weight', 'longevity-core' ) . '</th></tr></thead><tbody>';
		foreach ( $calculated['dimensions'] as $dimension ) {
			$html .= '<tr><th scope="row">' . esc_html( $dimension['name'] ) . '</th><td>' . esc_html( number_format_i18n( (float) $dimension['score'], 1 ) ) . '/5</td><td>' . esc_html( number_format_i18n( (float) $dimension['weight'], 1 ) ) . '%</td></tr>';
		}
		$html .= '</tbody></table></div>';
		if ( $override ) {
			$html .= '<p><strong>' . esc_html__( 'Documented score adjustment:', 'longevity-core' ) . '</strong> ' . esc_html( $override ) . '</p>';
		}
		return $html . '</section>';
	}

	/** Render a valid, version-matched test method. */
	public static function render_test_method( int $post_id ): string {
		if ( $post_id <= 0 || ! get_post_meta( $post_id, 'testing_required', true ) || ! Approval_Service::is_current( $post_id, 'testing' ) ) {
			return '';
		}
		$status       = (string) get_post_meta( $post_id, 'testing_status', true );
		$version      = (string) get_post_meta( $post_id, 'testing_protocol_version', true );
		$record_id    = (int) get_post_meta( $post_id, 'test_record_id', true );
		$record_valid = Review_Methodology::valid_test_record( $record_id, $version );
		if ( ! in_array( $status, array( 'complete', 'approved' ), true ) || ! $record_valid ) {
			return '<aside class="longevity-testing-note" role="note"><strong>' . esc_html__( 'Testing incomplete:', 'longevity-core' ) . '</strong> ' . esc_html__( 'This page must not imply completed hands-on testing until a version-matched, approved test record exists.', 'longevity-core' ) . '</aside>';
		}
		$fields = array(
			__( 'Product', 'longevity-core' )               => get_post_meta( $record_id, 'product_name', true ),
			__( 'Protocol version', 'longevity-core' )      => $version,
			__( 'Test dates', 'longevity-core' )            => trim( (string) get_post_meta( $record_id, 'test_start_date', true ) . ' – ' . (string) get_post_meta( $record_id, 'test_end_date', true ), ' –' ),
			__( 'Testing duration', 'longevity-core' )      => get_post_meta( $post_id, 'testing_duration', true ),
			__( 'Acquisition', 'longevity-core' )           => get_post_meta( $record_id, 'acquisition_method', true ),
			__( 'Measurement equipment', 'longevity-core' ) => get_post_meta( $record_id, 'measurement_equipment', true ),
			__( 'Comparison devices', 'longevity-core' )    => get_post_meta( $record_id, 'comparison_devices', true ),
			__( 'Environment', 'longevity-core' )           => get_post_meta( $record_id, 'environment', true ),
			__( 'Protocol deviations', 'longevity-core' )   => get_post_meta( $record_id, 'deviations', true ),
			__( 'Failures observed', 'longevity-core' )     => get_post_meta( $record_id, 'failures', true ),
		);
		$html = '<details class="longevity-test-method" data-lel-event="review_method_open"><summary>' . esc_html__( 'How this product was tested', 'longevity-core' ) . '</summary><dl>';
		foreach ( $fields as $label => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
			}
		}
		$html .= '</dl>';
		$method_url = (string) get_post_meta( $post_id, 'testing_methodology_url', true );
		if ( $method_url ) {
			$html .= '<p><a href="' . esc_url( $method_url ) . '" data-lel-event="methodology_download" data-placement="review-method">' . esc_html__( 'Read the full methodology', 'longevity-core' ) . '</a></p>';
		}
		return $html . '<p>' . esc_html__( 'Consumer testing describes this unit and protocol. It does not establish clinical accuracy or universal outcomes.', 'longevity-core' ) . '</p></details>';
	}

	/** Render safe, deduplicated public sources linked to verified claims. */
	public static function render_source_list( int $post_id ): string {
		$sources = Claims::public_sources_for_post( $post_id, 50 );
		if ( empty( $sources ) ) {
			return '';
		}
		$id   = Public_Content::unique_id( 'sources', $post_id );
		$html = '<section class="longevity-source-list" aria-labelledby="' . esc_attr( $id ) . '" data-lel-event="source_open" data-content-id="' . esc_attr( (string) $post_id ) . '" data-placement="source-list"><h2 id="' . esc_attr( $id ) . '">' . esc_html__( 'Sources', 'longevity-core' ) . '</h2><ol>';
		foreach ( $sources as $source ) {
			$html .= '<li><cite>' . esc_html( $source['title'] ) . '</cite>';
			$details = array_filter( array( $source['authors'], $source['publication_date'] ) );
			if ( $details ) {
				$html .= '. ' . esc_html( implode( '. ', $details ) );
			}
			if ( $source['label'] && ! in_array( $source['label'], array( $source['source_type'], $source['authors'], $source['publication_date'] ), true ) ) {
				$html .= '. <span class="longevity-source-label">' . esc_html( $source['label'] ) . '</span>';
			}
			if ( $source['jurisdiction'] ) {
				$html .= '. <span class="longevity-source-jurisdiction">' . esc_html( sprintf( __( 'Jurisdiction: %s', 'longevity-core' ), $source['jurisdiction'] ) ) . '</span>';
			}
			if ( $source['accessed_date'] ) {
				$html .= '. ' . esc_html( sprintf( __( 'Accessed %s', 'longevity-core' ), $source['accessed_date'] ) );
			}
			if ( $source['url'] ) {
				$html .= '. <a href="' . esc_url( $source['url'] ) . '" rel="external noopener" data-lel-event="outbound_citation_click">' . esc_html__( 'View source', 'longevity-core' ) . '</a>';
			} elseif ( $source['identifier'] ) {
				$html .= '. <span class="longevity-source-id">' . esc_html( $source['identifier'] ) . '</span>';
			}
			if ( $source['archive_url'] ) {
				$html .= ' <a href="' . esc_url( $source['archive_url'] ) . '" rel="external noopener" class="longevity-archive-link">' . esc_html__( 'Archive', 'longevity-core' ) . '</a>';
			}
			if ( $source['public_conflict'] ) {
				$html .= '. <span class="longevity-source-conflict">' . esc_html( $source['public_conflict'] ) . '</span>';
			}
			$html .= '</li>';
		}
		return $html . '</ol></section>';
	}

	/** Render verified material claims as a public evidence matrix. */
	public static function render_claim_evidence_matrix( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		$claims = get_posts(
			array(
				'post_type'              => 'lel_claim',
				'post_status'            => 'any',
				'posts_per_page'         => 50,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array( 'key' => 'post_id', 'value' => $post_id, 'compare' => '=', 'type' => 'NUMERIC' ),
					array( 'key' => 'verification_status', 'value' => 'verified' ),
				),
			)
		);
		if ( empty( $claims ) ) {
			return '';
		}
		$id   = Public_Content::unique_id( 'claims', $post_id );
		$html = '<section class="longevity-claim-matrix" aria-labelledby="' . esc_attr( $id ) . '" data-lel-event="claim_matrix_expand" data-content-id="' . esc_attr( (string) $post_id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html__( 'Claim-level evidence', 'longevity-core' ) . '</h2><div class="longevity-claim-table-wrapper"><table class="longevity-claim-table"><thead><tr><th>' . esc_html__( 'Claim', 'longevity-core' ) . '</th><th>' . esc_html__( 'Confidence', 'longevity-core' ) . '</th><th>' . esc_html__( 'Population', 'longevity-core' ) . '</th><th>' . esc_html__( 'Outcome', 'longevity-core' ) . '</th><th>' . esc_html__( 'Evidence design', 'longevity-core' ) . '</th><th>' . esc_html__( 'Rationale', 'longevity-core' ) . '</th><th>' . esc_html__( 'Verified', 'longevity-core' ) . '</th></tr></thead><tbody>';
		foreach ( $claims as $claim ) {
			$claim_text    = trim( (string) get_post_meta( $claim->ID, 'claim_text', true ) );
			$grade         = trim( (string) get_post_meta( $claim->ID, 'evidence_grade', true ) );
			$population    = trim( (string) get_post_meta( $claim->ID, 'population', true ) );
			$outcome       = trim( (string) get_post_meta( $claim->ID, 'outcome', true ) );
			$design        = trim( (string) get_post_meta( $claim->ID, 'evidence_design', true ) );
			$rationale     = trim( (string) get_post_meta( $claim->ID, 'evidence_notes', true ) );
			$verified_date = trim( (string) get_post_meta( $claim->ID, 'verification_date', true ) );
			$source_url    = esc_url_raw( (string) get_post_meta( $claim->ID, 'source_url', true ) );

			$grade_labels  = array( 'A' => __( 'Strong', 'longevity-core' ), 'B' => __( 'Moderate', 'longevity-core' ), 'C' => __( 'Limited', 'longevity-core' ), 'D' => __( 'Mechanistic', 'longevity-core' ), 'U' => __( 'Unclear', 'longevity-core' ) );
			$grade_display = $grade_labels[ $grade ] ?? $grade;

			$html .= '<tr>';
			$html .= '<td class="longevity-claim-text">' . esc_html( $claim_text ) . '</td>';
			$html .= '<td><span class="longevity-badge" data-grade="' . esc_attr( $grade ) . '">' . esc_html( $grade_display ) . '</span></td>';
			$html .= '<td>' . esc_html( $population ) . '</td>';
			$html .= '<td>' . esc_html( $outcome ) . '</td>';
			$html .= '<td>' . esc_html( $design ) . '</td>';
			$html .= '<td>' . esc_html( $rationale ) . '</td>';
			$html .= '<td>';
			if ( $verified_date ) {
				$html .= '<time datetime="' . esc_attr( $verified_date ) . '">' . esc_html( $verified_date ) . '</time>';
			}
			if ( $source_url ) {
				$html .= ' <a href="' . esc_url( $source_url ) . '" rel="external noopener">' . esc_html__( 'Source', 'longevity-core' ) . '</a>';
			}
			$html .= '</td>';
			$html .= '</tr>';
		}
		return $html . '</tbody></table></div></section>';
	}
}

<?php
/**
 * Shared server-rendered public components.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Renders approved public state for shortcodes, blocks, and templates. */
final class Public_Components {
	/** @var array<string, int> */
	private static array $instance_counts = array();

	/** Register content filters used by public components. */
	public static function init(): void {
		add_filter( 'the_content', array( self::class, 'add_heading_ids' ), 12 );
	}

	/** Render visible breadcrumbs from the same hierarchy used by schema. */
	public static function render_breadcrumbs( int $post_id = 0 ): string {
		$items = self::breadcrumb_items( $post_id );
		if ( count( $items ) < 2 ) {
			return '';
		}
		$html = '<nav class="longevity-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'longevity-core' ) . '"><ol>';
		$last = count( $items ) - 1;
		foreach ( $items as $index => $item ) {
			$html .= '<li>';
			if ( $index !== $last && ! empty( $item['url'] ) ) {
				$html .= '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>';
			} else {
				$html .= '<span aria-current="page">' . esc_html( $item['name'] ) . '</span>';
			}
			$html .= '</li>';
		}
		return $html . '</ol></nav>';
	}

	/** Get deterministic visible breadcrumb facts. */
	public static function breadcrumb_items( int $post_id = 0 ): array {
		$items = array( array( 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ) );
		if ( is_search() ) {
			$items[] = array( 'name' => __( 'Search', 'longevity-core' ), 'url' => '' );
			return $items;
		}
		if ( is_category() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$items[] = array( 'name' => $term->name, 'url' => '' );
			}
			return $items;
		}
		if ( is_author() ) {
			$user = get_queried_object();
			if ( $user instanceof \WP_User ) {
				$items[] = array( 'name' => $user->display_name, 'url' => '' );
			}
			return $items;
		}
		if ( is_post_type_archive( 'review' ) ) {
			$items[] = array( 'name' => __( 'Consumer Lab', 'longevity-core' ), 'url' => '' );
			return $items;
		}
		$post_id = $post_id > 0 ? $post_id : get_queried_object_id();
		if ( $post_id <= 0 ) {
			return $items;
		}
		if ( 'review' === get_post_type( $post_id ) ) {
			$items[] = array( 'name' => __( 'Consumer Lab', 'longevity-core' ), 'url' => get_post_type_archive_link( 'review' ) );
		} else {
			$categories = get_the_category( $post_id );
			if ( $categories ) {
				$items[] = array( 'name' => $categories[0]->name, 'url' => get_category_link( $categories[0]->term_id ) );
			}
		}
		$items[] = array( 'name' => get_the_title( $post_id ), 'url' => '' );
		return $items;
	}

	/** Render author and editorial dates. */
	public static function render_article_meta( int $post_id ): string {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return '';
		}
		$author_id      = (int) get_post_field( 'post_author', $post_id );
		$reviewer_id    = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
		$review_date    = (string) get_post_meta( $post_id, 'medical_review_date', true );
		$review_attested = 'complete' === get_post_meta( $post_id, 'medical_review_status', true ) && get_post_meta( $post_id, 'medical_review_attested', true );
		$fact_date      = (string) get_post_meta( $post_id, 'fact_checked_date', true );
		$fact_user      = (int) get_post_meta( $post_id, 'fact_checked_by', true );
		$cutoff         = (string) get_post_meta( $post_id, 'evidence_cutoff_date', true );
		$correction     = (string) get_post_meta( $post_id, 'correction_status', true );
		$word_count     = str_word_count( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ) );
		$reading_time   = max( 1, (int) ceil( $word_count / 200 ) );

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
		if ( $fact_date && $fact_user ) {
			$html .= '<span class="longevity-meta-factcheck">' . sprintf( '<span class="longevity-meta-label">%s</span> <a href="%s">%s</a> <time datetime="%s">%s</time>', esc_html__( 'Fact-checked by', 'longevity-core' ), esc_url( get_author_posts_url( $fact_user ) ), esc_html( get_the_author_meta( 'display_name', $fact_user ) ), esc_attr( $fact_date ), esc_html( $fact_date ) ) . '</span>';
		}
		$html .= '<span class="longevity-meta-reading">' . sprintf( '<span class="longevity-meta-label">%s</span> %s', esc_html__( 'Reading time', 'longevity-core' ), esc_html( sprintf( _n( '%s min', '%s min', $reading_time, 'longevity-core' ), number_format_i18n( $reading_time ) ) ) ) . '</span>';
		if ( 'none' !== $correction ) {
			$labels = array( 'reported' => __( 'Correction reported', 'longevity-core' ), 'investigating' => __( 'Correction under review', 'longevity-core' ), 'pending' => __( 'Correction pending', 'longevity-core' ), 'complete' => __( 'Correction published', 'longevity-core' ) );
			$html .= '<span class="longevity-meta-correction">' . esc_html( $labels[ $correction ] ?? $correction ) . '</span>';
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
		$id   = self::unique_id( 'trust', $post_id );
		$html = '<section class="longevity-trust-summary" aria-labelledby="' . esc_attr( $id ) . '" data-lel-event="evidence_summary_open"><h2 id="' . esc_attr( $id ) . '">' . esc_html__( 'At a glance', 'longevity-core' ) . '</h2>';
		if ( $summary ) {
			$html .= '<div class="longevity-bottom-line"><h3>' . esc_html__( 'Bottom line', 'longevity-core' ) . '</h3><p>' . esc_html( $summary ) . '</p></div>';
		}
		if ( $scope ) {
			$html .= '<p><strong>' . esc_html__( 'Scope:', 'longevity-core' ) . '</strong> ' . esc_html( $scope ) . '</p>';
		}
		if ( $grade ) {
			$grade_labels = array( 'A' => __( 'Strong', 'longevity-core' ), 'B' => __( 'Moderate', 'longevity-core' ), 'C' => __( 'Limited', 'longevity-core' ), 'D' => __( 'Mechanistic or anecdotal', 'longevity-core' ), 'U' => __( 'Unclear', 'longevity-core' ) );
			$html .= '<div class="longevity-evidence-grade"><span class="longevity-badge" data-grade="' . esc_attr( $grade ) . '">' . esc_html( sprintf( __( 'Confidence in the main conclusion: %s', 'longevity-core' ), $grade_labels[ $grade ] ?? __( 'Unclassified', 'longevity-core' ) ) ) . '</span>';
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
			$html  .= '<p class="longevity-disclosure"><strong>' . esc_html__( 'Commercial disclosure:', 'longevity-core' ) . '</strong> ' . esc_html( $labels[ $relationship ] ?? $relationship ) . ' ' . esc_html__( 'Commercial relationships do not determine editorial conclusions.', 'longevity-core' ) . '</p>';
		}
		return $html . '</section>';
	}

	/** Render verified scoped reviewer identity. */
	public static function render_reviewer_card( int $post_id ): string {
		if ( $post_id <= 0 || 'complete' !== get_post_meta( $post_id, 'medical_review_status', true ) || ! get_post_meta( $post_id, 'medical_review_attested', true ) ) {
			return '';
		}
		$user_id = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
		if ( $user_id <= 0 || 'verified' !== get_user_meta( $user_id, 'credential_verification_status', true ) ) {
			return '';
		}
		$name        = (string) get_the_author_meta( 'display_name', $user_id );
		$credentials = trim( (string) get_user_meta( $user_id, 'professional_credentials', true ) );
		if ( '' === $name || '' === $credentials ) {
			return '';
		}
		$id    = self::unique_id( 'reviewer', $post_id );
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

	/** Render topic directory with guide counts and optional review counts. */
	public static function render_topic_directory(): string {
		$topics = array(
			'evidence-literacy' => array(
				'label'   => __( 'Evidence Literacy', 'longevity-core' ),
				'desc'    => __( 'Learn how to evaluate health claims and interpret study quality.', 'longevity-core' ),
				'example' => __( 'How do I know whether a health claim is supported?', 'longevity-core' ),
			),
			'sleep' => array(
				'label'   => __( 'Sleep', 'longevity-core' ),
				'desc'    => __( 'Evidence-led sleep guidance and measurement literacy.', 'longevity-core' ),
				'example' => __( 'How can I improve sleep before buying a device?', 'longevity-core' ),
			),
			'movement' => array(
				'label'   => __( 'Movement', 'longevity-core' ),
				'desc'    => __( 'Resistance training, physical capacity, and healthy aging.', 'longevity-core' ),
				'example' => __( 'How should a beginner structure resistance training?', 'longevity-core' ),
			),
			'nutrition' => array(
				'label'   => __( 'Nutrition', 'longevity-core' ),
				'desc'    => __( 'Dietary patterns and foods associated with healthy aging.', 'longevity-core' ),
				'example' => __( 'Which dietary patterns have the strongest human evidence?', 'longevity-core' ),
			),
			'wearables' => array(
				'label'   => __( 'Wearables', 'longevity-core' ),
				'desc'    => __( 'Consumer measurement devices and data interpretation.', 'longevity-core' ),
				'example' => __( 'What can a sleep tracker measure reliably?', 'longevity-core' ),
			),
			'supplements' => array(
				'label'   => __( 'Supplements', 'longevity-core' ),
				'desc'    => __( 'Ingredients, labels, third-party testing, and marketing red flags.', 'longevity-core' ),
				'example' => __( 'How can I screen supplement marketing and labels?', 'longevity-core' ),
			),
		);

		$html = '<section class="longevity-topic-directory" aria-labelledby="lel-topic-directory-title"><div class="longevity-section-header"><p class="longevity-kicker">' . esc_html__( 'Topics', 'longevity-core' ) . '</p><h2 id="lel-topic-directory-title">' . esc_html__( 'Pick a topic to explore', 'longevity-core' ) . '</h2></div><div class="longevity-topic-cards">';

		$has_cards = false;
		foreach ( $topics as $slug => $topic ) {
			$term = get_term_by( 'slug', $slug, 'category' );
			if ( ! $term ) {
				continue;
			}

			$guide_count = (int) $term->count;

			$review_args = array(
				'post_type'      => 'review',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'category'       => $term->term_id,
				'meta_query'     => array(
					array(
						'key'     => 'review_score',
						'compare' => 'EXISTS',
					),
				),
			);
			$review_query = new \WP_Query( $review_args );
			$review_count = $review_query->post_count;

			if ( 0 === $guide_count && 0 === $review_count ) {
				continue;
			}
			$has_cards = true;

			$html .= '<article class="longevity-topic-card"><h3><a href="' . esc_url( get_category_link( $term->term_id ) ) . '">' . esc_html( $topic['label'] ) . '</a></h3><p class="longevity-topic-desc">' . esc_html( $topic['desc'] ) . '</p><p class="longevity-topic-example">' . esc_html( $topic['example'] ) . '</p><p class="longevity-topic-counts">';

			$counts = array();
			if ( $guide_count > 0 ) {
				$counts[] = esc_html( sprintf( _n( '%d guide', '%d guides', $guide_count, 'longevity-core' ), $guide_count ) );
			}
			if ( $review_count > 0 ) {
				$counts[] = esc_html( sprintf( _n( '%d product report', '%d product reports', $review_count, 'longevity-core' ), $review_count ) );
			}
			if ( ! empty( $counts ) ) {
				$html .= implode( ' &middot; ', $counts );
			}
			$html .= '</p></article>';
		}

		if ( ! $has_cards ) {
			return '<section class="longevity-topic-directory" aria-labelledby="lel-topic-directory-title"><div class="longevity-section-header"><p class="longevity-kicker">' . esc_html__( 'Topics', 'longevity-core' ) . '</p><h2 id="lel-topic-directory-title">' . esc_html__( 'Pick a topic to explore', 'longevity-core' ) . '</h2></div><p>' . esc_html__( 'Topics will appear here as content is published.', 'longevity-core' ) . '</p></section>';
		}

		return $html . '</div></section>';
	}

	/** Render guide archive with topic filter and sort controls. */
	public static function render_guide_directory(): string {
		$page = max( 1, (int) ( $_GET['guide_page'] ?? 1 ) );
		$sort = sanitize_key( $_GET['guide_sort'] ?? '' );
		$topic_slug = sanitize_key( $_GET['guide_topic'] ?? '' );

		$tax_query = array();
		if ( $topic_slug ) {
			$term = get_term_by( 'slug', $topic_slug, 'category' );
			if ( $term ) {
				$tax_query = array(
					array(
						'taxonomy' => 'category',
						'field'    => 'slug',
						'terms'    => $topic_slug,
					),
				);
			}
		}

		$orderby = 'date';
		$order   = 'DESC';
		if ( 'updated' === $sort ) {
			$orderby = 'modified';
		} elseif ( 'title' === $sort ) {
			$orderby = 'title';
			$order   = 'ASC';
		}

		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 12,
			'paged'          => $page,
			'tax_query'      => $tax_query,
			'orderby'        => $orderby,
			'order'          => $order,
			'no_found_rows'  => false,
		);
		$query = new \WP_Query( $args );

		$html = '<section class="longevity-guide-directory" aria-labelledby="lel-guide-directory-title"><div class="longevity-section-header"><p class="longevity-kicker">' . esc_html__( 'Guides', 'longevity-core' ) . '</p><h2 id="lel-guide-directory-title">' . esc_html__( 'Evidence guides', 'longevity-core' ) . '</h2></div>';

		$html .= '<form class="longevity-guide-filters" method="get" action=""><label>' . esc_html__( 'Topic', 'longevity-core' ) . ' <select name="guide_topic">';
		$html .= '<option value="">' . esc_html__( 'All topics', 'longevity-core' ) . '</option>';
		$categories = get_categories( array( 'hide_empty' => true ) );
		foreach ( $categories as $cat ) {
			$selected = selected( $topic_slug, $cat->slug, false );
			$html .= '<option value="' . esc_attr( $cat->slug ) . '" ' . $selected . '>' . esc_html( $cat->name ) . '</option>';
		}
		$html .= '</select></label>';

		$html .= '<label>' . esc_html__( 'Sort', 'longevity-core' ) . ' <select name="guide_sort">';
		$html .= '<option value="">' . esc_html__( 'Newest', 'longevity-core' ) . '</option>';
		$html .= '<option value="updated" ' . selected( 'updated', $sort, false ) . '>' . esc_html__( 'Recently updated', 'longevity-core' ) . '</option>';
		$html .= '<option value="title" ' . selected( 'title', $sort, false ) . '>' . esc_html__( 'Title A–Z', 'longevity-core' ) . '</option>';
		$html .= '</select></label>';
		$html .= '<button class="wp-element-button" type="submit">' . esc_html__( 'Apply', 'longevity-core' ) . '</button>';
		if ( $topic_slug || $sort ) {
			$html .= ' <a class="longevity-clear-filters" href="' . esc_url( home_url( '/guides/' ) ) . '">' . esc_html__( 'Clear filters', 'longevity-core' ) . '</a>';
		}
		$html .= '</form>';

		if ( $query->have_posts() ) {
			$html .= '<div class="longevity-card-grid">';
			while ( $query->have_posts() ) {
				$query->the_post();
				$html .= '<article class="longevity-card"><h3><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
				$excerpt = get_the_excerpt();
				if ( $excerpt ) {
					$html .= '<p>' . esc_html( wp_trim_words( $excerpt, 30 ) ) . '</p>';
				}
				$cats = get_the_category();
				if ( $cats ) {
					$html .= '<p class="longevity-small">' . esc_html( $cats[0]->name ) . ' &middot; ' . esc_html( get_the_modified_date() ) . '</p>';
				}
				$html .= '</article>';
			}
			wp_reset_postdata();

			$html .= '</div>';

			$total   = $query->max_num_pages;
			if ( $total > 1 ) {
				$html .= '<nav class="longevity-pagination" aria-label="' . esc_attr__( 'Guide pagination', 'longevity-core' ) . '">';
				for ( $i = 1; $i <= $total; ++$i ) {
					$link = add_query_arg( 'guide_page', $i );
					if ( $topic_slug ) {
						$link = add_query_arg( 'guide_topic', $topic_slug, $link );
					}
					if ( $sort ) {
						$link = add_query_arg( 'guide_sort', $sort, $link );
					}
					$html .= '<a class="' . ( $i === $page ? 'longevity-current' : '' ) . '" href="' . esc_url( $link ) . '">' . $i . '</a> ';
				}
				$html .= '</nav>';
			}
		} else {
			$html .= '<div class="longevity-empty-state"><p>' . esc_html__( 'No guides match the selected filters.', 'longevity-core' ) . '</p>';
			if ( $topic_slug ) {
				$html .= ' <a href="' . esc_url( home_url( '/guides/' ) ) . '">' . esc_html__( 'Clear filters and browse all guides.', 'longevity-core' ) . '</a>';
			}
			$html .= '</div>';
		}

		return $html . '</section>';
	}

	/** Render only categories containing at least one fully eligible ranked report. */
	public static function render_ranking_directory(): string {
		$groups = Rankings::directory();
		if ( empty( $groups ) ) {
			return '<section class="longevity-ranking-empty longevity-empty-state" aria-labelledby="lel-ranking-empty"><p class="longevity-kicker">Our testing program</p><h2 id="lel-ranking-empty">Protocols define how future testing is conducted</h2><p>A published protocol describes the observations, comparisons, conditions, and scoring model. It does not mean a product has been tested. Tested presentation appears only after the completed record is approved and version-matched.</p><p><a href="' . esc_url( home_url( '/testing-methodology/' ) ) . '">' . esc_html__( 'See how testing works', 'longevity-core' ) . '</a></p></section>';
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
			$html .= '<article class="longevity-ranking-category"><div class="longevity-category-mark" aria-hidden="true">' . esc_html( strtoupper( mb_substr( $term->name, 0, 1 ) ) ) . '</div><div><p class="longevity-kicker">' . esc_html( sprintf( _n( '%d eligible report', '%d eligible reports', $group['count'], 'longevity-core' ), $group['count'] ) ) . '</p><h3><a href="' . esc_url( get_category_link( $term->term_id ) ) . '">' . esc_html( $term->name ) . '</a></h3><dl class="longevity-category-facts"><div><dt>' . esc_html__( 'Top score', 'longevity-core' ) . '</dt><dd>' . esc_html( number_format_i18n( $group['highest_score'], 1 ) ) . '/5</dd></div><div><dt>' . esc_html__( 'Updated', 'longevity-core' ) . '</dt><dd><time datetime="' . esc_attr( $group['latest'] ) . '">' . esc_html( $group['latest'] ) . '</time></dd></div></dl></div></article>';
		}
		return $html . '</div></section>';
	}

	/** Render one category's eligible reviews with safe GET controls and stable order. */
	public static function render_ranking_list(): string {
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term || 'category' !== $term->taxonomy ) {
			return '';
		}
		$all     = Rankings::reviews( (int) $term->term_id, 'score', array(), 100 );
		if ( empty( $all ) ) {
			return '';
		}
		$minimum = Rankings::minimum_ranking_size();
		if ( count( $all ) < $minimum ) {
			$html = '<section class="longevity-ranking-pre-launch" aria-labelledby="lel-ranking-pre"><div class="longevity-section-header"><div><p class="longevity-kicker">Consumer Lab ranking</p><h2 id="lel-ranking-pre">' . esc_html( $term->name ) . ' — reports only</h2></div><p>' . esc_html( sprintf( __( '%d eligible tested product found. A minimum of %d comparable reports is required before a numbered ranking is produced. Below are individual reports in the order they were last updated.', 'longevity-core' ), count( $all ), $minimum ) ) . '</p></div><ul class="longevity-report-list">';
			foreach ( $all as $review ) {
				$html .= '<li><a href="' . esc_url( get_permalink( $review ) ) . '">' . esc_html( get_the_title( $review ) ) . '</a> <span class="longevity-small">' . esc_html( sprintf( __( 'Score: %s/5', 'longevity-core' ), number_format_i18n( (float) get_post_meta( $review->ID, 'review_score', true ), 1 ) ) ) . '</span></li>';
			}
			return $html . '</ul></section>';
		}
		$reviews = Rankings::reviews( (int) $term->term_id );
		$sort       = Rankings::requested_sort();
		$filters    = Rankings::requested_filters();
		$confidence = array_values( array_unique( array_map( static fn( $post ) => (string) get_post_meta( $post->ID, 'review_score_confidence', true ), $all ) ) );
		$subscriptions = array_values( array_unique( array_map( static fn( $post ) => (bool) get_post_meta( $post->ID, 'subscription_required', true ), $all ) ) );
		$html = '<section class="longevity-ranking-list" aria-labelledby="lel-ranking-list-title"><div class="longevity-section-header"><div><p class="longevity-kicker">Consumer Lab ranking</p><h2 id="lel-ranking-list-title">' . esc_html( $term->name ) . ' product reports</h2></div><p>' . esc_html( sprintf( _n( '%d eligible tested product', '%d eligible tested products', count( $all ), 'longevity-core' ), count( $all ) ) ) . '</p></div>';
		$html .= '<form class="longevity-ranking-filters" method="get" action="' . esc_url( get_category_link( $term->term_id ) ) . '"><label>' . esc_html__( 'Sort rankings', 'longevity-core' ) . '<select name="ranking_sort" data-lel-event="ranking_sort" data-category="' . esc_attr( $term->slug ) . '">' . self::options( array( 'score' => __( 'Overall score', 'longevity-core' ), 'confidence' => __( 'Confidence', 'longevity-core' ), 'updated' => __( 'Recently updated', 'longevity-core' ), 'title' => __( 'Product name', 'longevity-core' ) ), $sort ) . '</select></label>';
		if ( count( $confidence ) > 1 ) {
			$options = array( '' => __( 'All confidence levels', 'longevity-core' ) );
			foreach ( $confidence as $value ) {
				$options[ $value ] = $value;
			}
			$html .= '<label>' . esc_html__( 'Confidence', 'longevity-core' ) . '<select name="confidence" data-lel-event="comparison_filter_use" data-category="' . esc_attr( $term->slug ) . '">' . self::options( $options, $filters['confidence'] ?? '' ) . '</select></label>';
		}
		if ( count( $subscriptions ) > 1 ) {
			$html .= '<label>' . esc_html__( 'Subscription', 'longevity-core' ) . '<select name="subscription" data-lel-event="comparison_filter_use" data-category="' . esc_attr( $term->slug ) . '">' . self::options( array( '' => __( 'Any subscription status', 'longevity-core' ), 'not_required' => __( 'No subscription required', 'longevity-core' ), 'required' => __( 'Subscription required', 'longevity-core' ) ), $filters['subscription'] ?? '' ) . '</select></label>';
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
		if ( $post_id <= 0 || 'review' !== get_post_type( $post_id ) ) {
			return '';
		}
		$version   = (string) get_post_meta( $post_id, 'testing_protocol_version', true );
		$record_id = (int) get_post_meta( $post_id, 'test_record_id', true );
		$complete  = in_array( get_post_meta( $post_id, 'testing_status', true ), array( 'complete', 'approved' ), true ) && Review_Methodology::valid_test_record( $record_id, $version );

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
		if ( $post_id <= 0 || 'review' !== get_post_type( $post_id ) ) {
			return '';
		}
		$version   = (string) get_post_meta( $post_id, 'testing_protocol_version', true );
		$record_id = (int) get_post_meta( $post_id, 'test_record_id', true );
		if ( ! Review_Methodology::valid_test_record( $record_id, $version ) ) {
			return '';
		}
		$rows = Review_Methodology::sanitize_public_results( get_post_meta( $record_id, 'public_test_results', true ) );
		if ( empty( $rows ) ) {
			return '';
		}
		$deviations = trim( (string) get_post_meta( $record_id, 'deviations', true ) );
		$failures   = trim( (string) get_post_meta( $record_id, 'failures', true ) );
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
		if ( $post_id <= 0 || 'review' !== get_post_type( $post_id ) ) {
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
			__( 'Price context', 'longevity-core' )       => self::checked_context( $post_id, 'price_checked_date', 'price_region' ),
			__( 'Warranty checked', 'longevity-core' )    => get_post_meta( $post_id, 'warranty_checked_date', true ),
			__( 'Major failures', 'longevity-core' )      => get_post_meta( $post_id, 'major_failures', true ),
		);
		$fields = array_filter( $fields, static fn( $value ) => '' !== trim( (string) $value ) );
		if ( empty( $fields ) ) {
			return '';
		}
		$id   = self::unique_id( 'decision', $post_id );
		$html = '<section class="longevity-review-decision" aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html__( 'Review decision summary', 'longevity-core' ) . '</h2><dl class="longevity-review-decision-grid">';
		foreach ( $fields as $label => $value ) {
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
		}
		return $html . '</dl></section>';
	}

	/** Render a reproducible score explanation. */
	public static function render_review_score( int $post_id ): string {
		if ( $post_id <= 0 || 'review' !== get_post_type( $post_id ) ) {
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
		$id   = self::unique_id( 'score', $post_id );
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
		if ( $post_id <= 0 || ! get_post_meta( $post_id, 'testing_required', true ) ) {
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
		$id   = self::unique_id( 'sources', $post_id );
		$html = '<section class="longevity-source-list" aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html__( 'Sources', 'longevity-core' ) . '</h2><ol>';
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
		$id   = self::unique_id( 'claims', $post_id );
		$html = '<section class="longevity-claim-matrix" aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html__( 'Claim-level evidence', 'longevity-core' ) . '</h2><div class="longevity-claim-table-wrapper"><table class="longevity-claim-table"><thead><tr><th>' . esc_html__( 'Claim', 'longevity-core' ) . '</th><th>' . esc_html__( 'Confidence', 'longevity-core' ) . '</th><th>' . esc_html__( 'Population', 'longevity-core' ) . '</th><th>' . esc_html__( 'Outcome', 'longevity-core' ) . '</th><th>' . esc_html__( 'Evidence design', 'longevity-core' ) . '</th><th>' . esc_html__( 'Rationale', 'longevity-core' ) . '</th><th>' . esc_html__( 'Verified', 'longevity-core' ) . '</th></tr></thead><tbody>';
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

	/** Render a TOC only for long articles with at least three H2 headings. */
	public static function render_table_of_contents( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || str_word_count( wp_strip_all_tags( $post->post_content ) ) < 600 ) {
			return '';
		}
		$headings = self::extract_headings( $post->post_content );
		if ( count( array_filter( $headings, static fn( $heading ) => 2 === $heading['level'] ) ) < 3 ) {
			return '';
		}
		$html = '<nav class="longevity-toc" aria-label="' . esc_attr__( 'On this page', 'longevity-core' ) . '"><strong>' . esc_html__( 'On this page', 'longevity-core' ) . '</strong><ol>';
		foreach ( $headings as $heading ) {
			$class = 3 === $heading['level'] ? ' class="is-subheading"' : '';
			$html .= '<li' . $class . '><a href="#' . esc_attr( $heading['id'] ) . '">' . esc_html( $heading['text'] ) . '</a></li>';
		}
		return $html . '</ol></nav>';
	}

	/** Add the same stable IDs used by the TOC while preserving manual IDs. */
	public static function add_heading_ids( string $content ): string {
		if ( ! is_singular( array( 'post', 'review' ) ) || false === stripos( $content, '<h2' ) ) {
			return $content;
		}
		$headings = self::extract_headings( $content );
		$offset   = 0;
		return (string) preg_replace_callback(
			'/<h([23])([^>]*)>(.*?)<\/h\1>/is',
			static function ( array $matches ) use ( $headings, &$offset ): string {
				$heading = $headings[ $offset ] ?? null;
				++$offset;
				if ( ! $heading || preg_match( "/\\sid=(['\"])[^'\"]+\\1/i", $matches[2] ) ) {
					return $matches[0];
				}
				return '<h' . $matches[1] . $matches[2] . ' id="' . esc_attr( $heading['id'] ) . '">' . $matches[3] . '</h' . $matches[1] . '>';
			},
			$content
		);
	}

	/** Render deterministic related content. */
	public static function render_related_content( int $post_id, int $limit = 3 ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		$manual_ids = trim( (string) get_post_meta( $post_id, '_longevity_related_post_ids', true ) );
		$posts      = array();
		if ( $manual_ids ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $manual_ids ) ) );
			$ids = array_diff( $ids, array( $post_id ) );
			if ( $ids ) {
				$posts = get_posts(
					array(
						'post_type'              => array( 'post', 'review' ),
						'post_status'            => 'publish',
						'post__in'               => array_values( $ids ),
						'posts_per_page'         => min( 6, max( 1, $limit ) ),
						'orderby'                => 'post__in',
						'ignore_sticky_posts'    => true,
						'no_found_rows'          => true,
						'update_post_term_cache' => false,
					)
				);
			}
		}
		if ( empty( $posts ) ) {
			$categories = wp_get_post_categories( $post_id );
			$tags       = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
			$args       = array(
				'post_type'           => array( 'post', 'review' ),
				'post_status'         => 'publish',
				'post__not_in'        => array( $post_id ),
				'posts_per_page'      => min( 6, max( 1, $limit ) ),
				'orderby'             => array( 'date' => 'DESC', 'ID' => 'DESC' ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			);
			if ( $categories ) {
				$args['category__in'] = $categories;
			} elseif ( $tags ) {
				$args['tag__in'] = $tags;
			} else {
				$args['post_type'] = get_post_type( $post_id );
			}
			$posts = get_posts( $args );
		}
		if ( empty( $posts ) ) {
			return '';
		}
		$id   = self::unique_id( 'related', $post_id );
		$html = '<section class="longevity-related-content" aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html__( 'Continue exploring', 'longevity-core' ) . '</h2><ul>';
		foreach ( $posts as $related ) {
			$html .= '<li><a href="' . esc_url( get_permalink( $related ) ) . '">' . esc_html( get_the_title( $related ) ) . '</a> <span class="longevity-small">' . esc_html( 'review' === $related->post_type ? __( 'Consumer Lab review', 'longevity-core' ) : __( 'Evidence guide', 'longevity-core' ) ) . '</span></li>';
		}
		return $html . '</ul></section>';
	}

	/** Render compact metadata for a query-loop card. */
	public static function render_content_card_meta( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		$items   = array();
		$items[] = '<span><strong>' . esc_html( 'review' === get_post_type( $post_id ) ? __( 'Review', 'longevity-core' ) : __( 'Guide', 'longevity-core' ) ) . '</strong></span>';
		$items[] = '<time datetime="' . esc_attr( get_the_modified_date( DATE_W3C, $post_id ) ) . '">' . esc_html( sprintf( __( 'Updated %s', 'longevity-core' ), get_the_modified_date( '', $post_id ) ) ) . '</time>';
		$grade = (string) get_post_meta( $post_id, 'evidence_grade', true );
		if ( $grade ) {
			$grade_labels = array( 'A' => __( 'Strong', 'longevity-core' ), 'B' => __( 'Moderate', 'longevity-core' ), 'C' => __( 'Limited', 'longevity-core' ), 'D' => __( 'Mechanistic', 'longevity-core' ), 'U' => __( 'Unclear', 'longevity-core' ) );
			$items[] = '<span>' . esc_html( sprintf( __( 'Main conclusion: %s', 'longevity-core' ), $grade_labels[ $grade ] ?? __( 'Unclassified', 'longevity-core' ) ) ) . '</span>';
		}
		if ( 'review' === get_post_type( $post_id ) && in_array( get_post_meta( $post_id, 'testing_status', true ), array( 'complete', 'approved' ), true ) && Review_Methodology::valid_test_record( (int) get_post_meta( $post_id, 'test_record_id', true ), (string) get_post_meta( $post_id, 'testing_protocol_version', true ) ) ) {
			$items[] = '<span>' . esc_html__( 'Tested', 'longevity-core' ) . '</span>';
		}
		if ( 'complete' === get_post_meta( $post_id, 'medical_review_status', true ) && get_post_meta( $post_id, 'medical_review_attested', true ) ) {
			$items[] = '<span>' . esc_html__( 'Medical review recorded', 'longevity-core' ) . '</span>';
		}
		return '<div class="longevity-card-meta">' . implode( '', $items ) . '</div>';
	}

	/** Render an allowlisted GET search/filter form. */
	public static function render_search_filters(): string {
		$query        = get_search_query();
		$content_type = Content_Discovery::requested_content_type();
		$sort         = Content_Discovery::requested_sort();
		$category     = Content_Discovery::requested_category();
		$count        = isset( $GLOBALS['wp_query'] ) ? (int) $GLOBALS['wp_query']->found_posts : 0;
		$html         = '<p class="longevity-result-count" aria-live="polite">' . esc_html( sprintf( _n( '%s result', '%s results', $count, 'longevity-core' ), number_format_i18n( $count ) ) ) . '</p><form class="longevity-search-form" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '">';
		$html        .= '<label>' . esc_html__( 'Search terms', 'longevity-core' ) . '<input type="search" name="s" value="' . esc_attr( $query ) . '"></label>';
		$html        .= '<label>' . esc_html__( 'Content type', 'longevity-core' ) . '<select name="content_type">' . self::options( array( 'all' => __( 'All content', 'longevity-core' ), 'guide' => __( 'Evidence guides', 'longevity-core' ), 'review' => __( 'Consumer Lab reviews', 'longevity-core' ) ), $content_type ) . '</select></label>';

		$cat_options = array( '' => __( 'All topics', 'longevity-core' ) );
		$categories  = get_categories( array( 'hide_empty' => false ) );
		foreach ( $categories as $cat ) {
			$cat_options[ $cat->slug ] = $cat->name;
		}
		$html .= '<label>' . esc_html__( 'Topic', 'longevity-core' ) . '<select name="category">' . self::options( $cat_options, $category ) . '</select></label>';

		$html        .= '<label>' . esc_html__( 'Sort', 'longevity-core' ) . '<select name="sort">' . self::options( array( 'relevance' => __( 'Relevance', 'longevity-core' ), 'newest' => __( 'Newest', 'longevity-core' ), 'updated' => __( 'Recently updated', 'longevity-core' ) ), $sort ) . '</select></label>';
		$has_filters  = ( 'all' !== $content_type || '' !== $category || 'relevance' !== $sort );
		if ( $has_filters ) {
			$html .= ' <a class="longevity-clear-filters" href="' . esc_url( home_url( '/?s=' . rawurlencode( $query ) ) ) . '">' . esc_html__( 'Clear filters', 'longevity-core' ) . '</a>';
		}
		return $html . '<button type="submit" class="wp-element-button">' . esc_html__( 'Apply filters', 'longevity-core' ) . '</button></form>';
	}

	/** Render public author identity without private reviewer metadata. */
	public static function render_author_profile(): string {
		$user = get_queried_object();
		if ( ! $user instanceof \WP_User ) {
			return '';
		}
		$name        = $user->display_name;
		$bio         = (string) get_the_author_meta( 'description', $user->ID );
		$credentials = 'verified' === get_user_meta( $user->ID, 'credential_verification_status', true ) ? (string) get_user_meta( $user->ID, 'professional_credentials', true ) : '';
		$scope       = $credentials ? (string) get_user_meta( $user->ID, 'review_scope', true ) : '';
		$conflict    = (string) get_user_meta( $user->ID, 'conflict_disclosure', true );
		$html        = '<header class="longevity-author-profile"><h1>' . esc_html( $name ) . '</h1>';
		if ( $credentials ) {
			$html .= '<p><strong>' . esc_html__( 'Verified professional credentials:', 'longevity-core' ) . '</strong> ' . esc_html( $credentials ) . '</p>';
		}
		if ( $bio ) {
			$html .= '<p>' . esc_html( $bio ) . '</p>';
		}
		if ( $scope ) {
			$html .= '<p><strong>' . esc_html__( 'Qualified review scope:', 'longevity-core' ) . '</strong> ' . esc_html( $scope ) . '</p>';
		}
		if ( $conflict ) {
			$html .= '<p><strong>' . esc_html__( 'Public conflict disclosure:', 'longevity-core' ) . '</strong> ' . esc_html( $conflict ) . '</p>';
		}
		return $html . '</header>';
	}

	/** Render correction history through the authoritative correction service. */
	public static function render_corrections( int $post_id ): string {
		return $post_id > 0 ? Corrections::render( $post_id ) : '';
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

	/**
	 * Resolve the client IP address.
	 *
	 * Respects X-Forwarded-For only when REMOTE_ADDR matches a trusted proxy
	 * defined via the LONGEVITY_TRUSTED_PROXIES constant. Without trusted-proxy
	 * configuration, returns REMOTE_ADDR directly.
	 */
	private static function get_client_ip(): string {
		$trusted_proxies = defined( 'LONGEVITY_TRUSTED_PROXIES' ) && is_array( LONGEVITY_TRUSTED_PROXIES ) ? LONGEVITY_TRUSTED_PROXIES : array();
		$remote_addr     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		if ( $trusted_proxies && in_array( $remote_addr, $trusted_proxies, true ) ) {
			$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
			if ( '' !== $forwarded ) {
				$ips = explode( ',', $forwarded );
				return trim( (string) end( $ips ) );
			}
		}
		return $remote_addr;
	}

	/** Render a contact form with abuse protection. */
	public static function render_contact_form(): string {
		$ip      = self::get_client_ip();
		$blocked = get_transient( 'lel_contact_block_' . $ip );

		if ( $blocked ) {
			return '<aside class="longevity-contact-blocked" role="alert"><p>' . esc_html__( 'Too many submissions from this IP address. Please try again later.', 'longevity-core' ) . '</p></aside>';
		}

		wp_enqueue_script( 'longevity-contact-form', LONGEVITY_CORE_URL . 'assets/contact-form.js', array(), LONGEVITY_CORE_VERSION, true );

		$nonce = wp_create_nonce( 'longevity_contact' );
		$api_url = rest_url( 'longevity/v1/contact' );

		$html = '<form class="longevity-contact-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="longevity_contact_submit">';
		$html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
		$html .= '<div style="position:absolute;left:-9999px" aria-hidden="true"><label for="longevity-website">' . esc_html__( 'Website', 'longevity-core' ) . '</label><input type="text" name="longevity_website" id="longevity-website" tabindex="-1" autocomplete="off"></div>';

		$html .= '<p><label for="longevity-contact-name">' . esc_html__( 'Name', 'longevity-core' ) . ' <span class="required">*</span></label>';
		$html .= '<input type="text" name="longevity_contact_name" id="longevity-contact-name" required maxlength="100"></p>';

		$html .= '<p><label for="longevity-contact-email">' . esc_html__( 'Email', 'longevity-core' ) . ' <span class="required">*</span></label>';
		$html .= '<input type="email" name="longevity_contact_email" id="longevity-contact-email" required maxlength="254"></p>';

		$html .= '<p><label for="longevity-contact-subject">' . esc_html__( 'Subject', 'longevity-core' ) . ' <span class="required">*</span></label>';
		$html .= '<select name="longevity_contact_subject" id="longevity-contact-subject" required>';
		$html .= '<option value="">' . esc_html__( 'Select a subject', 'longevity-core' ) . '</option>';
		$html .= '<option value="general">' . esc_html__( 'General inquiry', 'longevity-core' ) . '</option>';
		$html .= '<option value="correction">' . esc_html__( 'Report a correction', 'longevity-core' ) . '</option>';
		$html .= '<option value="privacy">' . esc_html__( 'Privacy request', 'longevity-core' ) . '</option>';
		$html .= '<option value="commercial">' . esc_html__( 'Commercial inquiry', 'longevity-core' ) . '</option>';
		$html .= '<option value="other">' . esc_html__( 'Other', 'longevity-core' ) . '</option>';
		$html .= '</select></p>';

		$html .= '<p><label for="longevity-contact-message">' . esc_html__( 'Message', 'longevity-core' ) . ' <span class="required">*</span></label>';
		$html .= '<textarea name="longevity_contact_message" id="longevity-contact-message" required rows="8" maxlength="5000"></textarea></p>';

		$html .= '<p><button type="submit" class="wp-element-button">' . esc_html__( 'Send message', 'longevity-core' ) . '</button></p>';
		$html .= '<p class="longevity-small">' . esc_html__( 'This form is protected by rate limiting. Your IP address and submission time are recorded for abuse prevention and will not be used for any other purpose.', 'longevity-core' ) . '</p>';
		$html .= '</form>';

		return $html;
	}

	/** Handle contact form submission. */
	public static function handle_contact_submission(): void {
		$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'longevity_contact' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'longevity-core' ), 403 );
		}

		$honeypot = sanitize_text_field( wp_unslash( $_POST['longevity_website'] ?? '' ) );
		if ( '' !== $honeypot ) {
			wp_die( esc_html__( 'Submission rejected.', 'longevity-core' ), 400 );
		}

		$ip  = self::get_client_ip();
		$key = 'lel_contact_count_' . $ip;
		$count = (int) get_transient( $key );
		if ( $count >= 5 ) {
			set_transient( 'lel_contact_block_' . $ip, '1', HOUR_IN_SECONDS );
			wp_die( esc_html__( 'Too many submissions. Please try again later.', 'longevity-core' ), 429 );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$name    = sanitize_text_field( wp_unslash( $_POST['longevity_contact_name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['longevity_contact_email'] ?? '' ) );
		$subject = sanitize_text_field( wp_unslash( $_POST['longevity_contact_subject'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['longevity_contact_message'] ?? '' ) );

		if ( '' === $name || '' === $email || '' === $subject || '' === $message ) {
			wp_die( esc_html__( 'All required fields must be completed.', 'longevity-core' ), 400 );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'longevity_message',
				'post_status'  => 'private',
				'post_title'   => sprintf( '[%s] %s', $subject, $name ),
				'post_content' => $message,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html__( 'Could not save your message. Please try again later.', 'longevity-core' ), 500 );
		}

		update_post_meta( $post_id, 'contact_subject', $subject );
		update_post_meta( $post_id, 'contact_email', $email );
		update_post_meta( $post_id, 'contact_name', $name );
		update_post_meta( $post_id, 'contact_ip', $ip );
		update_post_meta( $post_id, 'contact_submitted', gmdate( DATE_ATOM ) );

		if ( 'correction' === $subject ) {
			update_post_meta( $post_id, 'contact_type', 'correction_report' );
		}

		$redirect = home_url( '/contact/?submitted=1' );
		wp_safe_redirect( $redirect );
		exit;
	}

	/** Render portable policy links. */
	public static function render_policy_links(): string {
		$links = array( 'about' => __( 'About', 'longevity-core' ), 'editorial-policy' => __( 'Editorial Policy', 'longevity-core' ), 'medical-disclaimer' => __( 'Medical Disclaimer', 'longevity-core' ), 'affiliate-disclosure' => __( 'Affiliate Disclosure', 'longevity-core' ), 'corrections' => __( 'Corrections', 'longevity-core' ), 'testing-methodology' => __( 'Testing Methodology', 'longevity-core' ), 'privacy' => __( 'Privacy', 'longevity-core' ), 'terms' => __( 'Terms', 'longevity-core' ), 'contact' => __( 'Contact', 'longevity-core' ) );
		$html  = '<nav class="longevity-policy-nav" aria-label="' . esc_attr__( 'Publication policies', 'longevity-core' ) . '"><ul>';
		foreach ( $links as $slug => $label ) {
			$page = get_page_by_path( $slug );
			$url  = $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
			$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		return $html . '</ul></nav>';
	}

	/** Render route-aware footer navigation. */
	public static function render_footer_nav(): string {
		$groups = array(
			'Explore' => array(
				'start_here' => __( 'Start Here', 'longevity-core' ),
			),
			'How We Work' => array(
				'evidence_methodology' => __( 'Evidence Methodology', 'longevity-core' ),
				'testing_methodology'  => __( 'Testing Methodology', 'longevity-core' ),
			),
			'About' => array(
				'about'  => __( 'About the publication', 'longevity-core' ),
				'contact' => __( 'Contact', 'longevity-core' ),
			),
			'Policies' => array(
				'editorial_policy'     => __( 'Editorial Policy', 'longevity-core' ),
				'medical_disclaimer'   => __( 'Medical Disclaimer', 'longevity-core' ),
				'affiliate_disclosure' => __( 'Affiliate Disclosure', 'longevity-core' ),
				'corrections'          => __( 'Corrections', 'longevity-core' ),
				'privacy'              => __( 'Privacy', 'longevity-core' ),
				'terms'                => __( 'Terms', 'longevity-core' ),
			),
		);

		$html = '<div class="longevity-footer-grid alignwide">';
		$html .= '<div class="longevity-footer-intro">';
		$html .= '<p class="longevity-kicker">' . esc_html__( 'Longevity Evidence Lab', 'longevity-core' ) . '</p>';
		$html .= '<h2>' . esc_html__( 'Decisions grounded in evidence you can inspect.', 'longevity-core' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'We publish evidence guides and governed consumer test reports with uncertainty, limitations, methods, disclosures, and corrections kept visible.', 'longevity-core' ) . '</p>';
		$html .= '<p class="longevity-small">' . esc_html__( 'Educational information only. This publication does not provide individualized medical advice.', 'longevity-core' ) . '</p>';
		$html .= '</div>';

		foreach ( $groups as $group_label => $routes ) {
			$html .= '<nav aria-label="' . esc_attr( $group_label ) . '">';
			$html .= '<h3>' . esc_html( $group_label ) . '</h3><ul>';
			foreach ( $routes as $key => $label ) {
				$url = Routes::public_page_url( $key );
				if ( null !== $url ) {
					$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
				}
			}
			$html .= '</ul></nav>';
		}

		return $html . '</div>';
	}

	/** Render footer ownership and review context. */
	public static function render_footer_meta(): string {
		$reviewed = (string) get_option( 'lel_policy_review_date', '' );
		$html     = '<p class="longevity-small">&copy; ' . esc_html( gmdate( 'Y' ) ) . ' ' . esc_html( get_bloginfo( 'name' ) ) . '. ' . esc_html__( 'Material corrections remain visible. Commercial relationships do not control conclusions.', 'longevity-core' );
		if ( $reviewed ) {
			$html .= ' ' . esc_html__( 'Policies last reviewed:', 'longevity-core' ) . ' <time datetime="' . esc_attr( $reviewed ) . '">' . esc_html( $reviewed ) . '</time>.';
		}
		return $html . '</p>';
	}

	/** Extract stable, deduplicated H2/H3 identifiers. */
	private static function extract_headings( string $content ): array {
		preg_match_all( '/<h([23])([^>]*)>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER );
		$headings = array();
		$used     = array();
		foreach ( $matches as $match ) {
			$text = trim( wp_strip_all_tags( $match[3] ) );
			if ( '' === $text ) {
				continue;
			}
			$id = '';
			if ( preg_match( "/\\sid=(['\"])([^'\"]+)\\1/i", $match[2], $id_match ) ) {
				$id = sanitize_title( $id_match[2] );
			}
			$base = $id ?: sanitize_title( $text );
			$base = $base ?: 'section';
			$id   = $base;
			$i    = 2;
			while ( isset( $used[ $id ] ) ) {
				$id = $base . '-' . $i;
				++$i;
			}
			$used[ $id ] = true;
			$headings[]  = array( 'level' => (int) $match[1], 'id' => $id, 'text' => $text );
		}
		return $headings;
	}

	/** Build select options. */
	private static function options( array $options, string $selected ): string {
		$html = '';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html;
	}

	/** Join price-check date and region without emitting empty punctuation. */
	private static function checked_context( int $post_id, string $date_key, string $region_key ): string {
		$date   = trim( (string) get_post_meta( $post_id, $date_key, true ) );
		$region = trim( (string) get_post_meta( $post_id, $region_key, true ) );
		return implode( ' · ', array_filter( array( $date, $region ) ) );
	}

	/** Return a page-unique heading ID. */
	private static function unique_id( string $component, int $post_id ): string {
		$key = $component . '-' . $post_id;
		self::$instance_counts[ $key ] = ( self::$instance_counts[ $key ] ?? 0 ) + 1;
		return 'lel-' . sanitize_html_class( $key ) . '-' . self::$instance_counts[ $key ];
	}

	/** Human-readable, deliberately scoped medical-review label. */
	private static function scope_label( string $scope ): string {
		$labels = array( 'full_article' => __( 'Medically reviewed for the full article scope recorded by the reviewer.', 'longevity-core' ), 'safety_only' => __( 'Medically reviewed for safety language.', 'longevity-core' ), 'contraindications_only' => __( 'Medically reviewed for contraindication language.', 'longevity-core' ), 'dosage_language_only' => __( 'Medically reviewed for dosage-language accuracy and boundaries.', 'longevity-core' ), 'product_accuracy_only' => __( 'Medically reviewed for product accuracy language and non-diagnostic limitations.', 'longevity-core' ), 'medical_disclaimer_only' => __( 'Medically reviewed only for the medical disclaimer.', 'longevity-core' ), 'claim_ids' => __( 'Medically reviewed only for the recorded claim IDs.', 'longevity-core' ) );
		return $labels[ $scope ] ?? __( 'Medically reviewed for the scope recorded on this page.', 'longevity-core' );
	}
}

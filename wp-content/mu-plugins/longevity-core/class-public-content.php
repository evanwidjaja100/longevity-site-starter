<?php
/**
 * Public content components.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Renders content-area components: TOC, related content, search, etc. */
class Public_Content {
	/**
	 * Per-request counters used to build page-unique element IDs.
	 *
	 * @var array<string, int>
	 */
	private static array $instance_counts = array();

	/** Register content filters used by public components. */
	public static function init(): void {
		add_filter( 'the_content', array( self::class, 'add_heading_ids' ), 12 );
	}

	/**
	 * Render a TOC only for long articles with at least three H2 headings.
	 *
	 * @param int $post_id Article post ID.
	 * @return string Table-of-contents markup, or an empty string.
	 */
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

	/**
	 * Add the same stable IDs used by the TOC while preserving manual IDs.
	 *
	 * @param string $content Post content HTML.
	 * @return string Content with stable heading IDs added.
	 */
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

	/**
	 * Render deterministic related content.
	 *
	 * @param int $post_id Current article post ID.
	 * @param int $limit   Maximum number of related items.
	 * @return string Related-content markup, or an empty string.
	 */
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
				'orderby'             => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
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
			$guide_event = 'review' === $related->post_type ? 'ranking_report_open' : 'guide_open';
			$guide_attr  = 'guide_open' === $guide_event ? 'data-lel-event="guide_open" data-guide-id="' . esc_attr( (string) $related->ID ) . '" data-placement="related-content"' : '';
			$html       .= '<li><a href="' . esc_url( get_permalink( $related ) ) . '" ' . $guide_attr . '>' . esc_html( get_the_title( $related ) ) . '</a> <span class="longevity-small">' . esc_html( 'review' === $related->post_type ? __( 'Consumer Lab review', 'longevity-core' ) : __( 'Evidence guide', 'longevity-core' ) ) . '</span></li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * Render compact metadata for a query-loop card.
	 *
	 * @param int $post_id Post ID for the card.
	 * @return string Card metadata markup.
	 */
	public static function render_content_card_meta( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		$items   = array();
		$items[] = '<span><strong>' . esc_html( 'review' === get_post_type( $post_id ) ? __( 'Review', 'longevity-core' ) : __( 'Guide', 'longevity-core' ) ) . '</strong></span>';
		$items[] = '<time datetime="' . esc_attr( get_the_modified_date( DATE_W3C, $post_id ) ) . '">' . esc_html( sprintf( /* translators: %s: human-readable last-updated date. */ __( 'Updated %s', 'longevity-core' ), get_the_modified_date( '', $post_id ) ) ) . '</time>';
		$grade   = (string) get_post_meta( $post_id, 'evidence_grade', true );
		if ( $grade ) {
			$grade_labels = array(
				'A' => __( 'Strong', 'longevity-core' ),
				'B' => __( 'Moderate', 'longevity-core' ),
				'C' => __( 'Limited', 'longevity-core' ),
				'D' => __( 'Mechanistic', 'longevity-core' ),
				'U' => __( 'Unclear', 'longevity-core' ),
			);
			$items[]      = '<span>' . esc_html( sprintf( /* translators: %s: evidence-grade strength label. */ __( 'Main conclusion: %s', 'longevity-core' ), $grade_labels[ $grade ] ?? __( 'Unclassified', 'longevity-core' ) ) ) . '</span>';
		}
		if ( 'review' === get_post_type( $post_id ) && Runtime_Config::scoring_model_status()['valid'] && Approval_Service::is_current( $post_id, 'testing' ) && Review_Methodology::valid_test_record( (int) get_post_meta( $post_id, 'test_record_id', true ), (string) get_post_meta( $post_id, 'testing_protocol_version', true ) ) ) {
			$items[] = '<span>' . esc_html__( 'Tested', 'longevity-core' ) . '</span>';
		}
		if ( Approval_Service::is_current( $post_id, 'medical' ) ) {
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
		$html         = '<p class="longevity-result-count" aria-live="polite">' . esc_html( sprintf( /* translators: %s: number of search results. */ _n( '%s result', '%s results', $count, 'longevity-core' ), number_format_i18n( $count ) ) ) . '</p><form class="longevity-search-form" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '">';
		$html        .= '<label>' . esc_html__( 'Search terms', 'longevity-core' ) . '<input type="search" name="s" value="' . esc_attr( $query ) . '"></label>';
		$html        .= '<label>' . esc_html__( 'Content type', 'longevity-core' ) . '<select name="content_type">' . self::options(
			array(
				'all'    => __( 'All content', 'longevity-core' ),
				'guide'  => __( 'Evidence guides', 'longevity-core' ),
				'review' => __( 'Consumer Lab reviews', 'longevity-core' ),
			),
			$content_type
		) . '</select></label>';

		$cat_options = array( '' => __( 'All topics', 'longevity-core' ) );
		$categories  = get_categories( array( 'hide_empty' => false ) );
		foreach ( $categories as $cat ) {
			$cat_options[ $cat->slug ] = $cat->name;
		}
		$html .= '<label>' . esc_html__( 'Topic', 'longevity-core' ) . '<select name="category">' . self::options( $cat_options, $category ) . '</select></label>';

		$html       .= '<label>' . esc_html__( 'Sort', 'longevity-core' ) . '<select name="sort">' . self::options(
			array(
				'relevance' => __( 'Relevance', 'longevity-core' ),
				'newest'    => __( 'Newest', 'longevity-core' ),
				'updated'   => __( 'Recently updated', 'longevity-core' ),
			),
			$sort
		) . '</select></label>';
		$has_filters = ( 'all' !== $content_type || '' !== $category || 'relevance' !== $sort );
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
		$snapshot    = Reviewer_Credentials::public_snapshot( $user->ID );
		$name        = $user->display_name;
		$bio         = (string) get_the_author_meta( 'description', $user->ID );
		$credentials = (string) ( $snapshot['credentials'] ?? '' );
		$scope       = (string) ( $snapshot['scope'] ?? '' );
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

	/**
	 * Render correction history through the authoritative correction service.
	 *
	 * @param int $post_id Post ID whose corrections should be rendered.
	 * @return string Correction-history markup, or an empty string.
	 */
	public static function render_corrections( int $post_id ): string {
		return $post_id > 0 ? Corrections::render( $post_id ) : '';
	}

	/** Render topic directory with guide counts and optional review counts. */
	public static function render_topic_directory(): string {
		$topics = array(
			'evidence-literacy' => array(
				'label'   => __( 'Evidence Literacy', 'longevity-core' ),
				'desc'    => __( 'Learn how to evaluate health claims and interpret study quality.', 'longevity-core' ),
				'example' => __( 'How do I know whether a health claim is supported?', 'longevity-core' ),
			),
			'sleep'             => array(
				'label'   => __( 'Sleep', 'longevity-core' ),
				'desc'    => __( 'Evidence-led sleep guidance and measurement literacy.', 'longevity-core' ),
				'example' => __( 'How can I improve sleep before buying a device?', 'longevity-core' ),
			),
			'movement'          => array(
				'label'   => __( 'Movement', 'longevity-core' ),
				'desc'    => __( 'Resistance training, physical capacity, and healthy aging.', 'longevity-core' ),
				'example' => __( 'How should a beginner structure resistance training?', 'longevity-core' ),
			),
			'nutrition'         => array(
				'label'   => __( 'Nutrition', 'longevity-core' ),
				'desc'    => __( 'Dietary patterns and foods associated with healthy aging.', 'longevity-core' ),
				'example' => __( 'Which dietary patterns have the strongest human evidence?', 'longevity-core' ),
			),
			'wearables'         => array(
				'label'   => __( 'Wearables', 'longevity-core' ),
				'desc'    => __( 'Consumer measurement devices and data interpretation.', 'longevity-core' ),
				'example' => __( 'What can a sleep tracker measure reliably?', 'longevity-core' ),
			),
			'supplements'       => array(
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

			$review_args  = array(
				'post_type'      => 'review',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
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

			$html .= '<article class="longevity-topic-card"><h3><a href="' . esc_url( get_category_link( $term->term_id ) ) . '" data-lel-event="topic_open" data-topic="' . esc_attr( $term->slug ) . '" data-placement="topic-directory">' . esc_html( $topic['label'] ) . '</a></h3><p class="longevity-topic-desc">' . esc_html( $topic['desc'] ) . '</p><p class="longevity-topic-example">' . esc_html( $topic['example'] ) . '</p><p class="longevity-topic-counts">';

			$counts = array();
			if ( $guide_count > 0 ) {
				$counts[] = esc_html( sprintf( /* translators: %d: number of published guides. */ _n( '%d guide', '%d guides', $guide_count, 'longevity-core' ), $guide_count ) );
			}
			if ( $review_count > 0 ) {
				$counts[] = esc_html( sprintf( /* translators: %d: number of product reports. */ _n( '%d product report', '%d product reports', $review_count, 'longevity-core' ), $review_count ) );
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
		$page       = isset( $_GET['guide_page'] ) ? max( 1, (int) $_GET['guide_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Anonymous public read; nonces do not apply.
		$sort       = sanitize_key( wp_unslash( $_GET['guide_sort'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Anonymous public read; nonces do not apply.
		$topic_slug = sanitize_key( wp_unslash( $_GET['guide_topic'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Anonymous public read; nonces do not apply.

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

		$args  = array(
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

		$html      .= '<form class="longevity-guide-filters" method="get" action=""><label>' . esc_html__( 'Topic', 'longevity-core' ) . ' <select name="guide_topic">';
		$html      .= '<option value="">' . esc_html__( 'All topics', 'longevity-core' ) . '</option>';
		$categories = get_categories( array( 'hide_empty' => true ) );
		foreach ( $categories as $cat ) {
			$selected = selected( $topic_slug, $cat->slug, false );
			$html    .= '<option value="' . esc_attr( $cat->slug ) . '" ' . $selected . '>' . esc_html( $cat->name ) . '</option>';
		}
		$html .= '</select></label>';

		$html .= '<label>' . esc_html__( 'Sort', 'longevity-core' ) . ' <select name="guide_sort">';
		$html .= '<option value="">' . esc_html__( 'Newest', 'longevity-core' ) . '</option>';
		$html .= '<option value="updated" ' . selected( 'updated', $sort, false ) . '>' . esc_html__( 'Recently updated', 'longevity-core' ) . '</option>';
		$html .= '<option value="title" ' . selected( 'title', $sort, false ) . '>' . esc_html__( 'Title A–Z', 'longevity-core' ) . '</option>';
		$html .= '</select></label>';
		$html .= '<button class="wp-element-button" type="submit">' . esc_html__( 'Apply', 'longevity-core' ) . '</button>';
		if ( $topic_slug || $sort ) {
			$guides_url = Routes::public_page_url( 'guides' );
			$html      .= ' <a class="longevity-clear-filters" href="' . esc_url( $guides_url ? $guides_url : home_url( '/guides/' ) ) . '">' . esc_html__( 'Clear filters', 'longevity-core' ) . '</a>';
		}
		$html .= '</form>';

		if ( $query->have_posts() ) {
			$html .= '<div class="longevity-card-grid">';
			while ( $query->have_posts() ) {
				$query->the_post();
				$html   .= '<article class="longevity-card"><h3><a href="' . esc_url( get_permalink() ) . '" data-lel-event="guide_open" data-guide-id="' . esc_attr( (string) get_the_ID() ) . '" data-placement="guide-directory">' . esc_html( get_the_title() ) . '</a></h3>';
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

			$total = $query->max_num_pages;
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
				$guides_url = Routes::public_page_url( 'guides' );
				$html      .= ' <a href="' . esc_url( $guides_url ? $guides_url : home_url( '/guides/' ) ) . '">' . esc_html__( 'Clear filters and browse all guides.', 'longevity-core' ) . '</a>';
			}
			$html .= '</div>';
		}

		return $html . '</section>';
	}

	/**
	 * Extract stable, deduplicated H2/H3 identifiers.
	 *
	 * @param string $content Post content HTML.
	 * @return array List of heading arrays with level, id, and text.
	 */
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
			$base = $id ? $id : sanitize_title( $text );
			$base = $base ? $base : 'section';
			$id   = $base;
			$i    = 2;
			while ( isset( $used[ $id ] ) ) {
				$id = $base . '-' . $i;
				++$i;
			}
			$used[ $id ] = true;
			$headings[]  = array(
				'level' => (int) $match[1],
				'id'    => $id,
				'text'  => $text,
			);
		}
		return $headings;
	}

	/**
	 * Build select options.
	 *
	 * @param array  $options  Map of option values to labels.
	 * @param string $selected Currently selected value.
	 * @return string Rendered option markup.
	 */
	public static function options( array $options, string $selected ): string {
		$html = '';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html;
	}

	/**
	 * Join price-check date and region without emitting empty punctuation.
	 *
	 * @param int    $post_id    Post ID to read metadata from.
	 * @param string $date_key   Meta key holding the price-check date.
	 * @param string $region_key Meta key holding the price region.
	 * @return string Joined context string, possibly empty.
	 */
	public static function checked_context( int $post_id, string $date_key, string $region_key ): string {
		$date   = trim( (string) get_post_meta( $post_id, $date_key, true ) );
		$region = trim( (string) get_post_meta( $post_id, $region_key, true ) );
		return implode( ' · ', array_filter( array( $date, $region ) ) );
	}

	/**
	 * Return a page-unique heading ID.
	 *
	 * @param string $component Component slug used as the ID prefix.
	 * @param int    $post_id   Post ID the component renders for.
	 * @return string Unique element ID.
	 */
	public static function unique_id( string $component, int $post_id ): string {
		$key                           = $component . '-' . $post_id;
		self::$instance_counts[ $key ] = ( self::$instance_counts[ $key ] ?? 0 ) + 1;
		return 'lel-' . sanitize_html_class( $key ) . '-' . self::$instance_counts[ $key ];
	}
}

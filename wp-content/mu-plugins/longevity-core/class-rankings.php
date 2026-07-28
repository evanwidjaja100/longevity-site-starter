<?php
/**
 * Authoritative public ranking eligibility, ordering, filtering, and aggregates.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Keeps every public ranking representation behind the same governance rules. */
final class Rankings {
	private const CACHE_GROUP      = 'longevity_rankings';
	private const CONFIDENCE_ORDER = array(
		'High confidence'     => 4,
		'Moderate confidence' => 3,
		'Low confidence'      => 2,
		'Preliminary'         => 1,
	);

	/** Register bounded cache invalidation hooks. */
	public static function init(): void {
		add_action( 'save_post_review', array( self::class, 'invalidate' ) );
		add_action( 'save_post_lel_test_record', array( self::class, 'invalidate' ) );
		add_action( 'save_post_lel_protocol', array( self::class, 'invalidate' ) );
		add_action( 'set_object_terms', array( self::class, 'invalidate' ) );
		add_action( 'transition_post_status', array( self::class, 'invalidate' ) );
	}

	/** Delete versioned ranking aggregates without exposing private record data. */
	public static function invalidate(): void {
		update_option( 'lel_rankings_cache_version', (string) microtime( true ), false );
	}

	/**
	 * Return the complete set of eligible review IDs from a persistent cache.
	 *
	 * Evaluates ALL published reviews (not just the newest 100) and stores
	 * the eligible ID set in a versioned transient. Invalidation is triggered
	 * by governed transitions via the hooks registered in init().
	 *
	 * @return list<int> Eligible review post IDs.
	 */
	public static function eligible_ids(): array {
		$version   = (string) get_option( 'lel_rankings_cache_version', '1' );
		$cache_key = 'eligible_ids_' . md5( $version );
		$cached    = get_transient( 'lel_rankings_' . $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$all_reviews = get_posts(
			array(
				'post_type'              => 'review',
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'fields'                 => 'ids',
				'orderby'                => array( 'modified' => 'DESC', 'ID' => 'DESC' ),
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);
		$eligible = array_values( array_filter( array_map( 'intval', $all_reviews ), static fn( int $id ) => self::is_eligible( $id ) ) );
		set_transient( 'lel_rankings_' . $cache_key, $eligible, 6 * HOUR_IN_SECONDS );
		return $eligible;
	}

	/**
	 * Return machine-readable reasons a review cannot appear in a test-based ranking.
	 *
	 * @param int $post_id Review post ID.
	 */
	public static function eligibility_reasons( int $post_id ): array {
		$reasons = array();
		$post    = get_post( $post_id );
		if ( ! $post || 'review' !== $post->post_type ) {
			return array( 'not_review' );
		}
		if ( 'publish' !== $post->post_status ) {
			$reasons[] = 'not_published';
		}
		if ( Publication_Gates::evaluate( $post_id )->is_blocked() ) {
			$reasons[] = 'publication_gate_failed';
		}
		if ( ! Runtime_Config::scoring_model_status()['valid'] ) {
			$reasons[] = 'scoring_model_invalid';
		}
		if ( ! Approval_Service::is_current( $post_id, 'testing' ) ) {
			$reasons[] = 'testing_approval_stale';
		}
		if ( ! Approval_Service::is_current( $post_id, 'editorial' ) ) {
			$reasons[] = 'editorial_approval_stale';
		}
		foreach ( array( 'content_summary', 'content_limitations', 'tested_product_model', 'comparison_set', 'review_score_version', 'review_score_confidence', 'last_material_update', 'next_content_review_date' ) as $field ) {
			if ( '' === trim( (string) get_post_meta( $post_id, $field, true ) ) ) {
				$reasons[] = 'missing_' . $field;
			}
		}
		if ( ! in_array( get_post_meta( $post_id, 'editorial_approval_status', true ), array( 'ready', 'published' ), true ) ) {
			$reasons[] = 'editorial_incomplete';
		}
		if ( ! get_post_meta( $post_id, 'testing_required', true ) ) {
			$reasons[] = 'testing_not_required';
		}
		if ( ! in_array( get_post_meta( $post_id, 'testing_status', true ), array( 'complete', 'approved' ), true ) ) {
			$reasons[] = 'testing_incomplete';
		}

		$record_id = (int) get_post_meta( $post_id, 'test_record_id', true );
		$version   = (string) get_post_meta( $post_id, 'testing_protocol_version', true );
		if ( ! Review_Methodology::valid_test_record( $record_id, $version ) ) {
			$reasons[] = 'test_record_invalid';
		}

		$score      = (float) get_post_meta( $post_id, 'review_score', true );
		$dimensions = get_post_meta( $post_id, 'review_score_dimensions', true );
		$override   = trim( (string) get_post_meta( $post_id, 'review_score_override_reason', true ) );
		if ( $score <= 0 || $score > 5 ) {
			$reasons[] = 'score_invalid';
		} else {
			try {
				$calculated = Review_Methodology::calculate_score( is_array( $dimensions ) ? $dimensions : array() );
				if ( abs( (float) $calculated['score'] - $score ) > 0.01 && '' === $override ) {
					$reasons[] = 'score_not_reproducible';
				}
			} catch ( \InvalidArgumentException $exception ) {
				$reasons[] = 'score_dimensions_invalid';
			}
		}

		if ( ! array_key_exists( (string) get_post_meta( $post_id, 'review_score_confidence', true ), self::CONFIDENCE_ORDER ) ) {
			$reasons[] = 'confidence_invalid';
		}
		if ( ! in_array( get_post_meta( $post_id, 'commercial_relationship', true ), array( 'none', 'affiliate', 'product_supplied', 'sponsored' ), true ) ) {
			$reasons[] = 'commercial_relationship_missing';
		}
		if ( 'affiliate' === get_post_meta( $post_id, 'commercial_relationship', true ) && ( ! in_array( get_post_meta( $post_id, 'affiliate_disclosure_status', true ), array( 'approved', 'complete' ), true ) || ! Affiliate_Registry::all_destinations_registered( $post->post_content ) ) ) {
			$reasons[] = 'affiliate_controls_incomplete';
		}
		if ( get_post_meta( $post_id, 'medical_review_required', true ) && ! Approval_Service::is_current( $post_id, 'medical' ) ) {
			$reasons[] = 'medical_review_incomplete';
		}
		if ( in_array( get_post_meta( $post_id, 'correction_status', true ), array( 'reported', 'investigating', 'pending' ), true ) ) {
			$reasons[] = 'material_correction_open';
		}
		if ( 'archived' === get_post_meta( $post_id, 'editorial_approval_status', true ) ) {
			$reasons[] = 'archived';
		}
		$next_review = (string) get_post_meta( $post_id, 'next_content_review_date', true );
		if ( $next_review && Date_Validator::is_valid( $next_review ) && Date_Validator::compare( $next_review, Date_Validator::today() ) < 0 ) {
			$reasons[] = 'materially_overdue';
		}
		return array_values( array_unique( $reasons ) );
	}

	/**
	 * Whether a review is safe and complete enough for a public ordered ranking.
	 *
	 * @param int $post_id Review post ID.
	 */
	public static function is_eligible( int $post_id ): bool {
		return array() === self::eligibility_reasons( $post_id );
	}

	/** Return the requested public sort through a strict allowlist. */
	public static function requested_sort(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted GET state is intentionally linkable.
		$value = isset( $_GET['ranking_sort'] ) ? sanitize_key( wp_unslash( $_GET['ranking_sort'] ) ) : 'score';
		return in_array( $value, array( 'score', 'confidence', 'updated', 'title' ), true ) ? $value : 'score';
	}

	/** Return requested filters only when their values are allowlisted. */
	public static function requested_filters(): array {
		$filters = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted GET state is intentionally linkable.
		if ( isset( $_GET['confidence'] ) ) {
			$value = sanitize_text_field( wp_unslash( $_GET['confidence'] ) );
			if ( array_key_exists( $value, self::CONFIDENCE_ORDER ) ) {
				$filters['confidence'] = $value;
			}
		}
		if ( isset( $_GET['subscription'] ) ) {
			$value = sanitize_key( wp_unslash( $_GET['subscription'] ) );
			if ( in_array( $value, array( 'required', 'not_required' ), true ) ) {
				$filters['subscription'] = $value;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $filters;
	}

	/**
	 * Get a bounded eligible result set and apply safe in-memory deterministic ordering.
	 *
	 * Uses the persistent eligible_ids() cache to avoid re-evaluating governance
	 * rules on every request. Sorting and filtering remain in-memory for
	 * deterministic tie-breaking.
	 *
	 * @param int    $category_id Optional category term ID.
	 * @param string $sort        Allowlisted sort key.
	 * @param array  $filters     Allowlisted filter values.
	 * @param int    $limit       Bounded result limit.
	 */
	public static function reviews( int $category_id = 0, string $sort = '', array $filters = array(), int $limit = 100 ): array {
		$eligible = self::eligible_ids();
		if ( empty( $eligible ) ) {
			return array();
		}
		$args = array(
			'post_type'              => 'review',
			'post_status'            => 'publish',
			'post__in'               => $eligible,
			'posts_per_page'         => min( 500, max( 1, count( $eligible ) ) ),
			'orderby'                => array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
		);
		if ( 0 < $category_id ) {
			$args['cat'] = $category_id;
		}
		$posts = get_posts( $args );
		if ( array() === $filters ) {
			$filters = self::requested_filters();
		}
		$posts = array_values(
			array_filter(
				$posts,
				static function ( $post ) use ( $filters ): bool {
					if ( isset( $filters['confidence'] ) && get_post_meta( $post->ID, 'review_score_confidence', true ) !== $filters['confidence'] ) {
						return false;
					}
					if ( isset( $filters['subscription'] ) ) {
						$required = (bool) get_post_meta( $post->ID, 'subscription_required', true );
						if ( ( 'required' === $filters['subscription'] ) !== $required ) {
							return false;
						}
					}
					return true;
				}
			)
		);
		$posts = array_slice( $posts, 0, min( 100, max( 1, $limit ) ) );
		return self::sort( $posts, '' === $sort ? self::requested_sort() : $sort );
	}

	/**
	 * Deterministically order eligible WP_Post-like records.
	 *
	 * @param array  $posts WP_Post-like records.
	 * @param string $sort  Allowlisted sort key.
	 */
	public static function sort( array $posts, string $sort = 'score' ): array {
		$sort = in_array( $sort, array( 'score', 'confidence', 'updated', 'title' ), true ) ? $sort : 'score';
		usort(
			$posts,
			static function ( $left, $right ) use ( $sort ): int {
				$left_id    = (int) $left->ID;
				$right_id   = (int) $right->ID;
				$score      = (float) get_post_meta( $right_id, 'review_score', true ) <=> (float) get_post_meta( $left_id, 'review_score', true );
				$confidence = ( self::CONFIDENCE_ORDER[ (string) get_post_meta( $right_id, 'review_score_confidence', true ) ] ?? 0 ) <=> ( self::CONFIDENCE_ORDER[ (string) get_post_meta( $left_id, 'review_score_confidence', true ) ] ?? 0 );
				$updated    = strcmp( (string) get_post_meta( $right_id, 'last_material_update', true ), (string) get_post_meta( $left_id, 'last_material_update', true ) );
				$title      = strcasecmp( get_the_title( $left_id ), get_the_title( $right_id ) );
				$chains     = array(
					'score'      => array( $score, $confidence, $updated, $title, $left_id <=> $right_id ),
					'confidence' => array( $confidence, $score, $updated, $title, $left_id <=> $right_id ),
					'updated'    => array( $updated, $score, $confidence, $title, $left_id <=> $right_id ),
					'title'      => array( $title, $score, $confidence, $updated, $left_id <=> $right_id ),
				);
				foreach ( $chains[ $sort ] as $comparison ) {
					if ( 0 !== $comparison ) {
						return $comparison;
					}
				}
				return 0;
			}
		);
		return $posts;
	}

	/** Minimum eligible comparable reports required for a public ranking category. */
	public static function minimum_ranking_size(): int {
		return (int) apply_filters( 'longevity_minimum_ranking_size', 3 );
	}

	/** Whether at least one category has enough inventory for a public ranking. */
	public static function has_public_ranking_inventory(): bool {
		foreach ( self::directory() as $group ) {
			if ( $group['count'] >= self::minimum_ranking_size() ) {
				return true;
			}
		}
		return false;
	}

	/** Assign ranking bands based on meaningful difference threshold. */
	public static function assign_bands( array $posts ): array {
		if ( empty( $posts ) ) {
			return array();
		}
		$threshold = Review_Methodology::minimum_meaningful_difference();
		$bands     = array();
		$current   = array();
		$prev_score = null;
		foreach ( $posts as $post ) {
			$score = (float) get_post_meta( $post->ID, 'review_score', true );
			if ( null !== $prev_score && ( $prev_score - $score ) > $threshold ) {
				$bands[]  = $current;
				$current  = array();
			}
			$current[]   = $post;
			$prev_score  = $score;
		}
		$bands[] = $current;
		return $bands;
	}

	/** Aggregate categories that contain at least one eligible ranked review. */
	public static function directory(): array {
		$cache_key = 'directory_' . md5( (string) get_option( 'lel_rankings_cache_version', '1' ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$groups = array();
		foreach ( self::reviews( 0, 'score', array(), 100 ) as $review ) {
			foreach ( get_the_category( $review->ID ) as $term ) {
				if ( ! isset( $groups[ $term->term_id ] ) ) {
					$groups[ $term->term_id ] = array(
						'term'          => $term,
						'count'         => 0,
						'latest'        => '',
						'highest_score' => 0.0,
					);
				}
				++$groups[ $term->term_id ]['count'];
				$groups[ $term->term_id ]['latest']        = max( $groups[ $term->term_id ]['latest'], (string) get_post_meta( $review->ID, 'last_material_update', true ) );
				$groups[ $term->term_id ]['highest_score'] = max( $groups[ $term->term_id ]['highest_score'], (float) get_post_meta( $review->ID, 'review_score', true ) );
			}
		}
		$groups = array_values( $groups );
		usort( $groups, static fn( $left, $right ) => strcasecmp( $left['term']->name, $right['term']->name ) );
		wp_cache_set( $cache_key, $groups, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $groups;
	}
}

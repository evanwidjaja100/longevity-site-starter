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
	private const CACHE_GROUP             = 'longevity_rankings';
	private const ELIGIBLE_CACHE          = 'lel_rankings_eligible';
	private const RANKING_VERSION         = '2';
	private const RANKING_BATCH_DEFAULT   = 200;
	private const RANKING_CEILING_DEFAULT = 5000;
	private const CONFIDENCE_ORDER        = array(
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
		add_action( 'save_post_lel_affiliate', array( self::class, 'invalidate' ) );
		add_action( 'set_object_terms', array( self::class, 'invalidate' ) );
		add_action( 'transition_post_status', array( self::class, 'invalidate' ) );
	}

	/** Back-compat hook entry point: invalidate the whole ranking cache. */
	public static function invalidate(): void {
		self::invalidate_all( 'governed_transition' );
	}

	/**
	 * Central ranking invalidation API: bump the shared cache generation so every
	 * signature-scoped aggregate is recomputed on next read. No private record
	 * data is written or logged.
	 */
	public static function invalidate_all( string $reason = 'unspecified' ): void {
		$next = (int) get_option( 'lel_rankings_generation', 1 ) + 1;
		update_option( 'lel_rankings_generation', $next, false );
		update_option( 'lel_rankings_cache_version', (string) $next, false );
		Logger::log(
			Logger::INFO,
			'rankings_cache_invalidated',
			array(
				'scope'      => 'all',
				'reason'     => self::sanitize_reason( $reason ),
				'generation' => $next,
			)
		);
	}

	/**
	 * Invalidate rankings for a single review after a durable per-review
	 * transition. The eligible set is a shared aggregate, so the generation is
	 * bumped and the live re-validation guard guarantees this review is
	 * re-evaluated regardless.
	 */
	public static function invalidate_review( int $post_id, string $reason = 'unspecified' ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		self::invalidate_all( 'review:' . self::sanitize_reason( $reason ) );
	}

	/** Reduce an invalidation reason to a bounded, privacy-safe token. */
	private static function sanitize_reason( string $reason ): string {
		$reason = preg_replace( '/[^a-z0-9_:.-]/i', '_', $reason ) ?? '';
		return '' === $reason ? 'unspecified' : substr( $reason, 0, 64 );
	}

	/**
	 * Immutable generation signature folded into every cache entry. A change to
	 * the ranking version, invalidation generation, scoring model, or the UTC
	 * eligibility date makes prior cache entries non-matching (requirement: do
	 * not rely solely on TTL; date-based rules re-evaluate at UTC rollover).
	 */
	private static function generation_signature(): string {
		$scoring = Runtime_Config::scoring_model_status();
		return implode(
			'|',
			array(
				self::RANKING_VERSION,
				(string) get_option( 'lel_rankings_cache_version', '1' ),
				$scoring['valid'] ? (string) ( $scoring['model']['version'] ?? '' ) : 'invalid',
				Date_Validator::today(),
			)
		);
	}

	/**
	 * Return the complete set of eligible review IDs.
	 *
	 * A signature-scoped persistent cache narrows the candidate set, but every
	 * cached id is re-validated live before it is returned for public rendering.
	 * Stale ids are dropped, counted, and force a generation bump. A cached
	 * eligible id can therefore never bypass a current governance failure.
	 *
	 * @return list<int> Eligible review post IDs.
	 */
	public static function eligible_ids(): array {
		$signature = self::generation_signature();
		$cached    = get_transient( self::ELIGIBLE_CACHE );
		if ( is_array( $cached ) && isset( $cached['signature'], $cached['ids'] ) && is_array( $cached['ids'] ) && hash_equals( $signature, (string) $cached['signature'] ) ) {
			return self::live_revalidate( array_values( array_map( 'intval', $cached['ids'] ) ) );
		}
		$candidates = self::collect_published_review_ids();
		if ( self::is_degraded() ) {
			return array();
		}
		$eligible = array_values( array_filter( $candidates, static fn( int $id ) => self::is_eligible( $id ) ) );
		set_transient(
			self::ELIGIBLE_CACHE,
			array(
				'signature' => $signature,
				'ids'       => $eligible,
			),
			6 * HOUR_IN_SECONDS
		);
		return $eligible;
	}

	/**
	 * Collect every published review ID in deterministic ascending-ID keyset
	 * batches. Batches bound per-query memory and warm the metadata cache so
	 * eligibility evaluation avoids N+1 queries. The complete population is
	 * bounded only by an explicit, documented safety ceiling; exceeding it fails
	 * the projection closed rather than silently truncating.
	 *
	 * @return list<int> Published review IDs (empty when the ceiling is breached).
	 */
	private static function collect_published_review_ids(): array {
		$batch   = max( 1, min( 500, (int) apply_filters( 'longevity_ranking_batch_size', self::RANKING_BATCH_DEFAULT ) ) );
		$ceiling = max( 1, (int) apply_filters( 'longevity_max_ranked_reviews', self::RANKING_CEILING_DEFAULT ) );
		$ids     = array();
		$after   = 0;
		do {
			$GLOBALS['lel_rankings_keyset_after'] = $after;
			add_filter( 'posts_where', array( self::class, 'keyset_where' ) );
			$page = get_posts(
				array(
					'post_type'              => 'review',
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => $batch,
					'orderby'                => array( 'ID' => 'ASC' ),
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => true,
					'suppress_filters'       => false,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => true,
				)
			);
			remove_filter( 'posts_where', array( self::class, 'keyset_where' ) );
			unset( $GLOBALS['lel_rankings_keyset_after'] );
			$page    = array_values( array_map( 'intval', (array) $page ) );
			$highest = $after;
			foreach ( $page as $id ) {
				$ids[]   = $id;
				$highest = max( $highest, $id );
			}
			if ( count( $ids ) > $ceiling ) {
				self::mark_degraded( count( $ids ), $ceiling );
				return array();
			}
			if ( $highest <= $after ) {
				break;
			}
			$after = $highest;
		} while ( count( $page ) === $batch );
		$ids = array_values( array_unique( $ids ) );
		self::enforce_population_ceiling( $ids, $ceiling );
		return self::is_degraded() ? array() : $ids;
	}

	/** Keyset WHERE clause bounding a collection batch to IDs above the cursor. */
	public static function keyset_where( string $where ): string {
		global $wpdb;
		$after = (int) ( $GLOBALS['lel_rankings_keyset_after'] ?? 0 );
		if ( $after > 0 && isset( $wpdb->posts ) ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
		}
		return $where;
	}

	/**
	 * Enforce the explicit population safety ceiling. Within the ceiling the
	 * degraded flag is cleared and the population passes through unchanged;
	 * above it the projection is marked degraded and fails closed to an empty set.
	 *
	 * @param list<int> $ids     Candidate population.
	 * @param int       $ceiling Maximum supported population.
	 * @return list<int> The population when within the ceiling, otherwise empty.
	 */
	public static function enforce_population_ceiling( array $ids, int $ceiling ): array {
		if ( count( $ids ) > $ceiling ) {
			self::mark_degraded( count( $ids ), $ceiling );
			return array();
		}
		self::clear_degraded();
		return array_values( $ids );
	}

	/** Record a bounded, privacy-safe diagnostic and mark rankings degraded. */
	private static function mark_degraded( int $count, int $ceiling ): void {
		update_option( 'lel_rankings_degraded', 1, false );
		Logger::log(
			Logger::WARNING,
			'rankings_population_ceiling_exceeded',
			array(
				'population' => $count,
				'ceiling'    => $ceiling,
			)
		);
	}

	/** Clear the degraded flag once a projection completes within budget. */
	private static function clear_degraded(): void {
		if ( '' !== (string) get_option( 'lel_rankings_degraded', '' ) ) {
			delete_option( 'lel_rankings_degraded' );
		}
	}

	/** Whether the ranking projection is currently failing closed above its ceiling. */
	public static function is_degraded(): bool {
		return (bool) get_option( 'lel_rankings_degraded', false );
	}

	/**
	 * Re-evaluate every cached id against current governance state. Ids that are
	 * no longer eligible (or cannot be confirmed) are dropped fail-closed and the
	 * generation is bumped so the cached aggregate rebuilds without them.
	 *
	 * @param list<int> $ids Cached candidate ids.
	 * @return list<int> Live-eligible ids.
	 */
	private static function live_revalidate( array $ids ): array {
		$live     = array();
		$rejected = 0;
		foreach ( $ids as $id ) {
			if ( self::is_eligible( $id ) ) {
				$live[] = $id;
			} else {
				++$rejected;
			}
		}
		if ( $rejected > 0 ) {
			$total = (int) get_option( 'lel_rankings_cache_rejections', 0 ) + $rejected;
			update_option( 'lel_rankings_cache_rejections', $total, false );
			Logger::log(
				Logger::WARNING,
				'rankings_cache_stale_rejected',
				array(
					'rejected' => $rejected,
					'total'    => $total,
				)
			);
			self::invalidate_all( 'live_revalidation_rejected' );
		}
		return $live;
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
	 * Fail-closed: any error while evaluating eligibility is treated as
	 * ineligible so a governance-evaluation failure can never make a review public.
	 *
	 * @param int $post_id Review post ID.
	 */
	public static function is_eligible( int $post_id ): bool {
		try {
			return array() === self::eligibility_reasons( $post_id );
		} catch ( \Throwable $error ) {
			Logger::log( Logger::ERROR, 'rankings_eligibility_error', array( 'error' => get_class( $error ) ) );
			return false;
		}
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
		$eligible = self::eligible_in_category( $category_id );
		if ( empty( $eligible ) ) {
			return array();
		}
		if ( array() === $filters ) {
			$filters = self::requested_filters();
		}
		$ordered = self::order_and_limit( $eligible, '' === $sort ? self::requested_sort() : $sort, $filters, $limit );
		return empty( $ordered ) ? array() : self::hydrate( $ordered );
	}

	/**
	 * Complete eligible review IDs, optionally scoped to a single category. The
	 * population is never capped here so callers can count or order the whole set.
	 *
	 * @param int $category_id Optional category term ID.
	 * @return list<int> Complete eligible review IDs.
	 */
	public static function eligible_in_category( int $category_id = 0 ): array {
		$eligible = self::eligible_ids();
		if ( empty( $eligible ) || $category_id <= 0 ) {
			return $eligible;
		}
		return array_values( array_filter( $eligible, static fn( int $id ): bool => self::in_category( $id, $category_id ) ) );
	}

	/** Whether a review belongs to the given category term. */
	private static function in_category( int $post_id, int $category_id ): bool {
		foreach ( get_the_category( $post_id ) as $term ) {
			if ( (int) $term->term_id === $category_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hydrate full WP_Post objects only for the final ordered IDs, preserving the
	 * computed order regardless of the store's default ordering.
	 *
	 * @param list<int> $ids Ordered, already-limited review IDs.
	 * @return array<int, \WP_Post> Hydrated posts in the supplied order.
	 */
	private static function hydrate( array $ids ): array {
		$ids   = array_values( array_map( 'intval', $ids ) );
		$posts = get_posts(
			array(
				'post_type'              => 'review',
				'post_status'            => 'publish',
				'post__in'               => $ids,
				'orderby'                => 'post__in',
				'posts_per_page'         => count( $ids ),
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => true,
				'update_post_meta_cache' => true,
			)
		);
		$order = array_flip( $ids );
		usort( $posts, static fn( $left, $right ): int => ( $order[ (int) $left->ID ] ?? PHP_INT_MAX ) <=> ( $order[ (int) $right->ID ] ?? PHP_INT_MAX ) );
		return $posts;
	}

	/**
	 * Deterministically order eligible WP_Post-like records.
	 *
	 * @param array  $posts WP_Post-like records.
	 * @param string $sort  Allowlisted sort key.
	 */
	public static function sort( array $posts, string $sort = 'score' ): array {
		$sort = self::normalize_sort( $sort );
		usort( $posts, static fn( $left, $right ): int => self::compare_ids( (int) $left->ID, (int) $right->ID, $sort ) );
		return $posts;
	}

	/**
	 * Deterministically order bare review IDs using the same tie-breaking chain
	 * as sort(). Used to order the complete filtered population before any limit.
	 *
	 * @param list<int> $ids  Review IDs.
	 * @param string    $sort Allowlisted sort key.
	 * @return list<int> Ordered IDs.
	 */
	public static function sort_ids( array $ids, string $sort = 'score' ): array {
		$sort = self::normalize_sort( $sort );
		$ids  = array_values( array_map( 'intval', $ids ) );
		usort( $ids, static fn( int $left, int $right ): int => self::compare_ids( $left, $right, $sort ) );
		return $ids;
	}

	/**
	 * Filter the complete population, sort it, and only then apply the caller's
	 * limit. Filtering and sorting always run over every candidate so a
	 * top-ranked review can never be dropped by a pre-sort cap.
	 *
	 * @param list<int> $ids     Complete candidate review IDs.
	 * @param string    $sort    Allowlisted sort key.
	 * @param array     $filters Allowlisted filter values.
	 * @param int       $limit   Caller's requested maximum, applied last.
	 * @return list<int> Ordered, limited review IDs.
	 */
	public static function order_and_limit( array $ids, string $sort, array $filters, int $limit ): array {
		$ids      = array_values( array_map( 'intval', $ids ) );
		$filtered = array_values( array_filter( $ids, static fn( int $id ): bool => self::passes_filters( $id, $filters ) ) );
		$ordered  = self::sort_ids( $filtered, $sort );
		return array_slice( $ordered, 0, max( 1, $limit ) );
	}

	/** Whether a review's metadata satisfies the allowlisted filter values. */
	private static function passes_filters( int $post_id, array $filters ): bool {
		if ( isset( $filters['confidence'] ) && (string) get_post_meta( $post_id, 'review_score_confidence', true ) !== $filters['confidence'] ) {
			return false;
		}
		if ( isset( $filters['subscription'] ) ) {
			$required = (bool) get_post_meta( $post_id, 'subscription_required', true );
			if ( ( 'required' === $filters['subscription'] ) !== $required ) {
				return false;
			}
		}
		return true;
	}

	/** Reduce an arbitrary sort request to an allowlisted key. */
	private static function normalize_sort( string $sort ): string {
		return in_array( $sort, array( 'score', 'confidence', 'updated', 'title' ), true ) ? $sort : 'score';
	}

	/**
	 * Shared deterministic comparison used by every ranking sort. Returns a
	 * negative, zero, or positive integer ordering $left_id relative to
	 * $right_id for the requested sort key, always resolving ties by ascending ID.
	 */
	private static function compare_ids( int $left_id, int $right_id, string $sort ): int {
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
		$threshold  = Review_Methodology::minimum_meaningful_difference();
		$bands      = array();
		$current    = array();
		$prev_score = null;
		foreach ( $posts as $post ) {
			$score = (float) get_post_meta( $post->ID, 'review_score', true );
			if ( null !== $prev_score && ( $prev_score - $score ) > $threshold ) {
				$bands[] = $current;
				$current = array();
			}
			$current[]  = $post;
			$prev_score = $score;
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
		$groups = self::aggregate_directory( self::eligible_ids() );
		wp_cache_set( $cache_key, $groups, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $groups;
	}

	/**
	 * Group the complete eligible population into per-category aggregates. Counts,
	 * latest-update, and top-score span every supplied review so directory and
	 * minimum-inventory decisions never use a truncated subset.
	 *
	 * @param list<int> $ids Complete eligible review IDs.
	 * @return list<array{term:\WP_Term,count:int,latest:string,highest_score:float}>
	 */
	public static function aggregate_directory( array $ids ): array {
		$groups = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			foreach ( get_the_category( $id ) as $term ) {
				$term_id = (int) $term->term_id;
				if ( ! isset( $groups[ $term_id ] ) ) {
					$groups[ $term_id ] = array(
						'term'          => $term,
						'count'         => 0,
						'latest'        => '',
						'highest_score' => 0.0,
					);
				}
				++$groups[ $term_id ]['count'];
				$groups[ $term_id ]['latest']        = max( $groups[ $term_id ]['latest'], (string) get_post_meta( $id, 'last_material_update', true ) );
				$groups[ $term_id ]['highest_score'] = max( $groups[ $term_id ]['highest_score'], (float) get_post_meta( $id, 'review_score', true ) );
			}
		}
		$groups = array_values( $groups );
		usort( $groups, static fn( $left, $right ): int => strcasecmp( $left['term']->name, $right['term']->name ) );
		return $groups;
	}
}

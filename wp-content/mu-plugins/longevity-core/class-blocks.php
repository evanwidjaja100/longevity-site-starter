<?php
/**
 * First-party dynamic trust blocks.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Registers editor blocks that share the public component renderer. */
final class Blocks {
	/**
	 * Block slug to shared public component renderer callback map.
	 *
	 * @var array<string, array{0:string,1:string}>
	 */
	private const RENDERERS = array(
		'article-meta'           => array( Public_Trust::class, 'render_article_meta' ),
		'trust-summary'          => array( Public_Trust::class, 'render_trust_summary' ),
		'reviewer-card'          => array( Public_Trust::class, 'render_reviewer_card' ),
		'source-list'            => array( Public_Rankings::class, 'render_source_list' ),
		'table-of-contents'      => array( Public_Content::class, 'render_table_of_contents' ),
		'review-score'           => array( Public_Rankings::class, 'render_review_score' ),
		'review-decision'        => array( Public_Rankings::class, 'render_review_decision' ),
		'test-method'            => array( Public_Rankings::class, 'render_test_method' ),
		'corrections'            => array( Public_Content::class, 'render_corrections' ),
		'related-content'        => array( Public_Content::class, 'render_related_content' ),
		'content-card-meta'      => array( Public_Content::class, 'render_content_card_meta' ),
		'breadcrumbs'            => array( Public_Nav::class, 'render_breadcrumbs' ),
		'product-report-summary' => array( Public_Rankings::class, 'render_product_report_summary' ),
		'test-results'           => array( Public_Rankings::class, 'render_test_results' ),
		'claim-evidence-matrix'  => array( Public_Rankings::class, 'render_claim_evidence_matrix' ),
	);

	/** Register blocks on init. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ), 20 );
	}

	/** Register metadata and server callbacks. */
	public static function register(): void {
		wp_register_script(
			'longevity-core-blocks',
			LONGEVITY_CORE_URL . 'assets/blocks.js',
			array( 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n' ),
			LONGEVITY_CORE_VERSION,
			true
		);
		foreach ( self::RENDERERS as $slug => $method ) {
			register_block_type(
				LONGEVITY_CORE_PATH . 'blocks/' . $slug,
				array(
					'render_callback' => static function ( array $attributes, string $content, \WP_Block $block ) use ( $method ): string {
						unset( $attributes, $content );
						$post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();
						return $method[0]::{$method[1]}( $post_id );
					},
				)
			);
		}

		register_block_type(
			LONGEVITY_CORE_PATH . 'blocks/search-filters',
			array( 'render_callback' => static fn() => Public_Content::render_search_filters() )
		);
		register_block_type(
			LONGEVITY_CORE_PATH . 'blocks/author-profile',
			array( 'render_callback' => static fn() => Public_Content::render_author_profile() )
		);
		register_block_type(
			LONGEVITY_CORE_PATH . 'blocks/topic-directory',
			array( 'render_callback' => static fn() => Public_Content::render_topic_directory() )
		);
		register_block_type(
			LONGEVITY_CORE_PATH . 'blocks/guide-directory',
			array( 'render_callback' => static fn() => Public_Content::render_guide_directory() )
		);
		register_block_type(
			LONGEVITY_CORE_PATH . 'blocks/ranking-directory',
			array( 'render_callback' => static fn() => Public_Rankings::render_ranking_directory() )
		);
		register_block_type(
			LONGEVITY_CORE_PATH . 'blocks/ranking-list',
			array( 'render_callback' => static fn() => Public_Rankings::render_ranking_list() )
		);
	}
}

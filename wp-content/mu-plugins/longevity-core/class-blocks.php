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
	/** @var array<string, string> */
	private const RENDERERS = array(
		'article-meta'      => 'render_article_meta',
		'trust-summary'     => 'render_trust_summary',
		'reviewer-card'     => 'render_reviewer_card',
		'source-list'       => 'render_source_list',
		'table-of-contents' => 'render_table_of_contents',
		'review-score'      => 'render_review_score',
		'review-decision'   => 'render_review_decision',
		'test-method'       => 'render_test_method',
		'corrections'       => 'render_corrections',
		'related-content'   => 'render_related_content',
		'content-card-meta' => 'render_content_card_meta',
		'breadcrumbs'       => 'render_breadcrumbs',
		'product-report-summary' => 'render_product_report_summary',
		'test-results'      => 'render_test_results',
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
						return Public_Components::$method( $post_id );
					},
				)
			);
		}

		register_block_type(
			LONGEVITY_CORE_PATH . 'blocks/search-filters',
			array( 'render_callback' => static fn() => Public_Components::render_search_filters() )
		);
		register_block_type(
			LONGEVITY_CORE_PATH . 'blocks/author-profile',
			array( 'render_callback' => static fn() => Public_Components::render_author_profile() )
		);
		register_block_type(
			LONGEVITY_CORE_PATH . 'blocks/ranking-directory',
			array( 'render_callback' => static fn() => Public_Components::render_ranking_directory() )
		);
		register_block_type(
			LONGEVITY_CORE_PATH . 'blocks/ranking-list',
			array( 'render_callback' => static fn() => Public_Components::render_ranking_list() )
		);
	}
}

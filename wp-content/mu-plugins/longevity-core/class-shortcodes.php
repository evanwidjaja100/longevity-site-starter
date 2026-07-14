<?php
/**
 * Backward-compatible shortcode adapters.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Maps legacy shortcodes to shared server-side renderers. */
final class Shortcodes {
	/** Register existing public shortcode names without changing their contracts. */
	public static function init(): void {
		add_shortcode( 'affiliate_link', array( self::class, 'affiliate_link' ) );
		add_shortcode( 'medical_disclaimer', static fn() => Public_Components::render_medical_disclaimer() );
		add_shortcode( 'review_box', array( self::class, 'review_box' ) );
		add_shortcode( 'longevity_article_meta', static fn() => Public_Components::render_article_meta( (int) get_the_ID() ) );
		add_shortcode( 'longevity_trust_summary', static fn() => Public_Components::render_trust_summary( (int) get_the_ID() ) );
		add_shortcode( 'longevity_reviewer_card', static fn() => Public_Components::render_reviewer_card( (int) get_the_ID() ) );
		add_shortcode( 'longevity_test_method', static fn() => Public_Components::render_test_method( (int) get_the_ID() ) );
		add_shortcode( 'longevity_review_score', static fn() => Public_Components::render_review_score( (int) get_the_ID() ) );
		add_shortcode( 'longevity_corrections', static fn() => Public_Components::render_corrections( (int) get_the_ID() ) );
		add_shortcode( 'longevity_policy_links', static fn() => Public_Components::render_policy_links() );
		add_shortcode( 'longevity_footer_meta', static fn() => Public_Components::render_footer_meta() );
	}

	/** Preserve the affiliate shortcode interface and registry enforcement. */
	public static function affiliate_link( array $atts ): string {
		$atts = shortcode_atts( array( 'url' => '', 'label' => __( 'Check current price', 'longevity-core' ), 'placement' => 'article' ), $atts, 'affiliate_link' );
		return Affiliate_Registry::render_link( (string) $atts['url'], (string) $atts['label'], (string) $atts['placement'] );
	}

	/** Preserve the legacy review-box attributes. */
	public static function review_box( array $atts ): string {
		$atts = shortcode_atts( array( 'score' => '', 'best_for' => '', 'tested' => '' ), $atts, 'review_box' );
		return Public_Components::render_legacy_review_box( $atts );
	}
}

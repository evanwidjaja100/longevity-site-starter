<?php
/**
 * Theme bootstrap.
 *
 * @package LongevityStarter
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'longevity-starter', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
		wp_enqueue_style( 'longevity-consumer-lab', get_theme_file_uri( 'assets/css/consumer-lab.css' ), array( 'longevity-starter' ), wp_get_theme()->get( 'Version' ) );
		wp_enqueue_script( 'longevity-site-ui', get_theme_file_uri( 'assets/js/site-ui.js' ), array(), wp_get_theme()->get( 'Version' ), true );
	}
);

add_action(
	'after_setup_theme',
	static function (): void {
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_block_template_skip_link' );
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'editor-styles' );
		add_editor_style( array( 'style.css', 'assets/css/consumer-lab.css' ) );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'custom-logo' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
		add_image_size( 'longevity-card', 720, 450, true );
		add_image_size( 'longevity-hero', 1440, 900, false );
	}
);

/** Describe responsive card and article-image display widths to WordPress. */
add_filter(
	'wp_calculate_image_sizes',
	static function ( string $sizes, array $size ): string {
		if ( $size[0] <= 720 ) {
			return '(max-width: 700px) 100vw, (max-width: 1000px) 50vw, 33vw';
		}
		return $sizes;
	},
	10,
	2
);

/** Keep excerpts concise; templates provide explicit link labels. */
add_filter( 'excerpt_more', static fn() => '&hellip;' );

/** Make skip-link targets programmatically focusable without changing tab order. */
add_filter(
	'render_block_core/group',
	static function ( string $content, array $block ): string {
		if ( 'main' !== ( $block['attrs']['tagName'] ?? '' ) || 'main-content' !== ( $block['attrs']['anchor'] ?? '' ) ) {
			return $content;
		}
		return (string) preg_replace( '/<main\b/', '<main tabindex="-1"', $content, 1 );
	},
	10,
	2
);

/** Add semantic body classes for trust-oriented layouts. */
add_filter(
	'body_class',
	static function ( array $classes ): array {
		if ( is_singular( 'review' ) ) {
			$classes[] = 'is-product-review';
		}
		if ( is_singular( array( 'post', 'review' ) ) ) {
			$classes[] = 'is-evidence-article';
		}
		return $classes;
	}
);

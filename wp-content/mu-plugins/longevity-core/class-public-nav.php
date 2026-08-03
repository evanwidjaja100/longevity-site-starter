<?php
/**
 * Public navigation components.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Renders breadcrumbs, footer nav, and policy links. */
class Public_Nav {
	/**
	 * Render visible breadcrumbs from the same hierarchy used by schema.
	 *
	 * @param int $post_id Optional post ID; 0 resolves the queried object.
	 */
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

	/**
	 * Get deterministic visible breadcrumb facts.
	 *
	 * @param int $post_id Optional post ID; 0 resolves the queried object.
	 */
	public static function breadcrumb_items( int $post_id = 0 ): array {
		$items = array(
			array(
				'name' => get_bloginfo( 'name' ),
				'url'  => home_url( '/' ),
			),
		);
		if ( is_search() ) {
			$items[] = array(
				'name' => __( 'Search', 'longevity-core' ),
				'url'  => '',
			);
			return $items;
		}
		if ( is_category() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$items[] = array(
					'name' => $term->name,
					'url'  => '',
				);
			}
			return $items;
		}
		if ( is_author() ) {
			$user = get_queried_object();
			if ( $user instanceof \WP_User ) {
				$items[] = array(
					'name' => $user->display_name,
					'url'  => '',
				);
			}
			return $items;
		}
		if ( is_post_type_archive( 'review' ) ) {
			$items[] = array(
				'name' => __( 'Consumer Lab', 'longevity-core' ),
				'url'  => '',
			);
			return $items;
		}
		$post_id = $post_id > 0 ? $post_id : get_queried_object_id();
		if ( $post_id <= 0 ) {
			return $items;
		}
		if ( 'review' === get_post_type( $post_id ) ) {
			$items[] = array(
				'name' => __( 'Consumer Lab', 'longevity-core' ),
				'url'  => get_post_type_archive_link( 'review' ),
			);
		} else {
			$categories = get_the_category( $post_id );
			if ( $categories ) {
				$items[] = array(
					'name' => $categories[0]->name,
					'url'  => get_category_link( $categories[0]->term_id ),
				);
			}
		}
		$items[] = array(
			'name' => get_the_title( $post_id ),
			'url'  => '',
		);
		return $items;
	}

	/** Render route-aware footer navigation. */
	public static function render_footer_nav(): string {
		$groups = array(
			'Explore'     => array(
				'start_here' => __( 'Start Here', 'longevity-core' ),
			),
			'How We Work' => array(
				'evidence_methodology' => __( 'Evidence Methodology', 'longevity-core' ),
				'testing_methodology'  => __( 'Testing Methodology', 'longevity-core' ),
			),
			'About'       => array(
				'about'   => __( 'About the publication', 'longevity-core' ),
				'contact' => __( 'Contact', 'longevity-core' ),
			),
			'Policies'    => array(
				'editorial_policy'     => __( 'Editorial Policy', 'longevity-core' ),
				'medical_disclaimer'   => __( 'Medical Disclaimer', 'longevity-core' ),
				'affiliate_disclosure' => __( 'Affiliate Disclosure', 'longevity-core' ),
				'corrections'          => __( 'Corrections', 'longevity-core' ),
				'privacy'              => __( 'Privacy', 'longevity-core' ),
				'terms'                => __( 'Terms', 'longevity-core' ),
			),
		);

		$html  = '<div class="longevity-footer-grid alignwide">';
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

	/** Render portable policy links. */
	public static function render_policy_links(): string {
		$links = array(
			'about'                => __( 'About', 'longevity-core' ),
			'editorial-policy'     => __( 'Editorial Policy', 'longevity-core' ),
			'medical-disclaimer'   => __( 'Medical Disclaimer', 'longevity-core' ),
			'affiliate-disclosure' => __( 'Affiliate Disclosure', 'longevity-core' ),
			'corrections'          => __( 'Corrections', 'longevity-core' ),
			'testing-methodology'  => __( 'Testing Methodology', 'longevity-core' ),
			'privacy'              => __( 'Privacy', 'longevity-core' ),
			'terms'                => __( 'Terms', 'longevity-core' ),
			'contact'              => __( 'Contact', 'longevity-core' ),
		);
		$html  = '<nav class="longevity-policy-nav" aria-label="' . esc_attr__( 'Publication policies', 'longevity-core' ) . '"><ul>';
		foreach ( $links as $slug => $label ) {
			$url = Routes::public_page_url( $slug );
			if ( null === $url ) {
				$url = home_url( '/' . $slug . '/' );
			}
			$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		return $html . '</ul></nav>';
	}
}

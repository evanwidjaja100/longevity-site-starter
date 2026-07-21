<?php
/**
 * Conservative schema graph builder.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Emits visible-content-backed JSON-LD when no equivalent SEO provider owns schema. */
final class Schema {
	/** Register hooks. */
	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'output' ), 30 );
	}

	/**
	 * Output conservative social metadata.
	 *
	 * @deprecated 3.1.0 Social meta is handled by SEO::output_social_meta() at priority 4.
	 *             Kept as a no-op for external callers.
	 */
	public static function output_social_meta(): void {
		_deprecated_function( __METHOD__, '3.1.0', 'SEO::output_social_meta' );
	}

	/** Output the JSON-LD graph. */
	public static function output(): void {
		if ( self::seo_provider_owns_schema() ) {
			return;
		}
		$graph = self::build_graph();
		if ( empty( $graph ) ) {
			return;
		}
		$schema = array( '@context' => 'https://schema.org', '@graph' => $graph );
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	/** Build a graph for the current request. */
	public static function build_graph(): array {
		$home       = home_url( '/' );
		$org_id     = trailingslashit( $home ) . '#organization';
		$website_id = trailingslashit( $home ) . '#website';
		$graph      = array(
			array_filter(
				array(
					'@type'       => 'Organization',
					'@id'         => $org_id,
					'name'        => get_bloginfo( 'name' ),
					'url'         => $home,
					'description' => get_bloginfo( 'description' ),
					'logo'        => self::logo_schema(),
				),
				static fn( $value ) => ! empty( $value )
			),
			array(
				'@type'     => 'WebSite',
				'@id'       => $website_id,
				'url'       => $home,
				'name'      => get_bloginfo( 'name' ),
				'inLanguage'=> get_bloginfo( 'language' ),
				'publisher' => array( '@id' => $org_id ),
			),
		);

		if ( is_singular( array( 'post', 'review', 'page' ) ) ) {
			$post_id = get_queried_object_id();
			$graph   = array_merge( $graph, self::singular_graph( $post_id, $org_id, $website_id ) );
		}
		if ( is_post_type_archive( 'review' ) ) {
			$items = array();
			foreach ( Rankings::directory() as $index => $group ) {
				$items[] = array( '@type' => 'ListItem', 'position' => $index + 1, 'name' => $group['term']->name, 'url' => get_category_link( $group['term']->term_id ) );
			}
			$collection = array( '@type' => 'CollectionPage', '@id' => get_post_type_archive_link( 'review' ) . '#collection', 'url' => get_post_type_archive_link( 'review' ), 'name' => __( 'Consumer Lab rankings', 'longevity-core' ), 'isPartOf' => array( '@id' => $website_id ) );
			if ( $items ) {
				$collection['mainEntity'] = array( '@type' => 'ItemList', 'itemListElement' => $items );
			}
			$graph[] = $collection;
		}
		if ( is_category() ) {
			$term = get_queried_object();
			$ranked = $term instanceof \WP_Term ? Rankings::reviews( (int) $term->term_id ) : array();
			if ( $ranked ) {
				$items = array();
				foreach ( $ranked as $index => $review ) {
					$items[] = array( '@type' => 'ListItem', 'position' => $index + 1, 'name' => get_the_title( $review ), 'url' => get_permalink( $review ) );
				}
				$graph[] = array( '@type' => 'CollectionPage', '@id' => get_category_link( $term->term_id ) . '#ranking', 'url' => get_category_link( $term->term_id ), 'name' => $term->name . ' ' . __( 'Consumer Lab ranking', 'longevity-core' ), 'mainEntity' => array( '@type' => 'ItemList', 'itemListElement' => $items ), 'isPartOf' => array( '@id' => $website_id ) );
			}
		}
		return $graph;
	}

	/** Build page, article, author, reviewer, breadcrumb, and optional review entities. */
	private static function singular_graph( int $post_id, string $org_id, string $website_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$url         = get_permalink( $post_id );
		$page_id     = $url . '#webpage';
		$article_id  = $url . '#article';
		$author_id   = $url . '#author';
		$breadcrumb  = $url . '#breadcrumb';
		$description = get_the_excerpt( $post_id );
		if ( '' === $description ) {
			$description = (string) get_post_meta( $post_id, 'content_summary', true );
		}
		$graph = array();
		$graph[] = array_filter(
			array(
				'@type'      => 'WebPage',
				'@id'        => $page_id,
				'url'        => $url,
				'name'       => get_the_title( $post_id ),
				'description'=> $description,
				'isPartOf'   => array( '@id' => $website_id ),
				'breadcrumb' => array( '@id' => $breadcrumb ),
				'inLanguage' => get_bloginfo( 'language' ),
			),
			static fn( $value ) => ! empty( $value )
		);
		$graph[] = self::breadcrumb_schema( $post_id, $breadcrumb );

		if ( 'page' === $post->post_type ) {
			return $graph;
		}

		$author_user_id = (int) $post->post_author;
		$graph[] = array_filter(
			array(
				'@type'       => 'Person',
				'@id'         => $author_id,
				'name'        => get_the_author_meta( 'display_name', $author_user_id ),
				'url'         => get_author_posts_url( $author_user_id ),
				'description' => get_the_author_meta( 'description', $author_user_id ),
			),
			static fn( $value ) => ! empty( $value )
		);

		$article = array(
			'@type'            => 'BlogPosting',
			'@id'              => $article_id,
			'url'              => $url,
			'headline'         => get_the_title( $post_id ),
			'description'      => $description,
			'mainEntityOfPage' => array( '@id' => $page_id ),
			'datePublished'    => get_the_date( DATE_W3C, $post_id ),
			'dateModified'     => get_the_modified_date( DATE_W3C, $post_id ),
			'author'           => array( '@id' => $author_id ),
			'publisher'        => array( '@id' => $org_id ),
			'isPartOf'         => array( '@id' => $website_id ),
			'inLanguage'       => get_bloginfo( 'language' ),
			'wordCount'        => str_word_count( wp_strip_all_tags( $post->post_content ) ),
			'articleSection'   => self::article_sections( $post_id ),
			'image'            => self::image_schema( $post_id ),
			'citation'         => Claims::citations_for_post( $post_id ),
		);

		$reviewer = self::reviewer_schema( $post_id, $url );
		if ( $reviewer ) {
			$graph[]               = $reviewer['person'];
			$article['reviewedBy'] = array( '@id' => $reviewer['person']['@id'] );
		}
		$article = array_filter( $article, static fn( $value ) => ! empty( $value ) || 0 === $value );
		$graph[] = $article;

		if ( 'review' === $post->post_type ) {
			$review = self::review_schema( $post_id, $article_id, $url );
			if ( $review ) {
				$graph[] = $review['product'];
				$graph[] = $review['review'];
			}
		}
		return $graph;
	}

	/** Build a review entity only for a specific product with real score/method metadata. */
	private static function review_schema( int $post_id, string $article_id, string $url ): ?array {
		$model      = trim( (string) get_post_meta( $post_id, 'tested_product_model', true ) );
		$score      = (float) get_post_meta( $post_id, 'review_score', true );
		$version    = trim( (string) get_post_meta( $post_id, 'review_score_version', true ) );
		$confidence = trim( (string) get_post_meta( $post_id, 'review_score_confidence', true ) );
		$test_state = (string) get_post_meta( $post_id, 'testing_status', true );
		$record_id  = (int) get_post_meta( $post_id, 'test_record_id', true );
		$dimensions = get_post_meta( $post_id, 'review_score_dimensions', true );
		$disclosure = (string) get_post_meta( $post_id, 'affiliate_disclosure_status', true );
		if ( ! Rankings::is_eligible( $post_id ) || '' === $model || $score <= 0 || '' === $version || '' === $confidence || ! in_array( $test_state, array( 'complete', 'approved' ), true ) || ! Review_Methodology::valid_test_record( $record_id, (string) get_post_meta( $post_id, 'testing_protocol_version', true ) ) ) {
			return null;
		}
		if ( ! in_array( get_post_meta( $post_id, 'commercial_relationship', true ), array( '', 'none' ), true ) && ! in_array( $disclosure, array( 'approved', 'complete' ), true ) ) {
			return null;
		}
		try {
			$calculated = Review_Methodology::calculate_score( is_array( $dimensions ) ? $dimensions : array() );
		} catch ( \InvalidArgumentException $exception ) {
			return null;
		}
		$override = trim( (string) get_post_meta( $post_id, 'review_score_override_reason', true ) );
		if ( abs( (float) $calculated['score'] - $score ) > 0.01 && '' === $override ) {
			return null;
		}
		$product_id = $url . '#product';
		$review_id  = $url . '#review';
		return array(
			'product' => array(
				'@type' => 'Product',
				'@id'   => $product_id,
				'name'  => $model,
			),
			'review' => array(
				'@type'        => 'Review',
				'@id'          => $review_id,
				'itemReviewed' => array( '@id' => $product_id ),
				'reviewBody'   => (string) get_post_meta( $post_id, 'content_summary', true ),
				'reviewRating' => array(
					'@type'       => 'Rating',
					'ratingValue' => $score,
					'bestRating'  => 5,
					'worstRating' => 0,
				),
				'isBasedOn'    => array( '@id' => $article_id ),
			),
		);
	}

	/** Build reviewer person entity only for completed scoped review. */
	private static function reviewer_schema( int $post_id, string $url ): ?array {
		if ( 'complete' !== get_post_meta( $post_id, 'medical_review_status', true ) ) {
			return null;
		}
		$user_id = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
		if ( $user_id <= 0 ) {
			return null;
		}
		$name = get_the_author_meta( 'display_name', $user_id );
		$credentials = get_user_meta( $user_id, 'professional_credentials', true );
		if ( 'verified' !== get_user_meta( $user_id, 'credential_verification_status', true ) || '' === trim( (string) $name ) || '' === trim( (string) $credentials ) ) {
			return null;
		}
		return array(
			'person' => array_filter(
				array(
					'@type'       => 'Person',
					'@id'         => $url . '#medical-reviewer',
					'name'        => $name,
					'url'         => get_author_posts_url( $user_id ),
					'description' => $credentials,
				),
				static fn( $value ) => ! empty( $value )
			),
		);
	}

	/** Build breadcrumbs from visible navigation facts. */
	private static function breadcrumb_schema( int $post_id, string $id ): array {
		$visible = Public_Components::breadcrumb_items( $post_id );
		$items   = array();
		foreach ( $visible as $index => $item ) {
			$items[] = array( '@type' => 'ListItem', 'position' => $index + 1, 'name' => $item['name'], 'item' => $item['url'] ?: get_permalink( $post_id ) );
		}
		return array( '@type' => 'BreadcrumbList', '@id' => $id, 'itemListElement' => $items );
	}

	/** Get visible article sections. */
	private static function article_sections( int $post_id ): array {
		return array_values( array_map( static fn( $term ) => $term->name, get_the_category( $post_id ) ) );
	}

	/** Get image schema from a real featured image. */
	private static function image_schema( int $post_id ): ?array {
		$image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
		if ( ! $image ) {
			return null;
		}
		return array( '@type' => 'ImageObject', 'url' => $image[0], 'width' => $image[1], 'height' => $image[2] );
	}

	/** Get site logo schema when configured. */
	private static function logo_schema(): ?array {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$image   = $logo_id ? wp_get_attachment_image_src( $logo_id, 'full' ) : false;
		if ( ! $image ) {
			return null;
		}
		return array( '@type' => 'ImageObject', 'url' => $image[0], 'width' => $image[1], 'height' => $image[2] );
	}

	/** Detect supported SEO providers that already produce equivalent schema. */
	private static function seo_provider_owns_schema(): bool {
		$detected = defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| class_exists( 'WPSEO_Options' )
			|| class_exists( 'RankMath' )
			|| function_exists( 'aioseo' );
		return (bool) apply_filters( 'longevity_core_disable_schema', $detected );
	}
}

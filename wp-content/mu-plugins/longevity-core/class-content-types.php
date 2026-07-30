<?php
/**
 * First-party content types and workflow statuses.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Registers public and administrative content types. */
final class Content_Types {
	/** Register hooks. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ), 5 );
	}

	/** Register content types and statuses. */
	public static function register(): void {
		register_post_type(
			'review',
			array(
				'labels'          => array(
					'name'          => __( 'Reviews', 'longevity-core' ),
					'singular_name' => __( 'Review', 'longevity-core' ),
				),
				'public'          => true,
				'show_in_rest'    => true,
				'has_archive'     => true,
				'rewrite'         => array( 'slug' => 'reviews' ),
				'menu_icon'       => 'dashicons-star-filled',
				'supports'        => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'revisions', 'custom-fields' ),
				'taxonomies'      => array( 'category', 'post_tag' ),
				'capability_type' => array( 'review', 'reviews' ),
				'map_meta_cap'    => true,
			)
		);

		self::register_private_type( 'lel_claim', __( 'Claims', 'longevity-core' ), __( 'Claim', 'longevity-core' ), 'dashicons-yes-alt', 'manage_claims' );
		self::register_private_type( 'lel_source', __( 'Sources', 'longevity-core' ), __( 'Source', 'longevity-core' ), 'dashicons-book-alt', 'manage_claims' );
		self::register_private_type( 'lel_protocol', __( 'Test Protocols', 'longevity-core' ), __( 'Test Protocol', 'longevity-core' ), 'dashicons-clipboard', 'manage_test_protocols' );
		self::register_private_type( 'lel_test_record', __( 'Test Records', 'longevity-core' ), __( 'Test Record', 'longevity-core' ), 'dashicons-chart-line', 'manage_test_records' );
		self::register_private_type( 'lel_correction', __( 'Corrections', 'longevity-core' ), __( 'Correction', 'longevity-core' ), 'dashicons-undo', 'manage_corrections' );
		self::register_private_type( 'lel_affiliate', __( 'Affiliate Registry', 'longevity-core' ), __( 'Affiliate Merchant', 'longevity-core' ), 'dashicons-money-alt', 'manage_affiliate_registry' );
		self::register_private_type( 'longevity_message', __( 'Messages', 'longevity-core' ), __( 'Message', 'longevity-core' ), 'dashicons-email-alt', 'manage_options' );

		$statuses = array(
			'lel_assigned'           => __( 'Assigned', 'longevity-core' ),
			'lel_researching'        => __( 'Researching', 'longevity-core' ),
			'lel_editorial_review'   => __( 'Editorial review', 'longevity-core' ),
			'lel_fact_check'         => __( 'Fact-check', 'longevity-core' ),
			'lel_medical_review'     => __( 'Medical review', 'longevity-core' ),
			'lel_testing_incomplete' => __( 'Testing incomplete', 'longevity-core' ),
			'lel_commercial_review'  => __( 'Commercial review', 'longevity-core' ),
			'lel_ready'              => __( 'Ready for publication', 'longevity-core' ),
			'lel_update_due'         => __( 'Update due', 'longevity-core' ),
			'lel_correction_pending' => __( 'Correction pending', 'longevity-core' ),
			'lel_archived'           => __( 'Archived', 'longevity-core' ),
		);

		foreach ( $statuses as $key => $label ) {
			register_post_status(
				$key,
				array(
					'label'                     => $label,
					'public'                    => false,
					'internal'                  => false,
					'protected'                 => true,
					'show_in_admin_status_list' => true,
					'show_in_admin_all_list'    => true,
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'longevity-core' ),
				)
			);
		}
	}

	/** Register an administrative CPT. */
	private static function register_private_type( string $post_type, string $plural, string $singular, string $icon, string $capability ): void {
		register_post_type(
			$post_type,
			array(
				'labels'              => array(
					'name'          => $plural,
					'singular_name' => $singular,
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'menu_icon'           => $icon,
				'supports'            => array( 'title', 'editor', 'author', 'revisions', 'custom-fields' ),
				'capabilities'        => array(
					'edit_post'              => $capability,
					'read_post'              => $capability,
					'delete_post'            => $capability,
					'edit_posts'             => $capability,
					'edit_others_posts'      => $capability,
					'publish_posts'          => $capability,
					'read_private_posts'     => $capability,
					'delete_posts'           => $capability,
					'delete_private_posts'   => $capability,
					'delete_published_posts' => $capability,
					'delete_others_posts'    => $capability,
					'edit_private_posts'     => $capability,
					'edit_published_posts'   => $capability,
					'create_posts'           => $capability,
				),
				'map_meta_cap'        => false,
			)
		);
	}
}

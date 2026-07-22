<?php
/**
 * Editorial roles and capabilities.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Registers least-privilege editorial roles. */
final class Roles {
	/** @var array<int, string> */
	private const ALL_CUSTOM_CAPS = array(
		'submit_for_fact_check',
		'complete_fact_check',
		'submit_for_medical_review',
		'complete_medical_review',
		'approve_commercial_disclosure',
		'approve_publication',
		'approve_publication_override',
		'manage_corrections',
		'manage_test_protocols',
		'manage_affiliate_registry',
		'manage_claims',
		'edit_claims',
		'verify_claims',
		'manage_test_records',
		'approve_test_records',
		'manage_affiliate_relationships',
		'verify_reviewer_credentials',
		'view_operational_readiness',
		'view_governance_audit',
	);

	/** @var array<int, string> */
	private const EDITORIAL_MANAGER_CAPS = array(
		'submit_for_fact_check',
		'submit_for_medical_review',
		'approve_commercial_disclosure',
		'approve_publication',
		'manage_corrections',
		'manage_claims',
		'edit_claims',
		'verify_claims',
		'manage_test_records',
		'approve_test_records',
		'manage_affiliate_relationships',
		'view_operational_readiness',
		'view_governance_audit',
	);

	/** Register hooks. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ), 20 );
	}

	/** Add roles and capabilities idempotently. */
	public static function register(): void {
		add_role(
			'lel_writer',
			__( 'Writer', 'longevity-core' ),
			array(
				'read'                      => true,
				'edit_posts'                => true,
				'delete_posts'              => true,
				'upload_files'              => true,
				'submit_for_fact_check'     => true,
				'submit_for_medical_review' => true,
				'edit_claims'                => true,
			)
		);
		add_role(
			'lel_fact_checker',
			__( 'Fact checker', 'longevity-core' ),
			array(
				'read'                => true,
				'complete_fact_check' => true,
				'verify_claims'       => true,
			)
		);
		add_role(
			'lel_medical_reviewer',
			__( 'Medical reviewer', 'longevity-core' ),
			array(
				'read'                    => true,
				'complete_medical_review' => true,
			)
		);
		add_role(
			'lel_product_tester',
			__( 'Product tester', 'longevity-core' ),
			array(
				'read'                  => true,
				'manage_test_protocols' => true,
				'manage_test_records'   => true,
				'upload_files'          => true,
			)
		);
		add_role(
			'lel_managing_editor',
			__( 'Managing editor', 'longevity-core' ),
			array(
				'read'                 => true,
				'edit_posts'           => true,
				'edit_others_posts'    => true,
				'edit_published_posts' => true,
				'publish_posts'        => true,
				'delete_posts'         => true,
				'upload_files'         => true,
				'moderate_comments'    => true,
			)
		);

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			self::add_caps( $administrator, self::ALL_CUSTOM_CAPS );
			self::add_review_caps( $administrator );
		}

		foreach ( array( 'editor', 'lel_managing_editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			self::add_caps( $role, self::EDITORIAL_MANAGER_CAPS );
			self::add_review_caps( $role );
		}

		$managing_editor = get_role( 'lel_managing_editor' );
		if ( $managing_editor ) {
			self::add_caps(
				$managing_editor,
				array(
					'approve_publication_override',
					'manage_test_protocols',
					'manage_affiliate_registry',
					'approve_test_records',
					'manage_affiliate_relationships',
					'view_operational_readiness',
					'view_governance_audit',
				)
			);
		}

		$writer = get_role( 'lel_writer' );
		if ( $writer ) {
			$writer->add_cap( 'edit_reviews' );
			$writer->add_cap( 'delete_reviews' );
		}
	}

	/** Add a list of capabilities to a role. */
	private static function add_caps( \WP_Role $role, array $capabilities ): void {
		foreach ( $capabilities as $capability ) {
			$role->add_cap( $capability );
		}
	}

	/** Add public review CPT capabilities. */
	private static function add_review_caps( \WP_Role $role ): void {
		self::add_caps(
			$role,
			array(
				'edit_review',
				'read_review',
				'delete_review',
				'edit_reviews',
				'edit_others_reviews',
				'publish_reviews',
				'read_private_reviews',
				'delete_reviews',
				'delete_private_reviews',
				'delete_published_reviews',
				'delete_others_reviews',
				'edit_private_reviews',
				'edit_published_reviews',
			)
		);
	}
}

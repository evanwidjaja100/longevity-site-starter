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
		'approve_trust_pages',
		'lel_manage_legal_holds',
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

	/** Register hooks (no request-time role reconciliation). */
	public static function init(): void {
	}

	/**
	 * Versioned role reconciliation: diff desired vs actual capabilities,
	 * add required capabilities, and explicitly remove obsolete ones.
	 * Idempotent and safe to re-run.
	 *
	 * @param bool $dry_run When true, report changes without applying them.
	 * @return array{added: list<string>, removed: list<string>, unchanged: int, missing_roles: list<string>}
	 */
	public static function reconcile( bool $dry_run = false ): array {
		$desired       = self::desired_capability_matrix();
		$added         = array();
		$removed       = array();
		$unchanged     = 0;
		$missing_roles = array();

		foreach ( $desired as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				$missing_roles[] = $role_name;
				continue;
			}
			$managed = array_keys( $caps );
			foreach ( $caps as $cap => $grant ) {
				$has = $role->has_cap( $cap );
				if ( $grant && ! $has ) {
					$added[] = $role_name . ':' . $cap;
					if ( ! $dry_run ) {
						$role->add_cap( $cap );
					}
				} elseif ( ! $grant && $has ) {
					$removed[] = $role_name . ':' . $cap;
					if ( ! $dry_run ) {
						$role->remove_cap( $cap );
					}
				} else {
					++$unchanged;
				}
			}
			// Remove any managed custom caps the role holds that are no longer granted by the matrix.
			$role_caps = array_keys( array_filter( (array) $role->capabilities ) );
			foreach ( $role_caps as $cap ) {
				if ( in_array( $cap, self::ALL_CUSTOM_CAPS, true ) && ! in_array( $cap, $managed, true ) ) {
					$removed[] = $role_name . ':' . $cap;
					if ( ! $dry_run ) {
						$role->remove_cap( $cap );
					}
				}
			}
		}

		if ( ! $dry_run ) {
			Audit_Log::record(
				$missing_roles ? 'roles_reconciliation_incomplete' : 'roles_reconciled',
				'system',
				0,
				array(
					'matrix_version' => self::MATRIX_VERSION,
					'added'          => $added,
					'removed'        => $removed,
					'missing_roles'  => $missing_roles,
				),
				get_current_user_id(),
				'cli',
				true
			);
			if ( ! $missing_roles ) {
				update_option( 'lel_roles_reconciled_at', gmdate( DATE_ATOM ), false );
				update_option( 'lel_roles_reconciled_version', self::MATRIX_VERSION, false );
			}
		}

		return array(
			'added'         => $added,
			'removed'       => $removed,
			'unchanged'     => $unchanged,
			'missing_roles' => $missing_roles,
		);
	}

	/** Current capability matrix version for drift detection. */
	public const MATRIX_VERSION = '2.2.0';

	/** The approved capability matrix: role => cap => granted. */
	private static function desired_capability_matrix(): array {
		$matrix = array(
			'administrator' => array_fill_keys( self::ALL_CUSTOM_CAPS, true ),
			'editor'        => array_fill_keys( self::EDITORIAL_MANAGER_CAPS, true ),
			'lel_managing_editor' => array_fill_keys( array_merge( self::EDITORIAL_MANAGER_CAPS, array( 'approve_publication_override', 'manage_test_protocols', 'manage_affiliate_registry', 'approve_test_records', 'manage_affiliate_relationships', 'view_operational_readiness', 'view_governance_audit', 'approve_trust_pages' ) ), true ),
			'lel_writer' => array(
				'read' => true, 'edit_posts' => true, 'delete_posts' => true, 'upload_files' => true,
				'submit_for_fact_check' => true, 'submit_for_medical_review' => true, 'edit_claims' => true,
				'edit_reviews' => true, 'delete_reviews' => true,
			),
			'lel_fact_checker' => array( 'read' => true, 'complete_fact_check' => true, 'verify_claims' => true ),
			'lel_medical_reviewer' => array( 'read' => true, 'complete_medical_review' => true ),
			'lel_product_tester' => array( 'read' => true, 'manage_test_protocols' => true, 'manage_test_records' => true, 'upload_files' => true ),
		);
		// Ensure administrator has all review CPT caps.
		foreach ( array( 'edit_review', 'read_review', 'delete_review', 'edit_reviews', 'edit_others_reviews', 'publish_reviews', 'read_private_reviews', 'delete_reviews', 'delete_private_reviews', 'delete_published_reviews', 'delete_others_reviews', 'edit_private_reviews', 'edit_published_reviews' ) as $cap ) {
			$matrix['administrator'][ $cap ] = true;
			$matrix['editor'][ $cap ]       = true;
			$matrix['lel_managing_editor'][ $cap ] = true;
		}
		return $matrix;
	}

	/** Add roles and capabilities idempotently. */
	public static function register(): void {
		add_role( 'lel_writer', __( 'Writer', 'longevity-core' ), array( 'read' => true, 'edit_posts' => true, 'delete_posts' => true, 'upload_files' => true ) );
		add_role( 'lel_fact_checker', __( 'Fact checker', 'longevity-core' ), array( 'read' => true ) );
		add_role( 'lel_medical_reviewer', __( 'Medical reviewer', 'longevity-core' ), array( 'read' => true ) );
		add_role( 'lel_product_tester', __( 'Product tester', 'longevity-core' ), array( 'read' => true, 'upload_files' => true ) );
		add_role( 'lel_managing_editor', __( 'Managing editor', 'longevity-core' ), array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'edit_published_posts' => true, 'publish_posts' => true, 'delete_posts' => true, 'upload_files' => true, 'moderate_comments' => true ) );

		foreach ( self::desired_capability_matrix() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap => $grant ) {
				if ( $grant ) {
					$role->add_cap( $cap );
				}
			}
		}
	}
}

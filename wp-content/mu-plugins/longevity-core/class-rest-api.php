<?php
/**
 * REST API endpoints.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Provides health and editorial readiness endpoints. */
final class Rest_API {
	/** Register hooks. */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( self::class, 'register_public_projection' ) );
	}

	/** Register REST routes. */
	public static function register_routes(): void {
		register_rest_route(
			'longevity/v1',
			'/health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'health' ),
			)
		);
		register_rest_route(
			'longevity/v1',
			'/system-readiness',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => static fn() => current_user_can( 'approve_publication' ) && current_user_can( 'view_operational_readiness' ),
				'callback'            => static fn() => rest_ensure_response( System_Readiness::report() ),
			)
		);
		register_rest_route(
			'longevity/v1',
			'/readiness/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => static fn( \WP_REST_Request $request ) => current_user_can( 'approve_publication', (int) $request['id'] ) || current_user_can( 'view_operational_readiness' ),
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( Publication_Gates::evaluate( (int) $request['id'] )->to_array() ),
				'args'                => array( 'id' => array( 'validate_callback' => static fn( $value ) => is_numeric( $value ) && (int) $value > 0 ) ),
			)
		);
	}


	/** Register a deliberately small public projection for published content. */
	public static function register_public_projection(): void {
		foreach ( array( 'post', 'review' ) as $post_type ) {
			register_rest_field(
				$post_type,
				'longevity_public',
				array(
					'get_callback' => static fn( array $object ) => self::public_projection( (int) ( $object['id'] ?? 0 ) ),
					'schema'       => array( 'type' => 'object', 'context' => array( 'view' ), 'readonly' => true ),
				)
			);
		}
	}

	/** Return only reviewed, non-sensitive fields; stale approvals fail closed. */
	public static function public_projection( int $post_id ): array {
		if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) ) {
			return array();
		}
		$out = array(
			'content_summary'     => (string) get_post_meta( $post_id, 'content_summary', true ),
			'content_scope'       => (string) get_post_meta( $post_id, 'content_scope', true ),
			'content_limitations' => (string) get_post_meta( $post_id, 'content_limitations', true ),
		);

		$material_health_claims = (bool) get_post_meta( $post_id, 'material_health_claims', true );
		if ( ! $material_health_claims || Approval_Service::is_current( $post_id, 'fact_check' ) ) {
			$out['evidence_grade']       = (string) get_post_meta( $post_id, 'evidence_grade', true );
			$out['evidence_cutoff_date'] = (string) get_post_meta( $post_id, 'evidence_cutoff_date', true );
		}

		$commercial_relationship = (string) get_post_meta( $post_id, 'commercial_relationship', true );
		if ( in_array( $commercial_relationship, array( '', 'none' ), true ) || Approval_Service::is_current( $post_id, 'commercial' ) ) {
			$out['commercial_relationship'] = $commercial_relationship;
		}
		if ( Approval_Service::is_current( $post_id, 'medical' ) ) {
			$reviewer_id = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
			$out['medical_review'] = array(
				'reviewer'         => Reviewer_Credentials::public_snapshot( $reviewer_id ),
				'scope'            => (string) get_post_meta( $post_id, 'medical_review_scope', true ),
				'review_date'      => (string) get_post_meta( $post_id, 'medical_review_date', true ),
				'next_review_date' => (string) get_post_meta( $post_id, 'next_medical_review_date', true ),
			);
		}
		if ( Approval_Service::is_current( $post_id, 'testing' ) ) {
			$out['testing'] = array(
				'status'             => 'approved',
				'acquisition_method' => (string) get_post_meta( $post_id, 'product_acquisition_method', true ),
				'protocol_version'   => (string) get_post_meta( $post_id, 'testing_protocol_version', true ),
			);
		}
		return array_filter( $out, static fn( $value ) => '' !== $value && array() !== $value );
	}

	/** Return a minimal public liveness response without environment secrets. */
	public static function health(): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array( 'status' => 'ok' ),
			200
		);
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}
}

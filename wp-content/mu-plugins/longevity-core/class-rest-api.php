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
			'/readiness/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => static fn( \WP_REST_Request $request ) => current_user_can( 'edit_post', (int) $request['id'] ),
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( Publication_Gates::evaluate( (int) $request['id'] )->to_array() ),
				'args'                => array( 'id' => array( 'validate_callback' => static fn( $value ) => is_numeric( $value ) && (int) $value > 0 ) ),
			)
		);
	}

	/** Return a minimal public liveness response without environment secrets. */
	public static function health(): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array(
				'status'  => 'ok',
				'version' => LONGEVITY_CORE_VERSION,
				'site'    => home_url( '/' ),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}
}

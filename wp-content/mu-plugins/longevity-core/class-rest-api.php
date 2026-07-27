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
	/** CSP report rate-limit window in seconds. */
	private const CSP_REPORT_WINDOW = 300;

	/** Maximum accepted CSP reports per window per client. */
	private const CSP_REPORTS_PER_WINDOW = 60;

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
		register_rest_route(
			'longevity/v1',
			'/csp-report',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'csp_report' ),
			)
		);
		register_rest_route(
			'longevity/v1',
			'/metrics',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => static fn() => current_user_can( 'view_operational_readiness' ),
				'callback'            => array( self::class, 'metrics' ),
			)
		);
	}

	/** Expose observability counters and readiness gauges in Prometheus text format. */
	public static function metrics(): \WP_REST_Response {
		$response = new \WP_REST_Response( Metrics::render(), 200 );
		$response->header( 'Content-Type', 'text/plain; version=0.0.4; charset=utf-8' );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
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

	/**
	 * Collect CSP violation reports.
	 *
	 * Accepts application/csp-report JSON bodies from browsers.
	 * Logs violations and increments a counter for observability.
	 * Rate-limited per client IP so a flood cannot drive unbounded
	 * option writes or log volume.
	 */
	public static function csp_report( \WP_REST_Request $request ): \WP_REST_Response {
		$content_type = $request->get_header( 'Content-Type' );
		if ( ! $content_type || ! str_starts_with( $content_type, 'application/csp-report' ) ) {
			return new \WP_REST_Response( array( 'status' => 'ignored' ), 415 );
		}

		$rate_key = self::client_report_key();
		$window   = (int) get_transient( $rate_key );
		if ( $window >= self::CSP_REPORTS_PER_WINDOW ) {
			return new \WP_REST_Response( array( 'status' => 'throttled' ), 429 );
		}
		set_transient( $rate_key, $window + 1, self::CSP_REPORT_WINDOW );

		$body = $request->get_json_params();
		if ( empty( $body ) ) {
			$raw = $request->get_body();
			$body = json_decode( $raw, true );
		}
		$report = isset( $body['csp-report'] ) ? $body['csp-report'] : $body;
		if ( ! is_array( $report ) ) {
			return new \WP_REST_Response( array( 'status' => 'ignored' ), 204 );
		}

		// Extract bounded fields for logging.
		$violated   = substr( sanitize_text_field( (string) ( $report['violated-directive'] ?? $report['effectiveDirective'] ?? '' ) ), 0, 128 );
		$blocked    = substr( sanitize_text_field( (string) ( $report['blocked-uri'] ?? $report['blockedURL'] ?? '' ) ), 0, 256 );
		$doc_uri    = substr( sanitize_text_field( (string) ( $report['document-uri'] ?? $report['documentURL'] ?? '' ) ), 0, 256 );

		Logger::warning( 'csp_violation', array( 'directive' => $violated, 'blocked' => $blocked, 'page' => $doc_uri ) );

		// Increment violation counter for readiness observability.
		$count = (int) get_option( 'lel_csp_violation_count', 0 );
		update_option( 'lel_csp_violation_count', $count + 1, false );

		return new \WP_REST_Response( array( 'status' => 'recorded' ), 204 );
	}

	/** Bounded transient rate-limit key for the current client (pseudonymous, no raw IP stored). */
	private static function client_report_key(): string {
		$ip     = class_exists( Public_Contact::class ) ? Public_Contact::get_client_ip() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0' );
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'longevity-csp-fallback' );
		return 'lel_csp_rl_' . substr( hash_hmac( 'sha256', $ip, $secret . '|csp-report' ), 0, 40 );
	}
}

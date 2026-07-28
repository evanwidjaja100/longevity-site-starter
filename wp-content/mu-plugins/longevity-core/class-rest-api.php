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

	/** Global application budget per window. */
	private const CSP_REPORTS_PER_WINDOW = 60;

	/** Maximum accepted raw report body in bytes (browsers send small reports). */
	private const CSP_REPORT_MAX_BYTES = 8192;

	/** Emit one aggregate sample and metrics update per this many accepted reports. */
	private const CSP_REPORT_SAMPLE_EVERY = 10;

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
					'get_callback' => static fn( array $item ) => self::public_projection( (int) ( $item['id'] ?? 0 ) ),
					'schema'       => array(
						'type'     => 'object',
						'context'  => array( 'view' ),
						'readonly' => true,
					),
				)
			);
		}
	}

	/**
	 * Return only reviewed, non-sensitive fields; stale approvals fail closed.
	 *
	 * @param int $post_id Published content ID.
	 */
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
			$reviewer_id           = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
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
		return array_filter( $out, static fn( $value ) => '' !== $value );
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
	 * Valid reports consume one atomic, database-backed global budget entry.
	 * Logging and metrics are sampled so they do not add per-report writes.
	 *
	 * @param \WP_REST_Request $request Incoming report request.
	 * @return \WP_REST_Response Bounded collector response.
	 */
	public static function csp_report( \WP_REST_Request $request ): \WP_REST_Response {
		$content_type = strtolower( trim( explode( ';', (string) $request->get_header( 'Content-Type' ), 2 )[0] ) );
		if ( 'application/csp-report' !== $content_type ) {
			return new \WP_REST_Response( array( 'status' => 'ignored' ), 415 );
		}

		$raw = (string) $request->get_body();
		if ( '' === $raw ) {
			return new \WP_REST_Response( array( 'status' => 'malformed' ), 400 );
		}
		if ( strlen( $raw ) > self::CSP_REPORT_MAX_BYTES ) {
			return new \WP_REST_Response( array( 'status' => 'too_large' ), 413 );
		}

		$report = self::valid_csp_report( $raw );
		if ( null === $report ) {
			return new \WP_REST_Response( array( 'status' => 'malformed' ), 400 );
		}

		$count = self::consume_csp_budget();
		if ( null === $count ) {
			return new \WP_REST_Response( array( 'status' => 'unavailable' ), 503 );
		}
		if ( $count > self::CSP_REPORTS_PER_WINDOW ) {
			return new \WP_REST_Response( array( 'status' => 'throttled' ), 429 );
		}

		self::record_csp_sample( $report, $count );

		return new \WP_REST_Response( array( 'status' => 'recorded' ), 204 );
	}

	/**
	 * Decode and validate the bounded legacy CSP report schema.
	 *
	 * @param string $raw Raw request body.
	 * @return array|null Validated report or null.
	 */
	private static function valid_csp_report( string $raw ): ?array {
		$body = json_decode( $raw, true, 8 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) || array_is_list( $body ) || 1 !== count( $body ) ) {
			return null;
		}

		$report = $body['csp-report'] ?? null;
		if ( ! is_array( $report ) || array_is_list( $report ) || count( $report ) > 32 ) {
			return null;
		}
		foreach ( $report as $value ) {
			if ( null !== $value && ! is_scalar( $value ) ) {
				return null;
			}
		}

		$directive = $report['effective-directive'] ?? $report['violated-directive'] ?? null;
		if ( ! is_string( $directive ) || strlen( $directive ) > 128 ) {
			return null;
		}
		$directive_token = strtok( trim( $directive ), " \t" );
		$directive       = false === $directive_token ? '' : strtolower( $directive_token );
		if ( ! preg_match( '/^[a-z][a-z0-9-]{0,63}$/', $directive ) ) {
			return null;
		}

		foreach ( array( 'blocked-uri', 'document-uri' ) as $field ) {
			if ( isset( $report[ $field ] ) && ( ! is_string( $report[ $field ] ) || strlen( $report[ $field ] ) > 2048 ) ) {
				return null;
			}
		}
		$report['effective-directive'] = $directive;
		return $report;
	}

	/** Consume the shared DB-backed application budget atomically. */
	private static function consume_csp_budget(): ?int {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return null;
		}

		$table      = $wpdb->prefix . 'lel_rate_limits';
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::CSP_REPORT_WINDOW );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Atomic global limiting requires the native DB and the trusted prefix-derived table name.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (rate_key, hit_count, expires_at) VALUES (%s, 1, %s) ON DUPLICATE KEY UPDATE hit_count = IF(expires_at < UTC_TIMESTAMP(), 1, LEAST(hit_count + 1, %d)), expires_at = IF(expires_at < UTC_TIMESTAMP(), VALUES(expires_at), expires_at)",
				'csp-report-global',
				$expires_at,
				self::CSP_REPORTS_PER_WINDOW + 1
			)
		);
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		$count = $wpdb->get_var( $wpdb->prepare( "SELECT hit_count FROM {$table} WHERE rate_key = %s AND expires_at >= UTC_TIMESTAMP()", 'csp-report-global' ) );
		// phpcs:enable
		return null === $count ? null : (int) $count;
	}

	/**
	 * Log a privacy-safe sample and batch the existing metric counter.
	 *
	 * @param array $report Validated report.
	 * @param int   $count  Current global window count.
	 */
	private static function record_csp_sample( array $report, int $count ): void {
		$is_batch = 0 === $count % self::CSP_REPORT_SAMPLE_EVERY;
		if ( ! self::should_sample_csp_report( $count ) ) {
			return;
		}

		$directive = (string) $report['effective-directive'];
		$blocked   = self::redact_report_url( (string) ( $report['blocked-uri'] ?? '' ) );
		$page      = self::redact_report_url( (string) ( $report['document-uri'] ?? '' ) );
		Logger::warning(
			'csp_violation',
			array(
				'directive'     => $directive,
				'blocked'       => $blocked,
				'page'          => $page,
				'fingerprint'   => substr( hash( 'sha256', $directive . '|' . $blocked . '|' . $page ), 0, 16 ),
				'window_count'  => $count,
				'sample_weight' => $is_batch ? self::CSP_REPORT_SAMPLE_EVERY : 1,
			)
		);

		if ( $is_batch ) {
			update_option( 'lel_csp_violation_count', (int) get_option( 'lel_csp_violation_count', 0 ) + self::CSP_REPORT_SAMPLE_EVERY, false );
		}
	}

	/**
	 * Whether this aggregate count gets one diagnostic log sample.
	 *
	 * @param int $count Current global window count.
	 */
	private static function should_sample_csp_report( int $count ): bool {
		return 1 === $count || 0 === $count % self::CSP_REPORT_SAMPLE_EVERY;
	}

	/**
	 * Reduce report URLs to non-sensitive origins or fixed CSP keywords.
	 *
	 * @param string $url Report URL value.
	 */
	private static function redact_report_url( string $url ): string {
		$url   = trim( $url );
		$lower = strtolower( $url );
		if ( in_array( $lower, array( '', 'inline', 'eval', 'self', 'data', 'blob', 'about' ), true ) ) {
			return $lower;
		}

		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		$host   = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		if ( in_array( $scheme, array( 'http', 'https' ), true ) && '' !== $host && preg_match( '/^[a-z0-9.:-]+$/i', $host ) ) {
			$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
			return $scheme . '://' . $host . $port;
		}
		return '' !== $scheme ? $scheme . ':' : '[redacted]';
	}
}

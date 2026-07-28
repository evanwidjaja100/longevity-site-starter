<?php

use Longevity\Core\Bootstrap;
use Longevity\Core\Rest_API;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once LONGEVITY_CORE_PATH . 'bootstrap.php';

final class CspReportTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['lel_test_options'] = array();
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_rate_limits', array() );
		$GLOBALS['wpdb']->lel_query_log = array();
		unset( $GLOBALS['lel_test_fail_rate_insert'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_fail_rate_insert'] );
	}

	private function request( string $body, string $content_type = 'application/csp-report' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/longevity/v1/csp-report' );
		if ( '' !== $content_type ) {
			$request->set_header( 'Content-Type', $content_type );
		}
		$request->set_body( $body );
		return $request;
	}

	private function body( string $directive = 'script-src' ): string {
		return wp_json_encode(
			array(
				'csp-report' => array(
					'violated-directive' => $directive,
					'blocked-uri'         => 'https://evil.example/script.js?token=secret#fragment',
					'document-uri'        => 'https://site.example/account/42?email=person@example.com',
				),
			)
		);
	}

	public function test_policy_and_framing_headers_are_intentional(): void {
		$nonce = Bootstrap::csp_nonce();
		self::assertSame( 'DENY', Bootstrap::framing_header() );
		self::assertSame(
			implode(
				'; ',
				array(
					"default-src 'self'",
					"script-src 'self' 'nonce-{$nonce}'",
					"style-src 'self' 'nonce-{$nonce}'",
					"style-src-attr 'unsafe-inline'",
					"img-src 'self' data: https:",
					"font-src 'self' data:",
					"connect-src 'self'",
					"frame-ancestors 'none'",
					"base-uri 'self'",
					"form-action 'self'",
					'report-uri http://example.com/wp-json/longevity/v1/csp-report',
				)
			),
			Bootstrap::content_security_policy()
		);
	}

	public function test_csp_mode_defaults_safely_and_requires_explicit_enforce(): void {
		$previous = getenv( 'LEL_CSP_MODE' );
		try {
			putenv( 'LEL_CSP_MODE' );
			self::assertSame( 'Content-Security-Policy-Report-Only', Bootstrap::csp_header_name() );
			putenv( 'LEL_CSP_MODE=invalid' );
			self::assertSame( 'Content-Security-Policy-Report-Only', Bootstrap::csp_header_name() );
			putenv( 'LEL_CSP_MODE=enforce' );
			self::assertSame( 'Content-Security-Policy', Bootstrap::csp_header_name() );
		} finally {
			false === $previous ? putenv( 'LEL_CSP_MODE' ) : putenv( 'LEL_CSP_MODE=' . $previous );
		}
	}

	public function test_wrong_content_type_is_rejected_exactly(): void {
		self::assertSame( 415, Rest_API::csp_report( $this->request( '{}', 'application/json' ) )->get_status() );
		self::assertSame( 415, Rest_API::csp_report( $this->request( '{}', 'application/csp-reporting' ) )->get_status() );
		self::assertSame( array(), $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_rate_limits' ) );
	}

	public function test_oversized_body_is_rejected_before_database_work(): void {
		$huge     = '{"csp-report":{"violated-directive":"script-src","blocked-uri":"' . str_repeat( 'a', 9000 ) . '"}}';
		$response = Rest_API::csp_report( $this->request( $huge ) );
		self::assertSame( 413, $response->get_status() );
		self::assertSame( array(), $GLOBALS['wpdb']->lel_query_log );
		self::assertSame( array(), $GLOBALS['lel_test_options'] );
	}

	#[DataProvider( 'malformed_reports' )]
	public function test_malformed_schema_is_rejected_without_writes( string $body ): void {
		$response = Rest_API::csp_report( $this->request( $body ) );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( array(), $GLOBALS['wpdb']->lel_query_log );
		self::assertSame( array(), $GLOBALS['lel_test_options'] );
	}

	public static function malformed_reports(): array {
		return array(
			'empty'             => array( '' ),
			'invalid JSON'      => array( 'not json at all' ),
			'unwrapped payload' => array( '{"violated-directive":"script-src"}' ),
			'missing directive' => array( '{"csp-report":{"blocked-uri":"https://example.com"}}' ),
			'nested field'      => array( '{"csp-report":{"violated-directive":"script-src","blocked-uri":{"url":"https://example.com"}}}' ),
			'oversized field'   => array( wp_json_encode( array( 'csp-report' => array( 'violated-directive' => 'script-src', 'document-uri' => str_repeat( 'a', 2049 ) ) ) ) ),
		);
	}

	public function test_valid_report_consumes_one_budget_write_without_metric_write(): void {
		$response = Rest_API::csp_report( $this->request( $this->body() ) );
		self::assertSame( 204, $response->get_status() );
		self::assertCount( 1, $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_rate_limits' ) );
		self::assertArrayNotHasKey( 'lel_csp_violation_count', $GLOBALS['lel_test_options'] );
	}

	public function test_report_urls_are_reduced_to_origins(): void {
		$method = new ReflectionMethod( Rest_API::class, 'redact_report_url' );
		self::assertSame( 'https://example.com', $method->invoke( null, 'https://user:pass@example.com/private/42?token=secret#fragment' ) );
		self::assertSame( 'data:', $method->invoke( null, 'data:text/plain,secret' ) );
		self::assertSame( '[redacted]', $method->invoke( null, 'not a URL or CSP keyword' ) );
	}

	public function test_high_volume_is_globally_bounded_and_aggregated(): void {
		$statuses = array();
		for ( $i = 0; $i < 70; ++$i ) {
			$statuses[] = Rest_API::csp_report( $this->request( $this->body( 'img-src' ) ) )->get_status();
		}

		self::assertSame( array_fill( 0, 60, 204 ), array_slice( $statuses, 0, 60 ) );
		self::assertSame( array_fill( 0, 10, 429 ), array_slice( $statuses, 60 ) );
		self::assertCount( 1, $GLOBALS['wpdb']->lel_test_rows( 'wp_lel_rate_limits' ), 'The global budget has bounded key cardinality.' );
		self::assertSame( 60, $GLOBALS['lel_test_options']['lel_csp_violation_count'], 'Metrics are updated in batches of ten.' );

		$writes = array_filter( $GLOBALS['wpdb']->lel_query_log, static fn( string $query ): bool => str_starts_with( $query, 'INSERT INTO wp_lel_rate_limits' ) );
		self::assertCount( 70, $writes, 'Each request performs at most the one atomic budget write.' );

		$sampler = new ReflectionMethod( Rest_API::class, 'should_sample_csp_report' );
		$samples = array_filter( range( 1, 60 ), static fn( int $count ): bool => $sampler->invoke( null, $count ) );
		self::assertSame( array( 1, 10, 20, 30, 40, 50, 60 ), array_values( $samples ), 'Log volume is sampled and bounded.' );
	}

	public function test_limiter_backend_failure_fails_closed_without_failure_write(): void {
		$GLOBALS['lel_test_fail_rate_insert'] = true;
		$response = Rest_API::csp_report( $this->request( $this->body( 'img-src' ) ) );
		self::assertSame( 503, $response->get_status() );
		self::assertSame( array(), $GLOBALS['lel_test_options'] );
	}
}

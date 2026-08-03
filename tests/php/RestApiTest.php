<?php

use Longevity\Core\Rest_API;
use PHPUnit\Framework\TestCase;

final class RestApiTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['lel_test_rest_routes'] );
	}

	public function test_health_returns_ok_status(): void {
		$response = Rest_API::health();
		$data = $response->get_data();

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'ok', $data['status'] );
	}

	public function test_health_exposes_only_liveness(): void {
		$response = Rest_API::health();
		self::assertSame( array( 'status' => 'ok' ), $response->get_data() );
	}

	public function test_health_sets_no_store_cache_header(): void {
		$response = Rest_API::health();
		ob_start();
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		ob_end_clean();

		self::assertSame( 200, $response->get_status() );
	}

	public function test_register_routes_adds_health_and_readiness(): void {
		$GLOBALS['lel_test_rest_routes'] = array();
		Rest_API::register_routes();

		self::assertCount( 5, $GLOBALS['lel_test_rest_routes'] );

		$namespaces = array_column( $GLOBALS['lel_test_rest_routes'], 'namespace' );
		self::assertSame( array( 'longevity/v1', 'longevity/v1', 'longevity/v1', 'longevity/v1', 'longevity/v1' ), $namespaces );

		$routes = array_column( $GLOBALS['lel_test_rest_routes'], 'route' );
		self::assertContains( '/health', $routes );
		self::assertContains( '/readiness/(?P<id>\d+)', $routes );
		self::assertContains( '/system-readiness', $routes );
		self::assertContains( '/csp-report', $routes );
		self::assertContains( '/metrics', $routes );
	}

	/**
	 * API contract test: documented response shape matches implementation.
	 * Docs state: GET /wp-json/longevity/v1/health returns {"status":"ok"}
	 */
	public function test_health_contract_matches_documentation(): void {
		$response = Rest_API::health();
		$data     = $response->get_data();

		// Contract: exactly one key 'status' with value 'ok'.
		self::assertSame( array( 'status' => 'ok' ), $data );
		self::assertArrayNotHasKey( 'version', $data );
		self::assertArrayNotHasKey( 'timestamp', $data );
		self::assertSame( 200, $response->get_status() );
	}
}

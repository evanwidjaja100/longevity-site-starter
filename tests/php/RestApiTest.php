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

	public function test_health_returns_version_constant(): void {
		$response = Rest_API::health();
		$data = $response->get_data();

		self::assertSame( LONGEVITY_CORE_VERSION, $data['version'] );
	}

	public function test_health_returns_site_url(): void {
		$response = Rest_API::health();
		$data = $response->get_data();

		self::assertSame( 'http://example.com/', $data['site'] );
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

		self::assertCount( 2, $GLOBALS['lel_test_rest_routes'] );

		$namespaces = array_column( $GLOBALS['lel_test_rest_routes'], 'namespace' );
		self::assertSame( array( 'longevity/v1', 'longevity/v1' ), $namespaces );

		$routes = array_column( $GLOBALS['lel_test_rest_routes'], 'route' );
		self::assertContains( '/health', $routes );
		self::assertContains( '/readiness/(?P<id>\d+)', $routes );
	}
}

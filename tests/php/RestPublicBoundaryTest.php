<?php

use Longevity\Core\Rest_API;
use PHPUnit\Framework\TestCase;

final class RestPublicBoundaryTest extends TestCase {
	public function test_health_exposes_no_environment_details(): void {
		self::assertSame( array( 'status' => 'ok' ), Rest_API::health()->get_data() );
	}

	public function test_private_registry_sources_disable_raw_rest_meta(): void {
		$root = LONGEVITY_CORE_PATH;
		foreach ( array( 'class-claims.php', 'class-affiliate-registry.php', 'class-review-methodology.php' ) as $file ) {
			self::assertStringNotContainsString( "'show_in_rest'      => true", (string) file_get_contents( $root . $file ), $file );
		}
	}

	public function test_material_evidence_and_commercial_relationships_fail_closed_without_current_approvals(): void {
		$source = (string) file_get_contents( LONGEVITY_CORE_PATH . 'class-rest-api.php' );

		self::assertStringContainsString( "Approval_Service::is_current( $post_id, 'fact_check' )", $source );
		self::assertStringContainsString( "Approval_Service::is_current( $post_id, 'commercial' )", $source );
		self::assertStringNotContainsString( "'commercial_relationship' => (string) get_post_meta", $source );
	}

}

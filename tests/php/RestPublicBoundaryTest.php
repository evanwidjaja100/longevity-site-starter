<?php

use Longevity\Core\Claims;
use Longevity\Core\Rest_API;
use PHPUnit\Framework\TestCase;

final class RestPublicBoundaryTest extends TestCase {
	public function test_health_exposes_no_environment_details(): void {
		self::assertSame( array( 'status' => 'ok' ), Rest_API::health()->get_data() );
	}

	public function test_private_registry_sources_disable_raw_rest_meta(): void {
		$GLOBALS['lel_test_registered_meta'] = array();
		Claims::register_meta();
		$registered = $GLOBALS['lel_test_registered_meta'];

		self::assertArrayHasKey( 'lel_claim', $registered );
		self::assertNotEmpty( $registered['lel_claim'] );

		foreach ( $registered['lel_claim'] as $field => $args ) {
			self::assertFalse(
				$args['show_in_rest'] ?? false,
				sprintf( 'Claim meta field "%s" must not be exposed in REST API.', $field )
			);
		}
	}

	public function test_public_projection_fails_closed_without_current_approvals(): void {
		// Unpublished posts return an empty projection regardless of metadata.
		$GLOBALS['lel_test_meta'][100]['evidence_grade'] = 'High';
		$GLOBALS['lel_test_meta'][100]['commercial_relationship'] = 'affiliate';
		$GLOBALS['lel_test_page_statuses'][100] = 'draft';

		$projection = Rest_API::public_projection( 100 );
		self::assertSame( array(), $projection, 'Draft posts must not expose any public projection.' );

		// Published posts without current approvals must not expose material evidence or commercial relationships.
		$GLOBALS['lel_test_page_statuses'][100] = 'publish';
		$GLOBALS['lel_test_meta'][100]['material_health_claims'] = true;
		$GLOBALS['lel_test_meta'][100]['evidence_grade'] = 'High';
		$GLOBALS['lel_test_meta'][100]['evidence_cutoff_date'] = '2026-01-01';
		$GLOBALS['lel_test_meta'][100]['commercial_relationship'] = 'affiliate';
		$GLOBALS['lel_test_meta'][100]['affiliate_disclosure_status'] = 'stale';

		$projection = Rest_API::public_projection( 100 );
		self::assertArrayNotHasKey( 'evidence_grade', $projection, 'Evidence grade must be fail-closed without current fact_check approval.' );
		self::assertArrayNotHasKey( 'commercial_relationship', $projection, 'Commercial relationship must be fail-closed without current commercial approval.' );
	}

	public function test_public_projection_exposes_safe_fields_for_published_content(): void {
		$GLOBALS['lel_test_page_statuses'][200] = 'publish';
		$GLOBALS['lel_test_meta'][200]['content_summary'] = 'A summary';
		$GLOBALS['lel_test_meta'][200]['content_scope'] = 'General health';
		$GLOBALS['lel_test_meta'][200]['content_limitations'] = 'Limited evidence';

		$projection = Rest_API::public_projection( 200 );
		self::assertSame( 'A summary', $projection['content_summary'] );
		self::assertSame( 'General health', $projection['content_scope'] );
		self::assertSame( 'Limited evidence', $projection['content_limitations'] );
	}
}

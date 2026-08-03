<?php
/**
 * Route-state contract test (PRV3-BOOT-01).
 *
 * Verifies that config/routes.json — the single source of truth consumed by
 * Playwright, k6, Lighthouse, and the CI fixture projection — agrees with the
 * PHP route registry (Routes), the canonical bootstrap (Bootstrap_Command),
 * and trust-page governance (Trust_Pages). Any divergence fails this test so
 * route expectations can never silently drift between suites.
 *
 * @package LongevityCore
 */

declare(strict_types=1);

use Longevity\Core\Bootstrap_Command;
use Longevity\Core\Routes;
use Longevity\Core\Trust_Pages;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/wp-cli-stub.php';
require_once LONGEVITY_CORE_PATH . 'class-cli.php';

final class RouteContractTest extends TestCase {

	/** @var array<string, mixed> */
	private static array $contract;

	public static function setUpBeforeClass(): void {
		$path = dirname( __DIR__, 2 ) . '/config/routes.json';
		self::assertFileExists( $path, 'config/routes.json route-state contract is missing.' );
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		self::assertIsArray( $decoded, 'config/routes.json is not valid JSON.' );
		self::$contract = $decoded;
	}

	/** @return array<string, array<string, mixed>> */
	private static function contract_pages(): array {
		return self::$contract['pages'];
	}

	public function test_contract_declares_schema_and_sections(): void {
		self::assertSame( 1, self::$contract['schema_version'] );
		foreach ( array( 'pages', 'categories', 'search', 'ci_fixture_content' ) as $section ) {
			self::assertArrayHasKey( $section, self::$contract );
		}
	}

	public function test_page_keys_match_routes_registry(): void {
		$definitions = Routes::definitions();
		self::assertSame(
			array_keys( $definitions['pages'] ),
			array_keys( self::contract_pages() ),
			'config/routes.json pages must list exactly the Routes page keys in registry order.'
		);
	}

	public function test_page_slugs_and_names_match_routes_registry(): void {
		$definitions = Routes::definitions();
		foreach ( $definitions['pages'] as $key => $def ) {
			$contract_page = self::contract_pages()[ $key ];
			$expected_slug = 'home' === $key ? 'home' : $def['slug'];
			self::assertSame( $expected_slug, $contract_page['slug'], "Slug mismatch for route '{$key}'." );
			self::assertSame( $def['name'], $contract_page['name'], "Name mismatch for route '{$key}'." );
			$expected_path = 'home' === $key ? '/' : '/' . $def['slug'] . '/';
			self::assertSame( $expected_path, $contract_page['path'], "Path mismatch for route '{$key}'." );
		}
	}

	public function test_bootstrap_pages_match_contract(): void {
		$canonical = ( new ReflectionClassConstant( Bootstrap_Command::class, 'CANONICAL_PAGES' ) )->getValue();

		$expected_bootstrap_keys = array_keys( array_filter(
			self::contract_pages(),
			static fn ( array $page ): bool => in_array( $page['bootstrap_status'], array( 'publish', 'draft' ), true )
		) );
		$canonical_keys          = array_keys( $canonical );
		sort( $expected_bootstrap_keys );
		sort( $canonical_keys );
		self::assertSame(
			$expected_bootstrap_keys,
			$canonical_keys,
			'Bootstrap_Command::CANONICAL_PAGES must create exactly the contract pages whose bootstrap_status is publish or draft.'
		);

		foreach ( $canonical as $key => $def ) {
			$contract_page = self::contract_pages()[ $key ];
			self::assertSame( $contract_page['slug'], $def['slug'], "Bootstrap slug mismatch for '{$key}'." );
			self::assertSame( $contract_page['bootstrap_status'], $def['status'], "Bootstrap status mismatch for '{$key}'." );
		}
	}

	public function test_archive_routes_are_not_bootstrap_pages(): void {
		foreach ( self::contract_pages() as $key => $page ) {
			if ( 'archive' === $page['type'] ) {
				self::assertSame( 'none', $page['bootstrap_status'], "Archive route '{$key}' must not be created as a WordPress page." );
			}
		}
	}

	public function test_categories_match_routes_registry(): void {
		$definitions = Routes::definitions();
		self::assertSame(
			array_keys( $definitions['categories'] ),
			array_keys( self::$contract['categories'] ),
			'config/routes.json categories must list exactly the Routes category keys.'
		);
		foreach ( $definitions['categories'] as $key => $def ) {
			$contract_category = self::$contract['categories'][ $key ];
			self::assertSame( $def['slug'], $contract_category['slug'], "Category slug mismatch for '{$key}'." );
			self::assertSame( $def['name'], $contract_category['name'], "Category name mismatch for '{$key}'." );
			self::assertSame( $def['legacy_slugs'], $contract_category['legacy_slugs'], "Legacy slugs mismatch for '{$key}'." );
			self::assertSame( '/category/' . $def['slug'] . '/', $contract_category['path'], "Category path mismatch for '{$key}'." );
		}
	}

	public function test_categories_match_bootstrap_command(): void {
		$canonical = ( new ReflectionClassConstant( Bootstrap_Command::class, 'CANONICAL_CATEGORIES' ) )->getValue();
		self::assertSame( array_keys( self::$contract['categories'] ), array_keys( $canonical ) );
		foreach ( $canonical as $key => $def ) {
			self::assertSame( self::$contract['categories'][ $key ]['slug'], $def['slug'], "Bootstrap category slug mismatch for '{$key}'." );
			self::assertSame( self::$contract['categories'][ $key ]['name'], $def['name'], "Bootstrap category name mismatch for '{$key}'." );
		}
	}

	public function test_trust_page_flags_match_trust_page_governance(): void {
		$contract_trust_slugs = array();
		foreach ( self::contract_pages() as $page ) {
			if ( ! empty( $page['trust_page'] ) ) {
				$contract_trust_slugs[] = $page['slug'];
			}
		}
		$expected = Trust_Pages::SLUGS;
		sort( $expected );
		sort( $contract_trust_slugs );
		self::assertSame( $expected, $contract_trust_slugs, 'Contract trust_page flags must match Trust_Pages::SLUGS exactly.' );
	}

	public function test_bootstrap_published_pages_are_public_in_every_mode(): void {
		foreach ( self::contract_pages() as $key => $page ) {
			if ( 'publish' === $page['bootstrap_status'] ) {
				self::assertTrue( $page['ci_fixture_public'], "Route '{$key}' is published at bootstrap and must be public in CI fixture mode." );
				self::assertFalse( $page['trust_page'], "Route '{$key}' cannot be a governed trust page and also publish at bootstrap without approval." );
			}
		}
	}

	public function test_draft_default_is_production_safe(): void {
		foreach ( self::contract_pages() as $key => $page ) {
			if ( 'page' !== $page['type'] || 'publish' === $page['bootstrap_status'] ) {
				continue;
			}
			self::assertSame( 'draft', $page['bootstrap_status'], "Route '{$key}' must default to draft after a clean bootstrap." );
			self::assertNotEmpty( $page['production_publication'], "Route '{$key}' needs a named production publication owner role." );
			self::assertNotContains( 'canonical_bootstrap', $page['production_publication'], "Draft route '{$key}' cannot claim bootstrap publication." );
		}
	}

	public function test_ci_fixture_content_paths_are_watermarked(): void {
		$fixture = self::$contract['ci_fixture_content'];
		foreach ( array_merge( $fixture['published_paths'], $fixture['blocked_paths'] ) as $path ) {
			self::assertMatchesRegularExpression(
				'#/test-[a-z0-9-]+/$#',
				$path,
				"Fixture path '{$path}' must use the reserved test- slug prefix so synthetic content stays detectable."
			);
		}
	}
}

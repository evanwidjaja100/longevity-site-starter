<?php
/**
 * Keeps the PHPCS custom-capability allowlist in lockstep with the
 * capabilities actually registered by Roles (PRV3-QA-01): the linter may only
 * trust capabilities the runtime really registers and enforces.
 *
 * @package LongevityCore
 */

declare(strict_types=1);

use Longevity\Core\Roles;
use PHPUnit\Framework\TestCase;

final class CapabilitiesConfigTest extends TestCase {

	public function test_phpcs_custom_capabilities_match_registered_roles(): void {
		$registered = ( new ReflectionClassConstant( Roles::class, 'ALL_CUSTOM_CAPS' ) )->getValue();

		$ruleset = (string) file_get_contents( dirname( __DIR__, 2 ) . '/phpcs.xml.dist' );
		self::assertSame(
			1,
			preg_match( '/name="custom_capabilities" value="([^"]+)"/', $ruleset, $matches ),
			'phpcs.xml.dist must declare the custom_capabilities property.'
		);
		$configured = explode( ',', $matches[1] );

		sort( $registered );
		sort( $configured );
		self::assertSame(
			$registered,
			$configured,
			'phpcs.xml.dist custom_capabilities must list exactly Roles::ALL_CUSTOM_CAPS — no unregistered capability may be trusted by the linter.'
		);
	}
}

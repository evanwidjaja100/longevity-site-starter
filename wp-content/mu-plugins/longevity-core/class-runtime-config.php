<?php
/**
 * Deployable runtime configuration loader.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Loads and validates production-critical plugin-local configuration. */
final class Runtime_Config {
	/** @var array<string, array> */
	private static array $cache = array();

	/** Load the scoring model with a structured status. */
	public static function scoring_model_status(): array {
		if ( isset( self::$cache['scoring'] ) ) {
			return self::$cache['scoring'];
		}
		$path = LONGEVITY_CORE_PATH . 'config/scoring/default-review-model.json';
		if ( ! is_readable( $path ) ) {
			return self::$cache['scoring'] = array( 'valid' => false, 'code' => 'missing_scoring_model', 'message' => 'The packaged scoring model is missing.', 'model' => array() );
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			return self::$cache['scoring'] = array( 'valid' => false, 'code' => 'invalid_scoring_json', 'message' => 'The packaged scoring model is not valid JSON.', 'model' => array() );
		}
		$version = (string) ( $decoded['version'] ?? '' );
		$minimum = $decoded['minimum_meaningful_difference'] ?? null;
		$rules   = $decoded['rules'] ?? null;
		if ( ! preg_match( '/^[0-9A-Za-z][0-9A-Za-z._-]{0,39}$/', $version ) || ! is_numeric( $minimum ) || (float) $minimum < 0 || ! is_array( $rules ) ) {
			return self::$cache['scoring'] = array( 'valid' => false, 'code' => 'invalid_scoring_schema', 'message' => 'The packaged scoring model fails schema validation.', 'model' => array() );
		}
		return self::$cache['scoring'] = array( 'valid' => true, 'code' => 'ok', 'message' => 'Scoring model loaded.', 'model' => $decoded );
	}

	/** Valid scoring model or an empty array. */
	public static function scoring_model(): array {
		$status = self::scoring_model_status();
		return $status['valid'] ? $status['model'] : array();
	}

	/** The two supported explicit CSP delivery modes. */
	public const CSP_MODES = array( 'report-only', 'enforce' );

	/**
	 * Resolve the explicit CSP release mode (single source of truth).
	 *
	 * The effective mode is always safe: anything other than an explicit,
	 * supported "enforce" resolves to report-only. Callers that gate
	 * production readiness must additionally require `configured` and
	 * reject `retired_key`.
	 *
	 * @return array{mode: string, configured: bool, invalid: bool, retired_key: bool, source: string}
	 */
	public static function csp_mode_status(): array {
		$raw    = null;
		$source = 'unset';
		if ( defined( 'LEL_CSP_MODE' ) ) {
			$raw    = constant( 'LEL_CSP_MODE' );
			$source = 'constant';
		} elseif ( false !== getenv( 'LEL_CSP_MODE' ) ) {
			$raw    = getenv( 'LEL_CSP_MODE' );
			$source = 'environment';
		}
		$normalized = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		$configured = in_array( $normalized, self::CSP_MODES, true );
		return array(
			'mode'        => 'enforce' === $normalized ? 'enforce' : 'report-only',
			'configured'  => $configured,
			'invalid'     => null !== $raw && ! $configured,
			'retired_key' => defined( 'LEL_CSP_ENFORCE' ) || false !== getenv( 'LEL_CSP_ENFORCE' ),
			'source'      => $source,
		);
	}

	/** Clear request cache for tests. */
	public static function reset(): void {
		self::$cache = array();
	}
}

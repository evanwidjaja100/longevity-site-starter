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

	/** Clear request cache for tests. */
	public static function reset(): void {
		self::$cache = array();
	}
}

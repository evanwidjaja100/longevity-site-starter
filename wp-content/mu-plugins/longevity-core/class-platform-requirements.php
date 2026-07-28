<?php
/**
 * Declared platform requirements and runtime evaluation.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Machine-enforced requirements for the tested WordPress/MySQL platform. */
final class Platform_Requirements {
	public const MIN_PHP       = '8.1';
	public const MIN_WORDPRESS = '7.0.1';
	public const MIN_MYSQL     = '8.0';

	public const REQUIRED_EXTENSIONS = array( 'json', 'mbstring', 'hash', 'filter', 'pcre' );

	/** Evaluate the actual WordPress runtime and database capabilities. */
	public static function check( bool $require_wp_cli = false ): array {
		global $wpdb, $wp_version;
		$loaded = array_values( array_filter( self::REQUIRED_EXTENSIONS, 'extension_loaded' ) );
		$db     = self::database_facts();

		return self::evaluate_runtime(
			PHP_VERSION,
			$loaded,
			isset( $wp_version ) ? (string) $wp_version : '',
			$db,
			$require_wp_cli ? defined( 'WP_CLI_VERSION' ) : null
		);
	}

	/** Evaluate only PHP and extension requirements. */
	public static function evaluate( string $php_version, array $loaded_extensions ): array {
		$results   = array();
		$results[] = array(
			'requirement' => 'php',
			'satisfied'   => version_compare( $php_version, self::MIN_PHP, '>=' ),
			'detail'      => sprintf( 'requires >= %s, found %s', self::MIN_PHP, $php_version ),
		);
		foreach ( self::REQUIRED_EXTENSIONS as $extension ) {
			$present   = in_array( $extension, $loaded_extensions, true );
			$results[] = array(
				'requirement' => 'ext-' . $extension,
				'satisfied'   => $present,
				'detail'      => $present ? 'loaded' : 'extension is not loaded',
			);
		}
		return $results;
	}

	/** Pure evaluator for WordPress, MySQL, and required database capabilities. */
	public static function evaluate_runtime( string $php_version, array $loaded_extensions, string $wordpress_version, array $database, ?bool $wp_cli = null ): array {
		$results = self::evaluate( $php_version, $loaded_extensions );
		$results[] = array(
			'requirement' => 'wordpress',
			'satisfied'   => '' !== $wordpress_version && version_compare( $wordpress_version, self::MIN_WORDPRESS, '>=' ),
			'detail'      => sprintf( 'requires >= %s, found %s', self::MIN_WORDPRESS, '' === $wordpress_version ? 'unavailable' : $wordpress_version ),
		);

		$database_version = (string) ( $database['version'] ?? '' );
		$mysql_version    = self::mysql_version( $database_version );
		$results[]        = array(
			'requirement' => 'mysql',
			'satisfied'   => null !== $mysql_version && version_compare( $mysql_version, self::MIN_MYSQL, '>=' ),
			'detail'      => sprintf( 'requires Oracle MySQL >= %s, found %s', self::MIN_MYSQL, '' === $database_version ? 'unavailable' : $database_version ),
		);
		$results[] = self::capability( 'mysql-innodb', 'InnoDB default storage engine', 'innodb' === strtolower( (string) ( $database['engine'] ?? '' ) ) );
		$results[] = self::capability( 'mysql-utf8mb4', 'utf8mb4 database character set', 0 === strpos( strtolower( (string) ( $database['charset'] ?? '' ) ), 'utf8mb4' ) );
		$results[] = self::capability( 'mysql-advisory-locks', 'GET_LOCK/RELEASE_LOCK support', true === ( $database['advisory_locks'] ?? false ) );
		$results[] = self::capability( 'mysql-information-schema', 'information_schema metadata access', true === ( $database['information_schema'] ?? false ) );
		if ( null !== $wp_cli ) {
			$results[] = self::capability( 'wp-cli', 'WP-CLI runtime', $wp_cli );
		}
		return $results;
	}

	/** Readiness check record summarizing unmet requirements. */
	public static function readiness_check(): array {
		$unmet = array();
		foreach ( self::check() as $result ) {
			if ( empty( $result['satisfied'] ) ) {
				$unmet[] = $result['requirement'];
			}
		}
		if ( $unmet ) {
			return array(
				'status'  => 'blocked',
				'message' => 'Unmet platform requirements: ' . implode( ', ', $unmet ) . '.',
				'unmet'   => $unmet,
			);
		}
		return array( 'status' => 'ok', 'message' => 'Declared platform requirements are satisfied.' );
	}

	/** Collect non-secret database version/capability facts. */
	private static function database_facts(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return array();
		}

		$lock_name = 'lel_platform_' . substr( hash( 'sha256', uniqid( '', true ) ), 0, 24 );
		$lock      = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		if ( '1' === (string) $lock ) {
			$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			$locks    = '1' === (string) $released;
		} else {
			$locks = false;
		}
		$metadata = $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()' );

		return array(
			'version'            => (string) $wpdb->get_var( 'SELECT VERSION()' ),
			'engine'             => (string) $wpdb->get_var( 'SELECT @@default_storage_engine' ),
			'charset'            => (string) $wpdb->get_var( 'SELECT @@character_set_database' ),
			'advisory_locks'     => $locks,
			'information_schema' => null !== $metadata && false !== $metadata,
		);
	}

	/** A standard capability result. */
	private static function capability( string $name, string $description, bool $satisfied ): array {
		return array(
			'requirement' => $name,
			'satisfied'   => $satisfied,
			'detail'      => $satisfied ? $description . ' available' : $description . ' unavailable',
		);
	}

	/** Parse only the tested Oracle MySQL family; MariaDB needs separate qualification. */
	private static function mysql_version( string $raw ): ?string {
		if ( '' === $raw || false !== stripos( $raw, 'mariadb' ) || 1 !== preg_match( '/\A(\d+(?:\.\d+){1,2})/', $raw, $matches ) ) {
			return null;
		}
		return $matches[1];
	}
}

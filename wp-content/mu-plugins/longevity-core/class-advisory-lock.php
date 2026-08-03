<?php
/**
 * Shared MySQL advisory-lock abstraction with explicit result states.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps GET_LOCK()/RELEASE_LOCK() with unambiguous scalar handling.
 *
 * GET_LOCK() returns 1 when acquired, 0 on contention, and NULL on error.
 * Callers must fail closed on every non-ACQUIRED result; this class never
 * falls back to non-atomic primitives.
 */
final class Advisory_Lock {
	public const ACQUIRED    = 'acquired';
	public const CONTENDED   = 'contended';
	public const UNSUPPORTED = 'unsupported';
	public const ERROR       = 'error';
	public const RELEASED    = 'released';
	public const NOT_HELD    = 'not_held';

	/**
	 * Attempt to acquire a named advisory lock.
	 *
	 * @param string $name    Lock name; callers should namespace it (see namespaced_name()).
	 * @param int    $timeout Seconds to wait for the lock.
	 * @return string One of the class result constants.
	 */
	public static function acquire( string $name, int $timeout = 0 ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return self::UNSUPPORTED;
		}
		try {
			$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return self::ERROR;
		}
		if ( null === $result ) {
			return self::ERROR;
		}
		if ( '1' === (string) $result ) {
			return self::ACQUIRED;
		}
		if ( '0' === (string) $result ) {
			return self::CONTENDED;
		}
		return self::ERROR;
	}

	/**
	 * Release a named advisory lock held by this connection.
	 *
	 * @param string $name Lock name previously passed to acquire().
	 */
	public static function release( string $name ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return self::UNSUPPORTED;
		}
		try {
			$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return self::ERROR;
		}
		if ( '1' === (string) $result ) {
			return self::RELEASED;
		}
		if ( '0' === (string) $result ) {
			return self::NOT_HELD;
		}
		return self::ERROR;
	}

	/**
	 * Run a callback while holding the lock, releasing it in all outcomes.
	 *
	 * @param string   $name     Lock name.
	 * @param int      $timeout  Seconds to wait for the lock.
	 * @param callable $callback Executed only when the lock is ACQUIRED.
	 * @return array{status: string, result: mixed, release_status: string} Callback result only on ACQUIRED.
	 */
	public static function with_lock( string $name, int $timeout, callable $callback ): array {
		$status = self::acquire( $name, $timeout );
		if ( self::ACQUIRED !== $status ) {
			return array(
				'status'         => $status,
				'result'         => null,
				'release_status' => '',
			);
		}
		$result = null;
		try {
			$result = $callback();
		} finally {
			$release_status = self::release( $name );
			if ( self::RELEASED !== $release_status && class_exists( Logger::class ) ) {
				Logger::error(
					'advisory_lock_release_failed',
					array(
						'lock_name'      => $name,
						'release_status' => $release_status,
					)
				);
			}
		}
		return array(
			'status'         => self::ACQUIRED,
			'result'         => $result,
			'release_status' => $release_status,
		);
	}

	/** Whether the database supports advisory locks (probe, per-request cache). */
	public static function supported(): bool {
		static $supported = null;
		if ( null !== $supported ) {
			return $supported;
		}
		$probe = self::namespaced_name( 'support_probe' );
		$state = self::acquire( $probe, 1 );
		if ( self::ACQUIRED === $state ) {
			$supported = self::RELEASED === self::release( $probe );
			return $supported;
		}
		$supported = false;
		return $supported;
	}

	/**
	 * Build a lock name namespaced by application and database identity so
	 * two sites sharing one MySQL server cannot collide.
	 *
	 * @param string $suffix Short lock purpose, e.g. 'migration'.
	 */
	public static function namespaced_name( string $suffix ): string {
		global $wpdb;
		$db      = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		$prefix  = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		$site_id = function_exists( 'get_current_blog_id' ) ? (string) get_current_blog_id() : '1';
		$suffix  = substr( preg_replace( '/[^a-z0-9_:-]/i', '_', $suffix ) ?? 'lock', 0, 32 );
		// GET_LOCK names are limited to 64 characters; hash database and site identity.
		return 'lel_' . $suffix . '_' . substr( hash( 'sha256', $db . '|' . $prefix . '|' . $site_id ), 0, 16 );
	}
}

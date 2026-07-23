<?php
/**
 * Serializes governance approval and publication decisions per post.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Uses a short-lived database advisory lock when the database is available. */
final class Publication_Lock {
	/** @var array<int, int> Re-entrant locks held by this request. */
	private static array $held = array();

	/** Option key for lock failure counter. */
	public const FAILURE_COUNTER_OPTION = 'lel_publication_lock_failures';

	/** Acquire the lock or fail closed. */
	public static function acquire( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( isset( self::$held[ $post_id ] ) ) {
			++self::$held[ $post_id ];
			return true;
		}

		global $wpdb;
		$name   = 'lel_publication_' . $post_id;
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) );
		if ( '1' !== (string) $locked && 1 !== $locked ) {
			self::record_failure( $post_id, (string) $locked );
			return false;
		}
		self::$held[ $post_id ] = 1;
		return true;
	}

	/** Log and count a lock acquisition failure for observability. */
	private static function record_failure( int $post_id, string $result ): void {
		error_log( sprintf( '[longevity-core] Publication lock acquisition failed for post %d (GET_LOCK returned: %s)', $post_id, $result ) );
		$count = (int) get_option( self::FAILURE_COUNTER_OPTION, 0 );
		update_option( self::FAILURE_COUNTER_OPTION, $count + 1, false );
	}

	/** Get the current lock failure count. */
	public static function failure_count(): int {
		return (int) get_option( self::FAILURE_COUNTER_OPTION, 0 );
	}

	/** Verify GET_LOCK support on this database server. */
	public static function get_lock_supported(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$test = $wpdb->get_var( "SELECT GET_LOCK('lel_lock_test', 1)" );
		if ( '1' === (string) $test || 1 === $test ) {
			$wpdb->get_var( "SELECT RELEASE_LOCK('lel_lock_test')" );
			return true;
		}
		return false;
	}

	/** Release one re-entrant lock level. */
	public static function release( int $post_id ): void {
		if ( ! isset( self::$held[ $post_id ] ) ) {
			return;
		}
		--self::$held[ $post_id ];
		if ( self::$held[ $post_id ] > 0 ) {
			return;
		}
		unset( self::$held[ $post_id ] );

		global $wpdb;
		$name = 'lel_publication_' . $post_id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/** Release every lock left by an interrupted request. */
	public static function release_all(): void {
		foreach ( array_keys( self::$held ) as $post_id ) {
			self::$held[ $post_id ] = 1;
			self::release( (int) $post_id );
		}
	}
}

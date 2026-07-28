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

		$result = Advisory_Lock::acquire( self::name( $post_id ), 5 );
		if ( Advisory_Lock::ACQUIRED !== $result ) {
			self::record_failure( $post_id, 'acquire', $result );
			return false;
		}
		self::$held[ $post_id ] = 1;
		return true;
	}

	/** Log and count a lock acquisition failure for observability. */
	private static function record_failure( int $post_id, string $operation, string $result ): void {
		Logger::error(
			'publication_lock_' . $operation . '_failed',
			array(
				'post_id'    => $post_id,
				'lock_state' => $result,
			)
		);
		$count = (int) get_option( self::FAILURE_COUNTER_OPTION, 0 );
		update_option( self::FAILURE_COUNTER_OPTION, $count + 1, false );
	}

	/** Get the current lock failure count. */
	public static function failure_count(): int {
		return (int) get_option( self::FAILURE_COUNTER_OPTION, 0 );
	}

	/** Verify GET_LOCK support on this database server. */
	public static function get_lock_supported(): bool {
		return Advisory_Lock::supported();
	}

	/** Release one re-entrant lock level. */
	public static function release( int $post_id ): bool {
		if ( ! isset( self::$held[ $post_id ] ) ) {
			return true;
		}
		--self::$held[ $post_id ];
		if ( self::$held[ $post_id ] > 0 ) {
			return true;
		}
		unset( self::$held[ $post_id ] );

		$result = Advisory_Lock::release( self::name( $post_id ) );
		if ( Advisory_Lock::RELEASED !== $result ) {
			self::record_failure( $post_id, 'release', $result );
			return false;
		}
		return true;
	}

	/** Release every lock left by an interrupted request. */
	public static function release_all(): void {
		foreach ( array_keys( self::$held ) as $post_id ) {
			self::$held[ $post_id ] = 1;
			self::release( (int) $post_id );
		}
	}

	/** Database- and site-scoped lock name for one publication record. */
	private static function name( int $post_id ): string {
		return Advisory_Lock::namespaced_name( 'publication_' . $post_id );
	}
}

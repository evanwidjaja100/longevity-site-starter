<?php
/**
 * Durable publication-override state machine.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Audit-before-authorize, idempotent publication overrides. */
final class Override_Intent {
	public const STATE_REQUESTED   = 'requested';
	public const STATE_AUTHORIZED  = 'authorized';
	public const STATE_APPLIED     = 'applied';
	public const STATE_FAILED      = 'failed';
	public const STATE_COMPENSATED = 'compensated';

	/**
	 * Request-local cache of intent rows keyed by correlation ID.
	 *
	 * @var array<string, array<string, mixed>> Request-local cache; the database remains authoritative.
	 */
	private static array $cache = array();

	/** Table name. */
	public static function table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_override_intents' : 'wp_lel_override_intents';
	}

	/** Install or additively upgrade the intent table. */
	public static function install(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
			return;
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$table   = self::table_name();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id varchar(64) NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			previous_status varchar(20) NOT NULL,
			requested_status varchar(20) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			capability_snapshot text NOT NULL,
			fingerprint varchar(128) NOT NULL,
			approval_state text NOT NULL,
			state varchar(20) NOT NULL DEFAULT 'requested',
			reason text NOT NULL,
			channel varchar(20) NOT NULL DEFAULT '',
			source_sha varchar(64) NOT NULL DEFAULT 'unavailable',
			plugin_version varchar(32) NOT NULL DEFAULT 'unknown',
			requested_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			authorized_at datetime DEFAULT NULL,
			applied_at datetime DEFAULT NULL,
			failed_at datetime DEFAULT NULL,
			compensated_at datetime DEFAULT NULL,
			result varchar(64) NOT NULL DEFAULT 'pending',
			expires_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_request (request_id),
			KEY post_state (post_id,state)
		) {$charset};";
		dbDelta( $sql );
	}

	/** Whether the table exists. */
	public static function exists(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}
		$table = self::table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Durably request and authorize an override.
	 *
	 * The caller supplies the correlation ID. Replays return the existing state,
	 * never insert another intent or repeat the authorization audit.
	 *
	 * @param string $correlation_id Stable caller-supplied idempotency key.
	 * @param array  $fields         Immutable override fields and validation snapshot.
	 * @return string One of the STATE_* constants, or STATE_FAILED when refused.
	 */
	public static function authorize( string $correlation_id, array $fields ): string {
		global $wpdb;
		$record = self::normalize( $correlation_id, $fields );
		if ( null === $record || ! isset( $wpdb ) || ! method_exists( $wpdb, 'insert' ) || ! self::exists() ) {
			return self::STATE_FAILED;
		}

		$existing = self::intent( $correlation_id );
		if ( $existing ) {
			return self::replay_state( $existing, $record );
		}

		$inserted = $wpdb->insert( self::table_name(), $record );
		if ( 1 !== (int) $inserted ) {
			$existing = self::intent( $correlation_id, true );
			return $existing ? self::replay_state( $existing, $record ) : self::STATE_FAILED;
		}
		self::$cache[ $correlation_id ] = $record;

		try {
			Audit_Log::record( 'publication_override_authorized', 'post', (int) $record['post_id'], self::audit_payload( $record, 'authorized' ), (int) $record['user_id'], (string) $record['channel'], true );
		} catch ( \Throwable $error ) {
			self::transition( $correlation_id, (int) $record['post_id'], self::STATE_REQUESTED, self::STATE_FAILED, 'authorization_audit_failed', 'failed_at' );
			Logger::error(
				'override_authorization_audit_failed',
				array(
					'post_id'        => $record['post_id'],
					'correlation_id' => $correlation_id,
					'message'        => $error->getMessage(),
				)
			);
			return self::STATE_FAILED;
		}

		if ( ! self::transition( $correlation_id, (int) $record['post_id'], self::STATE_REQUESTED, self::STATE_AUTHORIZED, 'authorized', 'authorized_at' ) ) {
			self::transition( $correlation_id, (int) $record['post_id'], self::STATE_REQUESTED, self::STATE_FAILED, 'authorization_transition_failed', 'failed_at' );
			return self::STATE_FAILED;
		}
		return self::STATE_AUTHORIZED;
	}

	/**
	 * Whether an intent still authorizes exactly one pending status transition.
	 *
	 * @param string $correlation_id Caller-supplied idempotency key.
	 * @param int    $post_id        Post the transition applies to.
	 * @return bool Whether the intent authorizes the transition.
	 */
	public static function authorizes_transition( string $correlation_id, int $post_id ): bool {
		$record = self::intent( $correlation_id );
		return is_array( $record ) && $post_id === (int) $record['post_id'] && self::STATE_AUTHORIZED === (string) $record['state'];
	}

	/**
	 * Finalize only after WordPress reports the actual post-status transition.
	 *
	 * @param string $correlation_id         Caller-supplied idempotency key.
	 * @param int    $post_id                Post the transition applies to.
	 * @param string $actual_status          Status WordPress actually applied.
	 * @param string $actual_previous_status Prior status WordPress reported, if known.
	 * @return string One of the STATE_* constants.
	 */
	public static function finalize( string $correlation_id, int $post_id, string $actual_status, string $actual_previous_status = '' ): string {
		$record = self::intent( $correlation_id );
		if ( ! $record || $post_id !== (int) $record['post_id'] ) {
			return self::STATE_FAILED;
		}
		$state = (string) $record['state'];
		if ( self::STATE_APPLIED === $state || self::STATE_COMPENSATED === $state ) {
			return $state;
		}
		if ( self::STATE_AUTHORIZED !== $state ) {
			return self::STATE_FAILED;
		}
		if ( ! hash_equals( (string) $record['requested_status'], $actual_status ) || ( '' !== $actual_previous_status && ! hash_equals( (string) $record['previous_status'], $actual_previous_status ) ) ) {
			self::transition( $correlation_id, $post_id, self::STATE_AUTHORIZED, self::STATE_FAILED, 'status_transition_failed', 'failed_at' );
			Audit_Log::record( 'publication_override_failed', 'post', $post_id, self::audit_payload( $record, 'status_transition_failed' ), (int) $record['user_id'], (string) $record['channel'] );
			return self::STATE_FAILED;
		}

		try {
			Audit_Log::record( 'publication_override_applied', 'post', $post_id, self::audit_payload( $record, 'applied' ), (int) $record['user_id'], (string) $record['channel'], true );
		} catch ( \Throwable $error ) {
			self::transition( $correlation_id, $post_id, self::STATE_AUTHORIZED, self::STATE_FAILED, 'finalization_audit_failed', 'failed_at' );
			Logger::error(
				'override_finalization_audit_failed',
				array(
					'post_id'        => $post_id,
					'correlation_id' => $correlation_id,
					'message'        => $error->getMessage(),
				)
			);
			return self::STATE_FAILED;
		}

		if ( self::transition( $correlation_id, $post_id, self::STATE_AUTHORIZED, self::STATE_APPLIED, 'applied', 'applied_at' ) ) {
			return self::STATE_APPLIED;
		}
		self::transition( $correlation_id, $post_id, self::STATE_AUTHORIZED, self::STATE_FAILED, 'finalization_transition_failed', 'failed_at' );
		return self::STATE_FAILED;
	}

	/**
	 * Record successful restoration of the immutable previous status.
	 *
	 * @param string $correlation_id Caller-supplied idempotency key.
	 * @param int    $post_id        Post the transition applies to.
	 * @param string $actual_status  Status WordPress restored to.
	 * @return string One of the STATE_* constants.
	 */
	public static function compensate( string $correlation_id, int $post_id, string $actual_status ): string {
		$record = self::intent( $correlation_id );
		if ( ! $record || $post_id !== (int) $record['post_id'] || ! in_array( (string) $record['state'], array( self::STATE_FAILED, self::STATE_AUTHORIZED ), true ) || ! hash_equals( (string) $record['previous_status'], $actual_status ) ) {
			return self::STATE_FAILED;
		}
		if ( ! self::transition( $correlation_id, $post_id, (string) $record['state'], self::STATE_COMPENSATED, 'previous_status_restored', 'compensated_at' ) ) {
			return self::STATE_FAILED;
		}
		Audit_Log::record( 'publication_override_compensated', 'post', $post_id, self::audit_payload( $record, 'previous_status_restored' ), (int) $record['user_id'], (string) $record['channel'] );
		return self::STATE_COMPENSATED;
	}

	/**
	 * Read a durable intent by correlation ID.
	 *
	 * @param string $correlation_id Caller-supplied idempotency key.
	 * @param bool   $refresh        Whether to bypass the request-local cache.
	 * @return array|null Intent row, or null when absent.
	 */
	public static function intent( string $correlation_id, bool $refresh = false ): ?array {
		global $wpdb;
		if ( ! $refresh && isset( self::$cache[ $correlation_id ] ) ) {
			return self::$cache[ $correlation_id ];
		}
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return null;
		}
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %s LIMIT 1", $correlation_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) ) {
			return null;
		}
		self::$cache[ $correlation_id ] = $row;
		return $row;
	}

	/**
	 * Normalize and validate every immutable authorization field.
	 *
	 * @param string $correlation_id Caller-supplied idempotency key.
	 * @param array  $fields         Raw override fields and validation snapshot.
	 * @return array|null Normalized record, or null when validation fails.
	 */
	private static function normalize( string $correlation_id, array $fields ): ?array {
		$correlation_id = trim( $correlation_id );
		$reason         = trim( sanitize_textarea_field( (string) ( $fields['reason'] ?? '' ) ) );
		$capabilities   = $fields['capability_snapshot'] ?? array();
		$user_id        = (int) ( $fields['user_id'] ?? 0 );
		$valid_statuses = array( 'publish', 'future', 'private' );
		if ( ! preg_match( '/\A[A-Za-z0-9._:-]{8,64}\z/', $correlation_id ) || (int) ( $fields['post_id'] ?? 0 ) <= 0 || $user_id <= 0 || strlen( $reason ) < 10 || strlen( $reason ) > 2000 || '' === (string) ( $fields['fingerprint'] ?? '' ) || ! in_array( (string) ( $fields['requested_status'] ?? '' ), $valid_statuses, true ) || '' === (string) ( $fields['previous_status'] ?? '' ) || ! is_array( $capabilities ) || empty( $capabilities['approve_publication_override'] ) || empty( $fields['nonce_verified'] ) || '' === (string) ( $fields['approval_state'] ?? '' ) ) {
			return null;
		}
		if ( function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 && get_current_user_id() !== $user_id ) {
			return null;
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'approve_publication_override' ) ) {
			return null;
		}
		return array(
			'request_id'          => $correlation_id,
			'post_id'             => (int) $fields['post_id'],
			'previous_status'     => substr( sanitize_key( (string) $fields['previous_status'] ), 0, 20 ),
			'requested_status'    => (string) $fields['requested_status'],
			'user_id'             => $user_id,
			'capability_snapshot' => (string) wp_json_encode( $capabilities ),
			'fingerprint'         => substr( sanitize_text_field( (string) $fields['fingerprint'] ), 0, 128 ),
			'approval_state'      => substr( sanitize_text_field( (string) $fields['approval_state'] ), 0, 255 ),
			'state'               => self::STATE_REQUESTED,
			'reason'              => $reason,
			'channel'             => substr( sanitize_key( (string) ( $fields['channel'] ?? '' ) ), 0, 20 ),
			'source_sha'          => substr( sanitize_text_field( (string) ( $fields['source_sha'] ?? 'unavailable' ) ), 0, 64 ),
			'plugin_version'      => substr( sanitize_text_field( (string) ( $fields['plugin_version'] ?? 'unknown' ) ), 0, 32 ),
			'requested_at'        => gmdate( 'Y-m-d H:i:s' ),
			'result'              => 'pending',
			// Compatibility with the previous non-null expiry column; never used as authority.
			'expires_at'          => '9999-12-31 23:59:59',
		);
	}

	/**
	 * Return a prior result only when the idempotency key describes the same immutable request.
	 *
	 * @param array $existing  Stored intent row.
	 * @param array $requested Normalized incoming request.
	 * @return string One of the STATE_* constants.
	 */
	private static function replay_state( array $existing, array $requested ): string {
		foreach ( array( 'post_id', 'previous_status', 'requested_status', 'user_id', 'capability_snapshot', 'fingerprint', 'approval_state', 'reason', 'channel', 'source_sha', 'plugin_version' ) as $field ) {
			if ( (string) ( $existing[ $field ] ?? '' ) !== (string) $requested[ $field ] ) {
				Audit_Log::record(
					'publication_override_replay_denied',
					'post',
					(int) $requested['post_id'],
					array(
						'correlation_id' => $requested['request_id'],
						'reason'         => 'immutable_field_mismatch',
					),
					(int) $requested['user_id'],
					(string) $requested['channel']
				);
				return self::STATE_FAILED;
			}
		}
		$state = (string) ( $existing['state'] ?? '' );
		if ( self::STATE_REQUESTED === $state ) {
			// A prior process may have stopped before or after its audit write. Fail it
			// rather than risk a duplicate audit entry; retry with a new correlation ID.
			self::transition( (string) $requested['request_id'], (int) $requested['post_id'], self::STATE_REQUESTED, self::STATE_FAILED, 'interrupted_authorization', 'failed_at' );
			return self::STATE_FAILED;
		}
		return in_array( $state, array( self::STATE_AUTHORIZED, self::STATE_APPLIED, self::STATE_FAILED, self::STATE_COMPENSATED ), true ) ? $state : self::STATE_FAILED;
	}

	/**
	 * Atomic state transition; immutable columns are never updated.
	 *
	 * @param string $correlation_id   Caller-supplied idempotency key.
	 * @param int    $post_id          Post the transition applies to.
	 * @param string $from             Required current state.
	 * @param string $to               Target state.
	 * @param string $result           Result label stored with the row.
	 * @param string $timestamp_column Timestamp column to stamp for this transition.
	 * @return bool Whether exactly one row transitioned.
	 */
	private static function transition( string $correlation_id, int $post_id, string $from, string $to, string $result, string $timestamp_column ): bool {
		global $wpdb;
		$columns = array( 'authorized_at', 'applied_at', 'failed_at', 'compensated_at' );
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) || ! in_array( $timestamp_column, $columns, true ) ) {
			return false;
		}
		if ( isset( self::$cache[ $correlation_id ] ) && $from !== (string) self::$cache[ $correlation_id ]['state'] ) {
			return false;
		}
		$table = self::table_name();
		$rows  = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = LOWER(%s), result = %s, {$timestamp_column} = UTC_TIMESTAMP() WHERE request_id = %s AND post_id = %d AND state = LOWER(%s)", $to, $result, $correlation_id, $post_id, $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $rows ) {
			return false;
		}
		if ( isset( self::$cache[ $correlation_id ] ) ) {
			self::$cache[ $correlation_id ]['state']             = $to;
			self::$cache[ $correlation_id ]['result']            = $result;
			self::$cache[ $correlation_id ][ $timestamp_column ] = gmdate( 'Y-m-d H:i:s' );
		}
		return true;
	}

	/**
	 * Immutable audit projection shared by each state transition.
	 *
	 * @param array  $record Stored intent row.
	 * @param string $result Result label for this projection.
	 * @return array Audit payload fields.
	 */
	private static function audit_payload( array $record, string $result ): array {
		return array(
			'correlation_id'      => $record['request_id'],
			'previous_status'     => $record['previous_status'],
			'requested_status'    => $record['requested_status'],
			'capability_snapshot' => $record['capability_snapshot'],
			'combined_hash'       => $record['fingerprint'],
			'approval_state'      => $record['approval_state'],
			'reason'              => $record['reason'],
			'source_sha'          => $record['source_sha'],
			'plugin_version'      => $record['plugin_version'],
			'requested_at'        => $record['requested_at'],
			'result'              => $result,
		);
	}
}

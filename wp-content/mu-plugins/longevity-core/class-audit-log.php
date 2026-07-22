<?php
/**
 * Append-only governance audit events.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Records bounded, sanitized, hash-chained governance events. */
final class Audit_Log {
	public const SCHEMA_VERSION = '1.0.0';

	/** Table name. */
	public static function table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_audit_events' : 'wp_lel_audit_events';
	}

	/** Install additive table. */
	public static function install(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
			return;
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$table   = self::table_name();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			occurred_at datetime NOT NULL,
			event_type varchar(64) NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL,
			object_type varchar(40) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			request_id varchar(64) NOT NULL,
			source_channel varchar(32) NOT NULL,
			payload_json text NOT NULL,
			previous_event_hash char(64) NOT NULL,
			event_hash char(64) NOT NULL,
			schema_version varchar(20) NOT NULL,
			PRIMARY KEY  (id),
			KEY object_time (object_type,object_id,occurred_at),
			KEY actor_time (actor_user_id,occurred_at),
			KEY event_type (event_type)
		) {$charset};";
		dbDelta( $sql );
	}

	/** Record an event. Sensitive text is never accepted wholesale. */
	public static function record( string $event_type, string $object_type, int $object_id, array $payload = array(), int $actor_id = 0, string $source_channel = 'system' ): int {
		global $wpdb;
		$event_type     = substr( sanitize_key( $event_type ), 0, 64 );
		$object_type    = substr( sanitize_key( $object_type ), 0, 40 );
		$source_channel = substr( sanitize_key( $source_channel ), 0, 32 );
		$payload        = self::sanitize_payload( $payload );
		$request_id     = self::request_id();
		$previous       = '';
		if ( isset( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			$table    = self::table_name();
			$previous = (string) $wpdb->get_var( "SELECT event_hash FROM {$table} ORDER BY id DESC LIMIT 1" );
		}
		$record = array(
			'occurred_at'        => gmdate( 'Y-m-d H:i:s' ),
			'event_type'         => $event_type,
			'actor_user_id'      => max( 0, $actor_id ),
			'object_type'        => $object_type,
			'object_id'          => max( 0, $object_id ),
			'request_id'         => $request_id,
			'source_channel'     => $source_channel,
			'payload_json'       => (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'previous_event_hash'=> $previous,
			'schema_version'     => self::SCHEMA_VERSION,
		);
		$record['event_hash'] = hash( 'sha256', Approval_Fingerprint::canonical_json( $record ) );
		if ( isset( $wpdb ) && method_exists( $wpdb, 'insert' ) && self::exists() ) {
			$inserted = $wpdb->insert( self::table_name(), $record );
			return false === $inserted ? 0 : (int) $wpdb->insert_id;
		}

		// Compatibility fallback for pre-migration environments. Bounded and non-sensitive.
		if ( $object_id > 0 && function_exists( 'get_post_meta' ) && function_exists( 'update_post_meta' ) ) {
			$legacy = get_post_meta( $object_id, '_longevity_audit_log', true );
			$legacy = is_array( $legacy ) ? $legacy : array();
			$legacy[] = array( 'time' => gmdate( DATE_W3C ), 'event' => $event_type, 'actor' => max( 0, $actor_id ), 'details' => $payload );
			update_post_meta( $object_id, '_longevity_audit_log', array_slice( $legacy, -100 ) );
		}
		return 0;
	}

	/** Whether the table exists. */
	public static function exists(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$table = self::table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/** Stable request correlation ID for the current request. */
	private static function request_id(): string {
		static $request_id = '';
		if ( '' === $request_id ) {
			$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : hash( 'sha256', microtime( true ) . ':' . mt_rand() );
		}
		return substr( $request_id, 0, 64 );
	}

	/** Sanitize and bound nested payloads. */
	private static function sanitize_payload( array $payload ): array {
		$blocked = array( 'body', 'message', 'contact_email', 'contact_ip', 'credential_verification_evidence_ref', 'raw_observations', 'evidence_references' );
		$out     = array();
		foreach ( array_slice( $payload, 0, 20, true ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || in_array( $key, $blocked, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = self::sanitize_payload( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$out[ $key ] = $value;
			} else {
				$out[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 255 );
			}
		}
		return $out;
	}
}

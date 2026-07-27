<?php
/**
 * Structured JSON logger with levels and request correlation.
 *
 * Emits one JSON object per line to the PHP error log so operators can
 * parse and correlate lines to audit rows by `request_id`.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Minimal leveled structured logger. */
final class Logger {
	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * Emit a structured log line.
	 *
	 * @param string               $level   One of the level constants.
	 * @param string               $event   Short machine-readable event key.
	 * @param array<string, mixed> $context Additional bounded context fields.
	 */
	public static function log( string $level, string $event, array $context = array() ): void {
		if ( ! function_exists( 'error_log' ) ) {
			return;
		}
		$record = array(
			'ts'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'level'      => in_array( $level, array( self::DEBUG, self::INFO, self::WARNING, self::ERROR ), true ) ? $level : self::INFO,
			'event'      => substr( $event, 0, 128 ),
			'request_id' => self::request_id(),
		);
		foreach ( array_slice( $context, 0, 20, true ) as $key => $value ) {
			$key = substr( preg_replace( '/[^a-z0-9_]/i', '_', (string) $key ) ?? '', 0, 40 );
			if ( '' === $key || isset( $record[ $key ] ) ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$record[ $key ] = is_string( $value ) ? substr( $value, 0, 512 ) : $value;
			} else {
				$record[ $key ] = substr( (string) wp_json_encode( $value ), 0, 512 );
			}
		}
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $record );
		error_log( '[longevity] ' . $json );
	}

	/** Convenience wrapper for warnings. */
	public static function warning( string $event, array $context = array() ): void {
		self::log( self::WARNING, $event, $context );
	}

	/** Convenience wrapper for errors. */
	public static function error( string $event, array $context = array() ): void {
		self::log( self::ERROR, $event, $context );
	}

	/** Convenience wrapper for informational events. */
	public static function info( string $event, array $context = array() ): void {
		self::log( self::INFO, $event, $context );
	}

	/**
	 * Stable per-request correlation ID, shared with the audit log so log lines
	 * and audit rows can be joined on `request_id`.
	 */
	public static function request_id(): string {
		static $request_id = '';
		if ( '' === $request_id ) {
			$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : hash( 'sha256', microtime( true ) . ':' . wp_rand() );
		}
		return substr( $request_id, 0, 64 );
	}
}

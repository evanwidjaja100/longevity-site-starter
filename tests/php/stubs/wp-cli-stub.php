<?php
/**
 * Minimal WP-CLI stub for PHPStan static analysis.
 *
 * This file is only loaded by PHPStan to provide type information for the
 * WP_CLI class. It is NOT loaded at runtime — the real WP_CLI class is
 * provided by the wp-cli/php-cli package when running under WP-CLI.
 *
 * @package LongevityCore
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	final class WP_CLI {
		public static function add_command( string $name, $callable, array $args = array() ): void {}
		public static function error( string $message, bool $exit = true ): void {}
		public static function warning( string $message ): void {}
		public static function success( string $message ): void {}
		public static function line( string $message ): void {}
		public static function log( string $message ): void {}
		public static function halt( int $code = 0 ): void {}
		public static function run( array $args ): bool { return true; }
	}
}

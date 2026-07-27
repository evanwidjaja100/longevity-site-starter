<?php
/**
 * Minimal wpdb stub for PHPStan static analysis.
 *
 * This file is only loaded by PHPStan to provide type information for the
 * wpdb class. It is NOT loaded at runtime.
 *
 * @package LongevityCore
 */

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public string $siteid = '1';

		public function insert( string $table, array $data, array $format = array() ): int { return 1; }
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): int { return 1; }
		public function delete( string $table, array $where, array $where_format = array() ): int { return 1; }
		public function get_var( string $query ): mixed { return null; }
		public function get_row( string $query, string $output = '' ): mixed { return null; }
		public function get_col( string $query ): array { return array(); }
		public function get_results( string $query, string $output = '' ): array { return array(); }
		public function prepare( string $query, ...$args ): string { return $query; }
		public function query( string $query ): int { return 0; }
		public function escape( string $data ): string { return $data; }
		public function esc_like( string $data ): string { return $data; }
	}
}

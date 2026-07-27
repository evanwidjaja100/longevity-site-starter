<?php
/**
 * Prometheus-format metrics exposition.
 *
 * Renders the observability counters and system-readiness signals as a
 * Prometheus text-format payload for scraping or the node_exporter textfile
 * collector. No secrets or PII are exposed.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Builds a Prometheus text-format metrics payload. */
final class Metrics {
	/** Map a readiness status string to a numeric gauge value. */
	private static function status_value( string $status ): float {
		switch ( $status ) {
			case 'ok':
				return 1.0;
			case 'blocked':
				return 0.0;
			default: // degraded, unknown_external, unknown.
				return 0.5;
		}
	}

	/**
	 * Render the full metrics payload in Prometheus text format.
	 *
	 * @return string
	 */
	public static function render(): string {
		$lines = array();

		$audit_failures = (int) get_option( 'lel_audit_write_failures', 0 );
		$csp_violations = (int) get_option( 'lel_csp_violation_count', 0 );
		$fallback_fail  = class_exists( Invalidation_Queue::class ) ? Invalidation_Queue::fallback_failure_count() : (int) get_option( 'lel_invalidation_fallback_failures', 0 );
		$lock_failures  = class_exists( Publication_Lock::class ) && method_exists( Publication_Lock::class, 'failure_count' ) ? Publication_Lock::failure_count() : 0;

		$lines[] = '# HELP lel_audit_write_failures_total Total audit-log write failures observed.';
		$lines[] = '# TYPE lel_audit_write_failures_total counter';
		$lines[] = 'lel_audit_write_failures_total ' . $audit_failures;

		$lines[] = '# HELP lel_csp_violations_total Total accepted CSP violation reports.';
		$lines[] = '# TYPE lel_csp_violations_total counter';
		$lines[] = 'lel_csp_violations_total ' . $csp_violations;

		$lines[] = '# HELP lel_invalidation_fallback_failures_total Total synchronous invalidation fallback failures.';
		$lines[] = '# TYPE lel_invalidation_fallback_failures_total counter';
		$lines[] = 'lel_invalidation_fallback_failures_total ' . $fallback_fail;

		$lines[] = '# HELP lel_publication_lock_failures_total Total publication-lock acquisition failures.';
		$lines[] = '# TYPE lel_publication_lock_failures_total counter';
		$lines[] = 'lel_publication_lock_failures_total ' . $lock_failures;

		// Per-check readiness gauges and an overall gauge.
		$report = class_exists( System_Readiness::class ) ? System_Readiness::report() : array( 'status' => 'unknown', 'checks' => array() );
		$lines[] = '# HELP lel_readiness_check Readiness per check: 1 ok, 0.5 degraded, 0 blocked.';
		$lines[] = '# TYPE lel_readiness_check gauge';
		foreach ( (array) ( $report['checks'] ?? array() ) as $name => $check ) {
			$label   = preg_replace( '/[^a-z0-9_]/i', '_', (string) $name );
			$status  = isset( $check['status'] ) ? (string) $check['status'] : 'unknown';
			$lines[] = sprintf( 'lel_readiness_check{check="%s",status="%s"} %s', $label, $status, self::format_float( self::status_value( $status ) ) );
		}
		$lines[] = '# HELP lel_readiness_overall Overall readiness: 1 ok, 0.5 degraded, 0 blocked.';
		$lines[] = '# TYPE lel_readiness_overall gauge';
		$lines[] = 'lel_readiness_overall ' . self::format_float( self::status_value( (string) ( $report['status'] ?? 'unknown' ) ) );

		return implode( "\n", $lines ) . "\n";
	}

	/** Format a float without a trailing locale decimal comma. */
	private static function format_float( float $value ): string {
		return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' ) ?: '0';
	}
}

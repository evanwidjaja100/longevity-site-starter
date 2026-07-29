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
			case 'error':
				return 0.0;
			default: // degraded or unknown_external; unknowns normalize before this call.
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
		$lock_failures  = class_exists( Publication_Lock::class ) ? Publication_Lock::failure_count() : 0;
		$rank_rejects   = (int) get_option( 'lel_rankings_cache_rejections', 0 );

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

		$lines[] = '# HELP lel_rankings_cache_rejected_total Total cached ranking IDs rejected by live eligibility revalidation.';
		$lines[] = '# TYPE lel_rankings_cache_rejected_total counter';
		$lines[] = 'lel_rankings_cache_rejected_total ' . $rank_rejects;

		// Affiliate dependency-edge health. -1 means "unknown", never zero:
		// an unavailable index or missing backfill report must not scrape as
		// a healthy empty state.
		$edge_count  = class_exists( Dependency_Index::class ) ? Dependency_Index::affiliate_edge_count() : null;
		$edge_report = get_option( 'lel_affiliate_edge_report', null );
		$edge_report = is_array( $edge_report ) ? $edge_report : null;
		$lines[]     = '# HELP lel_affiliate_dependency_edges Materialized affiliate dependency edges; -1 when the index is unavailable.';
		$lines[]     = '# TYPE lel_affiliate_dependency_edges gauge';
		$lines[]     = 'lel_affiliate_dependency_edges ' . ( null === $edge_count ? '-1' : $edge_count );
		foreach ( array(
			'lel_affiliate_unresolved_destinations' => array( 'unresolved', 'Affiliate destinations with no registry match during the last completed edge backfill; -1 before any completed backfill.' ),
			'lel_affiliate_ambiguous_destinations'  => array( 'ambiguous', 'Affiliate destinations matching multiple registry rows during the last completed edge backfill; -1 before any completed backfill.' ),
			'lel_affiliate_broad_fallbacks'         => array( 'broad_fallbacks', 'Parents bound to every registry row because edge extraction failed during the last completed backfill; -1 before any completed backfill.' ),
		) as $metric => $spec ) {
			$lines[] = '# HELP ' . $metric . ' ' . $spec[1];
			$lines[] = '# TYPE ' . $metric . ' gauge';
			$lines[] = $metric . ' ' . ( null === $edge_report ? '-1' : (int) ( $edge_report[ $spec[0] ] ?? -1 ) );
		}
		$backfill_pending = ! class_exists( Dependency_Index::class ) || ! Dependency_Index::backfill_marker_current();
		$lines[]          = '# HELP lel_dependency_backfill_pending 1 while the dependency-index backfill for the current generation has not completed.';
		$lines[]          = '# TYPE lel_dependency_backfill_pending gauge';
		$lines[]          = 'lel_dependency_backfill_pending ' . ( $backfill_pending ? 1 : 0 );

		$identity    = class_exists( Evidence_Store::class ) ? Evidence_Store::runtime_release_identity() : array();
		$environment = in_array( (string) ( $identity['environment'] ?? '' ), array( 'local', 'development', 'staging', 'production' ), true ) ? (string) $identity['environment'] : 'unknown';
		$source_sha  = preg_match( '/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', (string) ( $identity['release_sha'] ?? '' ) ) ? (string) $identity['release_sha'] : 'unknown';
		$artifact    = preg_match( '/\A[a-f0-9]{64}\z/', (string) ( $identity['artifact_checksum'] ?? '' ) ) ? (string) $identity['artifact_checksum'] : 'unknown';
		$lines[]     = '# HELP lel_build_info Immutable deployed build identity.';
		$lines[]     = '# TYPE lel_build_info gauge';
		$lines[]     = sprintf( 'lel_build_info{environment="%s",source_sha="%s",artifact_sha256="%s"} 1', $environment, $source_sha, $artifact );

		// Per-check readiness one-hot state gauges and an overall gauge.
		// One time series per (check, state) with value 0 or 1 replaces the
		// former changing-status-label gauge, which broke series continuity.
		$report = class_exists( System_Readiness::class ) ? System_Readiness::report() : array( 'status' => 'blocked', 'checks' => array() );
		$lines[] = '# HELP lel_readiness_check_state Readiness check state one-hot: exactly one state is 1 per check.';
		$lines[] = '# TYPE lel_readiness_check_state gauge';
		foreach ( (array) ( $report['checks'] ?? array() ) as $name => $check ) {
			$label  = preg_replace( '/[^a-z0-9_]/i', '_', (string) $name );
			$status = System_Readiness::normalize_status( isset( $check['status'] ) ? (string) $check['status'] : '' );
			foreach ( System_Readiness::CHECK_STATES as $state ) {
				$lines[] = sprintf( 'lel_readiness_check_state{check="%s",state="%s"} %d', $label, $state, $status === $state ? 1 : 0 );
			}
		}
		$lines[] = '# HELP lel_readiness_overall Overall readiness: 1 ok, 0.5 degraded, 0 blocked.';
		$lines[] = '# TYPE lel_readiness_overall gauge';
		$overall = System_Readiness::normalize_status( (string) ( $report['status'] ?? '' ) );
		$lines[] = 'lel_readiness_overall ' . self::format_float( self::status_value( $overall ) );
		$lines[] = '# HELP lel_readiness_overall_state Overall readiness state one-hot.';
		$lines[] = '# TYPE lel_readiness_overall_state gauge';
		foreach ( System_Readiness::CHECK_STATES as $state ) {
			$lines[] = sprintf( 'lel_readiness_overall_state{state="%s"} %d', $state, $overall === $state ? 1 : 0 );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/** Format a float without a trailing locale decimal comma. */
	private static function format_float( float $value ): string {
		return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' ) ?: '0';
	}
}

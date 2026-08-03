<?php
/**
 * Metric catalog contract test (PRV3-OBS-01).
 *
 * The monitoring documentation table and the metrics implementation must
 * describe exactly the same series: every documented metric is emitted and
 * every emitted metric is documented. Alert rules may only reference
 * documented series and must use reset-aware increase() windows for the
 * lifetime counters.
 *
 * @package LongevityCore
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MetricsCatalogTest extends TestCase {

	private static function repo_root(): string {
		return dirname( __DIR__, 2 );
	}

	/** @return string[] Metric names emitted by class-metrics.php (# TYPE lines plus dynamically-typed loop metrics). */
	private static function emitted_metrics(): array {
		$source = (string) file_get_contents( LONGEVITY_CORE_PATH . 'class-metrics.php' );
		preg_match_all( '/# TYPE (lel_[a-z0-9_]+) /', $source, $static_matches );
		preg_match_all( "/'(lel_[a-z0-9_]+)'\s*=>\s*array\(/", $source, $dynamic_matches );
		$names = array_unique( array_merge( $static_matches[1], $dynamic_matches[1] ) );
		sort( $names );
		return $names;
	}

	/** @return string[] Metric names documented in the monitoring.md catalog table. */
	private static function documented_metrics(): array {
		$doc = (string) file_get_contents( self::repo_root() . '/docs/operations/monitoring.md' );
		preg_match_all( '/^\| `(lel_[a-z0-9_]+)(?:\{[^}]*\})?` \|/m', $doc, $matches );
		$names = array_unique( $matches[1] );
		sort( $names );
		return $names;
	}

	public function test_documented_catalog_matches_emitted_metrics(): void {
		self::assertSame(
			self::emitted_metrics(),
			self::documented_metrics(),
			'docs/operations/monitoring.md metric catalog and class-metrics.php emissions have diverged.'
		);
	}

	public function test_alert_rules_reference_only_emitted_metrics(): void {
		$rules = (string) file_get_contents( self::repo_root() . '/ops/monitoring/alert-rules.yml' );
		preg_match_all( '/expr: .*?(lel_[a-z0-9_]+)/', $rules, $matches );
		$emitted = self::emitted_metrics();
		foreach ( array_unique( $matches[1] ) as $metric ) {
			self::assertContains( $metric, $emitted, "Alert rule references unknown metric {$metric}." );
		}
	}

	public function test_lifetime_counter_alerts_use_increase_windows(): void {
		$rules = (string) file_get_contents( self::repo_root() . '/ops/monitoring/alert-rules.yml' );
		preg_match_all( '/expr: (.+)/', $rules, $matches );
		foreach ( $matches[1] as $expr ) {
			if ( preg_match( '/lel_[a-z0-9_]+_total/', $expr, $counter ) ) {
				self::assertStringContainsString(
					'increase(',
					$expr,
					"Lifetime counter alert '{$expr}' must use a reset-aware increase() window, not a raw threshold."
				);
			}
		}
	}

	public function test_alert_runbook_annotations_point_to_existing_files(): void {
		$rules = (string) file_get_contents( self::repo_root() . '/ops/monitoring/alert-rules.yml' );
		preg_match_all( '/runbook: "([^"#]+)(?:#[^"]*)?"/', $rules, $matches );
		self::assertNotEmpty( $matches[1], 'Every alert must carry a runbook annotation.' );
		foreach ( array_unique( $matches[1] ) as $runbook ) {
			self::assertFileExists( self::repo_root() . '/' . $runbook, "Alert runbook annotation points to missing file {$runbook}." );
		}
	}

	public function test_stale_readiness_series_name_is_not_documented(): void {
		$doc = (string) file_get_contents( self::repo_root() . '/docs/operations/monitoring.md' );
		self::assertStringNotContainsString(
			'lel_readiness_check{check,status}',
			$doc,
			'The retired lel_readiness_check{check,status} series must not be documented; the emitted series is lel_readiness_check_state{check,state}.'
		);
	}
}

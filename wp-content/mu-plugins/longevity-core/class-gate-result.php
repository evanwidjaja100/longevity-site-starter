<?php
/**
 * Publication-gate result value object.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Collects publication readiness outcomes.
 */
final class Gate_Result {
	/**
	 * Blocking readiness failures that prevent publication.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $blocking = array();

	/**
	 * Non-blocking readiness warnings.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $warnings = array();

	/**
	 * Checks that passed.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $passed = array();

	/**
	 * Checks that did not apply to this post.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $not_applicable = array();

	private const BUCKETS = array(
		'block' => 'blocking',
		'warn'  => 'warnings',
		'pass'  => 'passed',
		'skip'  => 'not_applicable',
	);

	/**
	 * Append an outcome record to the bucket for a category.
	 *
	 * @param string $category Bucket selector: block, warn, pass, or skip.
	 * @param string $code     Machine-readable check identifier.
	 * @param string $message  Human-readable outcome description.
	 */
	private function push( string $category, string $code, string $message ): void {
		$prop            = self::BUCKETS[ $category ];
		$this->{$prop}[] = array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Record a blocking failure.
	 *
	 * @param string $code    Machine-readable check identifier.
	 * @param string $message Human-readable failure description.
	 */
	public function block( string $code, string $message ): void {
		$this->push( 'block', $code, $message ); }
	/**
	 * Record a non-blocking warning.
	 *
	 * @param string $code    Machine-readable check identifier.
	 * @param string $message Human-readable warning description.
	 */
	public function warn( string $code, string $message ): void {
		$this->push( 'warn', $code, $message ); }
	/**
	 * Record a passed check.
	 *
	 * @param string $code    Machine-readable check identifier.
	 * @param string $message Human-readable outcome description.
	 */
	public function pass( string $code, string $message ): void {
		$this->push( 'pass', $code, $message ); }
	/**
	 * Record a check that did not apply.
	 *
	 * @param string $code    Machine-readable check identifier.
	 * @param string $message Human-readable outcome description.
	 */
	public function skip( string $code, string $message ): void {
		$this->push( 'skip', $code, $message ); }

	/** Whether publication is blocked. */
	public function is_blocked(): bool {
		return ! empty( $this->blocking );
	}

	/** Get blocking items. */
	public function blocking(): array {
		return $this->blocking;
	}

	/** Get warning items. */
	public function warnings(): array {
		return $this->warnings;
	}

	/** Get passed items. */
	public function passed(): array {
		return $this->passed;
	}

	/** Get non-applicable items. */
	public function not_applicable(): array {
		return $this->not_applicable;
	}

	/** Calculate a completion percentage across applicable checks. */
	public function completion_percentage(): int {
		$applicable = count( $this->blocking ) + count( $this->warnings ) + count( $this->passed );
		if ( 0 === $applicable ) {
			return 100;
		}

		return (int) round( ( count( $this->passed ) / $applicable ) * 100 );
	}

	/** Convert to a REST-safe array. */
	public function to_array(): array {
		return array(
			'blocked'            => $this->is_blocked(),
			'completion_percent' => $this->completion_percentage(),
			'blocking'           => $this->blocking,
			'warnings'           => $this->warnings,
			'passed'             => $this->passed,
			'not_applicable'     => $this->not_applicable,
		);
	}
}

<?php
/**
 * Strict date validation helpers.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Validates ISO calendar dates using UTC semantics. */
final class Date_Validator {
	/** Normalize a valid YYYY-MM-DD date or return an empty string. */
	public static function normalize( $value ): string {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return '';
		}
		return $date->format( 'Y-m-d' ) === $value ? $value : '';
	}

	/** Parse a valid date. */
	public static function parse( $value ): ?\DateTimeImmutable {
		$normalized = self::normalize( $value );
		return '' === $normalized ? null : new \DateTimeImmutable( $normalized, new \DateTimeZone( 'UTC' ) );
	}


	/** Whether a value is a semantically valid ISO calendar date. */
	public static function is_valid( $value ): bool {
		return '' !== self::normalize( $value );
	}

	/** Compare two valid dates; negative, zero, or positive like strcmp. */
	public static function compare( string $left, string $right ): int {
		$left_date  = self::parse( $left );
		$right_date = self::parse( $right );
		if ( null === $left_date || null === $right_date ) {
			throw new \InvalidArgumentException( 'Both dates must use a valid YYYY-MM-DD calendar value.' );
		}
		return $left_date <=> $right_date;
	}

	/** Whether the first date occurs on or before the second. */
	public static function on_or_before( string $left, string $right ): bool {
		$left_date  = self::parse( $left );
		$right_date = self::parse( $right );
		return null !== $left_date && null !== $right_date && $left_date <= $right_date;
	}

	/** Whether a date is after another date. */
	public static function after( string $left, string $right ): bool {
		$left_date  = self::parse( $left );
		$right_date = self::parse( $right );
		return null !== $left_date && null !== $right_date && $left_date > $right_date;
	}

	/** Current date in UTC. */
	public static function today(): string {
		return gmdate( 'Y-m-d' );
	}
}

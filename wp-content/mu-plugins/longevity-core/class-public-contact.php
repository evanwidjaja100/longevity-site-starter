<?php
/**
 * Public contact components.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Renders contact form and handles submissions with abuse protection. */
class Public_Contact {
	private const RETENTION_HOOK             = 'lel_contact_retention_cleanup';
	private const SUBJECTS                   = array( 'general', 'correction', 'privacy', 'commercial', 'other' );
	private const MAX_REQUEST_BYTES          = 16384;
	private const PRIVACY_NOTICE_VERSION     = '2026-07';
	private const RECONCILIATION_PREFIX      = 'lel_contact_reconciliation_';
	private const IDEMPOTENCY_RETENTION_DAYS = 7;

	/** Canonical, authoritative per-record retention deadline (UTC `Y-m-d H:i:s`). */
	private const RETENTION_META_KEY = 'contact_retention_until_gmt';

	/** Legacy per-record deadline written as an ISO-8601 string by older releases. */
	private const LEGACY_RETENTION_META_KEY = 'contact_retention_until';

	/** Non-PII backfill report and resumable cursor for the retention migration. */
	private const RETENTION_BACKFILL_REPORT_OPTION = 'lel_contact_retention_backfill_report';
	private const RETENTION_BACKFILL_CURSOR_OPTION = 'lel_contact_retention_backfill_cursor';
	private const RETENTION_BACKFILL_BATCH         = 200;

	/** Register privacy-preserving retention. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'schedule_retention' ), 30 );
		add_action(
			self::RETENTION_HOOK,
			static function (): void {
				self::run_retention_cleanup();
			}
		);
	}
	/**
	 * Resolve the client IP address.
	 *
	 * Respects X-Forwarded-For only when REMOTE_ADDR matches a trusted proxy
	 * defined via the LONGEVITY_TRUSTED_PROXIES constant. Without trusted-proxy
	 * configuration, returns REMOTE_ADDR directly.
	 */
	public static function get_client_ip(): string {
		$trusted = defined( 'LONGEVITY_TRUSTED_PROXIES' ) && is_array( LONGEVITY_TRUSTED_PROXIES ) ? array_values( LONGEVITY_TRUSTED_PROXIES ) : array();
		$remote  = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return '0.0.0.0';
		}
		if ( ! in_array( $remote, $trusted, true ) ) {
			return $remote;
		}
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		$chain     = array_values( array_filter( array_map( 'trim', explode( ',', $forwarded ) ), static fn( $ip ) => (bool) filter_var( $ip, FILTER_VALIDATE_IP ) ) );
		$chain[]   = $remote;
		for ( $index = count( $chain ) - 1; $index >= 0; --$index ) {
			if ( ! in_array( $chain[ $index ], $trusted, true ) ) {
				return $chain[ $index ];
			}
		}
		return $remote;
	}

	/**
	 * Stable HMAC identifier; raw addresses are not persisted.
	 *
	 * @param string   $ip      Validated client address.
	 * @param int|null $version Optional key version.
	 */
	public static function rate_limit_identifier( string $ip, ?int $version = null ): string {
		$version = null === $version ? max( 1, (int) get_option( 'lel_contact_rate_key_version', 1 ) ) : max( 1, $version );
		$secret  = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'longevity-contact-fallback' );
		return 'v' . $version . '_' . hash_hmac( 'sha256', $ip, $secret . '|contact|' . $version );
	}

	/** Allowlisted user-facing error messages keyed by error code. */
	private static function feedback_messages(): array {
		return array(
			'security'         => __( 'Your session expired. Please reload the page and try again.', 'longevity-core' ),
			'rejected'         => __( 'Your submission could not be accepted.', 'longevity-core' ),
			'rate_limited'     => __( 'Too many submissions from this network. Please try again later.', 'longevity-core' ),
			'required'         => __( 'Please complete every required field.', 'longevity-core' ),
			'invalid_email'    => __( 'Please enter a valid email address.', 'longevity-core' ),
			'name_too_long'    => __( 'The name provided is too long.', 'longevity-core' ),
			'email_too_long'   => __( 'The email address provided is too long.', 'longevity-core' ),
			'message_too_long' => __( 'The message provided is too long.', 'longevity-core' ),
			'invalid_chars'    => __( 'The submission contains characters that are not allowed.', 'longevity-core' ),
			'save_failed'      => __( 'Your message could not be saved. Please try again later.', 'longevity-core' ),
		);
	}

	/** Render allowlisted success or error feedback from redirect query args. */
	public static function render_contact_feedback(): string {
		// Read-only display of allowlisted feedback; no state change, nonce not applicable.
		if ( isset( $_GET['submitted'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['submitted'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '<p class="longevity-contact-feedback longevity-contact-success" role="status">' . esc_html__( 'Thank you. Your message has been received.', 'longevity-core' ) . '</p>';
		}
		$error = isset( $_GET['contact_error'] ) ? sanitize_key( wp_unslash( $_GET['contact_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map   = self::feedback_messages();
		if ( '' !== $error && isset( $map[ $error ] ) ) {
			return '<p class="longevity-contact-feedback longevity-contact-error" role="alert">' . esc_html( $map[ $error ] ) . '</p>';
		}
		return '';
	}

	/** Render a contact form with abuse protection. */
	public static function render_contact_form(): string {
		$identifier = self::rate_limit_identifier( self::get_client_ip() );
		$feedback   = self::render_contact_feedback();

		$count = self::current_rate_count( 'lel_contact_count_' . $identifier );
		if ( null === $count ) {
			// Fail closed: without a working limiter, submissions are not accepted.
			return $feedback . '<aside class="longevity-contact-unavailable" role="alert"><p>' . esc_html__( 'The contact form is temporarily unavailable. Please try again later.', 'longevity-core' ) . '</p></aside>';
		}
		if ( $count > 5 ) {
			return $feedback . '<aside class="longevity-contact-blocked" role="alert"><p>' . esc_html__( 'Too many submissions from this network. Please try again later.', 'longevity-core' ) . '</p></aside>';
		}

		wp_enqueue_script( 'longevity-contact-form', LONGEVITY_CORE_URL . 'assets/contact-form.js', array(), LONGEVITY_CORE_VERSION, true );

		$nonce = wp_create_nonce( 'longevity_contact' );

		$html          = $feedback;
		$html         .= '<form class="longevity-contact-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html         .= '<input type="hidden" name="action" value="longevity_contact_submit">';
		$html         .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
		$html         .= '<input type="hidden" name="longevity_contact_request_id" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		$fixture_token = self::fixture_token_for_page();
		if ( '' !== $fixture_token ) {
			$html .= '<input type="hidden" name="longevity_contact_fixture" value="' . esc_attr( $fixture_token ) . '">';
		}
		$html .= '<div class="longevity-honeypot" aria-hidden="true" inert><label for="longevity-website">' . esc_html__( 'Website', 'longevity-core' ) . '</label><input type="text" name="longevity_website" id="longevity-website" tabindex="-1" autocomplete="off"></div>';

		$html .= '<p><label for="longevity-contact-name">' . esc_html__( 'Name', 'longevity-core' ) . ' <span class="required">*</span></label>';
		$html .= '<input type="text" name="longevity_contact_name" id="longevity-contact-name" required maxlength="100" autocomplete="name"></p>';

		$html .= '<p><label for="longevity-contact-email">' . esc_html__( 'Email', 'longevity-core' ) . ' <span class="required">*</span></label>';
		$html .= '<input type="email" name="longevity_contact_email" id="longevity-contact-email" required maxlength="254" autocomplete="email"></p>';

		$html .= '<p><label for="longevity-contact-subject">' . esc_html__( 'Subject', 'longevity-core' ) . ' <span class="required">*</span></label>';
		$html .= '<select name="longevity_contact_subject" id="longevity-contact-subject" required>';
		$html .= '<option value="">' . esc_html__( 'Select a subject', 'longevity-core' ) . '</option>';
		$html .= '<option value="general">' . esc_html__( 'General inquiry', 'longevity-core' ) . '</option>';
		$html .= '<option value="correction">' . esc_html__( 'Report a correction', 'longevity-core' ) . '</option>';
		$html .= '<option value="privacy">' . esc_html__( 'Privacy request', 'longevity-core' ) . '</option>';
		$html .= '<option value="commercial">' . esc_html__( 'Commercial inquiry', 'longevity-core' ) . '</option>';
		$html .= '<option value="other">' . esc_html__( 'Other', 'longevity-core' ) . '</option>';
		$html .= '</select></p>';

		$html .= '<p><label for="longevity-contact-message">' . esc_html__( 'Message', 'longevity-core' ) . ' <span class="required">*</span></label>';
		$html .= '<textarea name="longevity_contact_message" id="longevity-contact-message" required rows="8" maxlength="5000"></textarea></p>';

		$html .= '<p class="longevity-contact-warning" role="note"><strong>' . esc_html__( 'Do not submit diagnoses, medication lists, emergencies, or other sensitive health information.', 'longevity-core' ) . '</strong> ' . esc_html__( 'For an emergency, contact local emergency services.', 'longevity-core' ) . '</p>';

		$html .= '<p><button type="submit" class="wp-element-button">' . esc_html__( 'Send message', 'longevity-core' ) . '</button></p>';
		$html .= '<p class="longevity-small">' . esc_html__( 'This form is protected by rate limiting. A pseudonymous network identifier and submission time are retained for abuse prevention and will not be used for any other purpose.', 'longevity-core' ) . '</p>';
		$html .= '</form>';

		return $html;
	}


	/** Schedule daily deletion of expired private contact records. */
	public static function schedule_retention(): void {
		if ( ! Migrations::wordpress_ready() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::RETENTION_HOOK );
		}
	}

	/**
	 * Permanently remove contact records whose authoritative per-record deadline
	 * has elapsed, along with their PII-bearing outbox rows.
	 *
	 * Selection is driven solely by the canonical per-record deadline
	 * (`contact_retention_until_gmt`), never by the current global setting or the
	 * post date. A later change to the global setting therefore affects only new
	 * submissions; already-stored records keep the deadline captured at intake.
	 * Records lacking a usable canonical deadline are never selected or deleted.
	 */
	public static function run_retention_cleanup(): int {
		$now              = gmdate( 'Y-m-d H:i:s' );
		$runtime_cap      = 25; // Seconds.
		$start            = time();
		$total_deleted    = 0;
		$legal_hold       = 0;
		$malformed        = 0;
		$lock_unavailable = 0;
		$deletion_failure = 0;

		self::purge_expired_rate_rows();
		Contact_Idempotency::purge_terminal( self::IDEMPOTENCY_RETENTION_DAYS * DAY_IN_SECONDS );

		while ( true ) {
			$messages = get_posts(
				array(
					'post_type'      => 'longevity_message',
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => self::RETENTION_META_KEY,
							'value'   => $now,
							'compare' => '<=',
							'type'    => 'DATETIME',
						),
					),
					'no_found_rows'  => true,
				)
			);
			if ( empty( $messages ) ) {
				break;
			}
			$deleted_this_pass = 0;
			foreach ( $messages as $message_id ) {
				$message_id = (int) $message_id;
				// Fail-safe re-check on the request path: never delete a record
				// whose stored deadline is not a valid, due canonical datetime.
				if ( ! self::deadline_is_due( (string) get_post_meta( $message_id, self::RETENTION_META_KEY, true ), $now ) ) {
					++$malformed;
					continue;
				}
				$outcome = Advisory_Lock::with_lock(
					Legal_Hold::lock_name( $message_id ),
					0,
					static function () use ( $message_id ): string {
						if ( Legal_Hold::protects_from_retention( $message_id ) ) {
							return 'held';
						}
						return self::delete_contact_checked( $message_id, true, 'retention_cleanup_failed' ) ? 'deleted' : 'failed';
					}
				);
				if ( Advisory_Lock::ACQUIRED !== $outcome['status'] ) {
					++$lock_unavailable;
					Logger::warning(
						'contact_retention_lock_unavailable',
						array(
							'contact_id'  => $message_id,
							'lock_status' => $outcome['status'],
						)
					);
					continue;
				}
				if ( 'deleted' === $outcome['result'] ) {
					++$total_deleted;
					++$deleted_this_pass;
				} elseif ( 'held' === $outcome['result'] ) {
					++$legal_hold;
				} else {
					++$deletion_failure;
				}
			}
			if ( 0 === $deleted_this_pass ) {
				break;
			}
			// Stop if runtime cap exceeded; reschedule for remaining backlog.
			if ( ( time() - $start ) >= $runtime_cap ) {
				wp_schedule_single_event( time() + 60, self::RETENTION_HOOK );
				break;
			}
		}

		if ( $total_deleted > 0 || $legal_hold > 0 || $malformed > 0 || $lock_unavailable > 0 || $deletion_failure > 0 ) {
			Audit_Log::record(
				'contact_retention_cleanup',
				'system',
				0,
				array(
					'deleted_count'      => $total_deleted,
					'legal_hold_skipped' => $legal_hold,
					'malformed_deadline' => $malformed,
					'lock_unavailable'   => $lock_unavailable,
					'deletion_failure'   => $deletion_failure,
				),
				0,
				'cron'
			);
		}
		return $total_deleted;
	}

	/**
	 * One-time, additive, restart-safe backfill of the canonical per-record
	 * retention deadline from the legacy ISO-8601 value.
	 *
	 * Never deletes a record, never rewrites an existing canonical value, and
	 * never substitutes the current global setting for a stored deadline. Records
	 * with a malformed or missing legacy deadline are reported (NO PII) for a
	 * privacy-owner decision, not silently guessed. The report and cursor are
	 * persisted so an interrupted run resumes without double counting.
	 *
	 * @return array{normalized:int, already_canonical:int, malformed:int, missing:int, malformed_ids:list<int>, missing_ids:list<int>}
	 */
	public static function migrate_retention_deadlines(): array {
		$cursor = (int) get_option( self::RETENTION_BACKFILL_CURSOR_OPTION, 0 );
		$report = 0 === $cursor
			? self::empty_backfill_report()
			: self::sanitize_backfill_report( get_option( self::RETENTION_BACKFILL_REPORT_OPTION, array() ) );
		$seen   = array();

		while ( true ) {
			$ids = get_posts(
				array(
					'post_type'      => 'longevity_message',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => self::RETENTION_BACKFILL_BATCH,
					'offset'         => $cursor,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			// Deduplicate defensively so termination never depends on the data
			// store honoring the offset (guards resumable-batch correctness).
			$ids = array_values(
				array_filter(
					array_map( 'intval', (array) $ids ),
					static function ( int $id ) use ( &$seen ): bool {
						if ( $id <= 0 || isset( $seen[ $id ] ) ) {
							return false;
						}
						$seen[ $id ] = true;
						return true;
					}
				)
			);
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				self::backfill_one_deadline( $id, $report );
			}
			$cursor += self::RETENTION_BACKFILL_BATCH;
			update_option( self::RETENTION_BACKFILL_CURSOR_OPTION, $cursor, false );
			update_option( self::RETENTION_BACKFILL_REPORT_OPTION, $report, false );
		}
		update_option( self::RETENTION_BACKFILL_REPORT_OPTION, $report, false );
		return $report;
	}

	/**
	 * Backfill a single record's canonical deadline, updating the report counters.
	 *
	 * @param int   $id     Contact record post ID.
	 * @param array $report Backfill report counters, updated by reference.
	 */
	private static function backfill_one_deadline( int $id, array &$report ): void {
		if ( '' !== self::normalize_canonical_deadline( (string) get_post_meta( $id, self::RETENTION_META_KEY, true ) ) ) {
			++$report['already_canonical'];
			return;
		}
		$legacy = (string) get_post_meta( $id, self::LEGACY_RETENTION_META_KEY, true );
		if ( '' === trim( $legacy ) ) {
			++$report['missing'];
			self::append_bounded_id( $report['missing_ids'], $id );
			return;
		}
		$normalized = self::normalize_legacy_deadline( $legacy );
		if ( '' === $normalized ) {
			++$report['malformed'];
			self::append_bounded_id( $report['malformed_ids'], $id );
			return;
		}
		update_post_meta( $id, self::RETENTION_META_KEY, $normalized );
		if ( (string) get_post_meta( $id, self::RETENTION_META_KEY, true ) === $normalized ) {
			++$report['normalized'];
		} else {
			++$report['malformed'];
			self::append_bounded_id( $report['malformed_ids'], $id );
		}
	}

	/** Empty (zeroed) backfill report structure. */
	private static function empty_backfill_report(): array {
		return array(
			'normalized'        => 0,
			'already_canonical' => 0,
			'malformed'         => 0,
			'missing'           => 0,
			'malformed_ids'     => array(),
			'missing_ids'       => array(),
		);
	}

	/**
	 * Coerce a persisted report back into a well-formed structure for resume.
	 *
	 * @param mixed $stored Previously persisted report option value.
	 */
	private static function sanitize_backfill_report( $stored ): array {
		$report = self::empty_backfill_report();
		if ( ! is_array( $stored ) ) {
			return $report;
		}
		foreach ( array( 'normalized', 'already_canonical', 'malformed', 'missing' ) as $key ) {
			$report[ $key ] = max( 0, (int) ( $stored[ $key ] ?? 0 ) );
		}
		foreach ( array( 'malformed_ids', 'missing_ids' ) as $key ) {
			if ( isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
				$report[ $key ] = array_values( array_slice( array_map( 'intval', $stored[ $key ] ), 0, 100 ) );
			}
		}
		return $report;
	}

	/**
	 * Append an id to a bounded report list, avoiding duplicates and unbounded growth.
	 *
	 * @param array $ids Bounded report ID list, updated by reference.
	 * @param int   $id  Post ID to append.
	 */
	private static function append_bounded_id( array &$ids, int $id ): void {
		if ( count( $ids ) < 100 && ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
	}

	/**
	 * Whether a stored value is a valid canonical deadline at or before $now.
	 *
	 * @param string $value Stored deadline value.
	 * @param string $now   Current UTC datetime in `Y-m-d H:i:s` format.
	 */
	private static function deadline_is_due( string $value, string $now ): bool {
		$normalized = self::normalize_canonical_deadline( $value );
		return '' !== $normalized && strcmp( $normalized, $now ) <= 0;
	}

	/**
	 * Validate and canonicalize a `Y-m-d H:i:s` UTC datetime, or return an empty string.
	 *
	 * @param string $value Candidate datetime value.
	 */
	private static function normalize_canonical_deadline( string $value ): string {
		$value = trim( $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return '';
		}
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return '';
		}
		return $date->format( 'Y-m-d H:i:s' ) === $value ? $value : '';
	}

	/**
	 * Parse a legacy ISO-8601 deadline into a canonical UTC datetime, or return an empty string.
	 *
	 * @param string $value Legacy stored deadline value.
	 */
	private static function normalize_legacy_deadline( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$canonical = self::normalize_canonical_deadline( $value );
		if ( '' !== $canonical ) {
			return $canonical;
		}
		try {
			$date = new \DateTimeImmutable( $value );
		} catch ( \Exception $error ) {
			unset( $error );
			return '';
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Atomically increment a rate-limit counter.
	 *
	 * Uses the dedicated lel_rate_limits table (owned by migration 9; the
	 * request path issues no DDL) with INSERT ... ON DUPLICATE KEY UPDATE so
	 * limiting stays correct with no persistent object cache and under
	 * concurrent requests. Object cache is intentionally not consulted.
	 *
	 * @param string $key Key identifying the rate bucket.
	 * @return int|null The new count after increment, or null when the limiter
	 *                  is unavailable (missing table or failed write) — callers
	 *                  must fail closed.
	 */
	private static function atomic_rate_increment( string $key ): ?int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Atomic abuse limiting requires the dedicated prefix-derived custom table.
		$ttl   = HOUR_IN_SECONDS;
		$table = $wpdb->prefix . 'lel_rate_limits';

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );
		$result     = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (rate_key, hit_count, expires_at) VALUES (%s, 1, %s) ON DUPLICATE KEY UPDATE hit_count = IF(expires_at < UTC_TIMESTAMP(), 1, hit_count + 1), expires_at = IF(expires_at < UTC_TIMESTAMP(), VALUES(expires_at), expires_at)",
				$key,
				$expires_at
			)
		);
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		$count = self::current_rate_count( $key );
		if ( null === $count || $count < 1 ) {
			// The write claimed success but the counter is unreadable: unavailable.
			return null;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $count;
	}

	/**
	 * Read the current unexpired count for a rate bucket without incrementing.
	 *
	 * @param string $key Key identifying the rate bucket.
	 * @return int|null Count (0 when no bucket), or null when the limiter is
	 *                  unavailable — callers must fail closed.
	 */
	public static function current_rate_count( string $key ): ?int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Authoritative counters cannot use object-cache reads.
		$table = $wpdb->prefix . 'lel_rate_limits';
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT hit_count FROM {$table} WHERE rate_key = %s AND expires_at >= UTC_TIMESTAMP()", $key ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$count = (int) $value;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $count;
	}

	/** Whether the rate-limit table exists (readiness reporting; not the request path). */
	public static function rate_table_exists(): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Readiness must inspect the custom table directly.
		$table  = $wpdb->prefix . 'lel_rate_limits';
		$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $exists;
	}

	/** Delete expired rate-limit rows. */
	public static function purge_expired_rate_rows(): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scheduled cleanup owns this prefix-derived custom table.
		$table = $wpdb->prefix . 'lel_rate_limits';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return 0;
		}
		$deleted = (int) $wpdb->query( "DELETE FROM {$table} WHERE expires_at < UTC_TIMESTAMP()" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $deleted;
	}

	/** Create the rate-limit table (additive, idempotent). */
	public static function install_rate_table(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
			return;
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$table   = $wpdb->prefix . 'lel_rate_limits';
		$sql     = "CREATE TABLE {$table} (
			rate_key varchar(191) NOT NULL,
			hit_count int(10) unsigned NOT NULL DEFAULT 1,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (rate_key),
			KEY expires_at (expires_at)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Fail a submission safely without leaking internal details.
	 *
	 * Uses a 303 See Other redirect (the only valid way to answer a POST with
	 * a redirect to a GET view). The error key is allowlisted at render time.
	 *
	 * @param string $error_key Allowlisted feedback key.
	 */
	private static function fail_submission( string $error_key ): void {
		$redirect = self::feedback_url( 'contact_error=' . rawurlencode( $error_key ) );
		wp_safe_redirect( $redirect, 303 );
		exit;
	}

	/** Refuse a submission with 503 when the abuse limiter is unavailable (fail closed). */
	private static function fail_unavailable(): void {
		wp_die(
			esc_html__( 'The contact service is temporarily unavailable. Please try again later.', 'longevity-core' ),
			esc_html__( 'Service unavailable', 'longevity-core' ),
			array( 'response' => 503 )
		);
	}

	/** Handle contact form submission. */
	public static function handle_contact_submission(): void {
		$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'longevity_contact' ) ) {
			self::fail_submission( 'security' );
		}

		$honeypot = sanitize_text_field( wp_unslash( $_POST['longevity_website'] ?? '' ) );
		if ( '' !== $honeypot ) {
			self::fail_submission( 'rejected' );
		}
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : 0;
		if ( $content_length > self::MAX_REQUEST_BYTES ) {
			self::fail_submission( 'message_too_long' );
		}

		$identifier = self::rate_limit_identifier( self::get_client_ip() );
		$key        = 'lel_contact_count_' . $identifier;
		$count      = self::atomic_rate_increment( $key );
		if ( null === $count ) {
			self::fail_unavailable();
		}
		if ( $count > 5 ) {
			self::fail_submission( 'rate_limited' );
		}

		$raw_name    = (string) wp_unslash( $_POST['longevity_contact_name'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input is rejected or sanitized below.
		$raw_email   = (string) wp_unslash( $_POST['longevity_contact_email'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input is rejected or sanitized below.
		$raw_subject = (string) wp_unslash( $_POST['longevity_contact_subject'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input is rejected or sanitized below.
		$raw_message = (string) wp_unslash( $_POST['longevity_contact_message'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input is rejected or sanitized below.
		if ( preg_match( '/[\r\n]/', $raw_name ) || preg_match( '/[\r\n]/', $raw_email ) ) {
			self::fail_submission( 'invalid_chars' );
		}
		if ( mb_strlen( $raw_name ) > 100 ) {
			self::fail_submission( 'name_too_long' );
		}
		if ( mb_strlen( $raw_email ) > 254 ) {
			self::fail_submission( 'email_too_long' );
		}
		if ( mb_strlen( $raw_message ) > 5000 ) {
			self::fail_submission( 'message_too_long' );
		}

		$name       = sanitize_text_field( $raw_name );
		$email      = sanitize_email( $raw_email );
		$subject    = sanitize_text_field( $raw_subject );
		$message    = sanitize_textarea_field( $raw_message );
		$request_id = strtolower( sanitize_text_field( wp_unslash( $_POST['longevity_contact_request_id'] ?? '' ) ) );

		if ( '' === trim( $raw_name ) || '' === trim( $raw_email ) || '' === trim( $raw_subject ) || '' === trim( $raw_message ) ) {
			self::fail_submission( 'required' );
		}
		if ( '' === $name || '' === $subject || '' === $message || ! in_array( $subject, self::SUBJECTS, true ) ) {
			self::fail_submission( 'required' );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			self::fail_submission( 'invalid_email' );
		}
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request_id ) ) {
			self::fail_submission( 'security' );
		}

		$idempotency_key = Contact_Idempotency::key_hash( $request_id );
		$reservation     = Contact_Idempotency::reserve( $idempotency_key );
		$resume_post_id  = 0;
		switch ( $reservation['state'] ) {
			case 'unavailable':
				self::fail_unavailable();
				return;
			case 'completed':
			case 'in_progress':
				wp_safe_redirect( self::feedback_url( 'submitted=1' ), 303 );
				exit;
			case 'resume':
				$candidate = (int) $reservation['post_id'];
				$existing  = $candidate > 0 ? get_post( $candidate ) : null;
				if ( $existing && 'longevity_message' === $existing->post_type ) {
					$resume_post_id = $candidate;
				}
				break;
			case 'reserved':
			case 'reclaimed':
			default:
				break;
		}

		$retention_days = min( 365, max( 1, (int) get_option( 'lel_contact_retention_days', 90 ) ) );
		$retention_ts   = time() + ( $retention_days * DAY_IN_SECONDS );
		$meta_input     = array(
			'contact_subject'             => $subject,
			'contact_email'               => $email,
			'contact_name'                => $name,
			'contact_network_id'          => $identifier,
			'contact_rate_key_version'    => (string) max( 1, (int) get_option( 'lel_contact_rate_key_version', 1 ) ),
			'contact_submitted'           => gmdate( DATE_ATOM ),
			'contact_retention_until'     => gmdate( DATE_ATOM, $retention_ts ),
			'contact_retention_until_gmt' => gmdate( 'Y-m-d H:i:s', $retention_ts ),
			'contact_privacy_version'     => self::PRIVACY_NOTICE_VERSION,
			'contact_idempotency_key'     => $request_id,
		);
		$fixture_token  = sanitize_text_field( wp_unslash( $_POST['longevity_contact_fixture'] ?? '' ) );
		if ( '' !== $fixture_token && hash_equals( (string) get_option( 'lel_e2e_contact_fixture_token', '' ), $fixture_token ) ) {
			$meta_input['contact_fixture_token'] = $fixture_token;
		}
		if ( 'correction' === $subject ) {
			$meta_input['contact_type'] = 'correction_report';
		}

		if ( $resume_post_id > 0 ) {
			$post_id = $resume_post_id;
		} else {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'longevity_message',
					'post_status'  => 'private',
					'post_title'   => sprintf( '[%s] %s', $subject, $name ),
					'post_content' => $message,
					'meta_input'   => $meta_input,
				),
				true
			);

			if ( is_wp_error( $post_id ) || (int) $post_id < 1 ) {
				Contact_Idempotency::mark_failed( $idempotency_key );
				self::fail_submission( 'save_failed' );
			}
			$post_id = (int) $post_id;

			if ( ! Contact_Idempotency::link_post( $idempotency_key, $post_id ) ) {
				self::delete_contact_checked( $post_id, false, 'idempotency_link_failed' );
				Contact_Idempotency::mark_failed( $idempotency_key );
				self::fail_unavailable();
			}

			$post = get_post( $post_id );
			if ( ! $post || 'longevity_message' !== $post->post_type || 'private' !== $post->post_status || $message !== $post->post_content ) {
				self::delete_contact_checked( $post_id, false, 'aggregate_readback_failed' );
				Contact_Idempotency::mark_failed( $idempotency_key );
				self::fail_unavailable();
			}
			foreach ( $meta_input as $meta_key => $meta_value ) {
				if ( (string) get_post_meta( $post_id, $meta_key, true ) !== (string) $meta_value ) {
					self::delete_contact_checked( $post_id, false, 'metadata_readback_failed' );
					Contact_Idempotency::mark_failed( $idempotency_key );
					self::fail_unavailable();
				}
			}
		}

		// Mail is an asynchronous notification via the durable outbox; the
		// accepted record never depends on transport availability.
		$outbox_id = Notification_Outbox::enqueue(
			'contact_admin_notification',
			$post_id,
			(string) get_option( 'admin_email' ),
			array(
				'subject' => sprintf( '[Contact] %s from %s', $subject, $name ),
				'body'    => $message . "\n\nReply-to: {$email}",
				'headers' => array( "Reply-To: {$name} <{$email}>" ),
			),
			$request_id
		);
		if ( null === $outbox_id ) {
			self::delete_contact_checked( $post_id, true, 'outbox_enqueue_compensation_failed' );
			Contact_Idempotency::mark_failed( $idempotency_key );
			try {
				Audit_Log::record( 'contact_outbox_enqueue_failed', 'contact', $post_id, array( 'subject' => $subject ), 0, 'public_form' );
			} catch ( \RuntimeException $error ) {
				unset( $error );
				Logger::error( 'contact_outbox_enqueue_failed', array( 'contact_id' => $post_id ) );
			}
			self::fail_unavailable();
		}

		if ( ! Contact_Idempotency::complete( $idempotency_key, $post_id ) ) {
			self::mark_reconciliation_required( $post_id, 'idempotency_complete_failed' );
			self::fail_unavailable();
		}

		$redirect = self::feedback_url( 'submitted=1' );
		wp_safe_redirect( $redirect, 303 );
		exit;
	}

	/**
	 * Purge notification PII and delete a contact, recording incomplete compensation.
	 *
	 * @param int    $post_id      Contact record ID.
	 * @param bool   $purge_outbox Whether notification rows must be purged first.
	 * @param string $failure      Reconciliation reason prefix.
	 */
	private static function delete_contact_checked( int $post_id, bool $purge_outbox, string $failure ): bool {
		if ( $purge_outbox && ! Notification_Outbox::purge_for_object( $post_id ) ) {
			self::mark_reconciliation_required( $post_id, $failure . ':outbox_purge' );
			return false;
		}
		if ( ! wp_delete_post( $post_id, true ) ) {
			self::mark_reconciliation_required( $post_id, $failure . ':post_delete' );
			return false;
		}
		delete_option( self::RECONCILIATION_PREFIX . $post_id );
		return true;
	}

	/**
	 * Persist a non-PII operator reconciliation marker and emit an alert.
	 *
	 * @param int    $post_id Contact record ID.
	 * @param string $reason  Reconciliation reason.
	 */
	private static function mark_reconciliation_required( int $post_id, string $reason ): void {
		$state = array(
			'contact_id'  => $post_id,
			'reason'      => $reason,
			'recorded_at' => gmdate( DATE_ATOM ),
		);
		$key   = self::RECONCILIATION_PREFIX . $post_id;
		update_option( $key, $state, false );
		if ( get_option( $key, null ) !== $state ) {
			Logger::error( 'contact_reconciliation_state_write_failed', $state );
		}
		Logger::error( 'contact_reconciliation_required', $state );
	}

	/**
	 * Return feedback to the same same-origin form page when possible.
	 *
	 * @param string $query Allowlisted feedback query.
	 */
	private static function feedback_url( string $query ): string {
		$contact_url = Routes::public_page_url( 'contact' );
		$base        = $contact_url ? $contact_url : home_url( '/contact/' );
		if ( function_exists( 'wp_get_referer' ) && function_exists( 'wp_validate_redirect' ) ) {
			$referer = wp_get_referer();
			$valid   = $referer ? wp_validate_redirect( $referer, '' ) : '';
			if ( $valid && wp_parse_url( $valid, PHP_URL_HOST ) === wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) {
				$base = (string) strtok( $valid, '?' );
			}
		}
		return $base . '?' . $query;
	}

	/** Fixture token exists only on the guarded E2E-owned page. */
	private static function fixture_token_for_page(): string {
		if ( ! function_exists( 'get_queried_object_id' ) ) {
			return '';
		}
		$token = (string) get_post_meta( (int) get_queried_object_id(), '_lel_e2e_contact_fixture_token', true );
		return '' !== $token && hash_equals( (string) get_option( 'lel_e2e_contact_fixture_token', '' ), $token ) ? $token : '';
	}
}

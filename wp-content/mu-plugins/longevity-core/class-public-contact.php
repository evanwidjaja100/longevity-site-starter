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
	private const RETENTION_HOOK = 'lel_contact_retention_cleanup';
	private const SUBJECTS = array( 'general', 'correction', 'privacy', 'commercial', 'other' );

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
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) : '';
		$chain     = array_values( array_filter( array_map( 'trim', explode( ',', $forwarded ) ), static fn( $ip ) => (bool) filter_var( $ip, FILTER_VALIDATE_IP ) ) );
		$chain[]   = $remote;
		for ( $index = count( $chain ) - 1; $index >= 0; --$index ) {
			if ( ! in_array( $chain[ $index ], $trusted, true ) ) {
				return $chain[ $index ];
			}
		}
		return $remote;
	}

	/** Stable HMAC identifier; raw addresses are not persisted. */
	public static function rate_limit_identifier( string $ip, ?int $version = null ): string {
		$version = $version ?: max( 1, (int) get_option( 'lel_contact_rate_key_version', 1 ) );
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
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

		if ( self::current_rate_count( 'lel_contact_count_' . $identifier ) > 5 ) {
			return $feedback . '<aside class="longevity-contact-blocked" role="alert"><p>' . esc_html__( 'Too many submissions from this network. Please try again later.', 'longevity-core' ) . '</p></aside>';
		}

		wp_enqueue_script( 'longevity-contact-form', LONGEVITY_CORE_URL . 'assets/contact-form.js', array(), LONGEVITY_CORE_VERSION, true );

		$nonce = wp_create_nonce( 'longevity_contact' );

		$html = $feedback;
		$html .= '<form class="longevity-contact-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="longevity_contact_submit">';
		$html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
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

	/** Permanently remove contact records after the configured retention period. Legal-held messages are never age-deleted. Processes multiple batches within a runtime cap. */
	public static function run_retention_cleanup(): int {
		$retention_days = min( 365, max( 1, (int) get_option( 'lel_contact_retention_days', 90 ) ) );
		$cutoff         = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$runtime_cap    = 25; // seconds
		$start          = time();
		$total_deleted  = 0;

		self::purge_expired_rate_rows();

		while ( true ) {
			$messages = get_posts(
				array(
					'post_type'      => 'longevity_message',
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'date_query'     => array( array( 'before' => $cutoff, 'inclusive' => true, 'column' => 'post_date_gmt' ) ),
					'meta_query'     => array( array( 'key' => 'contact_legal_hold', 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'no_found_rows'  => true,
				)
			);
			if ( empty( $messages ) ) {
				break;
			}
			foreach ( $messages as $message_id ) {
				if ( wp_delete_post( (int) $message_id, true ) ) {
					++$total_deleted;
				}
			}
			// Stop if runtime cap exceeded; reschedule for remaining backlog.
			if ( ( time() - $start ) >= $runtime_cap ) {
				wp_schedule_single_event( time() + 60, self::RETENTION_HOOK );
				break;
			}
		}

		if ( $total_deleted > 0 ) {
			Audit_Log::record( 'contact_retention_cleanup', 'system', 0, array( 'deleted_count' => $total_deleted, 'retention_days' => $retention_days ), 0, 'cron' );
		}
		return $total_deleted;
	}

	/**
	 * Atomically increment a rate-limit counter.
	 *
	 * Uses the dedicated lel_rate_limits table with INSERT ... ON DUPLICATE KEY
	 * UPDATE so limiting stays correct with no persistent object cache and
	 * under concurrent requests. Object cache is intentionally not consulted.
	 *
	 * @param string $key Key identifying the rate bucket.
	 * @return int The new count after increment.
	 */
	private static function atomic_rate_increment( string $key ): int {
		global $wpdb;
		$ttl   = HOUR_IN_SECONDS;
		$table = $wpdb->prefix . 'lel_rate_limits';

		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			self::install_rate_table();
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (rate_key, hit_count, expires_at) VALUES (%s, 1, %s) ON DUPLICATE KEY UPDATE hit_count = IF(expires_at < UTC_TIMESTAMP(), 1, hit_count + 1), expires_at = IF(expires_at < UTC_TIMESTAMP(), VALUES(expires_at), expires_at)",
				$key,
				$expires_at
			)
		);

		return max( 1, self::current_rate_count( $key ) );
	}

	/** Read the current unexpired count for a rate bucket without incrementing. */
	public static function current_rate_count( string $key ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'lel_rate_limits';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT hit_count FROM {$table} WHERE rate_key = %s AND expires_at >= UTC_TIMESTAMP()", $key ) );
	}

	/** Delete expired rate-limit rows. */
	public static function purge_expired_rate_rows(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'lel_rate_limits';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return 0;
		}
		return (int) $wpdb->query( "DELETE FROM {$table} WHERE expires_at < UTC_TIMESTAMP()" );
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
	 */
	private static function fail_submission( string $error_key ): void {
		$redirect = home_url( '/contact/?error=' . rawurlencode( $error_key ) );
		wp_safe_redirect( $redirect, 303 );
		exit;
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

		$identifier = self::rate_limit_identifier( self::get_client_ip() );
		$key        = 'lel_contact_count_' . $identifier;
		$count      = self::atomic_rate_increment( $key );
		if ( $count > 5 ) {
			self::fail_submission( 'rate_limited' );
		}

		$name    = sanitize_text_field( wp_unslash( $_POST['longevity_contact_name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['longevity_contact_email'] ?? '' ) );
		$subject = sanitize_text_field( wp_unslash( $_POST['longevity_contact_subject'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['longevity_contact_message'] ?? '' ) );

		if ( '' === $name || '' === $email || '' === $subject || '' === $message || ! in_array( $subject, self::SUBJECTS, true ) ) {
			self::fail_submission( 'required' );
		}
		if ( ! is_email( $email ) ) {
			self::fail_submission( 'invalid_email' );
		}
		// Server-side length enforcement matching form constraints.
		if ( mb_strlen( $name ) > 100 ) {
			self::fail_submission( 'name_too_long' );
		}
		if ( mb_strlen( $email ) > 254 ) {
			self::fail_submission( 'email_too_long' );
		}
		if ( mb_strlen( $message ) > 5000 ) {
			self::fail_submission( 'message_too_long' );
		}
		// Prevent header injection: reject CR/LF in name and email.
		if ( preg_match( '/[\r\n]/', $name ) || preg_match( '/[\r\n]/', $email ) ) {
			self::fail_submission( 'invalid_chars' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'longevity_message',
				'post_status'  => 'private',
				'post_title'   => sprintf( '[%s] %s', $subject, $name ),
				'post_content' => $message,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			self::fail_submission( 'save_failed' );
		}

		update_post_meta( $post_id, 'contact_subject', $subject );
		update_post_meta( $post_id, 'contact_email', $email );
		update_post_meta( $post_id, 'contact_name', $name );
		update_post_meta( $post_id, 'contact_network_id', $identifier );
		update_post_meta( $post_id, 'contact_rate_key_version', max( 1, (int) get_option( 'lel_contact_rate_key_version', 1 ) ) );
		update_post_meta( $post_id, 'contact_submitted', gmdate( DATE_ATOM ) );

		if ( 'correction' === $subject ) {
			update_post_meta( $post_id, 'contact_type', 'correction_report' );
		}

		if ( defined( 'SMTP_HOST' ) || has_action( 'phpmailer_init' ) ) {
			$sent = wp_mail(
				get_option( 'admin_email' ),
				sprintf( '[Contact] %s from %s', $subject, $name ),
				$message . "\n\nReply-to: {$email}",
				array( "Reply-To: {$name} <{$email}>" )
			);
			if ( ! $sent ) {
				Audit_Log::record( 'contact_mail_failed', 'contact', (int) $post_id, array( 'subject' => $subject ), 0, 'public_form' );
			}
		}

		$redirect = home_url( '/contact/?submitted=1' );
		wp_safe_redirect( $redirect, 303 );
		exit;
	}
}

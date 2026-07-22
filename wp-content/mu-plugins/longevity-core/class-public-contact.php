<?php
/**
 * Public contact components extracted from Public_Components.
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
		add_action( self::RETENTION_HOOK, array( self::class, 'run_retention_cleanup' ) );
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

	/** Render a contact form with abuse protection. */
	public static function render_contact_form(): string {
		$identifier = self::rate_limit_identifier( self::get_client_ip() );
		$blocked    = get_transient( 'lel_contact_block_' . $identifier );

		if ( $blocked ) {
			return '<aside class="longevity-contact-blocked" role="alert"><p>' . esc_html__( 'Too many submissions from this IP address. Please try again later.', 'longevity-core' ) . '</p></aside>';
		}

		wp_enqueue_script( 'longevity-contact-form', LONGEVITY_CORE_URL . 'assets/contact-form.js', array(), LONGEVITY_CORE_VERSION, true );

		$nonce = wp_create_nonce( 'longevity_contact' );

		$html = '<form class="longevity-contact-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="longevity_contact_submit">';
		$html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
		$html .= '<div class="longevity-honeypot" aria-hidden="true"><label for="longevity-website">' . esc_html__( 'Website', 'longevity-core' ) . '</label><input type="text" name="longevity_website" id="longevity-website" tabindex="-1" autocomplete="off"></div>';

		$html .= '<p><label for="longevity-contact-name">' . esc_html__( 'Name', 'longevity-core' ) . ' <span class="required">*</span></label>';
		$html .= '<input type="text" name="longevity_contact_name" id="longevity-contact-name" required maxlength="100"></p>';

		$html .= '<p><label for="longevity-contact-email">' . esc_html__( 'Email', 'longevity-core' ) . ' <span class="required">*</span></label>';
		$html .= '<input type="email" name="longevity_contact_email" id="longevity-contact-email" required maxlength="254"></p>';

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
		if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::RETENTION_HOOK );
		}
	}

	/** Permanently remove contact records after the configured retention period. */
	public static function run_retention_cleanup(): int {
		$retention_days = min( 365, max( 1, (int) get_option( 'lel_contact_retention_days', 90 ) ) );
		$cutoff         = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$messages       = get_posts(
			array(
				'post_type'      => 'longevity_message',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'date_query'     => array( array( 'before' => $cutoff, 'inclusive' => true, 'column' => 'post_date_gmt' ) ),
			)
		);
		$deleted = 0;
		foreach ( $messages as $message_id ) {
			if ( wp_delete_post( (int) $message_id, true ) ) {
				++$deleted;
			}
		}
		if ( $deleted > 0 ) {
			Audit_Log::record( 'contact_retention_cleanup', 'system', 0, array( 'deleted_count' => $deleted, 'retention_days' => $retention_days ), 0, 'cron' );
		}
		return $deleted;
	}

	/** Handle contact form submission. */
	public static function handle_contact_submission(): void {
		$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'longevity_contact' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'longevity-core' ), 403 );
		}

		$honeypot = sanitize_text_field( wp_unslash( $_POST['longevity_website'] ?? '' ) );
		if ( '' !== $honeypot ) {
			wp_die( esc_html__( 'Submission rejected.', 'longevity-core' ), 400 );
		}

		$identifier = self::rate_limit_identifier( self::get_client_ip() );
		$key        = 'lel_contact_count_' . $identifier;
		$count = (int) get_transient( $key );
		if ( $count >= 5 ) {
			set_transient( 'lel_contact_block_' . $identifier, '1', HOUR_IN_SECONDS );
			wp_die( esc_html__( 'Too many submissions. Please try again later.', 'longevity-core' ), 429 );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$name    = sanitize_text_field( wp_unslash( $_POST['longevity_contact_name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['longevity_contact_email'] ?? '' ) );
		$subject = sanitize_text_field( wp_unslash( $_POST['longevity_contact_subject'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['longevity_contact_message'] ?? '' ) );

		if ( '' === $name || '' === $email || '' === $subject || '' === $message || ! in_array( $subject, self::SUBJECTS, true ) ) {
			wp_die( esc_html__( 'All required fields must be completed.', 'longevity-core' ), 400 );
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
			wp_die( esc_html__( 'Could not save your message. Please try again later.', 'longevity-core' ), 500 );
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
		wp_safe_redirect( $redirect );
		exit;
	}
}

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
	/**
	 * Resolve the client IP address.
	 *
	 * Respects X-Forwarded-For only when REMOTE_ADDR matches a trusted proxy
	 * defined via the LONGEVITY_TRUSTED_PROXIES constant. Without trusted-proxy
	 * configuration, returns REMOTE_ADDR directly.
	 */
	private static function get_client_ip(): string {
		$trusted_proxies = defined( 'LONGEVITY_TRUSTED_PROXIES' ) && is_array( LONGEVITY_TRUSTED_PROXIES ) ? LONGEVITY_TRUSTED_PROXIES : array();
		$remote_addr     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		if ( $trusted_proxies && in_array( $remote_addr, $trusted_proxies, true ) ) {
			$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
			if ( '' !== $forwarded ) {
				$ips = explode( ',', $forwarded );
				return trim( (string) end( $ips ) );
			}
		}
		return $remote_addr;
	}

	/** Render a contact form with abuse protection. */
	public static function render_contact_form(): string {
		$ip      = self::get_client_ip();
		$blocked = get_transient( 'lel_contact_block_' . $ip );

		if ( $blocked ) {
			return '<aside class="longevity-contact-blocked" role="alert"><p>' . esc_html__( 'Too many submissions from this IP address. Please try again later.', 'longevity-core' ) . '</p></aside>';
		}

		wp_enqueue_script( 'longevity-contact-form', LONGEVITY_CORE_URL . 'assets/contact-form.js', array(), LONGEVITY_CORE_VERSION, true );

		$nonce = wp_create_nonce( 'longevity_contact' );

		$html = '<form class="longevity-contact-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="longevity_contact_submit">';
		$html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
		$html .= '<div style="position:absolute;left:-9999px" aria-hidden="true"><label for="longevity-website">' . esc_html__( 'Website', 'longevity-core' ) . '</label><input type="text" name="longevity_website" id="longevity-website" tabindex="-1" autocomplete="off"></div>';

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

		$html .= '<p><button type="submit" class="wp-element-button">' . esc_html__( 'Send message', 'longevity-core' ) . '</button></p>';
		$html .= '<p class="longevity-small">' . esc_html__( 'This form is protected by rate limiting. Your IP address and submission time are recorded for abuse prevention and will not be used for any other purpose.', 'longevity-core' ) . '</p>';
		$html .= '</form>';

		return $html;
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

		$ip  = self::get_client_ip();
		$key = 'lel_contact_count_' . $ip;
		$count = (int) get_transient( $key );
		if ( $count >= 5 ) {
			set_transient( 'lel_contact_block_' . $ip, '1', HOUR_IN_SECONDS );
			wp_die( esc_html__( 'Too many submissions. Please try again later.', 'longevity-core' ), 429 );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$name    = sanitize_text_field( wp_unslash( $_POST['longevity_contact_name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['longevity_contact_email'] ?? '' ) );
		$subject = sanitize_text_field( wp_unslash( $_POST['longevity_contact_subject'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['longevity_contact_message'] ?? '' ) );

		if ( '' === $name || '' === $email || '' === $subject || '' === $message ) {
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
		update_post_meta( $post_id, 'contact_ip', $ip );
		update_post_meta( $post_id, 'contact_submitted', gmdate( DATE_ATOM ) );

		if ( 'correction' === $subject ) {
			update_post_meta( $post_id, 'contact_type', 'correction_report' );
		}

		if ( defined( 'SMTP_HOST' ) || has_action( 'phpmailer_init' ) ) {
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( '[Contact] %s from %s', $subject, $name ),
				$message . "\n\nReply-to: {$email}",
				array( "Reply-To: {$name} <{$email}>" )
			);
		}

		$redirect = home_url( '/contact/?submitted=1' );
		wp_safe_redirect( $redirect );
		exit;
	}
}

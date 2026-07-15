<?php
/**
 * Focused governance-editor assets.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Loads progressive enhancement only on supported editorial screens. */
final class Admin_Assets {
	/** Register the admin enqueue hook. */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/** Enqueue small dependency-free assets on post and review editors only. */
	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'post', 'review', 'lel_test_record' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'longevity-admin-governance', LONGEVITY_CORE_URL . 'assets/admin-governance.css', array(), LONGEVITY_CORE_VERSION );
		wp_enqueue_script( 'longevity-admin-governance', LONGEVITY_CORE_URL . 'assets/admin-governance.js', array(), LONGEVITY_CORE_VERSION, true );
	}
}

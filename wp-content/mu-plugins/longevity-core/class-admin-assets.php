<?php
/**
 * Focused governance-editor assets.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Enqueue governance editor assets on governed post-type edit screens. */
function longevity_admin_assets_init(): void {
	add_action(
		'admin_enqueue_scripts',
		function ( string $hook ): void {
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
	);
}

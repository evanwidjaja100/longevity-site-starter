<?php
/**
 * Guarded, reversible contact E2E fixture.
 *
 * Usage: wp eval-file /scripts/e2e-contact-fixture.php <action>
 *
 * @package LongevityCore
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- All identifiers are derived from the trusted WordPress prefix; ID lists contain only integers.

$enabled       = '1' === getenv( 'E2E_CONTACT_ENABLED' ) || ( defined( 'LONGEVITY_E2E_CONTACT' ) && LONGEVITY_E2E_CONTACT );
$expected_db   = (string) getenv( 'E2E_CONTACT_DATABASE' );
$database      = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
$site_host     = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
$database_host = defined( 'DB_HOST' ) ? strtolower( explode( ':', (string) DB_HOST )[0] ) : '';
$safe_site     = in_array( $site_host, array( 'localhost', '127.0.0.1', 'wordpress' ), true );
$safe_database = '' !== $expected_db && hash_equals( $database, $expected_db ) && in_array( $database_host, array( 'db', 'localhost', '127.0.0.1' ), true );
$safe_env      = in_array( wp_get_environment_type(), array( 'local', 'development', 'test' ), true );

if ( ! $enabled || ! $safe_env || ! $safe_site || ! $safe_database ) {
	\WP_CLI::error( 'Contact E2E refused: explicit flag, test environment, local site host, and exact local database confirmation are required.' );
}

$fixture_action = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';
global $wpdb;
$rate_table      = $wpdb->prefix . 'lel_rate_limits';
$rate_disabled   = $rate_table . '_e2e_disabled';
$outbox_table    = \Longevity\Core\Notification_Outbox::table_name();
$outbox_disabled = $outbox_table . '_e2e_disabled';
$snapshot_option = 'lel_e2e_contact_rate_snapshot';
$token_option    = 'lel_e2e_contact_fixture_token';
$page_marker     = '_lel_e2e_contact_fixture_token';
$message_marker  = 'contact_fixture_token';

$table_exists = static function ( string $table ) use ( $wpdb ): bool {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
};

$restore_table = static function ( string $live, string $disabled ) use ( $wpdb, $table_exists ): void {
	if ( ! $table_exists( $live ) && $table_exists( $disabled ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "RENAME TABLE {$disabled} TO {$live}" );
	}
};

$fixture_message_ids = static function () use ( $message_marker ): array {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => 'longevity_message',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => $message_marker, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'no_found_rows'  => true,
			)
		)
	);
};

$cleanup = static function () use ( $wpdb, $restore_table, $rate_table, $rate_disabled, $outbox_table, $outbox_disabled, $fixture_message_ids, $snapshot_option, $token_option, $page_marker ): void {
	$restore_table( $rate_table, $rate_disabled );
	$restore_table( $outbox_table, $outbox_disabled );
	foreach ( $fixture_message_ids() as $id ) {
		\Longevity\Core\Notification_Outbox::purge_for_object( $id );
		wp_delete_post( $id, true );
	}
	$pages = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => $page_marker, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'no_found_rows'  => true,
		)
	);
	foreach ( $pages as $page_id ) {
		wp_delete_post( (int) $page_id, true );
	}
	$snapshot = get_option( $snapshot_option, null );
	if ( is_array( $snapshot ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$rate_table} WHERE rate_key LIKE 'lel_contact_count_%'" );
		foreach ( $snapshot as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$rate_table,
				array(
					'rate_key'   => (string) $row['rate_key'],
					'hit_count'  => (int) $row['hit_count'],
					'expires_at' => (string) $row['expires_at'],
				)
			);
		}
	}
	delete_option( $snapshot_option );
	delete_option( $token_option );
	flush_rewrite_rules( false );
};

switch ( $fixture_action ) {
	case 'setup':
		$cleanup();
		if ( ! $table_exists( $rate_table ) || ! $table_exists( $outbox_table ) ) {
			\WP_CLI::error( 'Contact E2E requires migrated rate-limit and outbox tables.' );
		}
		// Preserve non-fixture limiter state and restore it byte-for-byte at cleanup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$snapshot = $wpdb->get_results( "SELECT rate_key, hit_count, expires_at FROM {$rate_table} WHERE rate_key LIKE 'lel_contact_count_%'", ARRAY_A );
		if ( ! update_option( $snapshot_option, $snapshot, false ) ) {
			\WP_CLI::error( 'Could not preserve pre-fixture limiter state.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$rate_table} WHERE rate_key LIKE 'lel_contact_count_%'" );

		$token   = wp_generate_uuid4();
		$content = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Contact test fixture</h1><!-- /wp:heading --><!-- wp:shortcode -->[longevity_contact_form]<!-- /wp:shortcode -->';
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'lel-e2e-contact-fixture',
				'post_title'   => 'Contact test fixture',
				'post_content' => $content,
				'meta_input'   => array( $page_marker => $token ),
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			$cleanup();
			\WP_CLI::error( 'Failed to create fixture-owned contact page.' );
		}
		if ( ! update_option( $token_option, $token, false ) ) {
			$cleanup();
			\WP_CLI::error( 'Failed to activate the fixture token.' );
		}
		flush_rewrite_rules( false );
		\WP_CLI::line(
			wp_json_encode(
				array(
					'ready'   => true,
					'page_id' => (int) $page_id,
					'path'    => '/lel-e2e-contact-fixture/',
				)
			)
		);
		break;

	case 'report':
		$ids      = $fixture_message_ids();
		$outbox   = 0;
		$statuses = array();
		if ( $ids && $table_exists( $outbox_table ) ) {
			$id_list = implode( ',', array_map( 'intval', $ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$outbox_table} WHERE object_id IN ({$id_list}) GROUP BY status", ARRAY_A );
			foreach ( $rows as $row ) {
				$statuses[ (string) $row['status'] ] = (int) $row['total'];
				$outbox                             += (int) $row['total'];
			}
		}
		\WP_CLI::line(
			wp_json_encode(
				array(
					'messages' => count( $ids ),
					'outbox'   => $outbox,
					'states'   => $statuses,
				)
			)
		);
		break;

	case 'disable-limiter':
		if ( ! $table_exists( $rate_table ) || $table_exists( $rate_disabled ) ) {
			\WP_CLI::error( 'Rate-limit table is not in the expected fixture state.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "RENAME TABLE {$rate_table} TO {$rate_disabled}" );
		\WP_CLI::success( 'contact-limiter-disabled' );
		break;

	case 'enable-limiter':
		$restore_table( $rate_table, $rate_disabled );
		\WP_CLI::success( 'contact-limiter-enabled' );
		break;

	case 'disable-outbox':
		if ( ! $table_exists( $outbox_table ) || $table_exists( $outbox_disabled ) ) {
			\WP_CLI::error( 'Outbox table is not in the expected fixture state.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "RENAME TABLE {$outbox_table} TO {$outbox_disabled}" );
		\WP_CLI::success( 'contact-outbox-disabled' );
		break;

	case 'enable-outbox':
		$restore_table( $outbox_table, $outbox_disabled );
		\WP_CLI::success( 'contact-outbox-enabled' );
		break;

	case 'cleanup':
		$cleanup();
		\WP_CLI::success( 'contact-fixture-clean' );
		break;

	default:
		\WP_CLI::error( 'Unknown action. Use setup, report, disable-limiter, enable-limiter, disable-outbox, enable-outbox, or cleanup.' );
}
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

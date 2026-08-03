<?php

use Longevity\Core\Audit_Log;
use Longevity\Core\Legal_Hold;
use Longevity\Core\Public_Contact;
use PHPUnit\Framework\TestCase;

/** Per-record contact retention deadlines are authoritative and never overridden by the global setting. */
final class ContactRetentionTest extends TestCase {
	protected function setUp(): void {
		Audit_Log::set_test_mode( true );
		$GLOBALS['lel_test_posts']         = array();
		$GLOBALS['lel_test_meta']          = array();
		$GLOBALS['lel_test_deleted_posts'] = array();
		$GLOBALS['lel_test_user_caps']     = array();
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_rate_limits', array() );
		$GLOBALS['wpdb']->lel_test_set_rows( 'wp_lel_notification_outbox', array() );
		unset(
			$GLOBALS['lel_test_get_lock_result'],
			$GLOBALS['lel_test_options']['lel_contact_retention_days'],
			$GLOBALS['lel_test_options']['lel_contact_retention_backfill_report'],
			$GLOBALS['lel_test_options']['lel_contact_retention_backfill_cursor']
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['lel_test_get_lock_result'],
			$GLOBALS['lel_test_options']['lel_contact_retention_days'],
			$GLOBALS['lel_test_options']['lel_contact_retention_backfill_report'],
			$GLOBALS['lel_test_options']['lel_contact_retention_backfill_cursor']
		);
	}

	private function seed_message( int $id, string $canonical_deadline ): void {
		$post                = new WP_Post();
		$post->ID            = $id;
		$post->post_type     = 'longevity_message';
		$post->post_status   = 'private';
		$post->post_date_gmt = '2020-01-01 00:00:00';
		$GLOBALS['lel_test_posts'][ $id ] = $post;
		if ( '' !== $canonical_deadline ) {
			$GLOBALS['lel_test_meta'][ $id ]['contact_retention_until_gmt'] = $canonical_deadline;
		}
	}

	private function past(): string {
		return gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
	}

	private function future(): string {
		return gmdate( 'Y-m-d H:i:s', time() + ( 100 * DAY_IN_SECONDS ) );
	}

	public function test_record_with_future_deadline_survives_global_decrease(): void {
		$this->seed_message( 100, $this->future() );
		$GLOBALS['lel_test_options']['lel_contact_retention_days'] = 30;

		Public_Contact::run_retention_cleanup();

		self::assertArrayHasKey( 100, $GLOBALS['lel_test_posts'], 'A record with a future per-record deadline must not be deleted after the global setting drops.' );
	}

	public function test_record_with_past_deadline_is_deleted_despite_global_increase(): void {
		$this->seed_message( 101, $this->past() );
		$GLOBALS['lel_test_options']['lel_contact_retention_days'] = 365;

		Public_Contact::run_retention_cleanup();

		self::assertArrayNotHasKey( 101, $GLOBALS['lel_test_posts'], 'A due per-record deadline must be honored even after the global setting rises.' );
	}

	public function test_exact_deadline_boundary_is_due(): void {
		$this->seed_message( 102, gmdate( 'Y-m-d H:i:s' ) );

		Public_Contact::run_retention_cleanup();

		self::assertArrayNotHasKey( 102, $GLOBALS['lel_test_posts'], 'A deadline equal to now is due.' );
	}

	public function test_missing_canonical_deadline_is_not_deleted(): void {
		$this->seed_message( 103, '' );

		Public_Contact::run_retention_cleanup();

		self::assertArrayHasKey( 103, $GLOBALS['lel_test_posts'], 'A record without a usable per-record deadline must not be silently deleted.' );
	}

	public function test_malformed_canonical_deadline_is_not_deleted(): void {
		$this->seed_message( 104, 'not-a-timestamp' );

		Public_Contact::run_retention_cleanup();

		self::assertArrayHasKey( 104, $GLOBALS['lel_test_posts'], 'A record with a malformed per-record deadline must not be silently deleted.' );
	}

	public function test_migration_normalizes_legacy_iso_to_canonical(): void {
		$post              = new WP_Post();
		$post->ID          = 200;
		$post->post_type   = 'longevity_message';
		$post->post_status = 'private';
		$GLOBALS['lel_test_posts'][200] = $post;
		$GLOBALS['lel_test_meta'][200]['contact_retention_until'] = '2026-10-27T08:09:10+00:00';

		$report = Public_Contact::migrate_retention_deadlines();

		self::assertSame( '2026-10-27 08:09:10', get_post_meta( 200, 'contact_retention_until_gmt', true ) );
		self::assertSame( 1, $report['normalized'] );
	}

	public function test_migration_preserves_existing_canonical(): void {
		$post              = new WP_Post();
		$post->ID          = 201;
		$post->post_type   = 'longevity_message';
		$post->post_status = 'private';
		$GLOBALS['lel_test_posts'][201] = $post;
		$GLOBALS['lel_test_meta'][201]['contact_retention_until']     = '2026-10-27T08:09:10+00:00';
		$GLOBALS['lel_test_meta'][201]['contact_retention_until_gmt'] = '2030-01-01 00:00:00';

		$report = Public_Contact::migrate_retention_deadlines();

		self::assertSame( '2030-01-01 00:00:00', get_post_meta( 201, 'contact_retention_until_gmt', true ), 'An explicit canonical deadline must never be rewritten.' );
		self::assertSame( 1, $report['already_canonical'] );
	}

	public function test_migration_reports_malformed_and_missing_without_writing(): void {
		$malformed              = new WP_Post();
		$malformed->ID          = 202;
		$malformed->post_type   = 'longevity_message';
		$malformed->post_status = 'private';
		$GLOBALS['lel_test_posts'][202] = $malformed;
		$GLOBALS['lel_test_meta'][202]['contact_retention_until'] = 'garbage-date';

		$missing              = new WP_Post();
		$missing->ID          = 203;
		$missing->post_type   = 'longevity_message';
		$missing->post_status = 'private';
		$GLOBALS['lel_test_posts'][203] = $missing;

		$report = Public_Contact::migrate_retention_deadlines();

		self::assertSame( '', get_post_meta( 202, 'contact_retention_until_gmt', true ) );
		self::assertSame( '', get_post_meta( 203, 'contact_retention_until_gmt', true ) );
		self::assertSame( 1, $report['malformed'] );
		self::assertSame( 1, $report['missing'] );
		self::assertContains( 202, $report['malformed_ids'] );
		self::assertContains( 203, $report['missing_ids'] );
		self::assertNotContains( 202, $GLOBALS['lel_test_deleted_posts'], 'Migration must never delete records.' );
		self::assertNotContains( 203, $GLOBALS['lel_test_deleted_posts'], 'Migration must never delete records.' );
	}

	public function test_migration_never_substitutes_global_setting(): void {
		$post              = new WP_Post();
		$post->ID          = 204;
		$post->post_type   = 'longevity_message';
		$post->post_status = 'private';
		$GLOBALS['lel_test_posts'][204] = $post;
		$GLOBALS['lel_test_meta'][204]['contact_retention_until'] = '2021-02-03T04:05:06+00:00';
		$GLOBALS['lel_test_options']['lel_contact_retention_days'] = 3650;

		Public_Contact::migrate_retention_deadlines();

		self::assertSame( '2021-02-03 04:05:06', get_post_meta( 204, 'contact_retention_until_gmt', true ), 'The stored explicit deadline must win over the current global setting.' );
	}

	public function test_cleanup_skips_records_under_legal_hold(): void {
		$this->seed_message( 300, $this->past() );
		$this->seed_message( 301, $this->past() );
		$GLOBALS['lel_test_user_caps'][42] = array( Legal_Hold::CAPABILITY );
		self::assertTrue( Legal_Hold::place( 301, 42, 'reason', 'CASE-301' ) );

		$deleted = Public_Contact::run_retention_cleanup();

		self::assertSame( 1, $deleted );
		self::assertArrayNotHasKey( 300, $GLOBALS['lel_test_posts'], 'A due, unheld record must be deleted.' );
		self::assertArrayHasKey( 301, $GLOBALS['lel_test_posts'], 'A due record under legal hold must be retained.' );
	}
}

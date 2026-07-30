<?php
/**
 * Trust-page governance: approval snapshots and publication gating.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Governs the public trust pages. Git templates are one-time seeds;
 * the operational content lives in WordPress and every published trust
 * page must represent one exact human-approved snapshot.
 */
final class Trust_Pages {
	public const APPROVAL_TYPE = 'trust_page';

	/**
	 * Slugs of the governed public trust pages.
	 *
	 * @var array<int, string>
	 */
	public const SLUGS = array(
		'about',
		'editorial-policy',
		'evidence-methodology',
		'testing-methodology',
		'medical-disclaimer',
		'affiliate-disclosure',
		'corrections',
		'privacy',
		'terms',
		'contact',
		'ai-assisted-work-disclosure',
	);

	/**
	 * Lowercase markers that indicate seed or internal placeholder content.
	 *
	 * @var array<int, string>
	 */
	private const PLACEHOLDER_MARKERS = array(
		'[date]',
		'[todo',
		'todo:',
		'[placeholder',
		'lorem ipsum',
		'[tbd',
		'tbd:',
		'[internal',
		'fixme',
	);

	/** Register publication gate and invalidation hooks. */
	public static function init(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'enforce_publication_gate' ), 5, 2 );
		add_action( 'post_updated', array( self::class, 'on_page_updated' ), 20, 3 );
	}

	/**
	 * Whether a slug identifies a governed trust page.
	 *
	 * @param string $slug Page slug to test.
	 */
	public static function is_trust_slug( string $slug ): bool {
		return in_array( $slug, self::SLUGS, true );
	}

	/**
	 * Whether a post is a governed trust page.
	 *
	 * @param int $post_id Post ID to test.
	 */
	public static function is_trust_page( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post && 'page' === $post->post_type && self::is_trust_slug( (string) $post->post_name );
	}

	/**
	 * Whether content still contains seed placeholders or internal notes.
	 *
	 * @param string $content Page content to scan.
	 */
	public static function has_placeholders( string $content ): bool {
		$normalized = strtolower( $content );
		foreach ( self::PLACEHOLDER_MARKERS as $marker ) {
			if ( false !== strpos( $normalized, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Approve the exact current snapshot of a trust page.
	 *
	 * @param int $page_id  Trust page ID.
	 * @param int $actor_id Approving user ID.
	 * @return array<string, mixed>|null The approval record, or null when rejected.
	 */
	public static function approve( int $page_id, int $actor_id ): ?array {
		if ( ! self::is_trust_page( $page_id ) ) {
			return null;
		}
		return Approval_Service::approve( $page_id, self::APPROVAL_TYPE, $actor_id );
	}

	/**
	 * Whether the page has a current approval matching its exact state.
	 *
	 * @param int $page_id Trust page ID.
	 */
	public static function is_approved( int $page_id ): bool {
		return Approval_Service::is_current( $page_id, self::APPROVAL_TYPE );
	}

	/**
	 * Block publication of a trust page without a current named human approval.
	 *
	 * A publish request that changes content can never carry a matching
	 * approval, so it is also forced back to draft.
	 *
	 * @param array<string, mixed> $data    Sanitized post data.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public static function enforce_publication_gate( array $data, array $postarr ): array {
		if ( 'page' !== ( $data['post_type'] ?? '' ) || 'publish' !== ( $data['post_status'] ?? '' ) ) {
			return $data;
		}
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$slug    = (string) ( $data['post_name'] ?? '' );
		if ( '' === $slug && $post_id > 0 ) {
			$existing = get_post( $post_id );
			$slug     = $existing ? (string) $existing->post_name : '';
		}
		if ( ! self::is_trust_slug( $slug ) ) {
			return $data;
		}
		$incoming = (string) wp_unslash( (string) ( $data['post_content'] ?? '' ) );
		$stored   = $post_id > 0 ? (string) ( get_post( $post_id )->post_content ?? '' ) : '';
		$blocked  = $post_id <= 0
			|| self::has_placeholders( $incoming )
			|| $incoming !== $stored
			|| ! self::is_approved( $post_id );
		if ( $blocked ) {
			$data['post_status'] = 'draft';
			Audit_Log::record(
				'trust_page_publication_blocked',
				'post',
				max( 0, $post_id ),
				array(
					'slug'   => $slug,
					'reason' => self::has_placeholders( $incoming ) ? 'placeholder_content' : 'missing_or_stale_trust_approval',
				),
				function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'workflow'
			);
		}
		return $data;
	}

	/**
	 * Invalidate the trust approval when material page state changes.
	 *
	 * @param int      $post_id     Updated page ID.
	 * @param \WP_Post $post_after  Page state after the update.
	 * @param \WP_Post $post_before Page state before the update.
	 */
	public static function on_page_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( 'page' !== $post_after->post_type || ! self::is_trust_slug( (string) $post_after->post_name ) ) {
			return;
		}
		$before = array( $post_before->post_title, $post_before->post_content, $post_before->post_name );
		$after  = array( $post_after->post_title, $post_after->post_content, $post_after->post_name );
		if ( $before !== $after ) {
			Approval_Service::invalidate( $post_id, self::APPROVAL_TYPE, 'trust_page_content_changed', function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		}
	}
}

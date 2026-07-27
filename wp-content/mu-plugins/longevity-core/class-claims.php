<?php
/**
 * Claim and source registry helpers.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Claim registry service. */
final class Claims {
	/** Register claim/source meta. */
	private static bool $tracking = false;

	public static function init(): void {
		add_action( 'init', array( self::class, 'register_meta' ), 12 );
		add_action( 'updated_post_meta', array( self::class, 'record_provenance' ), 20, 4 );
		add_action( 'added_post_meta', array( self::class, 'record_provenance' ), 20, 4 );
	}

	/** Register private claim and source metadata. */
	public static function register_meta(): void {
		$claim_fields = array(
			'claim_id', 'claim_text', 'claim_category', 'claim_importance', 'claim_location', 'source_id', 'source_type', 'source_title', 'source_authors', 'source_url', 'source_identifier', 'publication_date', 'accessed_date', 'jurisdiction', 'population', 'intervention', 'comparator', 'outcome', 'evidence_design', 'evidence_grade', 'conflict_notes', 'evidence_notes', 'verified_by', 'verified_at', 'verification_date', 'verification_status', 'verification_snapshot_hash', 'recheck_date', 'superseded_by', 'archive_url', 'prepared_by', 'prepared_at', 'last_edited_by', 'last_edited_at',
		);

		foreach ( $claim_fields as $field ) {
			register_post_meta(
				'lel_claim',
				$field,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => static fn( $value ) => self::sanitize_field( $field, $value ),
					'auth_callback'     => static fn( $allowed, $key, $post_id, $user_id ) => self::can_write_field( (string) $key, (int) $post_id, (int) $user_id ),
				)
			);
		}
		register_post_meta(
			'lel_claim',
			'post_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn( $allowed, $key, $post_id, $user_id ) => user_can( (int) $user_id, 'edit_claims' ),
			)
		);

		$source_fields = array( 'source_id', 'source_type', 'source_title', 'source_authors', 'source_url', 'source_identifier', 'publication_date', 'accessed_date', 'archive_url', 'rights_notes', 'source_notes', 'validation_status', 'recheck_date' );
		foreach ( $source_fields as $field ) {
			register_post_meta(
				'lel_source',
				$field,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => static fn( $value ) => self::sanitize_field( $field, $value ),
					'auth_callback'     => static fn( $allowed, $key, $post_id, $user_id ) => user_can( (int) $user_id, 'edit_claims' ),
				)
			);
		}
	}


	/** Enforce preparation/verification separation for claim metadata. */
	public static function can_write_field( string $field, int $post_id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( in_array( $field, array( 'prepared_by', 'prepared_at', 'last_edited_by', 'last_edited_at', 'verified_by', 'verified_at', 'verification_date', 'verification_snapshot_hash', 'verification_status' ), true ) ) {
			return false;
		}
		return user_can( $user_id, 'edit_claims' );
	}

	/** Verify the exact current claim snapshot through an explicit workflow service. */
	public static function verify( int $post_id, int $actor_id ): bool {
		$prepared_by = (int) get_post_meta( $post_id, 'prepared_by', true );
		// SoD is based on the immutable preparer; legacy claims fall back to last editor (fail-closed).
		$preparer = $prepared_by > 0 ? $prepared_by : (int) get_post_meta( $post_id, 'last_edited_by', true );
		if ( 'lel_claim' !== get_post_type( $post_id ) || $actor_id <= 0 || ! user_can( $actor_id, 'verify_claims' ) || $preparer === $actor_id ) {
			Audit_Log::record( 'metadata_write_denied', 'claim', $post_id, array( 'field' => 'verification_status' ), $actor_id, 'workflow' );
			return false;
		}
		$claim_id   = trim( (string) get_post_meta( $post_id, 'claim_id', true ) );
		$claim_text = trim( (string) get_post_meta( $post_id, 'claim_text', true ) );
		$source_id  = trim( (string) get_post_meta( $post_id, 'source_id', true ) );
		$source_url = trim( (string) get_post_meta( $post_id, 'source_url', true ) );
		$identifier = trim( (string) get_post_meta( $post_id, 'source_identifier', true ) );
		if ( '' === $claim_id || '' === $claim_text || ( '' === $source_id && '' === $source_url && '' === $identifier ) ) {
			Audit_Log::record( 'claim_verification_rejected', 'claim', $post_id, array( 'reason' => 'required_fields_missing' ), $actor_id, 'workflow' );
			return false;
		}
		$payload = array(
			'claim_id'          => $claim_id,
			'claim_text'        => $claim_text,
			'source_id'         => $source_id,
			'source_url'        => $source_url,
			'source_identifier' => $identifier,
			'status'            => 'verified',
		);
		$hash = hash( 'sha256', Approval_Fingerprint::canonical_json( $payload ) );
		self::$tracking = true;
		try {
			update_post_meta( $post_id, 'verification_status', 'verified' );
			update_post_meta( $post_id, 'verified_by', $actor_id );
			update_post_meta( $post_id, 'verified_at', gmdate( DATE_ATOM ) );
			update_post_meta( $post_id, 'verification_date', Date_Validator::today() );
			update_post_meta( $post_id, 'verification_snapshot_hash', $hash );
		} finally {
			self::$tracking = false;
		}
		Audit_Log::record( 'claim_verified', 'claim', $post_id, array( 'snapshot_hash' => $hash ), $actor_id, 'workflow' );
		return true;
	}

	/** Record immutable provenance fields after an authorized claim edit or verification. */
	public static function record_provenance( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		unset( $meta_id );
		if ( self::$tracking || 'lel_claim' !== get_post_type( $post_id ) ) {
			return;
		}
		$actor = get_current_user_id();
		if ( $actor <= 0 ) {
			return;
		}
		self::$tracking = true;
		try {
			if ( 'verification_status' === $meta_key && 'verified' === (string) $meta_value ) {
				if ( self::can_write_field( $meta_key, $post_id, $actor ) ) {
					$payload = array(
						'claim_id'  => (string) get_post_meta( $post_id, 'claim_id', true ),
						'claim_text'=> (string) get_post_meta( $post_id, 'claim_text', true ),
						'source_id' => (string) get_post_meta( $post_id, 'source_id', true ),
						'status'    => 'verified',
					);
					update_post_meta( $post_id, 'verified_by', $actor );
					update_post_meta( $post_id, 'verified_at', gmdate( DATE_ATOM ) );
					update_post_meta( $post_id, 'verification_date', Date_Validator::today() );
					update_post_meta( $post_id, 'verification_snapshot_hash', hash( 'sha256', Approval_Fingerprint::canonical_json( $payload ) ) );
					Audit_Log::record( 'claim_verified', 'claim', $post_id, array( 'snapshot_hash' => hash( 'sha256', Approval_Fingerprint::canonical_json( $payload ) ) ), $actor, 'workflow' );
				} else {
					update_post_meta( $post_id, 'verification_status', 'stale' );
					Audit_Log::record( 'metadata_write_denied', 'claim', $post_id, array( 'field' => 'verification_status', 'reason' => 'direct_write_bypass' ), $actor, 'workflow' );
				}
			} elseif ( ! in_array( $meta_key, array( 'prepared_by', 'prepared_at', 'last_edited_by', 'last_edited_at', 'verified_by', 'verified_at', 'verification_date', 'verification_snapshot_hash' ), true ) ) {
				if ( '' === (string) get_post_meta( $post_id, 'prepared_by', true ) ) {
					update_post_meta( $post_id, 'prepared_by', $actor );
					update_post_meta( $post_id, 'prepared_at', gmdate( DATE_ATOM ) );
				}
				update_post_meta( $post_id, 'last_edited_by', $actor );
				update_post_meta( $post_id, 'last_edited_at', gmdate( DATE_ATOM ) );
				if ( 'verification_status' !== $meta_key && 'verified' === get_post_meta( $post_id, 'verification_status', true ) ) {
					update_post_meta( $post_id, 'verification_status', 'stale' );
				}
			}
		} finally {
			self::$tracking = false;
		}
	}

	/** Count claims linked to a post. */
	public static function count_for_post( int $post_id, string $status = '' ): int {
		$args = array(
			'post_type'      => 'lel_claim',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => false,
			'meta_query'     => array(
				array( 'key' => 'post_id', 'value' => $post_id, 'compare' => '=', 'type' => 'NUMERIC' ),
			),
		);
		if ( '' !== $status ) {
			$args['meta_query'][] = array( 'key' => 'verification_status', 'value' => $status );
		}
		$query = new \WP_Query( $args );
		return (int) $query->found_posts;
	}

	/** Get public citation URLs/identifiers for an article. */
	public static function citations_for_post( int $post_id, int $limit = 20 ): array {
		$claims = get_posts(
			array(
				'post_type'      => 'lel_claim',
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array( 'key' => 'post_id', 'value' => $post_id, 'compare' => '=', 'type' => 'NUMERIC' ),
					array( 'key' => 'verification_status', 'value' => 'verified' ),
				),
			)
		);
		$citations = array();
		foreach ( $claims as $claim ) {
			$url        = esc_url_raw( (string) get_post_meta( $claim->ID, 'source_url', true ) );
			$identifier = sanitize_text_field( (string) get_post_meta( $claim->ID, 'source_identifier', true ) );
			if ( $url ) {
				$citations[] = $url;
			} elseif ( $identifier ) {
				$citations[] = $identifier;
			}
		}
		return array_values( array_unique( $citations ) );
	}

	/**
	 * Get safe bibliographic fields for verified claims linked to an article.
	 *
	 * Private notes, conflicts, email addresses, and source bodies are never returned.
	 */
	public static function public_sources_for_post( int $post_id, int $limit = 50 ): array {
		if ( $post_id <= 0 ) {
			return array();
		}
		$claims = get_posts(
			array(
				'post_type'              => 'lel_claim',
				'post_status'            => 'any',
				'posts_per_page'         => min( 100, max( 1, $limit ) ),
				'orderby'                => array( 'ID' => 'ASC' ),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array( 'key' => 'post_id', 'value' => $post_id, 'compare' => '=', 'type' => 'NUMERIC' ),
					array( 'key' => 'verification_status', 'value' => 'verified' ),
				),
			)
		);
		$sources = array();
		$seen    = array();
		foreach ( $claims as $claim ) {
			$title      = trim( (string) get_post_meta( $claim->ID, 'source_title', true ) );
			$url        = esc_url_raw( (string) get_post_meta( $claim->ID, 'source_url', true ) );
			$identifier = trim( (string) get_post_meta( $claim->ID, 'source_identifier', true ) );
			if ( '' === $title || ( '' === $url && '' === $identifier ) ) {
				continue;
			}
			$key = strtolower( $url ?: $identifier );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$source_type  = trim( (string) get_post_meta( $claim->ID, 'source_type', true ) );
			$design       = trim( (string) get_post_meta( $claim->ID, 'evidence_design', true ) );
			$label        = $design ?: $source_type;

			$public_conflict = '';
			$conflict_notes  = trim( (string) get_post_meta( $claim->ID, 'conflict_notes', true ) );
			if ( $conflict_notes && str_starts_with( $conflict_notes, '[public]' ) ) {
				$public_conflict = ltrim( substr( $conflict_notes, 7 ) );
			}

			$sources[] = array(
				'title'            => $title,
				'authors'          => trim( (string) get_post_meta( $claim->ID, 'source_authors', true ) ),
				'source_type'      => $source_type,
				'label'            => $label,
				'publication_date' => trim( (string) get_post_meta( $claim->ID, 'publication_date', true ) ),
				'accessed_date'    => trim( (string) get_post_meta( $claim->ID, 'accessed_date', true ) ),
				'jurisdiction'     => trim( (string) get_post_meta( $claim->ID, 'jurisdiction', true ) ),
				'url'              => $url,
				'identifier'       => $identifier,
				'archive_url'      => esc_url_raw( (string) get_post_meta( $claim->ID, 'archive_url', true ) ),
				'public_conflict'  => $public_conflict,
			);
		}
		return $sources;
	}

	/** Sanitize claim or source metadata by field name pattern. */
	private static function sanitize_field( string $field, $value ): string {
		$value = (string) $value;
		if ( str_contains( $field, 'url' ) ) {
			return esc_url_raw( $value );
		}
		if ( str_ends_with( $field, '_date' ) || 'recheck_date' === $field ) {
			return Meta_Registry::sanitize_value( 'date', $value );
		}
		if ( 'evidence_grade' === $field ) {
			return Meta_Registry::sanitize_value( 'evidence_grade', $value );
		}
		if ( in_array( $field, array( 'claim_text', 'conflict_notes', 'evidence_notes', 'population', 'intervention', 'comparator', 'outcome', 'rights_notes', 'source_notes' ), true ) ) {
			return sanitize_textarea_field( $value );
		}
		return sanitize_text_field( $value );
	}
}

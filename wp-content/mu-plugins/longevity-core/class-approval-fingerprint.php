<?php
/**
 * Deterministic approval fingerprints.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Produces stable SHA-256 fingerprints for reviewed content and dependencies. */
final class Approval_Fingerprint {
	public const SCHEMA_VERSION = '1.1.0';

	/** Canonical JSON for hashing. */
	public static function canonical_json( $value ): string {
		$normalized = self::normalize( $value );
		return (string) wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
	}

	/** Hash arbitrary structured data. */
	public static function hash( $value ): string {
		return hash( 'sha256', self::canonical_json( $value ) );
	}

	/** Build all hashes for an approval type. */
	public static function build( int $post_id, string $approval_type, array $prospective = array() ): array {
		$content      = self::content_payload( $post_id, $prospective );
		$governed     = self::governed_meta_payload( $post_id, $approval_type, $prospective );
		$dependencies = self::dependency_payload( $post_id, $approval_type, $prospective );
		$hashes       = array(
			'content_hash'       => self::hash( $content ),
			'governed_meta_hash' => self::hash( $governed ),
			'dependency_hash'    => self::hash( $dependencies ),
		);
		$hashes['combined_hash'] = self::hash( array( 'schema_version' => self::SCHEMA_VERSION ) + $hashes );
		$hashes['payload']       = array( 'content' => $content, 'governed_meta' => $governed, 'dependencies' => $dependencies );
		return $hashes;
	}

	/** Common reviewed content payload. */
	private static function content_payload( int $post_id, array $prospective = array() ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'post_id' => $post_id, 'missing' => true );
		}
		$thumbnail_id = function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post_id ) : 0;
		if ( array_key_exists( 'featured_image_id', $prospective ) ) {
			$thumbnail_id = (int) $prospective['featured_image_id'];
		}
		return array(
			'post_id'            => $post_id,
			'post_type'          => (string) $post->post_type,
			'title'              => (string) ( $prospective['post_title'] ?? $post->post_title ),
			'excerpt'            => (string) ( $prospective['post_excerpt'] ?? $post->post_excerpt ),
			'content'            => (string) ( $prospective['post_content'] ?? $post->post_content ),
			'author_id'          => (int) ( $prospective['post_author'] ?? $post->post_author ),
			'featured_image_id' => $thumbnail_id,
			'featured_image_alt'=> $thumbnail_id ? (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) : '',
		);
	}

	/** Explicit allowlist of metadata governed by each approval. */
	private static function governed_meta_payload( int $post_id, string $approval_type, array $prospective = array() ): array {
		$common = array( 'content_summary', 'content_scope', 'content_limitations', 'original_contribution', 'region_scope', 'material_health_claims', 'evidence_grade', 'evidence_grade_rationale', 'evidence_cutoff_date', 'uncertainty_statement_present' );
		$maps   = array(
			// Service-projected status, actor, and completion-date fields are not
			// material review inputs. Including them would make a newly-created
			// snapshot stale immediately after the compatibility projection runs.
			'fact_check' => array( 'next_fact_check_date' ),
			'medical'    => array( 'medical_review_required', 'medical_reviewer_user_id', 'medical_review_scope', 'medical_review_sections', 'medical_review_claim_ids', 'medical_review_limitations', 'medical_review_conflicts', 'medical_review_required_revisions', 'medical_review_revision_status', 'next_medical_review_date', 'medical_review_version' ),
			'testing'    => array( 'testing_required', 'testing_start_date', 'testing_end_date', 'testing_duration', 'testing_methodology_url', 'testing_protocol_version', 'test_record_id', 'product_acquisition_method', 'review_score', 'review_score_version', 'review_score_confidence', 'review_score_dimensions' ),
			'commercial' => array( 'commercial_relationship', 'affiliate_disclosure_required' ),
			'editorial'  => array( 'next_content_review_date', 'correction_status' ),
		);
		$fields = array_values( array_unique( array_merge( $common, $maps[ $approval_type ] ?? array() ) ) );
		$data   = array();
		foreach ( $fields as $field ) {
			$data[ $field ] = self::prospective_meta( $post_id, $field, $prospective );
		}
		return $data;
	}

	/** Approval-specific dependent records. */
	private static function dependency_payload( int $post_id, string $approval_type, array $prospective = array() ): array {
		if ( in_array( $approval_type, array( 'fact_check', 'medical' ), true ) ) {
			$data = self::claim_dependency_payload( $post_id );
			if ( 'medical' === $approval_type ) {
				$reviewer_id        = (int) self::prospective_meta( $post_id, 'medical_reviewer_user_id', $prospective );
				$data['reviewer']   = Reviewer_Credentials::public_snapshot( $reviewer_id );
			}
			return $data;
		}
		if ( 'testing' === $approval_type ) {
			$record_id = (int) self::prospective_meta( $post_id, 'test_record_id', $prospective );
			return array(
				'record_id'       => $record_id,
				'record'          => self::post_meta_subset( $record_id, array( 'protocol_id', 'protocol_version', 'test_start_date', 'test_end_date', 'acquisition_method', 'tester_user_ids', 'public_test_results', 'approval_status', 'approved_by', 'approved_at', 'approval_date' ) ),
				'scoring_model'   => Runtime_Config::scoring_model_status()['valid'] ? Runtime_Config::scoring_model() : array(),
			);
		}
		if ( 'commercial' === $approval_type ) {
			$content = (string) ( $prospective['post_content'] ?? get_post_field( 'post_content', $post_id ) );
			return array( 'destinations' => self::affiliate_dependency_payload( $content ) );
		}
		if ( 'editorial' === $approval_type ) {
			$data = array( 'claims' => self::claim_dependency_payload( $post_id ) );
			foreach ( array( 'fact_check', 'medical', 'testing', 'commercial' ) as $type ) {
				$current       = Approval_Repository::current( $post_id, $type );
				$data[ $type ] = $current ? array( 'id' => (int) $current['id'], 'combined_hash' => (string) $current['combined_hash'] ) : null;
			}
			$data['commercial_relationship']      = self::prospective_meta( $post_id, 'commercial_relationship', $prospective );
			$data['affiliate_disclosure_required'] = self::prospective_meta( $post_id, 'affiliate_disclosure_required', $prospective );
			return $data;
		}
		return array();
	}

	/** Snapshot normalized affiliate destinations and the registry records that govern them. */
	private static function affiliate_dependency_payload( string $content ): array {
		$urls = array();
		if ( function_exists( 'get_shortcode_regex' ) && preg_match_all( '/' . get_shortcode_regex( array( 'affiliate_link' ) ) . '/s', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$attributes = shortcode_parse_atts( $match[3] );
				if ( is_array( $attributes ) && ! empty( $attributes['url'] ) ) {
					$urls[] = (string) $attributes['url'];
				}
			}
		}
		if ( preg_match_all( '/<a\b(?=[^>]*\brel=["\'][^"\']*sponsored[^"\']*["\'])(?=[^>]*\bhref=["\']([^"\']+)["\'])[^>]*>/i', $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}
		$data = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) ) as $url ) {
			$normalized = Affiliate_Registry::normalize_destination( $url );
			$merchant   = Affiliate_Registry::find_by_url( $url );
			$record     = array();
			if ( $merchant ) {
				foreach ( array( 'merchant_domain', 'relationship_status', 'effective_date', 'expiration_date', 'last_verified_date', 'allow_subdomains', 'owner_user_id' ) as $field ) {
					$record[ $field ] = get_post_meta( $merchant->ID, $field, true );
				}
			}
			$data[] = array( 'destination' => $normalized, 'registered' => (bool) $merchant, 'record' => $record );
		}
		return $data;
	}

	/** Snapshot claim and linked-source inputs used by every public approval. */
	private static function claim_dependency_payload( int $post_id ): array {
		// Complete retrieval: every linked claim participates in the fingerprint.
		$claim_ids = Governed_Query::ids_by_meta( array( 'lel_claim' ), 'post_id', (string) $post_id );
		$data   = array();
		$fields = array( 'post_id', 'claim_id', 'claim_text', 'claim_category', 'claim_importance', 'claim_location', 'source_id', 'source_type', 'source_title', 'source_authors', 'source_url', 'source_identifier', 'publication_date', 'accessed_date', 'jurisdiction', 'population', 'intervention', 'comparator', 'outcome', 'evidence_design', 'evidence_grade', 'conflict_notes', 'evidence_notes', 'verified_by', 'verified_at', 'verification_date', 'verification_status', 'verification_snapshot_hash', 'recheck_date', 'superseded_by', 'archive_url' );
		$source_fields = array( 'source_id', 'source_type', 'source_title', 'source_authors', 'source_url', 'source_identifier', 'publication_date', 'accessed_date', 'archive_url', 'rights_notes', 'source_notes', 'validation_status', 'recheck_date' );
		foreach ( $claim_ids as $claim_id ) {
			$row = array( 'id' => (int) $claim_id );
			foreach ( $fields as $field ) {
				$row[ $field ] = get_post_meta( $claim_id, $field, true );
			}
			$source_id = (int) get_post_meta( $claim_id, 'source_id', true );
			$source    = $source_id > 0 ? get_post( $source_id ) : null;
			if ( $source ) {
				$row['source_post'] = array(
					'title'   => (string) $source->post_title,
					'excerpt' => (string) $source->post_excerpt,
					'content' => (string) $source->post_content,
					'author'  => (int) $source->post_author,
				);
				foreach ( $source_fields as $field ) {
					$row['source_post'][ $field ] = get_post_meta( $source_id, $field, true );
				}
			}
			$data[] = $row;
		}
		return $data;
	}

	/** Read an explicit subset of metadata. */
	private static function post_meta_subset( int $post_id, array $keys ): array {
		$data = array();
		foreach ( $keys as $key ) {
			$data[ $key ] = $post_id > 0 ? get_post_meta( $post_id, $key, true ) : '';
		}
		return $data;
	}

	/** Read a post meta value from the prospective request state when supplied. */
	private static function prospective_meta( int $post_id, string $key, array $prospective ) {
		if ( isset( $prospective['meta'] ) && is_array( $prospective['meta'] ) && array_key_exists( $key, $prospective['meta'] ) ) {
			return $prospective['meta'][ $key ];
		}
		return get_post_meta( $post_id, $key, true );
	}

	/** Recursively normalize maps, line endings, objects and numeric keys. */
	private static function normalize( $value ) {
		if ( is_string( $value ) ) {
			return str_replace( array( "\r\n", "\r" ), "\n", $value );
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::normalize( $item );
		}
		return $value;
	}
}

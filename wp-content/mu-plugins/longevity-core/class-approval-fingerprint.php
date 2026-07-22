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
	public const SCHEMA_VERSION = '1.0.0';

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
	public static function build( int $post_id, string $approval_type ): array {
		$content      = self::content_payload( $post_id );
		$governed     = self::governed_meta_payload( $post_id, $approval_type );
		$dependencies = self::dependency_payload( $post_id, $approval_type );
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
	private static function content_payload( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'post_id' => $post_id, 'missing' => true );
		}
		$thumbnail_id = function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post_id ) : 0;
		return array(
			'post_id'            => $post_id,
			'post_type'          => (string) $post->post_type,
			'title'              => (string) $post->post_title,
			'excerpt'            => (string) $post->post_excerpt,
			'content'            => (string) $post->post_content,
			'author_id'          => (int) $post->post_author,
			'featured_image_id' => $thumbnail_id,
			'featured_image_alt'=> $thumbnail_id ? (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) : '',
		);
	}

	/** Explicit allowlist of metadata governed by each approval. */
	private static function governed_meta_payload( int $post_id, string $approval_type ): array {
		$common = array( 'content_summary', 'content_scope', 'content_limitations', 'region_scope', 'material_health_claims', 'evidence_grade', 'evidence_grade_rationale', 'evidence_cutoff_date' );
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
			$data[ $field ] = get_post_meta( $post_id, $field, true );
		}
		return $data;
	}

	/** Approval-specific dependent records. */
	private static function dependency_payload( int $post_id, string $approval_type ): array {
		if ( in_array( $approval_type, array( 'fact_check', 'medical' ), true ) ) {
			$claims = get_posts( array( 'post_type' => 'lel_claim', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => 'post_id', 'meta_value' => $post_id ) );
			$data   = array();
			foreach ( $claims as $claim ) {
				$data[] = array(
					'id'                  => (int) $claim->ID,
					'claim_id'            => get_post_meta( $claim->ID, 'claim_id', true ),
					'claim_text'          => get_post_meta( $claim->ID, 'claim_text', true ),
					'verification_status' => get_post_meta( $claim->ID, 'verification_status', true ),
					'verified_by'         => get_post_meta( $claim->ID, 'verified_by', true ),
					'verified_at'         => get_post_meta( $claim->ID, 'verified_at', true ) ?: get_post_meta( $claim->ID, 'verification_date', true ),
					'source_id'           => get_post_meta( $claim->ID, 'source_id', true ),
					'source_url'          => get_post_meta( $claim->ID, 'source_url', true ),
				);
			}
			if ( 'medical' === $approval_type ) {
				$reviewer_id        = (int) get_post_meta( $post_id, 'medical_reviewer_user_id', true );
				$data['reviewer']   = Reviewer_Credentials::public_snapshot( $reviewer_id );
			}
			return $data;
		}
		if ( 'testing' === $approval_type ) {
			$record_id = (int) get_post_meta( $post_id, 'test_record_id', true );
			return array( 'record_id' => $record_id, 'record' => self::post_meta_subset( $record_id, array( 'protocol_id', 'protocol_version', 'test_start_date', 'test_end_date', 'acquisition_method', 'tester_user_ids', 'public_test_results', 'approval_status', 'approved_by', 'approved_at', 'approval_date' ) ) );
		}
		if ( 'commercial' === $approval_type ) {
			return array( 'destinations_registered' => Affiliate_Registry::all_destinations_registered( (string) get_post_field( 'post_content', $post_id ) ) );
		}
		if ( 'editorial' === $approval_type ) {
			$data = array();
			foreach ( array( 'fact_check', 'medical', 'testing', 'commercial' ) as $type ) {
				$current       = Approval_Repository::current( $post_id, $type );
				$data[ $type ] = $current ? array( 'id' => (int) $current['id'], 'combined_hash' => (string) $current['combined_hash'] ) : null;
			}
			return $data;
		}
		return array();
	}

	/** Read an explicit subset of metadata. */
	private static function post_meta_subset( int $post_id, array $keys ): array {
		$data = array();
		foreach ( $keys as $key ) {
			$data[ $key ] = $post_id > 0 ? get_post_meta( $post_id, $key, true ) : '';
		}
		return $data;
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

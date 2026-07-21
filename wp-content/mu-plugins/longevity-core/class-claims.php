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
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_meta' ), 12 );
	}

	/** Register private claim and source metadata. */
	public static function register_meta(): void {
		$claim_fields = array(
			'claim_id', 'claim_text', 'claim_category', 'claim_importance', 'claim_location', 'source_id', 'source_type', 'source_title', 'source_authors', 'source_url', 'source_identifier', 'publication_date', 'accessed_date', 'jurisdiction', 'population', 'intervention', 'comparator', 'outcome', 'evidence_design', 'evidence_grade', 'conflict_notes', 'evidence_notes', 'verified_by', 'verification_date', 'verification_status', 'recheck_date', 'superseded_by', 'archive_url',
		);

		foreach ( $claim_fields as $field ) {
			register_post_meta(
				'lel_claim',
				$field,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => static fn( $value ) => self::sanitize_claim_field( $field, $value ),
					'auth_callback'     => static fn() => current_user_can( 'manage_claims' ),
				)
			);
		}
		register_post_meta(
			'lel_claim',
			'post_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn() => current_user_can( 'manage_claims' ),
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
					'show_in_rest'      => true,
					'sanitize_callback' => static fn( $value ) => self::sanitize_source_field( $field, $value ),
					'auth_callback'     => static fn() => current_user_can( 'manage_claims' ),
				)
			);
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

	/** Sanitize claim metadata. */
	private static function sanitize_claim_field( string $field, $value ): string {
		$value = (string) $value;
		if ( in_array( $field, array( 'source_url' ), true ) ) {
			return esc_url_raw( $value );
		}
		if ( str_ends_with( $field, '_date' ) || 'recheck_date' === $field ) {
			return Meta_Registry::sanitize_value( 'date', $value );
		}
		if ( 'evidence_grade' === $field ) {
			return Meta_Registry::sanitize_value( 'evidence_grade', $value );
		}
		if ( in_array( $field, array( 'claim_text', 'conflict_notes', 'evidence_notes', 'population', 'intervention', 'comparator', 'outcome' ), true ) ) {
			return sanitize_textarea_field( $value );
		}
		return sanitize_text_field( $value );
	}

	/** Sanitize source metadata. */
	private static function sanitize_source_field( string $field, $value ): string {
		if ( str_contains( $field, 'url' ) ) {
			return esc_url_raw( (string) $value );
		}
		if ( str_ends_with( $field, '_date' ) ) {
			return Meta_Registry::sanitize_value( 'date', $value );
		}
		if ( in_array( $field, array( 'rights_notes', 'source_notes' ), true ) ) {
			return sanitize_textarea_field( (string) $value );
		}
		return sanitize_text_field( (string) $value );
	}
}

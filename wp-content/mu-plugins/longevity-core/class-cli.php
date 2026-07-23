<?php
/**
 * WP-CLI commands for claims, sources, readiness, and safe exports.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Registers CLI commands only when WP-CLI is active. */
final class CLI {
	/** Register command namespaces. */
	public static function init(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'longevity claims', Claims_Command::class );
		\WP_CLI::add_command( 'longevity sources', Sources_Command::class );
		\WP_CLI::add_command( 'longevity readiness', Readiness_Command::class );
		\WP_CLI::add_command( 'longevity freshness', Freshness_Command::class );
		\WP_CLI::add_command( 'longevity bootstrap', Bootstrap_Command::class );
		\WP_CLI::add_command( 'longevity migrate', Migrate_Command::class );
		\WP_CLI::add_command( 'longevity evidence', Evidence_Command::class );
	}
}

/** Shared CSV utilities. */
trait CSV_Command_Utilities {
	/** Require a logged-in CLI user with a capability. */
	private function require_capability( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			\WP_CLI::error( sprintf( 'This command requires %s. Run WP-CLI with an authorized --user.', $capability ) );
		}
	}

	/** Open an output stream. */
	private function output_stream( array $assoc_args ) {
		$file   = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';
		$stream = fopen( '' === $file ? 'php://output' : $file, 'wb' );
		if ( false === $stream ) {
			\WP_CLI::error( 'Unable to open CSV output.' );
		}
		return $stream;
	}

	/** Protect spreadsheet consumers from CSV formula injection. */
	private function csv_safe( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
	}

	/** Read a CSV file into associative rows and preserve row numbers. */
	private function read_csv( string $file ): array {
		$stream = fopen( $file, 'rb' );
		if ( false === $stream ) {
			\WP_CLI::error( 'Unable to read CSV file.' );
		}
		$headers = fgetcsv( $stream );
		if ( ! is_array( $headers ) ) {
			\WP_CLI::error( 'CSV header is missing.' );
		}
		$rows = array();
		$line = 1;
		while ( ( $values = fgetcsv( $stream ) ) !== false ) {
			++$line;
			if ( count( $values ) !== count( $headers ) ) {
				$rows[] = array(
					'__line'  => $line,
					'__error' => 'Column count does not match the header.',
				);
				continue;
			}
			$row           = array_combine( $headers, $values );
			$row['__line'] = $line;
			$rows[]        = $row;
		}
		fclose( $stream );
		return array( $headers, $rows );
	}
}

/** Claim import, export, and validation commands. */
final class Claims_Command {
	use CSV_Command_Utilities;

	/** @var array<int, string> */
	private const FIELDS = array( 'claim_id', 'post_id', 'claim_text', 'claim_category', 'claim_importance', 'claim_location', 'source_id', 'source_type', 'source_title', 'source_authors', 'source_url', 'source_identifier', 'publication_date', 'accessed_date', 'jurisdiction', 'population', 'intervention', 'comparator', 'outcome', 'evidence_design', 'evidence_grade', 'conflict_notes', 'evidence_notes', 'verified_by', 'verification_date', 'verification_status', 'recheck_date', 'superseded_by' );

	/**
	 * Export claims as UTF-8 CSV.
	 *
	 * ## OPTIONS
	 * [--file=<path>]
	 */
	public function export( array $args, array $assoc_args ): void {
		unset( $args );
		$this->require_capability( 'manage_claims' );
		$stream = $this->output_stream( $assoc_args );
		fputcsv( $stream, self::FIELDS );
		$posts = get_posts(
			array(
				'post_type'      => 'lel_claim',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			$row = array();
			foreach ( self::FIELDS as $field ) {
				$value = 'claim_text' === $field && ! get_post_meta( $post->ID, $field, true ) ? $post->post_content : get_post_meta( $post->ID, $field, true );
				$row[] = $this->csv_safe( $value );
			}
			fputcsv( $stream, $row );
		}
		fclose( $stream );
		\WP_CLI::success( sprintf( 'Exported %d claim(s).', count( $posts ) ) );
	}

	/**
	 * Import claims from CSV.
	 *
	 * ## OPTIONS
	 * --file=<path>
	 * [--dry-run]
	 * [--update]
	 */
	public function import( array $args, array $assoc_args ): void {
		unset( $args );
		$this->require_capability( 'edit_claims' );
		$file = (string) ( $assoc_args['file'] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( 'Provide a readable --file.' );
		}
		list( $headers, $rows ) = $this->read_csv( $file );
		$missing                = array_diff( self::FIELDS, $headers );
		if ( $missing ) {
			\WP_CLI::error( 'Missing required columns: ' . implode( ', ', $missing ) );
		}
		$dry_run      = isset( $assoc_args['dry-run'] );
		$allow_update = isset( $assoc_args['update'] );
		$created      = 0;
		$updated      = 0;
		$errors       = $this->validate_rows( $rows );
		if ( $errors ) {
			foreach ( $errors as $error ) {
				\WP_CLI::warning( $error );
			}
			\WP_CLI::error( 'Import rejected because validation failed. No rows were changed.' );
		}
		$ignored_verification = false;
		foreach ( $rows as $row ) {
			$existing = self::find_by_stable_id( (string) $row['claim_id'] );
			if ( $existing && ! $allow_update ) {
				\WP_CLI::error( sprintf( 'Claim %s already exists. Use --update explicitly.', $row['claim_id'] ) );
			}
			if ( $dry_run ) {
				$existing ? ++$updated : ++$created;
				continue;
			}
			$post_id = $existing ?: wp_insert_post(
				array(
					'post_type'    => 'lel_claim',
					'post_status'  => 'private',
					'post_title'   => sanitize_text_field( (string) $row['claim_id'] ),
					'post_content' => sanitize_textarea_field( (string) $row['claim_text'] ),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				\WP_CLI::error( $post_id->get_error_message() );
			}
			foreach ( self::FIELDS as $field ) {
				if ( in_array( $field, array( 'verified_by', 'verification_date', 'verification_status' ), true ) ) {
					$ignored_verification = $ignored_verification || '' !== trim( (string) ( $row[ $field ] ?? '' ) );
					continue;
				}
				$value = 'post_id' === $field ? absint( $row[ $field ] ) : Meta_Registry::sanitize_value( self::rule_for_field( $field ), $row[ $field ] );
				update_post_meta( (int) $post_id, $field, $value );
			}
			if ( ! $existing && '' === (string) get_post_meta( (int) $post_id, 'verification_status', true ) ) {
				update_post_meta( (int) $post_id, 'verification_status', 'not_verified' );
			}
			$existing ? ++$updated : ++$created;
		}
		if ( $ignored_verification ) {
			\WP_CLI::warning( 'Verification columns were ignored. Use `wp longevity claims verify <post-id>` with an independent verifier.' );
		}
		\WP_CLI::success( sprintf( '%s: %d create(s), %d update(s).', $dry_run ? 'Dry run valid' : 'Import complete', $created, $updated ) );
	}

	/**
	 * Verify an imported claim through the independent verification service.
	 *
	 * ## OPTIONS
	 * <post-id>
	 */
	public function verify( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$this->require_capability( 'verify_claims' );
		$post_id = absint( $args[0] ?? 0 );
		if ( $post_id <= 0 || ! Claims::verify( $post_id, get_current_user_id() ) ) {
			\WP_CLI::error( 'Claim verification failed. Confirm required fields and independent verifier ownership.' );
		}
		\WP_CLI::success( sprintf( 'Claim %d verified against its current snapshot.', $post_id ) );
	}

	/**
	 * Validate a claims CSV without changing WordPress.
	 *
	 * ## OPTIONS
	 * --file=<path>
	 */
	public function validate( array $args, array $assoc_args ): void {
		unset( $args );
		$this->require_capability( 'edit_claims' );
		$file = (string) ( $assoc_args['file'] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( 'Provide a readable --file.' );
		}
		list( $headers, $rows ) = $this->read_csv( $file );
		$missing                = array_diff( self::FIELDS, $headers );
		$errors                 = $missing ? array( 'Missing columns: ' . implode( ', ', $missing ) ) : $this->validate_rows( $rows );
		if ( $errors ) {
			foreach ( $errors as $error ) {
				\WP_CLI::warning( $error );
			}
			\WP_CLI::error( sprintf( 'Validation failed with %d error(s).', count( $errors ) ) );
		}
		\WP_CLI::success( sprintf( 'Validated %d claim row(s).', count( $rows ) ) );
	}

	/** Validate rows and duplicate stable IDs. */
	private function validate_rows( array $rows ): array {
		$errors = array();
		$seen   = array();
		foreach ( $rows as $row ) {
			$line = (int) ( $row['__line'] ?? 0 );
			if ( isset( $row['__error'] ) ) {
				$errors[] = sprintf( 'Row %d: %s', $line, $row['__error'] );
				continue;
			}
			$id = sanitize_key( (string) ( $row['claim_id'] ?? '' ) );
			if ( '' === $id ) {
				$errors[] = sprintf( 'Row %d: claim_id is required.', $line );
			} elseif ( isset( $seen[ $id ] ) ) {
				$errors[] = sprintf( 'Row %d: duplicate claim_id %s.', $line, $id );
			}
			$seen[ $id ] = true;
			if ( empty( $row['claim_text'] ) ) {
				$errors[] = sprintf( 'Row %d: claim_text is required.', $line );
			}
			if ( ! in_array( $row['evidence_grade'] ?? '', array( 'A', 'B', 'C', 'D', 'U' ), true ) ) {
				$errors[] = sprintf( 'Row %d: evidence_grade must be A, B, C, D, or U.', $line );
			}
			if ( empty( $row['evidence_notes'] ) ) {
				$errors[] = sprintf( 'Row %d: evidence_notes/rationale is required.', $line );
			}
		}
		return $errors;
	}

	/** Find a claim by stable ID. */
	private static function find_by_stable_id( string $claim_id ): int {
		$posts = get_posts(
			array(
				'post_type'      => 'lel_claim',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => 'claim_id',
				'meta_value'     => sanitize_key( $claim_id ),
			)
		);
		return $posts ? (int) $posts[0] : 0;
	}

	/** Map CSV fields to sanitization rules. */
	private static function rule_for_field( string $field ): string {
		if ( 'source_url' === $field ) {
			return 'url';
		}
		if ( str_ends_with( $field, '_date' ) || 'recheck_date' === $field ) {
			return 'date';
		}
		if ( 'evidence_grade' === $field ) {
			return 'evidence_grade';
		}
		if ( in_array( $field, array( 'claim_text', 'conflict_notes', 'evidence_notes', 'population', 'intervention', 'comparator', 'outcome' ), true ) ) {
			return 'textarea';
		}
		return 'text';
	}
}

/** Source registry export and validation commands. */
final class Sources_Command {
	use CSV_Command_Utilities;

	/** @var array<int, string> */
	private const FIELDS = array( 'source_id', 'source_type', 'source_title', 'source_authors', 'source_url', 'source_identifier', 'publication_date', 'accessed_date', 'archive_url', 'rights_notes', 'source_notes', 'validation_status', 'recheck_date' );

	/** Export sources. */
	public function export( array $args, array $assoc_args ): void {
		unset( $args );
		$this->require_capability( 'manage_claims' );
		$stream = $this->output_stream( $assoc_args );
		fputcsv( $stream, self::FIELDS );
		$posts = get_posts(
			array(
				'post_type'      => 'lel_source',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			$row = array_map( fn( string $field ) => $this->csv_safe( get_post_meta( $post->ID, $field, true ) ), self::FIELDS );
			fputcsv( $stream, $row );
		}
		fclose( $stream );
		\WP_CLI::success( sprintf( 'Exported %d source(s).', count( $posts ) ) );
	}

	/** Validate a source CSV. */
	public function validate( array $args, array $assoc_args ): void {
		unset( $args );
		$this->require_capability( 'manage_claims' );
		$file = (string) ( $assoc_args['file'] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( 'Provide a readable --file.' );
		}
		list( $headers, $rows ) = $this->read_csv( $file );
		$errors                 = array();
		$missing                = array_diff( self::FIELDS, $headers );
		if ( $missing ) {
			$errors[] = 'Missing columns: ' . implode( ', ', $missing );
		}
		$seen = array();
		foreach ( $rows as $row ) {
			$line = (int) ( $row['__line'] ?? 0 );
			$id   = sanitize_key( (string) ( $row['source_id'] ?? '' ) );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$errors[] = sprintf( 'Row %d: source_id is missing or duplicated.', $line );
			}
			$seen[ $id ] = true;
			if ( empty( $row['source_title'] ) || ( empty( $row['source_url'] ) && empty( $row['source_identifier'] ) ) ) {
				$errors[] = sprintf( 'Row %d: title and URL or identifier are required.', $line );
			}
		}
		if ( $errors ) {
			foreach ( $errors as $error ) {
				\WP_CLI::warning( $error );
			}
			\WP_CLI::error( 'Source validation failed.' );
		}
		\WP_CLI::success( sprintf( 'Validated %d source row(s).', count( $rows ) ) );
	}
}

/** Publication-readiness CLI command. */
final class Readiness_Command {
	/** Check a post readiness state. */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$post_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			\WP_CLI::error( 'Provide an editable post ID and an authorized --user.' );
		}
		$result = Publication_Gates::evaluate( $post_id );
		\WP_CLI::line( wp_json_encode( $result->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( $result->is_blocked() ) {
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( 'Publication readiness passed.' );
	}
}

/** Bootstrap command — creates canonical pages, categories, and placeholder content idempotently. */
final class Bootstrap_Command {

	/** @var array<string, array> Canonical page definitions. */
	private const CANONICAL_PAGES = array(
		'home'                 => array(
			'slug'   => 'home',
			'title'  => 'Home',
			'status' => 'publish',
		),
		'start_here'           => array(
			'slug'   => 'start-here',
			'title'  => 'Start Here',
			'status' => 'publish',
		),
		'guides'               => array(
			'slug'   => 'guides',
			'title'  => 'Guides',
			'status' => 'draft',
		),
		'topics'               => array(
			'slug'   => 'topics',
			'title'  => 'Topics',
			'status' => 'draft',
		),
		'evidence_methodology' => array(
			'slug'   => 'evidence-methodology',
			'title'  => 'Evidence Methodology',
			'status' => 'draft',
		),
		'about'                => array(
			'slug'   => 'about',
			'title'  => 'About',
			'status' => 'draft',
		),
		'editorial_policy'     => array(
			'slug'   => 'editorial-policy',
			'title'  => 'Editorial Policy',
			'status' => 'draft',
		),
		'medical_disclaimer'   => array(
			'slug'   => 'medical-disclaimer',
			'title'  => 'Medical Disclaimer',
			'status' => 'draft',
		),
		'affiliate_disclosure' => array(
			'slug'   => 'affiliate-disclosure',
			'title'  => 'Affiliate Disclosure',
			'status' => 'draft',
		),
		'corrections'          => array(
			'slug'   => 'corrections',
			'title'  => 'Corrections',
			'status' => 'draft',
		),
		'testing_methodology'  => array(
			'slug'   => 'testing-methodology',
			'title'  => 'Testing Methodology',
			'status' => 'draft',
		),
		'privacy'              => array(
			'slug'   => 'privacy',
			'title'  => 'Privacy',
			'status' => 'draft',
		),
		'terms'                => array(
			'slug'   => 'terms',
			'title'  => 'Terms',
			'status' => 'draft',
		),
		'contact'              => array(
			'slug'   => 'contact',
			'title'  => 'Contact',
			'status' => 'draft',
		),
		'ai_assist_disclosure' => array(
			'slug'   => 'ai-assisted-work-disclosure',
			'title'  => 'AI-Assisted Work Disclosure',
			'status' => 'draft',
		),
		'source_registry'      => array(
			'slug'   => 'source-registry',
			'title'  => 'Source Registry',
			'status' => 'draft',
		),
	);

	/** @var array<string, array> Canonical category definitions matching Routes. */
	private const CANONICAL_CATEGORIES = array(
		'evidence'     => array(
			'slug' => 'evidence-literacy',
			'name' => 'Evidence Literacy',
		),
		'sleep'        => array(
			'slug' => 'sleep',
			'name' => 'Sleep and Circadian Health',
		),
		'movement'     => array(
			'slug' => 'movement',
			'name' => 'Movement and Physical Capacity',
		),
		'nutrition'    => array(
			'slug' => 'nutrition',
			'name' => 'Nutrition and Healthy Aging',
		),
		'wearables'    => array(
			'slug' => 'wearables',
			'name' => 'Wearables and Consumer Measurement',
		),
		'supplements'  => array(
			'slug' => 'supplements',
			'name' => 'Supplements and High-Uncertainty Interventions',
		),
		'consumer_lab' => array(
			'slug' => 'consumer-lab',
			'name' => 'Consumer Lab',
		),
	);

	/**
	 * Create canonical pages idempotently.
	 *
	 * ## OPTIONS
	 * [--dry-run]     Preview changes without modifying the database.
	 *
	 * ## EXAMPLES
	 *     wp longevity bootstrap pages
	 *     wp longevity bootstrap pages --dry-run
	 */
	public function pages( array $args, array $assoc_args ): void {
		unset( $args );
		$dry_run  = isset( $assoc_args['dry-run'] );
		$created  = 0;
		$existing = 0;
		$errors   = array();

		foreach ( self::CANONICAL_PAGES as $key => $def ) {
			$slug = $def['slug'];

			$existing_page = get_page_by_path( $slug, OBJECT, 'page' );

			if ( $existing_page instanceof \WP_Post ) {
				\WP_CLI::line( "{$def['title']} (/{$slug}/): already exists (ID {$existing_page->ID}, status {$existing_page->post_status})" );
				++$existing;
				continue;
			}

			if ( 'home' === $key ) {
				$front_page_id = (int) get_option( 'page_on_front' );
				if ( $front_page_id > 0 ) {
					$front_page = get_post( $front_page_id );
					if ( $front_page && 'home' !== $front_page->post_name ) {
						\WP_CLI::line( "Home route is currently assigned to /{$front_page->post_name}/ (ID {$front_page_id}). A new 'home' page will be created and assigned." );
					}
				}
			}

			if ( $dry_run ) {
				\WP_CLI::line( "[DRY RUN] Would create {$def['title']} (/{$slug}/) as {$def['status']}" );
				++$created;
				continue;
			}

			$block_content = '';
			if ( 'home' === $key ) {
				$block_content = '<!-- wp:paragraph --><p>Welcome to Longevity Evidence Lab. This page serves as the static front page. The front-page.html template controls the visual layout.</p><!-- /wp:paragraph -->';
			} elseif ( 'start_here' === $key ) {
				$block_content = '<!-- wp:heading {"level":1} --><h1>Start Here</h1><!-- /wp:heading -->';
			}

			$post_id = wp_insert_post(
				array(
					'post_title'   => $def['title'],
					'post_name'    => $slug,
					'post_content' => $block_content,
					'post_status'  => $def['status'],
					'post_type'    => 'page',
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				$errors[] = "Failed to create {$def['title']}: " . $post_id->get_error_message();
				\WP_CLI::warning( "Failed to create {$def['title']}: " . $post_id->get_error_message() );
				continue;
			}

			\WP_CLI::line( "Created {$def['title']} (/{$slug}/) as {$def['status']} (ID {$post_id})" );
			++$created;

			if ( 'draft' === $def['status'] ) {
				update_post_meta( $post_id, '_longevity_noindex', '1' );
			}

			if ( 'home' === $key ) {
				update_option( 'page_on_front', (int) $post_id );
				update_option( 'show_on_front', 'page' );
				\WP_CLI::line( "Set Home (ID {$post_id}) as the static front page." );
			}
		}

		// Ensure the tagline is set for OG/Twitter fallback descriptions.
		$current_tagline = get_option( 'blogdescription' );
		if ( ! $dry_run && empty( $current_tagline ) ) {
			update_option( 'blogdescription', 'Independent health evidence and consumer testing — evidence grades, product measurements, limitations, and corrections.' );
			\WP_CLI::line( 'Set site tagline for OG/Twitter fallback descriptions.' );
		}

		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id > 0 ) {
			$front_page = get_post( $front_page_id );
			if ( $front_page && 'start-here' === $front_page->post_name ) {
				if ( ! $dry_run ) {
					\WP_CLI::warning( 'Start Here is currently the front page. A Home page must be created first.' );
				} else {
					\WP_CLI::line( '[DRY RUN] Would reassign front page from Start Here to Home.' );
				}
			}
		}

		\WP_CLI::success( sprintf( 'Bootstrap complete: %d created, %d existing, %d error(s).', $created, $existing, count( $errors ) ) );
		if ( $errors ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Create canonical categories idempotently.
	 *
	 * ## OPTIONS
	 * [--dry-run]     Preview changes without modifying the database.
	 *
	 * ## EXAMPLES
	 *     wp longevity bootstrap categories
	 *     wp longevity bootstrap categories --dry-run
	 */
	public function categories( array $args, array $assoc_args ): void {
		unset( $args );
		$dry_run  = isset( $assoc_args['dry-run'] );
		$created  = 0;
		$existing = 0;
		$errors   = array();

		foreach ( self::CANONICAL_CATEGORIES as $key => $def ) {
			$slug      = $def['slug'];
			$term      = term_exists( $slug, 'category' );
			$term_id   = 0;

			if ( $term ) {
				if ( is_array( $term ) ) {
					$term_id = (int) $term['term_id'];
				} else {
					$term_id = (int) $term;
				}
				\WP_CLI::line( "{$def['name']} (/{$slug}/): already exists (ID {$term_id})" );
				++$existing;
				continue;
			}

			if ( $dry_run ) {
				\WP_CLI::line( "[DRY RUN] Would create category {$def['name']} (/{$slug}/)" );
				++$created;
				continue;
			}

			$result = wp_insert_term( $def['name'], 'category', array( 'slug' => $slug ) );
			if ( is_wp_error( $result ) ) {
				$errors[] = "Failed to create category {$def['name']}: " . $result->get_error_message();
				\WP_CLI::warning( "Failed to create category {$def['name']}: " . $result->get_error_message() );
				continue;
			}

			$term_id = (int) $result['term_id'];
			\WP_CLI::line( "Created category {$def['name']} (/{$slug}/) as ID {$term_id}" );
			++$created;
		}

		\WP_CLI::success( sprintf( 'Bootstrap complete: %d created, %d existing, %d error(s).', $created, $existing, count( $errors ) ) );
		if ( $errors ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Create placeholder content (draft posts/reviews) for each canonical category.
	 *
	 * ## OPTIONS
	 * [--dry-run]     Preview changes without modifying the database.
	 *
	 * ## EXAMPLES
	 *     wp longevity bootstrap content
	 *     wp longevity bootstrap content --dry-run
	 */
	public function content( array $args, array $assoc_args ): void {
		unset( $args );
		$dry_run  = isset( $assoc_args['dry-run'] );
		$created  = 0;
		$existing = 0;
		$errors   = array();

		$post_blueprints = array(
			'LEL-001'     => array(
				'post_type'    => 'post',
				'post_title'   => 'What Longevity Evidence Lab Does—and Does Not Claim',
				'post_name'    => 'what-we-do-and-do-not-claim',
				'post_content' => '<!-- wp:paragraph --><p>Longevity Evidence Lab evaluates products, practices, and interventions that claim to support healthy aging. This page explains what we do—and what we do not—claim, so you can make an informed decision about whether to trust and use this publication.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>What we do</h2><!-- /wp:heading --><!-- wp:list --><ul><li>Register every material health or performance claim before publication.</li><li>Assign evidence grades (A–U) using a defined scale.</li><li>Disclose commercial relationships and affiliate links per content item.</li><li>Publish correction notices for substantive errors.</li><li>Set a scheduled review date for all content.</li></ul><!-- /wp:list --><!-- wp:heading --><h2>What we do not do</h2><!-- /wp:heading --><!-- wp:list --><ul><li>We do not provide individual medical advice, diagnosis, or treatment recommendations.</li><li>We do not guarantee outcomes from any product or practice.</li><li>We do not claim superiority over other publications or methodologies.</li><li>We do not accept payment for positive coverage.</li></ul><!-- /wp:list --><!-- wp:heading --><h2>How to use this site</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Start with our Start Here page for a guided introduction. Use the Topics hub to explore by subject area. Our Evidence and Testing Methodology pages explain how we produce scores and grades.</p><!-- /wp:paragraph -->',
			),
			'LEL-002'     => array(
				'post_type'    => 'post',
				'post_title'   => 'What Is Biohacking? An Evidence and Risk Framework',
				'post_name'    => 'biohacking-evidence-risk-framework',
				'post_content' => '<!-- wp:paragraph --><p>Biohacking covers a wide range of self-experimentation practices, from supplements and nootropics to light therapy and wearable devices. This framework helps you assess any biohacking practice by its evidence level and risk profile.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>The evidence-risk matrix</h2><!-- /wp:heading --><!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Risk level</th><th>Strong evidence</th><th>Moderate evidence</th><th>Limited evidence</th></tr></thead><tbody><tr><td>Low</td><td>Proceed with confidence</td><td>Proceed with awareness</td><td>Proceed cautiously</td></tr><tr><td>Medium</td><td>Proceed with monitoring</td><td>Proceed with caution</td><td>Avoid or consult expert</td></tr><tr><td>High</td><td>Consult expert</td><td>Avoid</td><td>Avoid</td></tr></tbody></table></figure><!-- /wp:table --><!-- wp:heading --><h2>How to use this framework</h2><!-- /wp:heading --><!-- wp:paragraph --><p>For any intervention: (1) identify the risk level based on known side effects and regulatory status, (2) assess the evidence quality independently, (3) plot the intersection on the matrix above, and (4) decide accordingly.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p><strong>Medical review note:</strong> This content discusses safety boundaries and escalation indicators. Medical review is pending before publication.</p><!-- /wp:paragraph -->',
			),
			'LEL-003'     => array(
				'post_type'    => 'post',
				'post_title'   => 'How to Read a Health Study Without Being Misled',
				'post_name'    => 'how-to-read-a-health-study',
				'post_content' => '<!-- wp:paragraph --><p>Health studies appear in headlines every day. Many are reliable; some are misleading. This guide provides a simple worksheet you can apply to any study to decide how much confidence to place in its findings.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Six questions for any study</h2><!-- /wp:heading --><!-- wp:list {"ordered":true} --><ol><li><strong>What study design was used?</strong> Randomised trials are stronger than observational studies for causal questions.</li><li><strong>How large was the sample?</strong> Small studies produce less precise estimates.</li><li><strong>Who was in the study?</strong> Does the population match your situation?</li><li><strong>How long did it last?</strong> Short durations may miss long-term effects.</li><li><strong>What was measured?</strong> Did they measure the outcome directly or by proxy?</li><li><strong>Who funded it?</strong> Consider potential conflicts of interest.</li></ol><!-- /wp:list --><!-- wp:heading --><h2>Study appraisal worksheet</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Download or copy the worksheet below to evaluate any study. Each question maps to a domain: design, sample, relevance, duration, measurement, and sponsorship.</p><!-- /wp:paragraph -->',
			),
			'LEL-004'     => array(
				'post_type'    => 'post',
				'post_title'   => 'How We Grade Evidence and Test Consumer Products',
				'post_name'    => 'how-we-grade-evidence-and-test-products',
				'post_content' => '<!-- wp:paragraph --><p>Every material claim on this site is assigned an evidence grade. Product reviews carry a Consumer Lab score. This page explains how both are produced so you can interpret them with confidence.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Evidence grades</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Grades follow a defined scale: A (strong), B (moderate), C (limited), D (mechanistic or anecdotal), and U (unclear). Each grade is specific to a single claim, not an entire article. Grades are assigned conservatively and include a rationale.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Product scores</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Consumer Lab scores use a multi-category buyer-facts framework. Each product is evaluated on the categories that matter for its type. Scores are calculated using the methodology published on our Testing Methodology page.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Protocol index</h2><!-- /wp:heading --><!-- wp:paragraph --><p>All test protocols are registered before testing begins. Protocol documents include measurement devices, test procedures, sample sizes, and statistical approaches. Deviations from registered protocols are noted in final reports.</p><!-- /wp:paragraph -->',
			),
			'LEL-005'     => array(
				'post_type'    => 'post',
				'post_title'   => 'How to Improve Sleep Before Buying Another Device',
				'post_name'    => 'improve-sleep-before-buying-device',
				'post_content' => '<!-- wp:paragraph --><p>Sleep trackers and smart devices promise better rest, but the fundamentals of sleep hygiene cost little and are backed by stronger evidence. This guide helps you decide whether to invest in fundamentals or a device.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Sleep fundamentals with strong evidence</h2><!-- /wp:heading --><!-- wp:list --><ul><li><strong>Consistent schedule:</strong> Going to bed and waking at the same time supports circadian alignment.</li><li><strong>Light management:</strong> Bright light exposure in the morning; dim, blue-reduced light in the evening.</li><li><strong>Temperature:</strong> A cool room (16–19°C) promotes sleep onset.</li><li><strong>Wind-down routine:</strong> 30 minutes of low-arousal activity before bed.</li><li><strong>Caffeine timing:</strong> Avoid caffeine within 8–10 hours of bedtime.</li></ul><!-- /wp:list --><!-- wp:heading --><h2>When to consider a device</h2><!-- /wp:heading --><!-- wp:paragraph --><p>After consistent application of fundamentals for 4–6 weeks, if sleep difficulties persist, a device that provides measurement and feedback may help identify patterns. Our decision tool below helps you assess whether you are ready to escalate.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p><strong>Medical review note:</strong> This content includes safety boundaries and sleep-disorder red flags. Medical review is pending before publication.</p><!-- /wp:paragraph -->',
			),
			'LEL-006'     => array(
				'post_type'    => 'post',
				'post_title'   => 'How Accurate Are Consumer Sleep Trackers?',
				'post_name'    => 'consumer-sleep-tracker-accuracy',
				'post_content' => '<!-- wp:paragraph --><p>Consumer sleep trackers from brands like Oura, Fitbit, Apple, and Whoop claim to measure sleep stages, heart rate, and recovery. This page summarises the published evidence for what these devices can and cannot measure reliably.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Evidence matrix by metric</h2><!-- /wp:heading --><!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Metric</th><th>Evidence level</th><th>Notes</th></tr></thead><tbody><tr><td>Heart rate (night)</td><td>B (Moderate)</td><td>Good agreement with ECG at group level; lower accuracy at individual level</td></tr><tr><td>Total sleep time</td><td>B (Moderate)</td><td>Generally reliable for longer sleep periods; less accurate with frequent awakenings</td></tr><tr><td>Sleep stages (NREM/REM)</td><td>C (Limited)</td><td>Limited agreement with polysomnography; misclassification of light sleep common</td></tr><tr><td>Sleep onset / offset</td><td>C (Limited)</td><td>Variable across devices; tends to overestimate sleep time</td></tr><tr><td>HRV</td><td>C (Limited)</td><td>Night-time HRV correlates with reference measures; daytime not well validated</td></tr></tbody></table></figure><!-- /wp:table --><!-- wp:heading --><h2>Key limitations</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Consumer devices are not medical-grade. They can provide useful trend data but should not be used for diagnosis or clinical decision-making. Accuracy varies by device firmware version, user characteristics, and sleeping environment.</p><!-- /wp:paragraph --><p><strong>Medical review note:</strong> This content interprets accuracy data in clinical context. Medical review is pending before publication.</p><!-- /wp:paragraph -->',
			),
			'LEL-007'     => array(
				'post_type'    => 'post',
				'post_title'   => 'Resistance Training for Healthy Aging: A Beginner Framework',
				'post_name'    => 'resistance-training-healthy-aging',
				'post_content' => '<!-- wp:paragraph --><p>Resistance training is one of the most evidence-supported interventions for healthy aging. This guide provides a progression framework for beginners, with safety boundaries and referral indicators.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Getting started (weeks 1–4)</h2><!-- /wp:heading --><!-- wp:list --><ul><li>Bodyweight exercises: squats, wall push-ups, glute bridges, planks.</li><li>2 sessions per week, 1 set of 10–15 repetitions per exercise.</li><li>Focus on form and controlled movement, not load.</li></ul><!-- /wp:list --><!-- wp:heading --><h2>Building consistency (weeks 5–12)</h2><!-- /wp:heading --><!-- wp:list --><ul><li>Add resistance bands or light dumbbells.</li><li>2–3 sessions per week, 2–3 sets of 10–12 repetitions.</li><li>Increase load when 12 repetitions become easy across all sets.</li></ul><!-- /wp:list --><!-- wp:heading --><h2>Safety and referral boundaries</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Consult a healthcare professional before starting if you have: uncontrolled hypertension, recent joint surgery, hernia, chronic pain conditions, or any condition that affects balance. Stop any exercise that causes sharp or persistent pain.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p><strong>Medical review note:</strong> This content includes safety screening guidance and contraindications. Medical review is pending before publication.</p><!-- /wp:paragraph -->',
			),
			'LEL-008'     => array(
				'post_type'    => 'post',
				'post_title'   => 'Foods and Dietary Patterns Associated With Healthy Aging',
				'post_name'    => 'foods-dietary-patterns-healthy-aging',
				'post_content' => '<!-- wp:paragraph --><p>Diet is one of the most studied modifiable factors in healthy aging. This guide reviews the dietary patterns with the strongest human evidence and provides an affordable meal-component matrix for practical application.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Dietary patterns with strongest evidence</h2><!-- /wp:heading --><!-- wp:list --><ul><li><strong>Mediterranean diet:</strong> Consistently associated with reduced cardiovascular events, cognitive decline, and all-cause mortality in prospective cohorts and trials.</li><li><strong>DASH diet:</strong> Strong evidence for blood pressure reduction and cardiovascular risk reduction.</li><li><strong>Plant-forward patterns:</strong> High intake of vegetables, fruit, legumes, whole grains, and nuts is consistently associated with better health outcomes.</li></ul><!-- /wp:list --><!-- wp:heading --><h2>Affordable meal-component matrix</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Eating well need not be expensive. Below are affordable components organised by food group, with approximate weekly costs.</p><!-- /wp:paragraph --><!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Food group</th><th>Affordable options</th><th>Estimated weekly cost (single)</th></tr></thead><tbody><tr><td>Vegetables</td><td>Frozen mixed vegetables, tinned tomatoes, seasonal greens</td><td>$8–12</td></tr><tr><td>Fruit</td><td>Bananas, frozen berries, tinned fruit in juice</td><td>$5–8</td></tr><tr><td>Protein</td><td>Eggs, tinned fish, legumes, tofu, bulk chicken</td><td>$12–18</td></tr><tr><td>Whole grains</td><td>Oats, brown rice, whole-wheat pasta, lentils</td><td>$4–7</td></tr><tr><td>Healthy fats</td><td>Olive oil (bulk), nuts (bulk), seeds</td><td>$5–10</td></tr></tbody></table></figure><!-- /wp:table --><!-- wp:paragraph --><p><strong>Medical review note:</strong> This content includes dietary safety considerations. Medical review is pending before publication.</p><!-- /wp:paragraph -->',
			),
		);

		foreach ( $post_blueprints as $key => $blueprint ) {
			$existing_post = get_page_by_path( $blueprint['post_name'], OBJECT, $blueprint['post_type'] );

			if ( $existing_post instanceof \WP_Post ) {
				\WP_CLI::line( "{$blueprint['post_title']} (/{$blueprint['post_name']}/): already exists (ID {$existing_post->ID}, status {$existing_post->post_status})" );
				++$existing;
				continue;
			}

			if ( $dry_run ) {
				\WP_CLI::line( "[DRY RUN] Would create {$blueprint['post_title']} (/{$blueprint['post_name']}/) as draft {$blueprint['post_type']}" );
				++$created;
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_title'   => $blueprint['post_title'],
					'post_name'    => $blueprint['post_name'],
					'post_content' => $blueprint['post_content'],
					'post_status'  => 'draft',
					'post_type'    => $blueprint['post_type'],
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				$errors[] = "Failed to create {$blueprint['post_title']}: " . $post_id->get_error_message();
				\WP_CLI::warning( "Failed to create {$blueprint['post_title']}: " . $post_id->get_error_message() );
				continue;
			}

			\WP_CLI::line( "Created {$blueprint['post_title']} (/{$blueprint['post_name']}/) as draft {$blueprint['post_type']} (ID {$post_id})" );
			++$created;
			update_post_meta( $post_id, '_longevity_noindex', '1' );
		}

		\WP_CLI::success( sprintf( 'Bootstrap complete: %d created, %d existing, %d error(s).', $created, $existing, count( $errors ) ) );
		if ( $errors ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Create editorial roles and capabilities idempotently.
	 *
	 * ## OPTIONS
	 * [--dry-run]     Preview changes without modifying the database.
	 *
	 * ## EXAMPLES
	 *     wp longevity bootstrap roles
	 *     wp longevity bootstrap roles --dry-run
	 */
	public function roles( array $args, array $assoc_args ): void {
		unset( $args );
		$dry_run = isset( $assoc_args['dry-run'] );
		if ( $dry_run ) {
			\WP_CLI::line( '[DRY RUN] Would register editorial roles and assign capabilities.' );
			return;
		}
		Roles::register();
		\WP_CLI::success( 'Editorial roles and capabilities registered.' );
	}

	/**
	 * Run all bootstrap commands in sequence: pages, categories, content.
	 *
	 * ## OPTIONS
	 * [--dry-run]     Preview changes without modifying the database.
	 *
	 * ## EXAMPLES
	 *     wp longevity bootstrap all
	 *     wp longevity bootstrap all --dry-run
	 */
	public function all( array $args, array $assoc_args ): void {
		\WP_CLI::line( '=== Bootstrap: pages ===' );
		$this->pages( $args, $assoc_args );

		\WP_CLI::line( '' );
		\WP_CLI::line( '=== Bootstrap: categories ===' );
		$this->categories( $args, $assoc_args );

		\WP_CLI::line( '' );
		\WP_CLI::line( '=== Bootstrap: content ===' );
		$this->content( $args, $assoc_args );

		\WP_CLI::success( 'Full bootstrap complete.' );
	}
}

/** Bounded freshness audit and operational-status command. */
final class Freshness_Command {
	/** Run the freshness audit or display the latest protected status counts. */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		if ( ! current_user_can( 'approve_publication' ) ) {
			\WP_CLI::error( 'This command requires approve_publication. Run WP-CLI with an authorized --user.' );
		}
		$action = sanitize_key( (string) ( $args[0] ?? 'status' ) );
		if ( 'run' === $action ) {
			$report = Freshness::run();
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			if ( 'failed' === ( $report['status'] ?? '' ) ) {
				\WP_CLI::halt( 1 );
			}
			\WP_CLI::success( 'Freshness audit completed without publishing or rewriting content.' );
			return;
		}
		if ( 'status' !== $action ) {
			\WP_CLI::error( 'Use `wp longevity freshness status` or `wp longevity freshness run`.' );
		}
		\WP_CLI::line( wp_json_encode( Freshness::status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}

/** Database migration command — runs pending migrations under a global lock. */
final class Migrate_Command {
	/**
	 * Run pending governance data migrations.
	 *
	 * Migrations are idempotent, chunked, and resumable. They must be run
	 * before promoting a new release to production traffic.
	 *
	 * ## OPTIONS
	 * [--force]
	 * : Override a stale migration lock.
	 *
	 * [--status]
	 * : Display current migration state without running migrations.
	 *
	 * ## EXAMPLES
	 *     wp longevity migrate
	 *     wp longevity migrate --force
	 *     wp longevity migrate --status
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		if ( isset( $assoc_args['status'] ) ) {
			$current = (int) get_option( 'lel_data_version', 0 );
			$state   = array(
				'current_version' => $current,
				'target_version'  => Migrations::CURRENT_VERSION,
				'pending'         => $current < Migrations::CURRENT_VERSION,
				'last_error'      => Migrations::error_state(),
				'lock'            => get_option( 'lel_migration_lock', null ),
			);
			\WP_CLI::line( wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$force  = isset( $assoc_args['force'] );
		$result = Migrations::run_migrations( $force );

		if ( $result['migrated'] ) {
			\WP_CLI::log( sprintf( 'Migrated version(s): %s', implode( ', ', $result['migrated'] ) ) );
		}

		if ( $result['success'] ) {
			if ( empty( $result['migrated'] ) ) {
				\WP_CLI::success( sprintf( 'Already at version %d. No migrations needed.', Migrations::CURRENT_VERSION ) );
			} else {
				\WP_CLI::success( sprintf( 'Migrations complete. Now at version %d.', Migrations::CURRENT_VERSION ) );
			}
		} else {
			\WP_CLI::error( $result['error'] );
		}
	}
}

/** Structured external evidence management for operators. */
final class Evidence_Command {
	/** @var array<string, string> Valid evidence types mapped to their option keys. */
	private const EVIDENCE_OPTIONS = array(
		'backup'       => 'lel_last_backup_evidence',
		'restore'      => 'lel_last_restore_drill_evidence',
		'mail'         => 'lel_mail_transport_evidence',
	);

	/**
	 * Set structured external readiness evidence.
	 *
	 * ## OPTIONS
	 *
	 * --type=<type>
	 * : Evidence type (backup, restore, mail).
	 *
	 * --result=<result>
	 * : Result value (ok, pass, fail, error).
	 *
	 * [--artifact=<ref>]
	 * : Artifact reference (e.g. backup file path or ID).
	 *
	 * [--performed-at=<datetime>]
	 * : ISO 8601 datetime when the action was performed. Defaults to now.
	 *
	 * [--expires-at=<datetime>]
	 * : ISO 8601 datetime when this evidence expires.
	 *
	 * [--actor=<name>]
	 * : Name or identifier of the operator performing the action.
	 *
	 * ## EXAMPLES
	 *     wp longevity evidence set --type=backup --result=ok --artifact=s3://bucket/backup-2026-07-23.sql.gz --actor=ops-bot
	 *     wp longevity evidence set --type=restore --result=ok --performed-at=2026-07-20T10:00:00Z --expires-at=2026-10-20T10:00:00Z
	 *     wp longevity evidence list
	 *
	 * @subcommand set
	 */
	public function set( array $args, array $assoc_args ): void {
		unset( $args );
		$type   = (string) ( $assoc_args['type'] ?? '' );
		$result = (string) ( $assoc_args['result'] ?? '' );

		if ( ! isset( self::EVIDENCE_OPTIONS[ $type ] ) ) {
			\WP_CLI::error( sprintf( 'Invalid evidence type "%s". Valid types: %s', $type, implode( ', ', array_keys( self::EVIDENCE_OPTIONS ) ) ) );
		}
		if ( ! in_array( $result, array( 'ok', 'pass', 'fail', 'error' ), true ) ) {
			\WP_CLI::error( 'Result must be one of: ok, pass, fail, error.' );
		}

		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$evidence    = array(
			'type'         => $type,
			'result'       => $result,
			'artifact_ref' => sanitize_text_field( (string) ( $assoc_args['artifact'] ?? '' ) ),
			'performed_at' => sanitize_text_field( (string) ( $assoc_args['performed-at'] ?? gmdate( DATE_W3C ) ) ),
			'expires_at'   => sanitize_text_field( (string) ( $assoc_args['expires-at'] ?? '' ) ),
			'actor'        => sanitize_text_field( (string) ( $assoc_args['actor'] ?? '' ) ),
			'environment'  => $environment,
			'release_id'   => sanitize_text_field( (string) ( $assoc_args['release-id'] ?? '' ) ),
		);

		$option = self::EVIDENCE_OPTIONS[ $type ];
		update_option( $option, wp_json_encode( $evidence, JSON_UNESCAPED_SLASHES ), false );

		Audit_Log::record( 'evidence_recorded', 'system', 0, array( 'type' => $type, 'result' => $result ), function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0, 'cli' );
		\WP_CLI::success( sprintf( 'Evidence for "%s" recorded (result: %s).', $type, $result ) );
	}

	/**
	 * List current external evidence state.
	 *
	 * ## EXAMPLES
	 *     wp longevity evidence list
	 *
	 * @subcommand list
	 */
	public function list( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$out = array();
		foreach ( self::EVIDENCE_OPTIONS as $type => $option ) {
			$value   = get_option( $option, '' );
			$decoded = is_string( $value ) ? json_decode( $value, true ) : null;
			$out[ $type ] = is_array( $decoded ) ? $decoded : ( '' !== $value ? array( 'legacy_value' => $value ) : null );
		}
		\WP_CLI::line( wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}

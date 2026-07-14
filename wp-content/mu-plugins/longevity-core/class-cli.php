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
		$file = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';
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
				$rows[] = array( '__line' => $line, '__error' => 'Column count does not match the header.' );
				continue;
			}
			$row = array_combine( $headers, $values );
			$row['__line'] = $line;
			$rows[] = $row;
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
		$posts = get_posts( array( 'post_type' => 'lel_claim', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
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
		$this->require_capability( 'manage_claims' );
		$file = (string) ( $assoc_args['file'] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( 'Provide a readable --file.' );
		}
		list( $headers, $rows ) = $this->read_csv( $file );
		$missing = array_diff( self::FIELDS, $headers );
		if ( $missing ) {
			\WP_CLI::error( 'Missing required columns: ' . implode( ', ', $missing ) );
		}
		$dry_run = isset( $assoc_args['dry-run'] );
		$allow_update = isset( $assoc_args['update'] );
		$created = 0;
		$updated = 0;
		$errors = $this->validate_rows( $rows );
		if ( $errors ) {
			foreach ( $errors as $error ) {
				\WP_CLI::warning( $error );
			}
			\WP_CLI::error( 'Import rejected because validation failed. No rows were changed.' );
		}
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
				$value = 'post_id' === $field ? absint( $row[ $field ] ) : Meta_Registry::sanitize_value( self::rule_for_field( $field ), $row[ $field ] );
				update_post_meta( (int) $post_id, $field, $value );
			}
			$existing ? ++$updated : ++$created;
		}
		\WP_CLI::success( sprintf( '%s: %d create(s), %d update(s).', $dry_run ? 'Dry run valid' : 'Import complete', $created, $updated ) );
	}

	/**
	 * Validate a claims CSV without changing WordPress.
	 *
	 * ## OPTIONS
	 * --file=<path>
	 */
	public function validate( array $args, array $assoc_args ): void {
		unset( $args );
		$this->require_capability( 'manage_claims' );
		$file = (string) ( $assoc_args['file'] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( 'Provide a readable --file.' );
		}
		list( $headers, $rows ) = $this->read_csv( $file );
		$missing = array_diff( self::FIELDS, $headers );
		$errors = $missing ? array( 'Missing columns: ' . implode( ', ', $missing ) ) : $this->validate_rows( $rows );
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
		$seen = array();
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
		$posts = get_posts( array( 'post_type' => 'lel_claim', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => 'claim_id', 'meta_value' => sanitize_key( $claim_id ) ) );
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
		$posts = get_posts( array( 'post_type' => 'lel_source', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
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
		$errors = array();
		$missing = array_diff( self::FIELDS, $headers );
		if ( $missing ) {
			$errors[] = 'Missing columns: ' . implode( ', ', $missing );
		}
		$seen = array();
		foreach ( $rows as $row ) {
			$line = (int) ( $row['__line'] ?? 0 );
			$id = sanitize_key( (string) ( $row['source_id'] ?? '' ) );
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

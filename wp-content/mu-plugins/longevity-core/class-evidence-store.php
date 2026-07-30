<?php
/**
 * Append-only external readiness evidence store.
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

/** Immutable, hash-bound evidence records (append-only; no update/delete). */
final class Evidence_Store {
	public const RESULTS      = array( 'ok', 'pass', 'fail', 'error' );
	public const ENVIRONMENTS = array( 'local', 'development', 'staging', 'production' );

	/**
	 * Registered evidence types and their identity and expiry requirements.
	 *
	 * @var array<string, array{release_scoped: bool, expiry_required: bool}>
	 */
	private const TYPES = array(
		'backup'           => array(
			'release_scoped'  => false,
			'expiry_required' => true,
		),
		'restore'          => array(
			'release_scoped'  => false,
			'expiry_required' => true,
		),
		'mail'             => array(
			'release_scoped'  => false,
			'expiry_required' => true,
		),
		'release-artifact' => array(
			'release_scoped'  => true,
			'expiry_required' => false,
		),
	);

	private const HASHED_FIELDS = array(
		'evidence_type',
		'release_sha',
		'artifact_checksum',
		'result',
		'environment',
		'produced_at',
		'expires_at',
		'payload_json',
		'recorded_by',
		'recorded_at',
	);

	/** Table name. */
	public static function table_name(): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . 'lel_external_evidence' : 'wp_lel_external_evidence';
	}

	/** Install the evidence table (additive, idempotent). */
	public static function install(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'dbDelta' ) ) {
			return;
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$table   = self::table_name();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			evidence_type varchar(64) NOT NULL,
			release_sha varchar(64) DEFAULT NULL,
			artifact_checksum varchar(128) DEFAULT NULL,
			result varchar(16) NOT NULL,
			environment varchar(32) NOT NULL DEFAULT '',
			produced_at datetime NOT NULL,
			expires_at datetime DEFAULT NULL,
			payload_json longtext NOT NULL,
			record_hash char(64) NOT NULL,
			recorded_by bigint(20) unsigned NOT NULL DEFAULT 0,
			recorded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY type_id (evidence_type,id),
			KEY release_sha (release_sha)
		) {$charset};";
		dbDelta( $sql );
	}

	/** Whether the table exists. */
	public static function exists(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$table = self::table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/** Registered evidence types. */
	public static function types(): array {
		return array_keys( self::TYPES );
	}

	/**
	 * Whether a type must carry exact release identity.
	 *
	 * @param string $type Evidence type slug.
	 * @return bool True when the type requires release identity.
	 */
	public static function is_release_scoped( string $type ): bool {
		return ! empty( self::TYPES[ $type ]['release_scoped'] );
	}

	/**
	 * Immutable runtime identity supplied in deployed configuration, never read
	 * from evidence or mutable WordPress options.
	 */
	public static function runtime_release_identity(): array {
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '';
		$source_sha  = defined( 'LEL_RELEASE_SHA' ) ? strtolower( trim( (string) constant( 'LEL_RELEASE_SHA' ) ) ) : '';
		$artifact    = defined( 'LEL_RELEASE_ARTIFACT_SHA256' ) ? strtolower( trim( (string) constant( 'LEL_RELEASE_ARTIFACT_SHA256' ) ) ) : '';

		return array(
			'environment'       => $environment,
			'release_sha'       => $source_sha,
			'artifact_checksum' => $artifact,
			'valid'             => in_array( $environment, self::ENVIRONMENTS, true )
				&& self::is_source_sha( $source_sha )
				&& self::is_sha256( $artifact ),
		);
	}

	/**
	 * Record a new immutable evidence row.
	 *
	 * @param string $type   Evidence type slug.
	 * @param array  $fields Evidence fields and free-form payload values.
	 * @return int|\WP_Error New evidence row ID, or WP_Error on failure.
	 */
	public static function record( string $type, array $fields ) {
		if ( ! isset( self::TYPES[ $type ] ) ) {
			return new \WP_Error( 'evidence_unknown_type', sprintf( 'Unknown evidence type "%s". Valid types: %s', $type, implode( ', ', self::types() ) ) );
		}

		$result = (string) ( $fields['result'] ?? '' );
		if ( ! in_array( $result, self::RESULTS, true ) ) {
			return new \WP_Error( 'evidence_invalid_result', sprintf( 'Result must be one of: %s.', implode( ', ', self::RESULTS ) ) );
		}

		$release_sha = strtolower( sanitize_text_field( (string) ( $fields['release_sha'] ?? $fields['source_sha'] ?? '' ) ) );
		$checksum    = strtolower( sanitize_text_field( (string) ( $fields['artifact_checksum'] ?? '' ) ) );
		if ( self::is_release_scoped( $type ) && ( '' === $release_sha || '' === $checksum ) ) {
			return new \WP_Error( 'evidence_release_identity_required', sprintf( 'Evidence type "%s" requires source SHA and artifact SHA-256.', $type ) );
		}
		if ( '' !== $release_sha && ! self::is_source_sha( $release_sha ) ) {
			return new \WP_Error( 'evidence_invalid_source_sha', 'Source SHA must be a 40- or 64-character hexadecimal commit hash.' );
		}
		if ( '' !== $checksum && ! self::is_sha256( $checksum ) ) {
			return new \WP_Error( 'evidence_invalid_artifact_checksum', 'Artifact checksum must be a 64-character hexadecimal SHA-256.' );
		}

		if ( ! self::exists() ) {
			return new \WP_Error( 'evidence_table_missing', 'Evidence table is not installed.' );
		}

		$environment = trim( (string) ( $fields['environment'] ?? ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '' ) ) );
		if ( ! in_array( $environment, self::ENVIRONMENTS, true ) ) {
			return new \WP_Error( 'evidence_invalid_environment', 'Environment must be local, development, staging, or production.' );
		}

		$produced_ts = self::timestamp( (string) ( $fields['produced_at'] ?? gmdate( DATE_W3C ) ) );
		$expires_raw = trim( (string) ( $fields['expires_at'] ?? '' ) );
		$expires_ts  = '' === $expires_raw ? null : self::timestamp( $expires_raw );
		if ( false === $produced_ts || $produced_ts > time() + 3600 ) {
			return new \WP_Error( 'evidence_invalid_produced_at', 'produced_at must be valid and no more than one hour in the future.' );
		}
		if ( false === $expires_ts || ( is_int( $expires_ts ) && $expires_ts <= $produced_ts ) ) {
			return new \WP_Error( 'evidence_invalid_expiry', 'expires_at must be a valid datetime later than produced_at.' );
		}
		if ( self::TYPES[ $type ]['expiry_required'] && null === $expires_ts ) {
			return new \WP_Error( 'evidence_expiry_required', sprintf( 'Evidence type "%s" requires expires_at.', $type ) );
		}

		$location        = trim( (string) ( $fields['attachment_location'] ?? '' ) );
		$attachment_hash = strtolower( trim( (string) ( $fields['attachment_sha256'] ?? '' ) ) );
		if ( ( '' === $location ) !== ( '' === $attachment_hash ) ) {
			return new \WP_Error( 'evidence_attachment_pair_required', 'attachment_location and attachment_sha256 must be supplied together.' );
		}
		if ( '' !== $attachment_hash && ( ! self::is_sha256( $attachment_hash ) || ! self::attachment_matches( $location, $attachment_hash ) ) ) {
			return new \WP_Error( 'evidence_attachment_mismatch', 'Attachment is missing, unreadable, or does not match attachment_sha256.' );
		}

		$supersedes_id = (int) ( $fields['supersedes_id'] ?? 0 );
		if ( $supersedes_id > 0 ) {
			$prior = self::get( $supersedes_id );
			if ( null === $prior
				|| true !== self::validate_stored_record( $prior )
				|| (string) ( $prior['evidence_type'] ?? '' ) !== $type
				|| (string) ( $prior['environment'] ?? '' ) !== $environment
				|| self::is_superseded( $supersedes_id ) ) {
				return new \WP_Error( 'evidence_invalid_supersession', 'supersedes_id must identify an active earlier record of the same type and environment.' );
			}
		}

		$payload = $fields;
		unset( $payload['result'], $payload['release_sha'], $payload['source_sha'], $payload['artifact_checksum'], $payload['environment'], $payload['produced_at'], $payload['expires_at'] );
		$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $payload_json ) ) {
			return new \WP_Error( 'evidence_payload_invalid', 'Evidence payload could not be encoded as JSON.' );
		}

		$row                = array(
			'evidence_type'     => $type,
			'release_sha'       => $release_sha,
			'artifact_checksum' => $checksum,
			'result'            => $result,
			'environment'       => $environment,
			'produced_at'       => gmdate( 'Y-m-d H:i:s', $produced_ts ),
			'expires_at'        => null === $expires_ts ? null : gmdate( 'Y-m-d H:i:s', $expires_ts ),
			'payload_json'      => $payload_json,
			'recorded_by'       => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
		);
		$row['recorded_at'] = gmdate( 'Y-m-d H:i:s' );
		$row['record_hash'] = self::canonical_hash( $row );

		global $wpdb;
		if ( false === $wpdb->insert( self::table_name(), $row ) ) {
			return new \WP_Error( 'evidence_write_failed', 'Failed to write evidence record.' );
		}
		$id = (int) $wpdb->insert_id;
		try {
			Audit_Log::record(
				'evidence_recorded',
				'external_evidence',
				$id,
				array(
					'type'              => $type,
					'result'            => $result,
					'environment'       => $environment,
					'release_sha'       => $release_sha,
					'artifact_checksum' => $checksum,
					'record_hash'       => $row['record_hash'],
				),
				$row['recorded_by'],
				(string) ( $fields['source_channel'] ?? 'system' ),
				true,
				'evidence:' . $id . ':' . $row['record_hash']
			);
		} catch ( \Throwable $error ) {
			// A record without its mandatory audit link is not evidence.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name derives from the trusted $wpdb->prefix.
			return new \WP_Error( 'evidence_audit_failed', $error->getMessage() );
		}
		return $id;
	}

	/**
	 * Fetch a single evidence record.
	 *
	 * @param int $id Evidence row ID.
	 * @return array|null Stored row, or null when not found.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 || ! self::exists() ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name derives from the trusted $wpdb->prefix.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Newest record for a registered type.
	 *
	 * @param string $type Evidence type slug.
	 * @return array|null Newest stored row, or null when none.
	 */
	public static function latest( string $type ): ?array {
		global $wpdb;
		if ( ! isset( self::TYPES[ $type ] ) || ! self::exists() ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE evidence_type = %s ORDER BY id DESC LIMIT 1', $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name derives from the trusted $wpdb->prefix.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Newest hash-valid, active record matching exact runtime identity fields.
	 *
	 * @param string $type              Evidence type slug.
	 * @param string $environment       Environment the record must match.
	 * @param string $release_sha       Optional source SHA the record must match.
	 * @param string $artifact_checksum Optional artifact SHA-256 the record must match.
	 * @return array|null Newest matching valid row, or null when none.
	 */
	public static function latest_valid_matching( string $type, string $environment, string $release_sha = '', string $artifact_checksum = '' ): ?array {
		global $wpdb;
		if ( ! isset( self::TYPES[ $type ] ) || ! in_array( $environment, self::ENVIRONMENTS, true ) || ! self::exists() ) {
			return null;
		}
		$sql  = 'SELECT * FROM ' . self::table_name() . ' WHERE evidence_type = %s AND environment = %s';
		$args = array( $type, $environment );
		if ( '' !== $release_sha ) {
			$sql   .= ' AND release_sha = %s';
			$args[] = strtolower( $release_sha );
		}
		if ( '' !== $artifact_checksum ) {
			$sql   .= ' AND artifact_checksum = %s';
			$args[] = strtolower( $artifact_checksum );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id DESC', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders assembled into $sql above; values bound via prepare( $args ); table name from trusted $wpdb->prefix.
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id > 0 && true === self::validate_stored_record( $row ) && ! self::is_superseded( $id ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * All records for a type, newest first.
	 *
	 * @param string $type  Evidence type slug.
	 * @param int    $limit Maximum rows to return.
	 * @return array Stored rows, newest first.
	 */
	public static function all( string $type, int $limit = 50 ): array {
		global $wpdb;
		if ( ! isset( self::TYPES[ $type ] ) || ! self::exists() ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE evidence_type = %s ORDER BY id DESC LIMIT %d', $type, max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name derives from the trusted $wpdb->prefix.
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Verify stored structure, record hash, and any local attachment hash.
	 *
	 * @param int $id Evidence row ID.
	 * @return bool True when the stored record validates.
	 */
	public static function verify( int $id ): bool {
		$row = self::get( $id );
		return null !== $row && true === self::validate_stored_record( $row );
	}

	/**
	 * Validate a stored row without deciding whether its result is launch-ready.
	 *
	 * @param array $row Stored evidence row.
	 * @return true|\WP_Error True when valid, or WP_Error describing the failure.
	 */
	public static function validate_stored_record( array $row ) {
		$type        = (string) ( $row['evidence_type'] ?? '' );
		$result      = (string) ( $row['result'] ?? '' );
		$environment = (string) ( $row['environment'] ?? '' );
		if ( ! isset( self::TYPES[ $type ] ) || ! in_array( $result, self::RESULTS, true ) || ! in_array( $environment, self::ENVIRONMENTS, true ) ) {
			return new \WP_Error( 'evidence_invalid_record', 'Stored evidence has an unknown type, result, or environment.' );
		}

		$source_sha = (string) ( $row['release_sha'] ?? '' );
		$checksum   = (string) ( $row['artifact_checksum'] ?? '' );
		if ( ( '' !== $source_sha && ! self::is_source_sha( $source_sha ) )
			|| ( '' !== $checksum && ! self::is_sha256( $checksum ) )
			|| ( self::is_release_scoped( $type ) && ( '' === $source_sha || '' === $checksum ) ) ) {
			return new \WP_Error( 'evidence_invalid_release_identity', 'Stored evidence has malformed or missing release identity.' );
		}

		$produced_ts = self::timestamp( (string) ( $row['produced_at'] ?? '' ) );
		$expires_at  = (string) ( $row['expires_at'] ?? '' );
		$expires_ts  = '' === $expires_at ? null : self::timestamp( $expires_at );
		if ( false === $produced_ts
			|| false === $expires_ts
			|| ( is_int( $expires_ts ) && $expires_ts <= $produced_ts )
			|| ( self::TYPES[ $type ]['expiry_required'] && null === $expires_ts ) ) {
			return new \WP_Error( 'evidence_invalid_record_time', 'Stored evidence has invalid production or expiry time.' );
		}

		$hash = (string) ( $row['record_hash'] ?? '' );
		if ( ! self::is_sha256( $hash ) || ! hash_equals( $hash, self::canonical_hash( $row ) ) ) {
			return new \WP_Error( 'evidence_record_hash_mismatch', 'Stored evidence record hash is malformed or does not match.' );
		}
		if ( ! self::has_audit_link( (int) ( $row['id'] ?? 0 ), $hash ) ) {
			return new \WP_Error( 'evidence_audit_link_missing', 'Stored evidence lacks its mandatory durable audit link.' );
		}

		$payload = self::payload( $row );
		if ( null === $payload ) {
			return new \WP_Error( 'evidence_payload_malformed', 'Stored evidence payload is not valid JSON.' );
		}
		$location        = trim( (string) ( $payload['attachment_location'] ?? '' ) );
		$attachment_hash = strtolower( trim( (string) ( $payload['attachment_sha256'] ?? '' ) ) );
		if ( ( '' === $location ) !== ( '' === $attachment_hash )
			|| ( '' !== $attachment_hash && ( ! self::is_sha256( $attachment_hash ) || ! self::attachment_matches( $location, $attachment_hash ) ) ) ) {
			return new \WP_Error( 'evidence_attachment_invalid', 'Stored evidence attachment is missing or its SHA-256 does not match.' );
		}

		$supersedes_id = (int) ( $payload['supersedes_id'] ?? 0 );
		if ( $supersedes_id > 0 ) {
			$prior = self::get( $supersedes_id );
			if ( null === $prior
				|| $supersedes_id >= (int) ( $row['id'] ?? 0 )
				|| (string) ( $prior['evidence_type'] ?? '' ) !== $type
				|| (string) ( $prior['environment'] ?? '' ) !== $environment
				|| true !== self::validate_stored_record( $prior ) ) {
				return new \WP_Error( 'evidence_supersession_invalid', 'Stored supersession relationship is invalid.' );
			}
		}
		return true;
	}

	/**
	 * Whether a later hash-valid record supersedes this record.
	 *
	 * @param int $id Evidence row ID.
	 * @return bool True when a later valid record supersedes it.
	 */
	public static function is_superseded( int $id ): bool {
		$row = self::get( $id );
		if ( null === $row ) {
			return false;
		}
		foreach ( self::all( (string) $row['evidence_type'], 500 ) as $candidate ) {
			if ( (int) ( $candidate['id'] ?? 0 ) <= $id ) {
				continue;
			}
			$payload = self::payload( $candidate );
			$hash    = (string) ( $candidate['record_hash'] ?? '' );
			if ( is_array( $payload ) && (int) ( $payload['supersedes_id'] ?? 0 ) === $id
				&& self::is_sha256( $hash ) && hash_equals( $hash, self::canonical_hash( $candidate ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compute the tamper-evident hash over the canonical field subset.
	 *
	 * @param array $row Evidence row fields.
	 * @return string SHA-256 hash of the canonical field subset.
	 */
	private static function canonical_hash( array $row ): string {
		$canonical = array();
		foreach ( self::HASHED_FIELDS as $field ) {
			$canonical[ $field ] = (string) ( $row[ $field ] ?? '' );
		}
		ksort( $canonical );
		return hash( 'sha256', (string) wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Verify that the audit log durably links this evidence ID to its exact hash.
	 *
	 * @param int    $id          Evidence row ID.
	 * @param string $record_hash Expected record hash.
	 * @return bool True when a durable audit link exists.
	 */
	private static function has_audit_link( int $id, string $record_hash ): bool {
		if ( $id < 1 || ! Audit_Log::exists() ) {
			return false;
		}
		foreach ( Audit_Log::test_events() as $event ) {
			if ( 'evidence_recorded' === ( $event['event_type'] ?? '' )
				&& 'external_evidence' === ( $event['object_type'] ?? '' )
				&& (int) ( $event['object_id'] ?? 0 ) === $id
				&& hash_equals( $record_hash, (string) ( $event['payload']['record_hash'] ?? '' ) ) ) {
				return true;
			}
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name derives from the trusted $wpdb->prefix.
				'SELECT payload_json FROM ' . Audit_Log::table_name() . ' WHERE event_type = %s AND object_type = %s AND object_id = %d ORDER BY id DESC',
				'evidence_recorded',
				'external_evidence',
				$id
			),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
			if ( is_array( $payload ) && JSON_ERROR_NONE === json_last_error() && hash_equals( $record_hash, (string) ( $payload['record_hash'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Decode a stored payload.
	 *
	 * @param array $row Stored evidence row.
	 * @return array|null Decoded payload, or null when malformed.
	 */
	private static function payload( array $row ): ?array {
		$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
		return is_array( $payload ) && JSON_ERROR_NONE === json_last_error() ? $payload : null;
	}

	/**
	 * Parse a UTC database datetime or an explicitly offset ISO datetime.
	 *
	 * @param string $value Datetime string to parse.
	 * @return int|false Unix timestamp, or false when unparseable.
	 */
	private static function timestamp( string $value ) {
		if ( '' === trim( $value ) ) {
			return false;
		}
		$suffix = preg_match( '/(?:Z|[+-]\d\d:\d\d)$/', $value ) ? '' : ' UTC';
		return strtotime( $value . $suffix );
	}

	/**
	 * Git uses SHA-1 today and can use SHA-256 repositories.
	 *
	 * @param string $value Candidate commit hash.
	 * @return bool True for a 40- or 64-character hexadecimal hash.
	 */
	private static function is_source_sha( string $value ): bool {
		return 1 === preg_match( '/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', $value );
	}

	/**
	 * Validate a SHA-256 hexadecimal digest.
	 *
	 * @param string $value Candidate digest.
	 * @return bool True for a 64-character hexadecimal digest.
	 */
	private static function is_sha256( string $value ): bool {
		return 1 === preg_match( '/\A[a-f0-9]{64}\z/', $value );
	}

	/**
	 * Verify a local attachment now and on every explicit verification.
	 *
	 * @param string $location Local filesystem path of the attachment.
	 * @param string $expected Expected SHA-256 digest.
	 * @return bool True when the file exists and matches the digest.
	 */
	private static function attachment_matches( string $location, string $expected ): bool {
		$scheme = (string) parse_url( $location, PHP_URL_SCHEME );
		if ( ( '' !== $scheme && 1 !== preg_match( '/\A[A-Za-z]:[\\\\\/]/', $location ) ) || ! is_file( $location ) || ! is_readable( $location ) ) {
			return false;
		}
		$actual = hash_file( 'sha256', $location );
		return is_string( $actual ) && hash_equals( $expected, $actual );
	}
}

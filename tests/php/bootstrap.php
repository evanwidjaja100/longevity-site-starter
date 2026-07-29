<?php
/** PHPUnit bootstrap with minimal WordPress stubs for pure service tests. */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
$GLOBALS['wp_version'] = '7.0.1';
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type(): string { return $GLOBALS['lel_test_environment_type'] ?? 'local'; }
}
define( 'LONGEVITY_CORE_PATH', dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/longevity-core/' );
define( 'LONGEVITY_CORE_VERSION', '3.0.0' );
define( 'LONGEVITY_CORE_URL', 'http://example.com/wp-content/mu-plugins/longevity-core/' );

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? '';
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ): string {
		return filter_var( (string) $value, FILTER_VALIDATE_URL ) ? (string) $value : '';
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $string ): string {
		return rtrim( $string, '/\\' );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'http://example.com' . $path;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		if ( isset( $GLOBALS['lel_test_options'] ) && array_key_exists( $option, $GLOBALS['lel_test_options'] ) ) {
			return $GLOBALS['lel_test_options'][ $option ];
		}
		if ( 'page_on_front' === $option ) {
			return $GLOBALS['lel_test_page_on_front'] ?? 0;
		}
		if ( 'page_for_posts' === $option ) {
			return 0;
		}
		if ( 'blog_public' === $option ) {
			return 0;
		}
		if ( 'lel_data_version' === $option ) {
			return \Longevity\Core\Migrations::CURRENT_VERSION;
		}
		return $default;
	}
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $slug, string $output = OBJECT, string $post_type = 'page' ) {
		unset( $output, $post_type );
		return $GLOBALS['lel_test_pages_by_slug'][ $slug ] ?? null;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ): string {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['lel_test_permalinks'][ $id ] ?? 'http://example.com/?p=' . $id;
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( string $field, $value, string $taxonomy = 'category' ) {
		unset( $taxonomy );
		if ( 'slug' === $field && isset( $GLOBALS['lel_test_terms_by_slug'][ $value ] ) ) {
			return $GLOBALS['lel_test_terms_by_slug'][ $value ];
		}
		if ( 'id' === $field && isset( $GLOBALS['lel_test_terms_by_id'][ $value ] ) ) {
			return $GLOBALS['lel_test_terms_by_id'][ $value ];
		}
		return null;
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term, string $taxonomy = '' ): string {
		unset( $taxonomy );
		$id = is_object( $term ) ? (int) $term->term_id : (int) $term;
		return $GLOBALS['lel_test_term_links'][ $id ] ?? 'http://example.com/category/' . $id;
	}
}
if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	function get_post_type_archive_link( string $post_type ): string {
		if ( 'review' === $post_type ) {
			return 'http://example.com/reviews/';
		}
		return 'http://example.com/' . $post_type . '/';
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post ): ?string {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['lel_test_page_statuses'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		unset( $single );
		return $GLOBALS['lel_test_meta'][ $post_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post ): string {
		$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return (string) ( $GLOBALS['lel_test_titles'][ $post_id ] ?? '' );
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	/** @var \wpdb $wpdb */
	$GLOBALS['wpdb'] = new class {
		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		public string $posts = 'wp_posts';
		public string $postmeta = 'wp_postmeta';
		public int $insert_id = 0;
		public string $last_error = '';
		public int $lel_audit_sequence = 0;
		private array $rows = array(
			'wp_lel_approval_snapshots'  => array(),
			'wp_lel_audit_events'        => array(),
			'wp_options'                 => array(),
			'wp_lel_override_intents'    => array(),
			'wp_lel_invalidation_queue'  => array(),
			'wp_lel_dependencies'        => array(),
			'wp_lel_audit_sequence'      => array(),
			'wp_lel_rate_limits'         => array(),
			'wp_lel_notification_outbox' => array(),
			'wp_lel_contact_idempotency' => array(),
			'wp_lel_external_evidence'   => array(),
		);

		/** @var list<string> All SQL passed to query()/get_var()/get_row()/get_col(). */
		public array $lel_query_log = array();

		public function lel_test_rows( string $table ): array {
			return $this->rows[ $table ] ?? array();
		}

		public function lel_test_set_rows( string $table, array $rows ): void {
			$this->rows[ $table ] = $rows;
		}

		public function lel_test_drop_table( string $table ): void {
			unset( $this->rows[ $table ] );
		}

		public function insert( string $table, array $data ) {
			if ( ! empty( $GLOBALS['lel_test_throw_on_insert'] ) ) {
				throw new \RuntimeException( 'Simulated insert exception (test hook).' );
			}
			if ( ! empty( $GLOBALS['lel_test_fail_insert'] ) ) {
				$this->last_error = 'Simulated insert failure (test hook).';
				return false;
			}
			if ( 'wp_lel_audit_events' === $table && ! empty( $data['idempotency_key'] ) ) {
				foreach ( $this->rows[ $table ] ?? array() as $row ) {
					if ( (string) ( $row['idempotency_key'] ?? '' ) === (string) $data['idempotency_key'] ) {
						$this->last_error = 'Duplicate entry for idempotency_key';
						return false;
					}
				}
			}
			$this->insert_id = count( $this->rows[ $table ] ?? array() ) + 1;
			$data['id']      = $this->insert_id;
			$this->rows[ $table ][] = $data;
			return 1;
		}

		public function prepare( string $query, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0]; // Real wpdb accepts all placeholders as one array.
			}
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
				$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function get_results( string $query, string $output = OBJECT ) {
			$this->lel_query_log[] = $query;
			if ( false !== strpos( $query, 'FROM wp_lel_audit_events' ) ) {
				preg_match( '/sequence > (\d+)/', $query, $floor_match );
				preg_match( '/LIMIT (\d+)/', $query, $limit_match );
				$floor = (int) ( $floor_match[1] ?? 0 );
				$limit = (int) ( $limit_match[1] ?? 500 );
				$rows  = array_values( array_filter( $this->rows['wp_lel_audit_events'], static fn( array $row ): bool => (int) $row['sequence'] > $floor ) );
				usort( $rows, static fn( array $a, array $b ): int => (int) $a['sequence'] <=> (int) $b['sequence'] );
				$rows = array_slice( $rows, 0, $limit );
				return ARRAY_A === $output ? $rows : array_map( static fn( array $row ) => (object) $row, $rows );
			}
			if ( false !== strpos( $query, 'FROM wp_lel_dependencies' ) ) {
				preg_match( '/parent_post_id = (\d+)/', $query, $parent_match );
				$rows = array_values( array_filter( $this->rows['wp_lel_dependencies'], static fn( array $row ): bool => ! isset( $parent_match[1] ) || (int) $row['parent_post_id'] === (int) $parent_match[1] ) );
				return ARRAY_A === $output ? $rows : array_map( static fn( array $row ) => (object) $row, $rows );
			}
			if ( false !== strpos( $query, 'FROM wp_lel_external_evidence' ) ) {
				$rows = $this->rows['wp_lel_external_evidence'] ?? array();
				if ( preg_match( "/evidence_type = '([^']+)'/", $query, $type_match ) ) {
					$rows = array_values( array_filter( $rows, static fn( $r ) => (string) ( $r['evidence_type'] ?? '' ) === $type_match[1] ) );
				}
				foreach ( array( 'environment', 'release_sha', 'artifact_checksum' ) as $field ) {
					if ( preg_match( "/{$field} = '([^']+)'/", $query, $match ) ) {
						$rows = array_values( array_filter( $rows, static fn( $r ) => (string) ( $r[ $field ] ?? '' ) === $match[1] ) );
					}
				}
				usort( $rows, static fn( $a, $b ) => (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ) );
				return ARRAY_A === $output ? $rows : array_map( static fn( $r ) => (object) $r, $rows );
			}
			if ( false !== strpos( $query, 'FROM wp_lel_approval_snapshots' ) ) {
				preg_match( '/id > (\d+)/', $query, $floor_match );
				preg_match( '/LIMIT (\d+)/', $query, $limit_match );
				$floor = (int) ( $floor_match[1] ?? 0 );
				$limit = (int) ( $limit_match[1] ?? 500 );
				$rows  = array_values( array_filter(
					$this->rows['wp_lel_approval_snapshots'],
					static fn( array $row ): bool => 'pending_audit' === (string) ( $row['approval_status'] ?? '' ) && (int) ( $row['id'] ?? 0 ) > $floor
				) );
				usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
				$rows = array_slice( $rows, 0, $limit );
				return ARRAY_A === $output ? $rows : array_map( static fn( array $row ) => (object) $row, $rows );
			}
			return array();
		}

		public function get_row( string $query, string $output = OBJECT ) {
			$this->lel_query_log[] = $query;
			if ( false !== strpos( $query, 'FROM wp_lel_audit_events' ) && false !== strpos( $query, 'idempotency_key =' ) ) {
				preg_match( "/idempotency_key = '([^']+)'/", $query, $key_match );
				foreach ( $this->rows['wp_lel_audit_events'] as $row ) {
					if ( isset( $key_match[1] ) && (string) ( $row['idempotency_key'] ?? '' ) === $key_match[1] ) {
						$out = array(
							'id'          => (int) ( $row['id'] ?? 0 ),
							'event_type'  => (string) ( $row['event_type'] ?? '' ),
							'object_type' => (string) ( $row['object_type'] ?? '' ),
							'object_id'   => (int) ( $row['object_id'] ?? 0 ),
						);
						return ARRAY_A === $output ? $out : (object) $out;
					}
				}
				return null;
			}
			if ( false !== strpos( $query, 'FROM wp_lel_external_evidence' ) ) {
				$rows = $this->rows['wp_lel_external_evidence'] ?? array();
				if ( preg_match( '/WHERE id = (\d+)/', $query, $id_match ) ) {
					foreach ( $rows as $row ) {
						if ( (int) ( $row['id'] ?? 0 ) === (int) $id_match[1] ) {
							return ARRAY_A === $output ? $row : (object) $row;
						}
					}
					return null;
				}
				if ( preg_match( "/evidence_type = '([^']+)'/", $query, $type_match ) ) {
					$matched = array_values( array_filter( $rows, static fn( $r ) => (string) ( $r['evidence_type'] ?? '' ) === $type_match[1] ) );
					if ( ! $matched ) {
						return null;
					}
					usort( $matched, static fn( $a, $b ) => (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ) );
					$row = $matched[0];
					return ARRAY_A === $output ? $row : (object) $row;
				}
				return null;
			}
			if ( false !== strpos( $query, 'FROM wp_lel_notification_outbox' ) ) {
				if ( ! isset( $this->rows['wp_lel_notification_outbox'] ) ) {
					$this->last_error = "Table 'wp_lel_notification_outbox' doesn't exist";
					return null;
				}
				preg_match( "/lease_owner = '([^']+)'/", $query, $owner_match );
				foreach ( $this->rows['wp_lel_notification_outbox'] ?? array() as $row ) {
					if ( isset( $owner_match[1] ) && (string) ( $row['lease_owner'] ?? '' ) === $owner_match[1] && 'pending' === ( $row['status'] ?? '' ) ) {
						return ARRAY_A === $output ? $row : (object) $row;
					}
				}
				return null;
			}
			if ( false !== strpos( $query, 'FROM wp_lel_contact_idempotency' ) ) {
				if ( ! isset( $this->rows['wp_lel_contact_idempotency'] ) ) {
					$this->last_error = "Table 'wp_lel_contact_idempotency' doesn't exist";
					return null;
				}
				preg_match( "/request_key_hash = '([^']+)'/", $query, $m );
				foreach ( $this->rows['wp_lel_contact_idempotency'] as $row ) {
					if ( isset( $m[1] ) && (string) ( $row['request_key_hash'] ?? '' ) === $m[1] ) {
						return ARRAY_A === $output ? $row : (object) $row;
					}
				}
				return null;
			}
			if ( false !== strpos( $query, 'FROM wp_lel_invalidation_queue' ) ) {
				preg_match( "/lease_owner = '([^']+)'/", $query, $owner_match );
				foreach ( $this->rows['wp_lel_invalidation_queue'] as $row ) {
					if ( isset( $owner_match[1] ) && (string) ( $row['lease_owner'] ?? '' ) === $owner_match[1] && 'processing' === ( $row['status'] ?? '' ) ) {
						return ARRAY_A === $output ? $row : (object) $row;
					}
				}
				return null;
			}
			preg_match( '/post_id = (\d+)/', $query, $post_match );
			preg_match( "/approval_type = '([^']+)'/", $query, $type_match );
			$rows = array_filter(
				$this->rows['wp_lel_approval_snapshots'],
				static function ( array $row ) use ( $post_match, $type_match ): bool {
					return (int) $row['post_id'] === (int) ( $post_match[1] ?? 0 )
						&& (string) $row['approval_type'] === (string) ( $type_match[1] ?? '')
						&& 'approved' === (string) $row['approval_status']
						&& empty( $row['invalidated_at'] );
				}
			);
			$rows = array_values( $rows );
			$row = $rows ? end( $rows ) : null;
			return $row ? ( ARRAY_A === $output ? $row : (object) $row ) : null;
		}

		public function get_col( string $query ): array {
			$this->lel_query_log[] = $query;
			if ( false !== strpos( $query, 'FROM wp_lel_dependencies' ) ) {
				preg_match( "/dependency_type = '([^']+)'/", $query, $type_match );
				preg_match( '/dependency_id = (\d+)/', $query, $dep_match );
				preg_match( '/dependency_id IN \(([^)]+)\)/', $query, $in_match );
				$dep_ids = array();
				if ( isset( $dep_match[1] ) ) {
					$dep_ids[] = (int) $dep_match[1];
				} elseif ( isset( $in_match[1] ) ) {
					$dep_ids = array_map( 'intval', explode( ',', $in_match[1] ) );
				}
				$out = array();
				foreach ( $this->rows['wp_lel_dependencies'] as $row ) {
					if ( isset( $type_match[1] ) && (string) $row['dependency_type'] !== $type_match[1] ) {
						continue;
					}
					if ( $dep_ids && ! in_array( (int) $row['dependency_id'], $dep_ids, true ) ) {
						continue;
					}
					$out[] = (string) $row['parent_post_id'];
				}
				return array_values( array_unique( $out ) );
			}
			if ( preg_match( "/SELECT ID FROM wp_posts WHERE post_type = 'lel_affiliate'/", $query ) ) {
				$out = array();
				foreach ( $GLOBALS['lel_test_posts'] ?? array() as $id => $post ) {
					if ( is_object( $post ) && 'lel_affiliate' === (string) $post->post_type && ! in_array( (string) $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
						$out[] = (string) $id;
					}
				}
				sort( $out, SORT_NUMERIC );
				return $out;
			}
			// Plain keyset over wp_posts (no postmeta join) for backfill enumeration.
			if ( preg_match( '/SELECT ID FROM wp_posts WHERE/', $query ) && false === strpos( $query, 'INNER JOIN' ) ) {
				preg_match( '/post_type IN \(([^)]+)\)/', $query, $type_match );
				preg_match( '/ID > (\d+)/', $query, $floor_match );
				preg_match( '/LIMIT (\d+)/', $query, $limit_match );
				$types = isset( $type_match[1] )
					? array_map( static fn( string $part ): string => trim( $part, " '" ), explode( ',', $type_match[1] ) )
					: array();
				$floor = (int) ( $floor_match[1] ?? 0 );
				$limit = (int) ( $limit_match[1] ?? 200 );
				$posts = $GLOBALS['lel_test_posts'] ?? array();
				ksort( $posts );
				$out = array();
				foreach ( $posts as $id => $post ) {
					if ( (int) $id <= $floor || ! is_object( $post ) ) {
						continue;
					}
					if ( $types && ! in_array( (string) $post->post_type, $types, true ) ) {
						continue;
					}
					$out[] = (string) $id;
					if ( count( $out ) >= $limit ) {
						break;
					}
				}
				return $out;
			}
			// Simulates Governed_Query keyset SQL against lel_test_posts/lel_test_meta.
			if ( preg_match( '/SELECT p\.ID FROM wp_posts p INNER JOIN wp_postmeta pm/', $query ) ) {
				preg_match( "/pm\.meta_key = '([^']+)'/", $query, $key_match );
				preg_match( "/pm\.meta_value = '([^']*)'/", $query, $value_match );
				preg_match( "/p\.post_type IN \(([^)]+)\)/", $query, $type_match );
				preg_match( "/p\.post_status IN \(([^)]+)\)/", $query, $status_match );
				preg_match( '/p\.ID > (\d+)/', $query, $floor_match );
				preg_match( '/LIMIT (\d+)/', $query, $limit_match );
				$parse_list = static function ( string $csv ): array {
					return array_map( static fn( string $part ): string => trim( $part, " '" ), explode( ',', $csv ) );
				};
				$types    = isset( $type_match[1] ) ? $parse_list( $type_match[1] ) : array();
				$statuses = isset( $status_match[1] ) ? $parse_list( $status_match[1] ) : null;
				$floor    = (int) ( $floor_match[1] ?? 0 );
				$limit    = (int) ( $limit_match[1] ?? 200 );
				$posts    = $GLOBALS['lel_test_posts'] ?? array();
				ksort( $posts );
				$out = array();
				foreach ( $posts as $id => $post ) {
					if ( (int) $id <= $floor || ! is_object( $post ) ) {
						continue;
					}
					if ( $types && ! in_array( (string) $post->post_type, $types, true ) ) {
						continue;
					}
					if ( null !== $statuses && ! in_array( (string) ( $post->post_status ?? '' ), $statuses, true ) ) {
						continue;
					}
					$meta = (string) ( $GLOBALS['lel_test_meta'][ $id ][ $key_match[1] ?? '' ] ?? '' );
					if ( $meta !== (string) ( $value_match[1] ?? '' ) ) {
						continue;
					}
					$out[] = (string) $id;
					if ( count( $out ) >= $limit ) {
						break;
					}
				}
				return $out;
			}
			return array();
		}

		public function get_var( string $query ) {
			$this->lel_query_log[] = $query;
			if ( 'SELECT LAST_INSERT_ID()' === trim( $query ) ) {
				return $this->lel_audit_sequence;
			}
			if ( false !== strpos( $query, 'information_schema.COLUMNS' ) ) {
				if ( preg_match( "/COLUMN_NAME = '([^']+)'/", $query, $column_match ) && ( $GLOBALS['lel_test_missing_schema_column'] ?? '' ) === $column_match[1] ) {
					return '0';
				}
				return '1';
			}
			if ( false !== strpos( $query, 'information_schema.STATISTICS' ) && preg_match( "/INDEX_NAME = '([^']+)'/", $query, $index_match ) && ( $GLOBALS['lel_test_missing_schema_index'] ?? '' ) === $index_match[1] ) {
				return '0';
			}
			if ( false !== strpos( $query, 'information_schema.STATISTICS' ) && false === strpos( $query, 'uniq_open_parent' ) ) {
				return '1';
			}
			if ( false !== strpos( $query, 'SELECT VERSION()' ) ) {
				return '8.0.40';
			}
			if ( false !== strpos( $query, 'SELECT @@default_storage_engine' ) ) {
				return 'InnoDB';
			}
			if ( false !== strpos( $query, 'SELECT @@character_set_database' ) ) {
				return 'utf8mb4';
			}
			if ( false !== strpos( $query, 'FROM wp_lel_audit_events' ) && false !== strpos( $query, 'idempotency_key =' ) ) {
				preg_match( "/idempotency_key = '([^']+)'/", $query, $key_match );
				foreach ( $this->rows['wp_lel_audit_events'] as $row ) {
					if ( (string) ( $row['idempotency_key'] ?? '' ) === (string) ( $key_match[1] ?? '' ) ) {
						return (string) $row['id'];
					}
				}
				return null;
			}
			if ( false !== strpos( $query, 'FROM wp_lel_approval_snapshots' ) && false !== strpos( $query, 'COUNT' ) ) {
				$count = 0;
				foreach ( $this->rows['wp_lel_approval_snapshots'] as $row ) {
					$status = (string) ( $row['approval_status'] ?? '' );
					if ( false !== strpos( $query, "approval_status = 'approved'" ) ) {
						if ( 'approved' === $status && empty( $row['invalidated_at'] ) && null === ( $row['audit_event_id'] ?? null ) && null === ( $row['activation_error'] ?? null ) ) {
							++$count;
						}
						continue;
					}
					if ( false !== strpos( $query, "approval_status = 'pending_audit'" ) ) {
						if ( 'pending_audit' !== $status ) {
							continue;
						}
						if ( preg_match( "/approved_at < '([^']+)'/", $query, $stale_match ) ) {
							if ( strtotime( (string) ( $row['approved_at'] ?? '' ) . ' UTC' ) < strtotime( $stale_match[1] . ' UTC' ) ) {
								++$count;
							}
							continue;
						}
						++$count;
					}
				}
				return (string) $count;
			}
			if ( false !== strpos( $query, 'FROM wp_lel_invalidation_queue' ) ) {
				if ( false !== strpos( $query, 'TIMESTAMPDIFF' ) ) {
					foreach ( $this->rows['wp_lel_invalidation_queue'] as $row ) {
						if ( 'pending' === ( $row['status'] ?? '' ) ) {
							return (string) max( 0, time() - strtotime( ( $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ) . ' UTC' ) );
						}
					}
					return null;
				}
				if ( preg_match( "/status = '([a-z]+)'/", $query, $status_match ) ) {
					$count = 0;
					foreach ( $this->rows['wp_lel_invalidation_queue'] as $row ) {
						if ( ( $row['status'] ?? '' ) === $status_match[1] ) {
							++$count;
						}
					}
					if ( false !== strpos( $query, 'SELECT COUNT(*)' ) ) {
						return (string) $count;
					}
					// SELECT id ... LIMIT 1 style existence probes.
					foreach ( $this->rows['wp_lel_invalidation_queue'] as $row ) {
						if ( ( $row['status'] ?? '' ) === $status_match[1] ) {
							preg_match( '/parent_post_id = (\d+)/', $query, $parent_match );
							if ( ! isset( $parent_match[1] ) || (int) ( $row['parent_post_id'] ?? 0 ) === (int) $parent_match[1] ) {
								return (string) ( $row['id'] ?? 1 );
							}
						}
					}
					return null;
				}
			}
			if ( false !== strpos( $query, 'information_schema.STATISTICS' ) && false !== strpos( $query, 'uniq_open_parent' ) ) {
				return empty( $GLOBALS['lel_test_open_uniqueness_missing'] ) ? '1' : '0';
			}
			if ( false !== strpos( $query, 'information_schema.COLUMNS' ) && ( false !== strpos( $query, 'audit_event_id' ) || false !== strpos( $query, 'idempotency_key' ) ) ) {
				return '1';
			}
			if ( false !== strpos( $query, 'information_schema.STATISTICS' ) && false !== strpos( $query, 'idempotency_key' ) ) {
				return '1';
			}
			if ( false !== strpos( $query, 'GET_LOCK' ) ) {
				if ( array_key_exists( 'lel_test_data_version_on_lock', $GLOBALS ) ) {
					$GLOBALS['lel_test_options']['lel_data_version'] = $GLOBALS['lel_test_data_version_on_lock'];
				}
				// Configurable per test: '1' acquired, '0' contended, null error.
				if ( array_key_exists( 'lel_test_get_lock_result', $GLOBALS ) ) {
					return $GLOBALS['lel_test_get_lock_result'];
				}
				return '1';
			}
			if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
				$GLOBALS['lel_test_release_lock_calls'] = ( $GLOBALS['lel_test_release_lock_calls'] ?? 0 ) + 1;
				return '1';
			}
			if ( false !== strpos( $query, 'SELECT 1' ) ) {
				return '1';
			}
			if ( false !== strpos( $query, 'FROM wp_lel_rate_limits' ) ) {
				if ( ! isset( $this->rows['wp_lel_rate_limits'] ) ) {
					$this->last_error = "Table 'wp_lel_rate_limits' doesn't exist";
					return null;
				}
				$this->last_error = '';
				preg_match( "/rate_key = '([^']+)'/", $query, $key_match );
				foreach ( $this->rows['wp_lel_rate_limits'] as $row ) {
					if ( (string) ( $row['rate_key'] ?? '' ) === (string) ( $key_match[1] ?? '' ) && strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' ) >= time() ) {
						return (string) ( $row['hit_count'] ?? 0 );
					}
				}
				return null;
			}
			if ( false !== strpos( $query, 'FROM wp_lel_notification_outbox' ) ) {
				if ( ! isset( $this->rows['wp_lel_notification_outbox'] ) ) {
					$this->last_error = "Table 'wp_lel_notification_outbox' doesn't exist";
					return null;
				}
				if ( preg_match( "/status = '([a-z_]+)'/", $query, $status_match ) ) {
					$count = 0;
					foreach ( $this->rows['wp_lel_notification_outbox'] as $row ) {
						if ( ( $row['status'] ?? '' ) === $status_match[1] ) {
							++$count;
						}
					}
					return (string) $count;
				}
				return null;
			}
			if ( false !== strpos( $query, 'FROM wp_lel_contact_idempotency' ) ) {
				if ( ! isset( $this->rows['wp_lel_contact_idempotency'] ) ) {
					$this->last_error = "Table 'wp_lel_contact_idempotency' doesn't exist";
					return null;
				}
				if ( preg_match( "/state = '([a-z_]+)'/", $query, $sm ) ) {
					$stuck  = false;
					$cutoff = 0;
					if ( preg_match( "/lease_expires_at < '([^']+)'/", $query, $cm ) ) {
						$stuck  = true;
						$cutoff = strtotime( $cm[1] . ' UTC' );
					}
					$count = 0;
					foreach ( $this->rows['wp_lel_contact_idempotency'] as $row ) {
						if ( (string) ( $row['state'] ?? '' ) !== $sm[1] ) {
							continue;
						}
						if ( $stuck ) {
							if ( empty( $row['lease_expires_at'] ) ) {
								continue;
							}
							if ( strtotime( (string) $row['lease_expires_at'] . ' UTC' ) >= $cutoff ) {
								continue;
							}
						}
						++$count;
					}
					return (string) $count;
				}
				return '0';
			}
			if ( preg_match( '/SHOW TABLES LIKE [\'\"]?([^\'\" ]+)/', $query, $match ) ) {
				return isset( $this->rows[ $match[1] ] ) ? $match[1] : null;
			}
			if ( false !== strpos( $query, 'SELECT event_hash' ) ) {
				$rows = $this->rows['wp_lel_audit_events'];
				$row  = $rows ? end( $rows ) : null;
				return $row['event_hash'] ?? '';
			}
			return 0;
		}

		public function query( string $query ) {
			$this->lel_query_log[] = $query;
			$trimmed = trim( $query );
			if ( 0 === strcasecmp( $trimmed, 'COMMIT' ) && ! empty( $GLOBALS['lel_test_fail_commit'] ) ) {
				$this->last_error = 'Simulated commit failure (test hook).';
				return false;
			}
			if ( preg_match( '/^UPDATE wp_lel_audit_sequence SET current_value = LAST_INSERT_ID\(/i', $trimmed ) ) {
				if ( ! empty( $GLOBALS['lel_test_fail_audit_sequence'] ) ) {
					$this->last_error = 'Simulated audit sequence failure (test hook).';
					return false;
				}
				++$this->lel_audit_sequence;
				return 1;
			}
			if ( preg_match( '/^INSERT IGNORE INTO wp_lel_audit_sequence\b/i', $trimmed ) ) {
				return 1;
			}
			if ( preg_match( '/^INSERT INTO wp_lel_rate_limits\b/i', $trimmed ) ) {
				if ( ! isset( $this->rows['wp_lel_rate_limits'] ) ) {
					$this->last_error = "Table 'wp_lel_rate_limits' doesn't exist";
					return false;
				}
				if ( ! empty( $GLOBALS['lel_test_fail_rate_insert'] ) ) {
					$this->last_error = 'Simulated rate insert failure (test hook).';
					return false;
				}
				$this->last_error = '';
				preg_match( "/VALUES \('([^']+)', 1, '([^']+)'\)/", $trimmed, $vals );
				$key     = (string) ( $vals[1] ?? '' );
				$expires = (string) ( $vals[2] ?? gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
				foreach ( $this->rows['wp_lel_rate_limits'] as &$row ) {
					if ( (string) ( $row['rate_key'] ?? '' ) === $key ) {
						if ( strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' ) < time() ) {
							$row['hit_count']  = 1;
							$row['expires_at'] = $expires;
						} else {
							$row['hit_count'] = (int) ( $row['hit_count'] ?? 0 ) + 1;
						}
						unset( $row );
						return 2;
					}
				}
				unset( $row );
				$this->rows['wp_lel_rate_limits'][] = array( 'rate_key' => $key, 'hit_count' => 1, 'expires_at' => $expires );
				return 1;
			}
			if ( preg_match( '/^INSERT INTO wp_lel_invalidation_queue\b/i', $trimmed ) ) {
				if ( ! empty( $GLOBALS['lel_test_fail_queue_insert'] ) ) {
					$this->last_error = 'Simulated queue insert failure (test hook).';
					return false;
				}
				preg_match( '/VALUES \((\d+), \'((?:[^\'\\\\]|\\\\.)*)\', (\d+)/', $trimmed, $vals );
				$parent = (int) ( $vals[1] ?? 0 );
				if ( false !== stripos( $trimmed, 'ON DUPLICATE KEY UPDATE' ) ) {
					foreach ( $this->rows['wp_lel_invalidation_queue'] as $row ) {
						if ( (int) ( $row['parent_post_id'] ?? 0 ) === $parent && ! empty( $row['open_marker'] ) ) {
							return 0; // Unique key uniq_open_parent: duplicate open row is a no-op.
						}
					}
				}
				$this->insert_id = count( $this->rows['wp_lel_invalidation_queue'] ) + 1;
				$this->rows['wp_lel_invalidation_queue'][] = array(
					'id'               => $this->insert_id,
					'parent_post_id'   => $parent,
					'reason'           => stripslashes( (string) ( $vals[2] ?? '' ) ),
					'actor_id'         => (int) ( $vals[3] ?? 0 ),
					'status'           => 'pending',
					'retry_count'      => 0,
					'open_marker'      => 1,
					'lease_owner'      => null,
					'lease_expires_at' => null,
					'created_at'       => gmdate( 'Y-m-d H:i:s' ),
					'processed_at'     => null,
					'last_error'       => null,
				);
				return 1;
			}
			if ( preg_match( '/^(DELETE FROM|INSERT IGNORE INTO) wp_lel_dependencies\b/i', $trimmed ) && ! empty( $GLOBALS['lel_test_fail_dependency_write'] ) ) {
				$this->last_error = 'Simulated dependency write failure (test hook).';
				return false;
			}
			if ( preg_match( '/^UPDATE wp_lel_notification_outbox\b/i', $trimmed ) ) {
				if ( ! isset( $this->rows['wp_lel_notification_outbox'] ) ) {
					$this->last_error = "Table 'wp_lel_notification_outbox' doesn't exist";
					return false;
				}
				// Atomic claim: SET lease_owner ... WHERE status = 'pending' AND (no live lease) LIMIT 1.
				if ( false === strpos( $trimmed, 'WHERE id =' ) && false !== strpos( $trimmed, 'SET lease_owner =' ) ) {
					preg_match( "/SET lease_owner = '([^']+)'/", $trimmed, $owner_match );
					usort( $this->rows['wp_lel_notification_outbox'], static fn( $a, $b ) => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
					foreach ( $this->rows['wp_lel_notification_outbox'] as &$row ) {
						if ( 'pending' !== ( $row['status'] ?? '' ) ) {
							continue;
						}
						$live_lease = ! empty( $row['lease_owner'] ) && ! empty( $row['lease_expires_at'] ) && strtotime( $row['lease_expires_at'] . ' UTC' ) >= time();
						if ( $live_lease ) {
							continue;
						}
						$row['lease_owner']      = $owner_match[1] ?? '';
						$row['lease_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + 120 );
						unset( $row );
						return 1;
					}
					unset( $row );
					return 0;
				}
				// Terminal/retry updates by id, guarded by lease owner.
				preg_match( '/WHERE id = (\d+)/', $trimmed, $id_match );
				preg_match( "/AND lease_owner = '([^']+)'/", $trimmed, $owner_match );
				preg_match( "/SET status = '([a-z_]+)'/", $trimmed, $status_match );
				preg_match( '/attempts = (\d+)/', $trimmed, $attempts_match );
				preg_match( "/last_error = '((?:[^'\\\\]|\\\\.)*)'/", $trimmed, $err_match );
				foreach ( $this->rows['wp_lel_notification_outbox'] as &$row ) {
					if ( (int) ( $row['id'] ?? 0 ) !== (int) ( $id_match[1] ?? -1 ) ) {
						continue;
					}
					if ( isset( $owner_match[1] ) && (string) ( $row['lease_owner'] ?? '' ) !== $owner_match[1] ) {
						continue;
					}
					if ( isset( $status_match[1] ) ) {
						$row['status'] = $status_match[1];
					}
					if ( isset( $attempts_match[1] ) ) {
						$row['attempts'] = (int) $attempts_match[1];
					}
					if ( isset( $err_match[1] ) ) {
						$row['last_error'] = stripslashes( $err_match[1] );
					}
					if ( false !== strpos( $trimmed, 'sent_at = UTC_TIMESTAMP()' ) ) {
						$row['sent_at'] = gmdate( 'Y-m-d H:i:s' );
					}
					if ( false !== strpos( $trimmed, 'lease_owner = NULL' ) ) {
						$row['lease_owner']      = null;
						$row['lease_expires_at'] = null;
					}
					unset( $row );
					return 1;
				}
				unset( $row );
				return 0;
			}
			if ( preg_match( '/^UPDATE wp_lel_invalidation_queue\b/i', $trimmed ) ) {
				// Atomic claim: UPDATE ... SET status = 'processing', lease_owner ... WHERE (pending OR expired lease) ORDER BY id LIMIT 1.
				if ( false !== strpos( $trimmed, "SET status = 'processing'" ) && false !== strpos( $trimmed, 'lease_owner' ) && false === strpos( $trimmed, 'WHERE id =' ) ) {
					preg_match( "/lease_owner = '([^']+)'/", $trimmed, $owner_match );
					usort( $this->rows['wp_lel_invalidation_queue'], static fn( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
					foreach ( $this->rows['wp_lel_invalidation_queue'] as &$row ) {
						$expired = 'processing' === ( $row['status'] ?? '' ) && ! empty( $row['lease_expires_at'] ) && strtotime( $row['lease_expires_at'] . ' UTC' ) < time();
						if ( 'pending' === ( $row['status'] ?? '' ) || $expired ) {
							$row['status']           = 'processing';
							$row['lease_owner']      = $owner_match[1] ?? '';
							$row['lease_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + 120 );
							unset( $row );
							return 1;
						}
					}
					unset( $row );
					return 0;
				}
				// Terminal/retry updates by id (+ lease_owner guard when present).
				if ( ! empty( $GLOBALS['lel_test_fail_queue_transition'] ) && false !== strpos( $trimmed, 'WHERE id =' ) ) {
					$this->last_error = 'Simulated queue transition failure (test hook).';
					return false;
				}
				preg_match( '/WHERE id = (\d+)/', $trimmed, $id_match );
				preg_match( "/AND lease_owner = '([^']+)'/", $trimmed, $owner_match );
				preg_match( "/SET status = '([a-z]+)'/", $trimmed, $status_match );
				preg_match( '/retry_count = (\d+)/', $trimmed, $retry_match );
				preg_match( '/INTERVAL (\d+) SECOND/', $trimmed, $interval_match );
				preg_match( '/audit_event_id = (\d+)/', $trimmed, $audit_match );
				preg_match( "/last_error = '((?:[^'\\\\]|\\\\.)*)'/", $trimmed, $err_match );
				$count = 0;
				foreach ( $this->rows['wp_lel_invalidation_queue'] as &$row ) {
					if ( (int) ( $row['id'] ?? 0 ) !== (int) ( $id_match[1] ?? -1 ) ) {
						continue;
					}
					if ( isset( $owner_match[1] ) && (string) ( $row['lease_owner'] ?? '' ) !== $owner_match[1] ) {
						continue;
					}
					if ( isset( $status_match[1] ) ) {
						$row['status'] = $status_match[1];
					}
					if ( isset( $retry_match[1] ) ) {
						$row['retry_count'] = (int) $retry_match[1];
					}
					if ( isset( $interval_match[1] ) ) {
						$row['lease_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + (int) $interval_match[1] );
					}
					if ( isset( $audit_match[1] ) ) {
						$row['audit_event_id'] = (int) $audit_match[1];
					}
					if ( isset( $err_match[1] ) ) {
						$row['last_error'] = stripslashes( $err_match[1] );
					}
					if ( false !== strpos( $trimmed, 'open_marker = NULL' ) ) {
						$row['open_marker'] = null;
					}
					if ( false !== strpos( $trimmed, 'lease_owner = NULL' ) ) {
						$row['lease_owner']      = null;
						$row['lease_expires_at'] = null;
					}
					if ( false !== strpos( $trimmed, 'processed_at = NOW()' ) || false !== strpos( $trimmed, 'processed_at = UTC_TIMESTAMP()' ) ) {
						$row['processed_at'] = gmdate( 'Y-m-d H:i:s' );
					}
					++$count;
				}
				unset( $row );
				return $count;
			}
			if ( preg_match( '/^DELETE FROM wp_lel_dependencies\b/i', $trimmed ) ) {
				preg_match( "/dependency_type = '([^']+)'/", $trimmed, $type_match );
				preg_match( '/parent_post_id = (\d+)/', $trimmed, $parent_match );
				$before = count( $this->rows['wp_lel_dependencies'] );
				$this->rows['wp_lel_dependencies'] = array_values( array_filter(
					$this->rows['wp_lel_dependencies'],
					static function ( array $row ) use ( $type_match, $parent_match ): bool {
						if ( isset( $type_match[1] ) && (string) $row['dependency_type'] !== $type_match[1] ) {
							return true;
						}
						if ( isset( $parent_match[1] ) && (int) $row['parent_post_id'] !== (int) $parent_match[1] ) {
							return true;
						}
						return false;
					}
				) );
				return $before - count( $this->rows['wp_lel_dependencies'] );
			}
			if ( preg_match( '/^INSERT IGNORE INTO wp_lel_dependencies\b/i', $trimmed ) ) {
				if ( ! empty( $GLOBALS['lel_test_fail_dependency_insert'] ) ) {
					$this->last_error = 'Simulated dependency insert failure (test hook).';
					return false;
				}
				preg_match( "/VALUES \('([^']+)', (\d+), (\d+)/", $trimmed, $vals );
				$new = array(
					'dependency_type' => (string) ( $vals[1] ?? '' ),
					'dependency_id'   => (int) ( $vals[2] ?? 0 ),
					'parent_post_id'  => (int) ( $vals[3] ?? 0 ),
				);
				foreach ( $this->rows['wp_lel_dependencies'] as $row ) {
					if ( $row['dependency_type'] === $new['dependency_type'] && $row['dependency_id'] === $new['dependency_id'] && $row['parent_post_id'] === $new['parent_post_id'] ) {
						return 0;
					}
				}
				$this->rows['wp_lel_dependencies'][] = $new;
				return 1;
			}
			if ( preg_match( '/^INSERT INTO wp_lel_contact_idempotency\b/i', $trimmed ) ) {
				if ( ! isset( $this->rows['wp_lel_contact_idempotency'] ) ) {
					$this->last_error = "Table 'wp_lel_contact_idempotency' doesn't exist";
					return false;
				}
				if ( ! empty( $GLOBALS['lel_test_fail_idem_insert'] ) ) {
					$this->last_error = 'Simulated idempotency insert failure (test hook).';
					return false;
				}
				preg_match( "/VALUES \('([^']+)', '([^']+)', (NULL|\d+), (NULL|'[^']+'), '([^']+)', '([^']+)', (NULL|'[^']+'), (\d+)\)/", $trimmed, $v );
				$key = (string) ( $v[1] ?? '' );
				foreach ( $this->rows['wp_lel_contact_idempotency'] as $row ) {
					if ( (string) ( $row['request_key_hash'] ?? '' ) === $key ) {
						$this->last_error = 'Duplicate entry for request_key_hash';
						return false;
					}
				}
				$this->last_error = '';
				$this->insert_id  = count( $this->rows['wp_lel_contact_idempotency'] ) + 1;
				$this->rows['wp_lel_contact_idempotency'][] = array(
					'id'               => $this->insert_id,
					'request_key_hash' => $key,
					'state'            => (string) ( $v[2] ?? 'processing' ),
					'message_post_id'  => ( isset( $v[3] ) && 'NULL' !== $v[3] ) ? (int) $v[3] : null,
					'lease_expires_at' => ( isset( $v[4] ) && 'NULL' !== $v[4] ) ? trim( $v[4], "'" ) : null,
					'created_at'       => (string) ( $v[5] ?? gmdate( 'Y-m-d H:i:s' ) ),
					'updated_at'       => (string) ( $v[6] ?? gmdate( 'Y-m-d H:i:s' ) ),
					'completed_at'     => ( isset( $v[7] ) && 'NULL' !== $v[7] ) ? trim( $v[7], "'" ) : null,
					'schema_version'   => (int) ( $v[8] ?? 1 ),
				);
				return 1;
			}
			if ( preg_match( '/^UPDATE wp_lel_contact_idempotency\b/i', $trimmed ) ) {
				if ( ! isset( $this->rows['wp_lel_contact_idempotency'] ) ) {
					$this->last_error = "Table 'wp_lel_contact_idempotency' doesn't exist";
					return false;
				}
				if ( ! empty( $GLOBALS['lel_test_fail_idem_update'] ) ) {
					$this->last_error = 'Simulated idempotency update failure (test hook).';
					return false;
				}
				preg_match( "/request_key_hash = '([^']+)'/", $trimmed, $key_match );
				$key        = (string) ( $key_match[1] ?? '' );
				$now        = time();
				$is_reclaim = false !== strpos( $trimmed, "SET state = 'processing'" );
				$new_state  = '';
				if ( preg_match( "/SET state = '([a-z_]+)'/", $trimmed, $sm ) ) {
					$new_state = $sm[1];
				}
				$count = 0;
				foreach ( $this->rows['wp_lel_contact_idempotency'] as &$row ) {
					if ( (string) ( $row['request_key_hash'] ?? '' ) !== $key ) {
						continue;
					}
					$state = (string) ( $row['state'] ?? '' );
					if ( $is_reclaim ) {
						$expired = 'processing' === $state && ! empty( $row['lease_expires_at'] ) && strtotime( (string) $row['lease_expires_at'] . ' UTC' ) < $now;
						if ( ! ( $expired || 'failed' === $state ) ) {
							continue;
						}
					} elseif ( 'processing' !== $state ) {
						continue;
					}
					if ( '' !== $new_state ) {
						$row['state'] = $new_state;
					}
					if ( false !== strpos( $trimmed, 'message_post_id = NULL' ) ) {
						$row['message_post_id'] = null;
					} elseif ( preg_match( '/message_post_id = (\d+)/', $trimmed, $pm ) ) {
						$row['message_post_id'] = (int) $pm[1];
					}
					if ( false !== strpos( $trimmed, 'lease_expires_at = NULL' ) ) {
						$row['lease_expires_at'] = null;
					} elseif ( preg_match( "/lease_expires_at = '([^']+)'/", $trimmed, $lm ) ) {
						$row['lease_expires_at'] = $lm[1];
					}
					if ( preg_match( "/completed_at = '([^']+)'/", $trimmed, $cm ) ) {
						$row['completed_at'] = $cm[1];
					}
					$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );
					++$count;
				}
				unset( $row );
				return $count;
			}
			if ( preg_match( '/^DELETE FROM wp_lel_contact_idempotency\b/i', $trimmed ) ) {
				if ( ! isset( $this->rows['wp_lel_contact_idempotency'] ) ) {
					$this->last_error = "Table 'wp_lel_contact_idempotency' doesn't exist";
					return false;
				}
				preg_match( "/updated_at < '([^']+)'/", $trimmed, $cm );
				$cutoff = isset( $cm[1] ) ? strtotime( $cm[1] . ' UTC' ) : 0;
				$before = count( $this->rows['wp_lel_contact_idempotency'] );
				$this->rows['wp_lel_contact_idempotency'] = array_values( array_filter(
					$this->rows['wp_lel_contact_idempotency'],
					static function ( array $row ) use ( $cutoff ): bool {
						$terminal = in_array( (string) ( $row['state'] ?? '' ), array( 'completed', 'failed' ), true );
						$old      = ! empty( $row['updated_at'] ) && strtotime( (string) $row['updated_at'] . ' UTC' ) < $cutoff;
						return ! ( $terminal && $old );
					}
				) );
				return $before - count( $this->rows['wp_lel_contact_idempotency'] );
			}
			if ( 0 === stripos( trim( $query ), 'UPDATE wp_lel_override_intents' ) ) {
				preg_match( "/request_id = '([^']+)'/", $query, $rid_match );
				preg_match( "/SET state = '([a-z]+)'/", $query, $set_match );
				preg_match( '/post_id = (\d+)/', $query, $post_match );
				preg_match( "/fingerprint = '([^']*)'/", $query, $fp_match );
				$require_authorized = false !== strpos( $query, "state = 'authorized'" );
				$require_unexpired  = false !== strpos( $query, 'expires_at > UTC_TIMESTAMP()' );
				$count              = 0;
				foreach ( $this->rows['wp_lel_override_intents'] as &$row ) {
					if ( (string) ( $row['request_id'] ?? '' ) !== (string) ( $rid_match[1] ?? '' ) ) {
						continue;
					}
					if ( isset( $post_match[1] ) && (int) ( $row['post_id'] ?? 0 ) !== (int) $post_match[1] ) {
						continue;
					}
					if ( isset( $fp_match[1] ) && (string) ( $row['fingerprint'] ?? '' ) !== $fp_match[1] ) {
						continue;
					}
					if ( $require_authorized && 'authorized' !== (string) ( $row['state'] ?? '' ) ) {
						continue;
					}
					if ( $require_unexpired && strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' ) <= time() ) {
						continue;
					}
					$row['state'] = $set_match[1] ?? (string) ( $row['state'] ?? '' );
					if ( false !== strpos( $query, 'consumed_at' ) ) {
						$row['consumed_at'] = gmdate( 'Y-m-d H:i:s' );
					}
					++$count;
				}
				unset( $row );
				return $count;
			}
			if ( 0 === stripos( trim( $query ), 'UPDATE wp_lel_approval_snapshots' ) ) {
				if ( preg_match( '/WHERE id = (\d+)/', $query, $id_match ) ) {
					$id       = (int) $id_match[1];
					$activate = false !== strpos( $query, "SET approval_status = 'approved'" );
					$reject   = false !== strpos( $query, "SET approval_status = 'rejected'" );
					if ( $activate && ! empty( $GLOBALS['lel_test_fail_activation'] ) ) {
						return 0;
					}
					preg_match( '/audit_event_id = (\d+)/', $query, $audit_match );
					preg_match( "/combined_hash = '([^']+)'/", $query, $hash_match );
					preg_match( "/activation_error = '((?:[^'\\\\]|\\\\.)*)'/", $query, $err_match );
					$count = 0;
					foreach ( $this->rows['wp_lel_approval_snapshots'] as &$row ) {
						if ( (int) ( $row['id'] ?? 0 ) !== $id || 'pending_audit' !== (string) ( $row['approval_status'] ?? '' ) ) {
							continue;
						}
						if ( $activate && isset( $hash_match[1] ) && (string) ( $row['combined_hash'] ?? '' ) !== $hash_match[1] ) {
							continue;
						}
						if ( $activate ) {
							$row['approval_status']  = 'approved';
							$row['audit_event_id']   = (int) ( $audit_match[1] ?? 0 );
							$row['activated_at']     = '2026-07-28 00:00:00';
							$row['activation_error'] = null;
						} elseif ( $reject ) {
							$row['approval_status']  = 'rejected';
							$row['activation_error'] = isset( $err_match[1] ) ? stripslashes( $err_match[1] ) : null;
						} else {
							$row['activation_error'] = isset( $err_match[1] ) ? stripslashes( $err_match[1] ) : null;
						}
						++$count;
					}
					unset( $row );
					return $count;
				}
				preg_match( '/post_id = (\d+)/', $query, $post_match );
				preg_match( "/approval_type = '([^']+)'/", $query, $type_match );
				$count = 0;
				foreach ( $this->rows['wp_lel_approval_snapshots'] as &$row ) {
					if ( (int) $row['post_id'] === (int) ( $post_match[1] ?? 0 ) && (string) $row['approval_type'] === (string) ( $type_match[1] ?? '' ) && empty( $row['invalidated_at'] ) ) {
						$row['invalidated_at'] = '2026-07-22 00:00:00';
						++$count;
					}
				}
				unset( $row );
				return $count;
			}
			if ( preg_match( '/^DELETE FROM wp_lel_external_evidence WHERE id = (\d+)/i', trim( $query ), $match ) ) {
				if ( ! empty( $GLOBALS['lel_test_fail_evidence_cleanup'] ) ) {
					return false;
				}
				$before = count( $this->rows['wp_lel_external_evidence'] );
				$this->rows['wp_lel_external_evidence'] = array_values( array_filter( $this->rows['wp_lel_external_evidence'], static fn( array $row ): bool => (int) $row['id'] !== (int) $match[1] ) );
				return $before - count( $this->rows['wp_lel_external_evidence'] );
			}
			return 0;
		}
	};
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post ): int {
		$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return (int) ( $GLOBALS['lel_test_thumbnails'][ $post_id ] ?? 0 );
	}
}

require_once LONGEVITY_CORE_PATH . 'class-gate-result.php';
require_once LONGEVITY_CORE_PATH . 'class-date-validator.php';
require_once LONGEVITY_CORE_PATH . 'class-runtime-config.php';
require_once LONGEVITY_CORE_PATH . 'class-routes.php';
require_once LONGEVITY_CORE_PATH . 'class-seo.php';
require_once LONGEVITY_CORE_PATH . 'class-review-methodology.php';
require_once LONGEVITY_CORE_PATH . 'class-meta-registry.php';
require_once LONGEVITY_CORE_PATH . 'class-roles.php';
require_once LONGEVITY_CORE_PATH . 'class-legal-hold.php';
require_once LONGEVITY_CORE_PATH . 'class-governed-query.php';
require_once LONGEVITY_CORE_PATH . 'class-meta-authorization.php';
require_once LONGEVITY_CORE_PATH . 'class-reviewer-credentials.php';
require_once LONGEVITY_CORE_PATH . 'class-approval-fingerprint.php';
require_once LONGEVITY_CORE_PATH . 'class-approval-repository.php';
require_once LONGEVITY_CORE_PATH . 'class-publication-lock.php';
require_once LONGEVITY_CORE_PATH . 'class-advisory-lock.php';
require_once LONGEVITY_CORE_PATH . 'class-platform-requirements.php';
require_once LONGEVITY_CORE_PATH . 'class-audit-log.php';
require_once LONGEVITY_CORE_PATH . 'class-override-intent.php';
require_once LONGEVITY_CORE_PATH . 'class-approval-service.php';
require_once LONGEVITY_CORE_PATH . 'class-claims.php';
require_once LONGEVITY_CORE_PATH . 'class-affiliate-registry.php';
require_once LONGEVITY_CORE_PATH . 'class-publication-gates.php';
require_once LONGEVITY_CORE_PATH . 'class-rankings.php';
require_once LONGEVITY_CORE_PATH . 'class-public-contact.php';
require_once LONGEVITY_CORE_PATH . 'class-public-content.php';
require_once LONGEVITY_CORE_PATH . 'class-public-nav.php';
require_once LONGEVITY_CORE_PATH . 'class-public-trust.php';
require_once LONGEVITY_CORE_PATH . 'class-trust-pages.php';
require_once LONGEVITY_CORE_PATH . 'class-public-rankings.php';
require_once LONGEVITY_CORE_PATH . 'class-admin-ui.php';
require_once LONGEVITY_CORE_PATH . 'class-content-discovery.php';
require_once LONGEVITY_CORE_PATH . 'class-rest-api.php';
require_once LONGEVITY_CORE_PATH . 'class-corrections.php';
require_once LONGEVITY_CORE_PATH . 'class-logger.php';
require_once LONGEVITY_CORE_PATH . 'class-migrations.php';
require_once LONGEVITY_CORE_PATH . 'class-freshness-repository.php';
require_once LONGEVITY_CORE_PATH . 'class-freshness.php';
require_once LONGEVITY_CORE_PATH . 'class-publication-lock.php';
require_once LONGEVITY_CORE_PATH . 'class-dependency-index.php';
require_once LONGEVITY_CORE_PATH . 'class-invalidation-queue.php';
require_once LONGEVITY_CORE_PATH . 'class-notification-outbox.php';
require_once LONGEVITY_CORE_PATH . 'class-contact-idempotency.php';
require_once LONGEVITY_CORE_PATH . 'class-evidence-store.php';
require_once LONGEVITY_CORE_PATH . 'class-system-readiness.php';
require_once LONGEVITY_CORE_PATH . 'class-metrics.php';

// Enable Audit_Log test mode: record() returns positive ID without DB writes.
Longevity\Core\Audit_Log::set_test_mode( true );


// --- Additional WP function stubs for test files ---

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return $url;
	}
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string {
		unset( $domain );
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = '' ): string {
		unset( $domain );
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_unique_id' ) ) {
	function wp_unique_id( string $prefix = '' ): string {
		static $id = 0;
		return $prefix . ++$id;
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( ...$args ): void {
		$GLOBALS['lel_test_enqueued_scripts'][] = $args;
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '' ): string {
		return 'test_nonce_' . $action;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'http://example.com/wp-json/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return $response;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = array() ): void {
		$GLOBALS['lel_test_rest_routes'][] = compact( 'namespace', 'route', 'args' );
	}
}
if ( ! function_exists( 'gmdate' ) ) {
	function gmdate( string $format, ?int $timestamp = null ): string {
		$ts = $timestamp ?? time();
		return date( $format, $ts ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		$values = array( 'name' => 'Longevity Evidence Lab', 'description' => 'Evidence-based health guidance' );
		return $values[ $show ] ?? '';
	}
}
if ( ! function_exists( 'has_shortcode' ) ) {
	function has_shortcode( string $content, string $tag ): bool {
		return false !== strpos( $content, '[' . $tag );
	}
}
if ( ! function_exists( 'get_shortcode_regex' ) ) {
	function get_shortcode_regex( array $tagnames = array() ): string {
		$tagregexp = ! empty( $tagnames ) ? implode( '|', array_map( 'preg_quote', $tagnames ) ) : '[a-zA-Z_]+';
		return '\\[(\\[?)(' . $tagregexp . ')(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)(?:(\\/))?\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?(\\]?)';
	}
}
if ( ! function_exists( 'shortcode_parse_atts' ) ) {
	function shortcode_parse_atts( string $text ): array {
		$atts = array();
		if ( preg_match_all( '/(\w+)\s*=\s*"([^"]*)"/', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$atts[ $match[1] ] = $match[2];
			}
		}
		return $atts;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : ( is_array( $value ) ? array_map( 'wp_unslash', $value ) : $value );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $content ): string {
		return $content;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['lel_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		if ( ! empty( $GLOBALS['lel_test_fail_set_transient'] ) ) {
			return false;
		}
		$GLOBALS['lel_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['lel_test_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook ) {
		return $GLOBALS['lel_test_scheduled'][ $hook ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		$GLOBALS['lel_test_scheduled'][ $hook ] = $timestamp;
		$GLOBALS['lel_test_scheduled_events'][] = array( 'hook' => $hook, 'timestamp' => $timestamp, 'single' => true );
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		$GLOBALS['lel_test_scheduled'][ $hook ] = $timestamp;
		$GLOBALS['lel_test_scheduled_events'][] = array( 'hook' => $hook, 'timestamp' => $timestamp, 'single' => false );
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$args ): bool {
		$caps = $GLOBALS['lel_test_current_user_caps'] ?? array();
		$check = in_array( $capability, $caps, true );
		if ( ! $check && 'edit_post' === $capability && ! empty( $args ) ) {
			return isset( $GLOBALS['lel_test_editable_posts'] ) && in_array( (int) $args[0], $GLOBALS['lel_test_editable_posts'], true );
		}
		return $check;
	}
}
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post ): bool {
		return $GLOBALS['lel_test_is_revision'] ?? false;
	}
}
if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $post ): bool {
		return $GLOBALS['lel_test_is_autosave'] ?? false;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): bool {
		return $nonce === 'test_nonce_' . $action || $nonce === 'valid_nonce';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, $meta_value ): bool {
		if ( in_array( $meta_key, (array) ( $GLOBALS['lel_test_fail_meta_updates'] ?? array() ), true ) ) {
			return false;
		}
		if ( ! isset( $GLOBALS['lel_test_meta'][ $post_id ] ) ) {
			$GLOBALS['lel_test_meta'][ $post_id ] = array();
		}
		$GLOBALS['lel_test_meta'][ $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return $GLOBALS['lel_test_is_admin'] ?? false;
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, int $decimals = 0 ): string {
		return number_format( (float) $number, $decimals, '.', '' );
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action, string $name = '_wpnonce', bool $referer = true, bool $echo = true ): string {
		$field = '<input type="hidden" id="' . $name . '" name="' . $name . '" value="test_nonce_' . $action . '" />';
		if ( $echo ) {
			echo $field;
		}
		return $field;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = '' ): string {
		unset( $domain );
		return 1 === $number ? $single : $plural;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post ) {
		if ( is_object( $post ) ) {
			return $post;
		}
		$id = (int) $post;
		return $GLOBALS['lel_test_posts'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, $post ): string {
		$post_obj = get_post( $post );
		if ( ! $post_obj || ! isset( $post_obj->$field ) ) {
			return '';
		}
		return (string) $post_obj->$field;
	}
}


if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, bool $autoload = false ): bool {
		if ( 'lel_data_version' === $option && ! empty( $GLOBALS['lel_test_fail_data_version_update'] ) ) {
			return false;
		}
		$GLOBALS['lel_test_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( $GLOBALS['lel_test_options'][ $option ] );
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, $value = '', string $autoload = 'yes' ): bool {
		$GLOBALS['lel_test_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_installing' ) ) {
	function wp_installing(): bool { return false; }
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ): string { return (string) json_encode( $value, $flags, $depth ); }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int { return (int) ( $GLOBALS['lel_test_current_user_id'] ?? 0 ); }
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( int $user_id, string $capability, ...$args ): bool {
		unset( $args );
		return in_array( $capability, $GLOBALS['lel_test_user_caps'][ $user_id ] ?? array(), true );
	}
}
if ( ! class_exists( 'WP_Role' ) ) {
	/** Real-shaped WP_Role stub: only name/capabilities/has_cap/add_cap/remove_cap exist, exactly like core. */
	class WP_Role {
		public string $name;
		/** @var array<string, bool> */
		public array $capabilities;

		public function __construct( string $role, array $capabilities = array() ) {
			$this->name         = $role;
			$this->capabilities = $capabilities;
		}

		public function has_cap( string $cap ): bool {
			return ! empty( $this->capabilities[ $cap ] );
		}

		public function add_cap( string $cap, bool $grant = true ): void {
			$this->capabilities[ $cap ] = $grant;
		}

		public function remove_cap( string $cap ): void {
			unset( $this->capabilities[ $cap ] );
		}
	}
}
if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ): ?WP_Role {
		return $GLOBALS['lel_test_roles'][ $role ] ?? null;
	}
}
if ( ! function_exists( 'add_role' ) ) {
	function add_role( string $role, string $display_name, array $capabilities = array() ): ?WP_Role {
		unset( $display_name );
		if ( isset( $GLOBALS['lel_test_roles'][ $role ] ) ) {
			return null;
		}
		$GLOBALS['lel_test_roles'][ $role ] = new WP_Role( $role, $capabilities );
		return $GLOBALS['lel_test_roles'][ $role ];
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key, bool $single = false ) { unset( $single ); return $GLOBALS['lel_test_user_meta'][ $user_id ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( int $user_id, string $key, $value ): bool { $GLOBALS['lel_test_user_meta'][ $user_id ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( int $user_id, string $key ): bool { unset( $GLOBALS['lel_test_user_meta'][ $user_id ][ $key ] ); return true; }
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) { return $GLOBALS['lel_test_users'][ $user_id ] ?? (object) array( 'ID' => $user_id, 'display_name' => 'User ' . $user_id ); }
}
if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ) {
		$meta = $GLOBALS['lel_test_user_meta'] ?? array();
		$ids  = array_map( 'intval', array_keys( $meta ) );
		sort( $ids, SORT_NUMERIC );

		$clauses = array();
		if ( isset( $args['meta_key'] ) ) {
			$clauses[] = array(
				'key'     => (string) $args['meta_key'],
				'value'   => $args['meta_value'] ?? '',
				'compare' => (string) ( $args['meta_compare'] ?? '=' ),
				'type'    => (string) ( $args['meta_type'] ?? 'CHAR' ),
			);
		}
		$relation = 'AND';
		if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
			foreach ( $args['meta_query'] as $key => $clause ) {
				if ( 'relation' === $key ) {
					$relation = 'OR' === strtoupper( (string) $clause ) ? 'OR' : 'AND';
					continue;
				}
				if ( is_array( $clause ) && isset( $clause['key'] ) ) {
					$clauses[] = array(
						'key'     => (string) $clause['key'],
						'value'   => $clause['value'] ?? '',
						'compare' => (string) ( $clause['compare'] ?? '=' ),
						'type'    => (string) ( $clause['type'] ?? 'CHAR' ),
					);
				}
			}
		}

		$is_date  = static fn( string $v ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v );
		$evaluate = static function ( int $id ) use ( $meta, $clauses, $relation, $is_date ): bool {
			if ( empty( $clauses ) ) {
				return true;
			}
			$results = array();
			foreach ( $clauses as $clause ) {
				$stored  = $meta[ $id ][ $clause['key'] ] ?? null;
				$compare = strtoupper( (string) $clause['compare'] );
				if ( 'EXISTS' === $compare ) {
					$results[] = null !== $stored && '' !== $stored;
					continue;
				}
				if ( 'NOT EXISTS' === $compare ) {
					$results[] = null === $stored || '' === $stored;
					continue;
				}
				if ( null === $stored ) {
					$results[] = false;
					continue;
				}
				$left  = (string) $stored;
				$right = (string) $clause['value'];
				if ( 'DATE' === strtoupper( (string) $clause['type'] )
					&& in_array( $compare, array( '<', '<=', '>', '>=' ), true )
					&& ( ! $is_date( $left ) || ! $is_date( $right ) ) ) {
					$results[] = false;
					continue;
				}
				switch ( $compare ) {
					case '!=':
						$results[] = $left !== $right;
						break;
					case '<':
						$results[] = strcmp( $left, $right ) < 0;
						break;
					case '<=':
						$results[] = strcmp( $left, $right ) <= 0;
						break;
					case '>':
						$results[] = strcmp( $left, $right ) > 0;
						break;
					case '>=':
						$results[] = strcmp( $left, $right ) >= 0;
						break;
					default:
						$results[] = $left === $right;
						break;
				}
			}
			return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
		};

		$matched = array_values( array_filter( $ids, $evaluate ) );
		if ( isset( $args['order'] ) && 'DESC' === strtoupper( (string) $args['order'] ) ) {
			rsort( $matched, SORT_NUMERIC );
		}
		$number = (int) ( $args['number'] ?? 0 );
		if ( $number > 0 ) {
			$matched = array_slice( $matched, 0, $number );
		}
		if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
			return $matched;
		}
		return array_map( static fn( int $id ) => get_userdata( $id ), $matched );
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post ): string { $obj = get_post( $post ); return $obj ? (string) $obj->post_type : (string) ( $GLOBALS['lel_test_post_types'][ (int) $post ] ?? '' ); }
}
if ( ! function_exists( 'register_rest_field' ) ) {
	function register_rest_field( string $post_type, string $field, array $args ): void { $GLOBALS['lel_test_rest_fields'][] = compact( 'post_type', 'field', 'args' ); }
}
if ( ! function_exists( 'register_post_meta' ) ) {
	function register_post_meta( string $post_type, string $meta_key, array $args = array() ): void {
		$GLOBALS['lel_test_registered_meta'][ $post_type ][ $meta_key ] = $args;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key ): bool {
		if ( in_array( $key, (array) ( $GLOBALS['lel_test_fail_meta_deletes'] ?? array() ), true ) ) {
			return false;
		}
		unset( $GLOBALS['lel_test_meta'][ $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {
		unset( $meta_type );
		return isset( $GLOBALS['lel_test_meta'][ $object_id ] ) && array_key_exists( $meta_key, $GLOBALS['lel_test_meta'][ $object_id ] );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string { return 'test-salt-' . $scheme; }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ): string {
		return (string) filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? (string) $email : false;
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	/** Records the call, then throws instead of exiting so tests can assert on it. */
	function wp_die( $message = '', $title = '', $args = array() ) {
		$GLOBALS['lel_test_wp_die'] = array( 'message' => (string) $message, 'title' => (string) $title, 'args' => (array) $args );
		throw new \RuntimeException( 'lel_test_wp_die' );
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/** Records the redirect, then throws so the following `exit` is never reached in tests. */
	function wp_safe_redirect( string $location, int $status = 302 ) {
		$GLOBALS['lel_test_redirects'][] = array( 'location' => $location, 'status' => $status );
		throw new \RuntimeException( 'lel_test_redirect' );
	}
}
if ( ! function_exists( 'has_action' ) ) {
	function has_action( string $hook, $callback = false ) {
		unset( $callback );
		return ! empty( $GLOBALS['lel_test_has_action'][ $hook ] );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false ) {
		if ( ! empty( $GLOBALS['lel_test_fail_wp_insert_post'] ) ) {
			return $wp_error ? new WP_Error( 'db_insert_error', 'Simulated post insert failure (test hook).' ) : 0;
		}
		$posts = $GLOBALS['lel_test_posts'] ?? array();
		$id    = $posts ? max( array_map( 'intval', array_keys( $posts ) ) ) + 1 : 1;
		$post  = new WP_Post();
		$post->ID           = $id;
		$post->post_type    = (string) ( $postarr['post_type'] ?? 'post' );
		$post->post_status  = (string) ( $postarr['post_status'] ?? 'draft' );
		$post->post_title   = (string) ( $postarr['post_title'] ?? '' );
		$post->post_content = (string) ( $postarr['post_content'] ?? '' );
		$GLOBALS['lel_test_posts'][ $id ] = $post;
		foreach ( (array) ( $postarr['meta_input'] ?? array() ) as $meta_key => $meta_value ) {
			if ( empty( $GLOBALS['lel_test_drop_meta_keys'] ) || ! in_array( $meta_key, (array) $GLOBALS['lel_test_drop_meta_keys'], true ) ) {
				update_post_meta( $id, (string) $meta_key, $meta_value );
			}
		}
		return $id;
	}
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( int $post_id, bool $force_delete = false ) {
		unset( $force_delete );
		$post = $GLOBALS['lel_test_posts'][ $post_id ] ?? null;
		unset( $GLOBALS['lel_test_posts'][ $post_id ], $GLOBALS['lel_test_meta'][ $post_id ] );
		$GLOBALS['lel_test_deleted_posts'][] = $post_id;
		return $post;
	}
}
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, string $subject, string $message, $headers = array() ): bool {
		$GLOBALS['lel_test_mails'][] = compact( 'to', 'subject', 'message', 'headers' );
		return empty( $GLOBALS['lel_test_fail_wp_mail'] );
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000001'; }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( ?bool $upload_bases = null, ?bool $create_dir = null, ?bool $refresh_cache = null ): array {
		return array( 'basedir' => '/tmp/wp-content/uploads', 'baseurl' => 'http://example.com/wp-content/uploads', 'path' => '/tmp/wp-content/uploads', 'url' => 'http://example.com/wp-content/uploads', 'error' => false );
	}
}

// --- Minimal WP class stubs ---

if ( ! defined( 'PHP_URL_HOST' ) ) {
	define( 'PHP_URL_HOST', 1 );
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		$parts = parse_url( $url );
		if ( -1 !== $component ) {
			return $parts[ $component ] ?? null;
		}
		return $parts;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = array() ): array {
		$GLOBALS['lel_test_last_get_posts_args'] = $args;
		if ( array_key_exists( 'lel_test_get_posts_result', $GLOBALS ) ) {
			return $GLOBALS['lel_test_get_posts_result'];
		}
		// Real-behavior simulation: honors post_type, meta filter, meta_query,
		// and posts_per_page exactly like WordPress (a 200 cap really truncates).
		$types      = array_map( 'strval', (array) ( $args['post_type'] ?? array( 'post' ) ) );
		$limit      = (int) ( $args['posts_per_page'] ?? 5 );
		$meta_key   = isset( $args['meta_key'] ) ? (string) $args['meta_key'] : null;
		$meta_value = isset( $args['meta_value'] ) ? (string) $args['meta_value'] : null;

		// Optional single-level meta_query with AND/OR relation.
		$clauses  = array();
		$relation = 'AND';
		if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
			foreach ( $args['meta_query'] as $mq_key => $clause ) {
				if ( 'relation' === $mq_key ) {
					$relation = 'OR' === strtoupper( (string) $clause ) ? 'OR' : 'AND';
					continue;
				}
				if ( is_array( $clause ) && isset( $clause['key'] ) ) {
					$clauses[] = array(
						'key'     => (string) $clause['key'],
						'value'   => $clause['value'] ?? '',
						'compare' => strtoupper( (string) ( $clause['compare'] ?? '=' ) ),
						'type'    => strtoupper( (string) ( $clause['type'] ?? 'CHAR' ) ),
					);
				}
			}
		}
		$is_datetime = static fn( string $v ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v );
		$is_date     = static fn( string $v ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v );
		$eval_meta   = static function ( int $id ) use ( $clauses, $relation, $is_datetime, $is_date ): bool {
			if ( empty( $clauses ) ) {
				return true;
			}
			$results = array();
			foreach ( $clauses as $clause ) {
				$stored  = $GLOBALS['lel_test_meta'][ $id ][ $clause['key'] ] ?? null;
				$compare = $clause['compare'];
				if ( 'EXISTS' === $compare ) {
					$results[] = null !== $stored && '' !== $stored;
					continue;
				}
				if ( 'NOT EXISTS' === $compare ) {
					$results[] = null === $stored || '' === $stored;
					continue;
				}
				if ( null === $stored ) {
					$results[] = false;
					continue;
				}
				$left  = (string) $stored;
				$right = (string) $clause['value'];
				if ( in_array( $compare, array( '<', '<=', '>', '>=' ), true ) ) {
					if ( 'DATETIME' === $clause['type'] && ( ! $is_datetime( $left ) || ! $is_datetime( $right ) ) ) {
						$results[] = false;
						continue;
					}
					if ( 'DATE' === $clause['type'] && ( ! $is_date( $left ) || ! $is_date( $right ) ) ) {
						$results[] = false;
						continue;
					}
				}
				switch ( $compare ) {
					case '!=':
						$results[] = $left !== $right;
						break;
					case '<':
						$results[] = strcmp( $left, $right ) < 0;
						break;
					case '<=':
						$results[] = strcmp( $left, $right ) <= 0;
						break;
					case '>':
						$results[] = strcmp( $left, $right ) > 0;
						break;
					case '>=':
						$results[] = strcmp( $left, $right ) >= 0;
						break;
					default:
						$results[] = $left === $right;
						break;
				}
			}
			return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
		};

		$posts = $GLOBALS['lel_test_posts'] ?? array();
		ksort( $posts );
		$matched = array();
		foreach ( $posts as $id => $post ) {
			if ( ! is_object( $post ) || ! in_array( (string) $post->post_type, $types, true ) ) {
				continue;
			}
			if ( null !== $meta_key && (string) ( $GLOBALS['lel_test_meta'][ $id ][ $meta_key ] ?? '' ) !== $meta_value ) {
				continue;
			}
			if ( ! $eval_meta( (int) $id ) ) {
				continue;
			}
			$matched[] = $post;
			if ( $limit > 0 && count( $matched ) >= $limit ) {
				break;
			}
		}
		if ( 'ids' === ( $args['fields'] ?? '' ) ) {
			return array_map( static fn( $p ) => (int) $p->ID, $matched );
		}
		return $matched;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $tag, $callback, $priority, $accepted_args );
		return true;
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $tag, $callback, int $priority = 10 ): bool {
		unset( $tag, $callback, $priority );
		return true;
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, string $group = '', bool $force = false, &$found = null ) {
		unset( $key, $group, $force );
		$found = false;
		return false;
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, string $group = '', int $expire = 0 ): bool {
		unset( $key, $value, $group, $expire );
		return true;
	}
}
if ( ! function_exists( 'get_the_category' ) ) {
	function get_the_category( int $post_id = 0 ): array {
		return $GLOBALS['lel_test_post_categories'][ $post_id ] ?? array();
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $query_vars = array();
		public int $found_posts = 0;
		public bool $_is_main_query = true;
		public bool $_is_search = false;
		public bool $_is_category = false;
		public bool $_is_tag = false;
		public bool $_is_author = false;
		public bool $_is_home = false;

		public function is_main_query(): bool { return $this->_is_main_query; }
		public function is_search(): bool { return $this->_is_search; }
		public function is_category(): bool { return $this->_is_category; }
		public function is_tag(): bool { return $this->_is_tag; }
		public function is_author(): bool { return $this->_is_author; }
		public function is_home(): bool { return $this->_is_home; }
		public function set( string $key, $value ): void { $this->query_vars[ $key ] = $value; }
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'post';
		public string $post_title = '';
		public string $post_excerpt = '';
		public string $post_content = '';
		public string $post_author = '0';
		public string $post_status = 'draft';
		public string $post_date_gmt = '';
	}
}

if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
	/**
	 * Minimal core-shaped HTML tag processor stub: next_tag() by tag name and
	 * get_attribute() with quoted/unquoted/boolean attributes, mirroring the
	 * subset of core behavior first-party code relies on. Set
	 * $GLOBALS['lel_test_html_processor_throws'] to simulate parser failure.
	 */
	class WP_HTML_Tag_Processor {
		private string $html;
		private int $offset = 0;
		/** @var array<string, string|true> */
		private array $attributes = array();

		public function __construct( string $html ) {
			$this->html = $html;
		}

		public function next_tag( $query = null ): bool {
			if ( ! empty( $GLOBALS['lel_test_html_processor_throws'] ) ) {
				throw new \RuntimeException( 'Simulated HTML parser failure (test mode).' );
			}
			$target = null;
			if ( is_string( $query ) && '' !== $query ) {
				$target = strtolower( $query );
			} elseif ( is_array( $query ) && isset( $query['tag_name'] ) ) {
				$target = strtolower( (string) $query['tag_name'] );
			}
			while ( preg_match( '/<([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $this->html, $m, PREG_OFFSET_CAPTURE, $this->offset ) ) {
				$this->offset = (int) $m[0][1] + strlen( $m[0][0] );
				$name = strtolower( $m[1][0] );
				if ( null !== $target && $name !== $target ) {
					continue;
				}
				$this->attributes = array();
				if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\/>]+)))?/', (string) $m[2][0], $attrs, PREG_SET_ORDER ) ) {
					foreach ( $attrs as $attr ) {
						$attr_name = strtolower( $attr[1] );
						if ( isset( $this->attributes[ $attr_name ] ) ) {
							continue;
						}
						if ( isset( $attr[4] ) && '' !== $attr[4] ) {
							$this->attributes[ $attr_name ] = html_entity_decode( $attr[4], ENT_QUOTES );
						} elseif ( isset( $attr[3] ) && '' !== $attr[3] ) {
							$this->attributes[ $attr_name ] = html_entity_decode( $attr[3], ENT_QUOTES );
						} elseif ( isset( $attr[2] ) && '' !== $attr[2] ) {
							$this->attributes[ $attr_name ] = html_entity_decode( $attr[2], ENT_QUOTES );
						} elseif ( isset( $attr[0] ) && false !== strpos( $attr[0], '=' ) ) {
							$this->attributes[ $attr_name ] = '';
						} else {
							$this->attributes[ $attr_name ] = true;
						}
					}
				}
				return true;
			}
			return false;
		}

		/** @return string|true|null */
		public function get_attribute( string $name ) {
			return $this->attributes[ strtolower( $name ) ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private $status;
		private array $headers = array();
		public function __construct( $data = null, int $status = 200 ) {
			$this->data = $data;
			$this->status = $status;
		}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
		public function header( string $key, string $value ): void { $this->headers[ $key ] = $value; }
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE = 'GET';
		const CREATABLE = 'POST';
		const EDITABLE = 'PUT';
		const DELETABLE = 'DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = array();
		private array $headers = array();
		private string $body = '';
		public string $method = 'GET';
		public string $route = '';
		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route = $route;
		}
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}
		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}
		public function get_header( string $key ) {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}
		public function set_body( string $body ): void {
			$this->body = $body;
		}
		public function get_body(): string {
			return $this->body;
		}
		public function get_json_params() {
			$decoded = json_decode( $this->body, true );
			return is_array( $decoded ) ? $decoded : null;
		}
		public function offsetGet( $offset ) {
			return $this->params[ $offset ] ?? null;
		}
		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private array $errors = array();
		private string $code = '';
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code = $code;
			if ( '' !== $message ) {
				$this->errors[ $code ][] = $message;
			}
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message( string $code = '' ): string {
			if ( '' === $code ) { $code = $this->code; }
			return $this->errors[ $code ][0] ?? '';
		}
	}
}

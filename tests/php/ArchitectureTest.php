<?php

namespace Longevity\Core;

defined( 'ABSPATH' ) || exit;

final class ArchitectureTest {
	private const CORE_BOOTSTRAP = LONGEVITY_CORE_PATH . 'bootstrap.php';

	private static array $failures = array();

	public static function run(): int {
		self::$failures = array();

		self::service_files_exist();
		self::service_init_methods_exist();
		self::no_missing_service_file();
		self::route_definitions_unique();
		self::no_unmanaged_route_literals();
		self::canonical_slugs_are_short();

		if ( self::$failures ) {
			foreach ( self::$failures as $failure ) {
				echo "[ARCH FAIL] {$failure}\n";
			}
			return 1;
		}
		echo "[ARCH OK] All architecture constraints pass.\n";
		return 0;
	}

	private static function service_files_exist(): void {
		$bootstrap = (string) file_get_contents( self::CORE_BOOTSTRAP );
		preg_match( '/\$longevity_core_files\s*=\s*array\((.*?)\);/s', $bootstrap, $match );
		if ( ! $match ) {
			self::$failures[] = 'Cannot extract service file list from bootstrap.php.';
			return;
		}
		preg_match_all( "/'([^']+)'/", $match[1], $files );
		foreach ( $files[1] as $file ) {
			$path = LONGEVITY_CORE_PATH . $file;
			if ( ! file_exists( $path ) ) {
				self::$failures[] = "Service file {$file} listed in bootstrap.php does not exist at {$path}.";
			}
		}
	}

	private static function service_init_methods_exist(): void {
		$bootstrap = (string) file_get_contents( self::CORE_BOOTSTRAP );
		preg_match( '/\$longevity_core_files\s*=\s*array\((.*?)\);/s', $bootstrap, $match );
		if ( ! $match ) {
			return;
		}
		preg_match_all( "/'class-([^']+)\.php'/", $match[1], $files );
		foreach ( $files[1] as $class_base ) {
			$class_name = __NAMESPACE__ . '\\' . self::to_class_name( $class_base );
			if ( ! class_exists( $class_name ) ) {
				self::$failures[] = "Class {$class_name} not loadable after including its file.";
			} elseif ( ! method_exists( $class_name, 'init' ) ) {
				self::$failures[] = "Class {$class_name} lacks an init() method.";
			}
		}
	}

	private static function no_missing_service_file(): void {
		$bootstrap = (string) file_get_contents( self::CORE_BOOTSTRAP );
		preg_match_all( '/([A-Z][a-zA-Z_]+)::init\(\)/', $bootstrap, $calls );
		$called_classes = array_unique( $calls[1] );

		preg_match( '/\$longevity_core_files\s*=\s*array\((.*?)\);/s', $bootstrap, $match );
		if ( ! $match ) {
			return;
		}
		preg_match_all( "/'class-([^']+)\.php'/", $match[1], $files );
		$file_classes = array_map( static fn( string $base ): string => self::to_class_name( $base ), $files[1] );

		foreach ( $called_classes as $class ) {
			if ( 'Bootstrap' === $class ) {
				continue;
			}
			if ( ! in_array( $class, $file_classes, true ) ) {
				self::$failures[] = "{$class}::init() is called but no corresponding class-{$class}.php file is in the service list.";
			}
		}
	}

	private static function route_definitions_unique(): void {
		$routes = (string) file_get_contents( LONGEVITY_CORE_PATH . 'class-routes.php' );
		preg_match_all( "/'slug'\\s*=>\\s*'([^']+)'/", $routes, $matches );
		$slugs = array_filter( $matches[1], static fn( string $s ): bool => '' !== $s );
		$dupes = array_keys( array_filter( array_count_values( $slugs ), static fn( int $c ): bool => $c > 1 ) );
		if ( $dupes ) {
			self::$failures[] = 'Duplicate route slugs: ' . implode( ', ', $dupes );
		}
	}

	private static function no_unmanaged_route_literals(): void {
		$routes = (string) file_get_contents( LONGEVITY_CORE_PATH . 'class-routes.php' );
		preg_match_all( "/'slug'\\s*=>\\s*'([^']+)'/", $routes, $matches );
		$canonical_slugs = $matches[1];

		$bootstrap = (string) file_get_contents( self::CORE_BOOTSTRAP );
		foreach ( $canonical_slugs as $slug ) {
			if ( '' === $slug ) {
				continue;
			}
			if ( preg_match( "/['\"]https?:\\/\\/[^'\" ]*\\/{$slug}[\\/'\" ]/", $bootstrap ) ) {
				self::$failures[] = "Bootstrap.php appears to contain a hardcoded URL for route /{$slug}/";
			}
		}
	}

	private static function canonical_slugs_are_short(): void {
		$routes = (string) file_get_contents( LONGEVITY_CORE_PATH . 'class-routes.php' );
		preg_match_all( "/'slug'\\s*=>\\s*'([^']+)'/", $routes, $slug_matches );
		$page_slugs = array();
		preg_match_all( "/'slug'\\s*=>\\s*'([^']+)'/", $routes, $temp );
		$all_slugs = $temp[1];

		preg_match_all( "/'legacy_slugs'\\s*=>\\s*array\(([^)]*)\)/", $routes, $legacy );
		if ( ! empty( $legacy[0] ) ) {
			foreach ( $legacy[0] as $legacy_block ) {
				if ( preg_match_all( "/'([^']+)'/", $legacy_block, $legacy_slugs ) ) {
					foreach ( $legacy_slugs[1] as $ls ) {
						$slug_used = false;
						foreach ( $all_slugs as $s ) {
							if ( $s === $ls ) {
								$slug_used = true;
								break;
							}
						}
						if ( $slug_used ) {
							self::$failures[] = "Legacy slug '{$ls}' also appears as a canonical slug.";
						}
					}
				}
			}
		}
	}

	private static function to_class_name( string $base ): string {
		return str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $base ) ) );
	}
}

<?php
/**
 * Plugin Name: Longevity Core
 * Description: Editorial governance, evidence traceability, product-testing controls, analytics, and conservative schema.
 * Version: 2.0.0
 * Requires PHP: 8.1
 * Text Domain: longevity-core
 */

defined( 'ABSPATH' ) || exit;

define( 'LONGEVITY_CORE_VERSION', '2.0.0' );
define( 'LONGEVITY_CORE_PATH', __DIR__ . '/longevity-core/' );
define( 'LONGEVITY_CORE_URL', content_url( 'mu-plugins/longevity-core/' ) );

require_once LONGEVITY_CORE_PATH . 'bootstrap.php';

Longevity\Core\Bootstrap::init();

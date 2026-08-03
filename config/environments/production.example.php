<?php
/** Production values to translate into host-managed wp-config.php constants. */
define( 'WP_ENVIRONMENT_TYPE', 'production' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FORCE_SSL_ADMIN', true );
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', true );
define( 'WP_AUTO_UPDATE_CORE', 'minor' );
// CSP delivery mode: 'report-only' or 'enforce'. Production launch readiness
// blocks until 'enforce' is approved per docs/operations/csp-enforcement-plan.md.
// The retired LEL_CSP_ENFORCE constant is ignored and must not be defined.
define( 'LEL_CSP_MODE', 'report-only' );
// Set these from the verified release-info file during managed-host deployment.
define( 'LEL_RELEASE_SHA', 'REPLACE_WITH_FULL_GIT_SHA' );
define( 'LEL_RELEASE_ARTIFACT_SHA256', 'REPLACE_WITH_ARTIFACT_SHA256' );

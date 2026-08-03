<?php
/**
 * Deliberately vulnerable fixture used ONLY to prove the taint scanner works.
 *
 * scripts/verify-sast-detection.sh runs Psalm taint analysis against this
 * file (separate config; never part of the main SAST lane or any runtime
 * path) and fails if TaintedHtml is NOT reported — guarding against a
 * silently neutered scanner.
 *
 * @package LongevityCoreTests
 */

// phpcs:ignoreFile
echo $_GET['q'];

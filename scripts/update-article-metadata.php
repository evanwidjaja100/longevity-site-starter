<?php
/**
 * Update the 8 foundational LEL articles with editorial metadata.
 *
 * Sets evidence cutoff dates, limitations, summaries, original contributions,
 * commercial relationships, region scope, and next review dates on each.
 *
 * Usage: wp eval-file scripts/update-article-metadata.php [post_id1,post_id2,...]
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script must be run via WP-CLI.\n";
	exit( 1 );
}
if ( get_current_user_id() <= 0 || ! current_user_can( 'approve_publication' ) ) {
	\WP_CLI::error( 'Run this script with an authenticated --user that can approve publication workflow changes.' );
}

$today = gmdate( 'Y-m-d' );
$next_year = gmdate( 'Y-m-d', strtotime( '+12 months' ) );

$articles = array(
	array(
		'post_name' => 'what-we-do-and-do-not-claim',
		'meta'      => array(
			'content_summary'         => 'Longevity Evidence Lab evaluates products, practices, and interventions that claim to support healthy aging. This page explains what we do and do not claim.',
			'content_limitations'     => 'This page describes the publication governance model at a point in time. Governance structures may evolve and the page will be updated accordingly.',
			'original_contribution'   => 'Published governance and decision-boundary map showing what the publication covers and what it does not.',
			'evidence_cutoff_date'    => $today,
			'region_scope'            => 'Global (educational content, not medical advice)',
			'commercial_relationship' => 'none',
			'next_content_review_date' => $next_year,
			'editorial_approval_status' => 'editorial_review',
		),
	),
	array(
		'post_name' => 'biohacking-evidence-risk-framework',
		'meta'      => array(
			'content_summary'         => 'A framework to assess any biohacking practice by its evidence level and risk profile, using a decision matrix.',
			'content_limitations'     => 'This framework provides general guidance only. Individual responses to interventions vary. Consult a healthcare professional before starting any new intervention.',
			'original_contribution'   => 'Evidence-risk decision matrix for evaluating biohacking practices.',
			'evidence_cutoff_date'    => $today,
			'region_scope'            => 'Global',
			'commercial_relationship' => 'none',
			'medical_review_required' => true,
			'next_content_review_date' => $next_year,
			'editorial_approval_status' => 'editorial_review',
		),
	),
	array(
		'post_name' => 'how-to-read-a-health-study',
		'meta'      => array(
			'content_summary'         => 'A simple worksheet anyone can apply to any health study to decide how much confidence to place in its findings.',
			'content_limitations'     => 'This worksheet is a simplification of established evidence-based medicine appraisal tools. It does not replace formal critical appraisal training.',
			'original_contribution'   => 'Study appraisal worksheet for general readers.',
			'evidence_cutoff_date'    => $today,
			'region_scope'            => 'Global',
			'commercial_relationship' => 'none',
			'next_content_review_date' => gmdate( 'Y-m-d', strtotime( '+24 months' ) ),
			'editorial_approval_status' => 'editorial_review',
		),
	),
	array(
		'post_name' => 'how-we-grade-evidence-and-test-products',
		'meta'      => array(
			'content_summary'         => 'How evidence grades (A–U) and Consumer Lab product scores are produced, including the protocol index.',
			'content_limitations'     => 'Methodology evolves. Check the protocol index for the current version applied to any review. This methodology does not eliminate all bias or error.',
			'original_contribution'   => 'Public methodology and protocol index.',
			'evidence_cutoff_date'    => $today,
			'region_scope'            => 'Global',
			'commercial_relationship' => 'none',
			'next_content_review_date' => $next_year,
			'editorial_approval_status' => 'editorial_review',
		),
	),
	array(
		'post_name' => 'improve-sleep-before-buying-device',
		'meta'      => array(
			'content_summary'         => 'Sleep fundamentals with strong evidence, and a decision tool to determine whether fundamentals or a device are the right next step.',
			'content_limitations'     => 'Does not constitute medical advice for sleep disorders. Consult a clinician if you suspect a sleep disorder such as insomnia or sleep apnoea.',
			'original_contribution'   => 'Decision tool for escalation and device need.',
			'evidence_cutoff_date'    => $today,
			'region_scope'            => 'Global',
			'commercial_relationship' => 'none',
			'medical_review_required' => true,
			'next_content_review_date' => $next_year,
			'editorial_approval_status' => 'editorial_review',
		),
	),
	array(
		'post_name' => 'consumer-sleep-tracker-accuracy',
		'meta'      => array(
			'content_summary'         => 'Summary of published evidence for what consumer sleep trackers can and cannot measure reliably, organised by metric.',
			'content_limitations'     => 'Accuracy varies by device firmware version, user characteristics, and sleeping environment. Consumer devices are not medical-grade and should not be used for diagnosis.',
			'original_contribution'   => 'Evidence matrix by metric.',
			'evidence_cutoff_date'    => $today,
			'region_scope'            => 'Global',
			'commercial_relationship' => 'none',
			'medical_review_required' => true,
			'next_content_review_date' => $next_year,
			'editorial_approval_status' => 'editorial_review',
		),
	),
	array(
		'post_name' => 'resistance-training-healthy-aging',
		'meta'      => array(
			'content_summary'         => 'A progression framework for beginners with safety boundaries and referral indicators for resistance training.',
			'content_limitations'     => 'Not a substitute for individualised exercise prescription. Consult a healthcare professional before starting if you have any medical conditions affecting balance, joints, or cardiovascular health.',
			'original_contribution'   => 'Progression framework with referral boundaries.',
			'evidence_cutoff_date'    => $today,
			'region_scope'            => 'Global',
			'commercial_relationship' => 'none',
			'medical_review_required' => true,
			'next_content_review_date' => $next_year,
			'editorial_approval_status' => 'editorial_review',
		),
	),
	array(
		'post_name' => 'foods-dietary-patterns-healthy-aging',
		'meta'      => array(
			'content_summary'         => 'Review of dietary patterns with the strongest human evidence for healthy aging, with an affordable meal-component matrix.',
			'content_limitations'     => 'Dietary needs vary by individual health status, allergies, and cultural context. This is not a clinical nutrition therapy plan.',
			'original_contribution'   => 'Affordable meal-component matrix.',
			'evidence_cutoff_date'    => $today,
			'region_scope'            => 'Global',
			'commercial_relationship' => 'none',
			'medical_review_required' => true,
			'next_content_review_date' => $next_year,
			'editorial_approval_status' => 'editorial_review',
		),
	),
);

$updated = 0;
$errors  = array();

foreach ( $articles as $article ) {
	$posts = get_posts( array( 'name' => $article['post_name'], 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => 1, 'no_found_rows' => true ) );
	$post  = ! empty( $posts ) ? $posts[0] : null;
	if ( ! $post ) {
		$errors[] = "Post not found: /{$article['post_name']}/";
		\WP_CLI::warning( "Post not found: /{$article['post_name']}/" );
		continue;
	}

	$dry_run = in_array( 'dry-run', $args ?? array(), true );
	if ( $dry_run ) {
		\WP_CLI::line( "[DRY RUN] Would update metadata for /{$article['post_name']}/ (ID {$post->ID})" );
		foreach ( $article['meta'] as $key => $value ) {
			\WP_CLI::line( "  → {$key}: " . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : $value ) );
		}
		++$updated;
		continue;
	}

	foreach ( array_keys( $article['meta'] ) as $key ) {
		if ( ! Meta_Authorization::can_write( $key, $post->ID, get_current_user_id(), 'cli' ) ) {
			\WP_CLI::error( sprintf( 'Current user is not authorized to update %s on post %d.', $key, $post->ID ) );
		}
	}
	foreach ( $article['meta'] as $key => $value ) {
		update_post_meta( $post->ID, $key, $value );
	}

	\WP_CLI::line( "Updated metadata for /{$article['post_name']}/ (ID {$post->ID})" );
	++$updated;
}

\WP_CLI::success( sprintf( 'Done: %d article(s) updated, %d error(s).', $updated, count( $errors ) ) );
\WP_CLI::warning( 'No editorial approval snapshot was created. Records remain in editorial_review until a human approver uses the approval workflow.' );
if ( $errors ) {
	\WP_CLI::halt( 1 );
}

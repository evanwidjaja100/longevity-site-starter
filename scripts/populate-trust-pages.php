<?php
/**
 * Populate trust policy pages with content from template markdown files.
 *
 * Git templates are ONE-TIME SEEDS. WordPress is the operational source of
 * truth for trust pages. This script refuses to overwrite a page that is
 * published or carries a current trust-page approval unless --force is
 * passed after a reviewed diff. It never changes public review dates;
 * those come from named human trust-page approvals.
 *
 * Usage: wp eval-file scripts/populate-trust-pages.php [--dry-run] [--force]
 *
 * @package LongevityCore
 */

namespace Longevity\Core;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script must be run via WP-CLI.\n";
	exit( 1 );
}
if ( get_current_user_id() <= 0 || ! current_user_can( 'approve_publication' ) ) {
	\WP_CLI::error( 'Run this script with an authenticated --user that can manage publication lifecycle fields.' );
}

$dry_run = in_array( '--dry-run', $args ?? array(), true );
$force   = in_array( '--force', $args ?? array(), true );

$template_dir = '/project-content/templates';

$page_map = array(
	'about.md'                       => 'about',
	'editorial-policy.md'            => 'editorial-policy',
	'evidence-methodology.md'        => 'evidence-methodology',
	'testing-methodology.md'         => 'testing-methodology',
	'medical-disclaimer.md'          => 'medical-disclaimer',
	'affiliate-disclosure.md'        => 'affiliate-disclosure',
	'corrections.md'                 => 'corrections',
	'privacy.md'                     => 'privacy',
	'terms.md'                       => 'terms',
	'contact.md'                     => 'contact',
	'ai-assisted-work-disclosure.md' => 'ai-assisted-work-disclosure',
);

$updated = 0;
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$errors = array();

foreach ( $page_map as $filename => $slug ) {
	$filepath = $template_dir . '/' . $filename;

	if ( ! file_exists( $filepath ) ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$errors[] = "Template not found: {$filename}";
		\WP_CLI::warning( "Template not found: {$filepath}" );
		continue;
	}

	$markdown = file_get_contents( $filepath );
	if ( false === $markdown || '' === trim( $markdown ) ) {
		$errors[] = "Empty template: {$filename}";
		\WP_CLI::warning( "Empty template: {$filepath}" );
		continue;
	}

	if ( Trust_Pages::has_placeholders( $markdown ) ) {
		$errors[] = "Placeholder markers remain in template: {$filename}";
		\WP_CLI::warning( "Template {$filename} still contains placeholder markers ([date], TODO, etc.). Fix the template before seeding." );
		continue;
	}

	$posts = get_posts(
		array(
			'name'           => $slug,
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		)
	);
	$post  = ! empty( $posts ) ? $posts[0] : null;
	if ( ! $post ) {
		$errors[] = "Page not found by slug: {$slug}";
		\WP_CLI::warning( "Page /{$slug}/ does not exist. Run bootstrap first." );
		continue;
	}

	$protected = 'publish' === $post->post_status || Trust_Pages::is_approved( (int) $post->ID );
	if ( $protected && ! $force ) {
		\WP_CLI::warning( "Skipping /{$slug}/: page is published or carries a current trust approval. Re-run with --force after reviewing the diff." );
		continue;
	}

	$html = convert_markdown_to_blocks( $markdown );

	if ( $html === (string) $post->post_content ) {
		\WP_CLI::line( "Unchanged /{$slug}/ (ID {$post->ID}); skipping." );
		continue;
	}

	if ( $dry_run ) {
		\WP_CLI::line( "[DRY RUN] Would update /{$slug}/ (ID {$post->ID}) with content from {$filename}" . ( $protected ? ' (FORCED overwrite of approved/published page)' : '' ) );
		++$updated;
		continue;
	}

	$result = wp_update_post(
		array(
			'ID'           => $post->ID,
			'post_content' => $html,
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		$errors[] = "Failed to update /{$slug}/: " . $result->get_error_message();
		\WP_CLI::warning( "Failed to update /{$slug}/: " . $result->get_error_message() );
		continue;
	}

	\WP_CLI::line( "Updated /{$slug}/ (ID {$post->ID}) with content from {$filename}. A named human trust-page approval is required before publication." );
	++$updated;
}

\WP_CLI::success( sprintf( 'Done: %d page(s) updated, %d error(s).', $updated, count( $errors ) ) );
if ( $errors ) {
	\WP_CLI::halt( 1 );
}

/**
 * Convert markdown content to basic WordPress block HTML.
 */
function convert_markdown_to_blocks( string $markdown ): string {
	$lines      = explode( "\n", $markdown );
	$blocks     = array();
	$in_list    = false;
	$list_tag   = 'ul';
	$list_items = array();

	foreach ( $lines as $line ) {
		$trimmed = trim( $line );

		// Skip front-matter lines (--- ... ---)
		if ( '---' === $trimmed ) {
			continue;
		}

		// Close any open list
		if ( $in_list && ( '' === $trimmed || str_starts_with( $trimmed, '#' ) || str_starts_with( $trimmed, '|' ) ) ) {
			$blocks[]   = render_list( $list_tag, $list_items );
			$list_items = array();
			$in_list    = false;
		}

		if ( '' === $trimmed ) {
			continue;
		}

		// Heading
		if ( str_starts_with( $trimmed, '## ' ) ) {
			$text     = esc_html( trim( substr( $trimmed, 3 ) ) );
			$blocks[] = '<!-- wp:heading --><h2 class="wp-block-heading">' . $text . '</h2><!-- /wp:heading -->';
			continue;
		}
		if ( str_starts_with( $trimmed, '### ' ) ) {
			$text     = esc_html( trim( substr( $trimmed, 4 ) ) );
			$blocks[] = '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . $text . '</h3><!-- /wp:heading -->';
			continue;
		}
		// Skip # Title lines — the theme renders the page title as H1.
		if ( str_starts_with( $trimmed, '# ' ) ) {
			continue;
		}

		// Table row
		if ( str_starts_with( $trimmed, '|' ) ) {
			$cells = array_map( 'trim', explode( '|', trim( $trimmed, '|' ) ) );
			// Skip separator rows (|---|)
			if ( preg_match( '/^[:\s\-|]+$/', $trimmed ) ) {
				continue;
			}
			if ( ! isset( $GLOBALS['_table_header'] ) ) {
				$GLOBALS['_table_header'] = $cells;
				continue;
			}
			if ( ! isset( $GLOBALS['_table_rows'] ) ) {
				$GLOBALS['_table_rows'] = array();
			}
			$GLOBALS['_table_rows'][] = $cells;
			continue;
		}

		// List item
		if ( str_starts_with( $trimmed, '- ' ) || str_starts_with( $trimmed, '* ' ) ) {
			if ( ! $in_list ) {
				$in_list  = true;
				$list_tag = 'ul';
			}
			$text         = convert_inline_markdown( trim( substr( $trimmed, 2 ) ) );
			$list_items[] = $text;
			continue;
		}
		if ( preg_match( '/^\d+[.)]\s/', $trimmed ) ) {
			if ( ! $in_list ) {
				$in_list  = true;
				$list_tag = 'ol';
			}
			$text         = convert_inline_markdown( preg_replace( '/^\d+[.)]\s/', '', $trimmed, 1 ) );
			$list_items[] = $text;
			continue;
		}

		// Horizontal rule
		if ( str_starts_with( $trimmed, '---' ) ) {
			$blocks[] = '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';
			continue;
		}

		// Paragraph
		$text     = convert_inline_markdown( $trimmed );
		$blocks[] = '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	// Close any remaining list
	if ( $in_list && ! empty( $list_items ) ) {
		$blocks[] = render_list( $list_tag, $list_items );
	}

	// Render any accumulated table
	if ( ! empty( $GLOBALS['_table_header'] ) || ! empty( $GLOBALS['_table_rows'] ) ) {
		$blocks[] = render_table( $GLOBALS['_table_header'] ?? array(), $GLOBALS['_table_rows'] ?? array() );
	}
	$GLOBALS['_table_header'] = null;
	$GLOBALS['_table_rows']   = null;

	return implode( "\n", $blocks );
}

/**
 * Convert inline markdown (bold, italic, links).
 */
function convert_inline_markdown( string $text ): string {
	$text = esc_html( $text );
	// Bold
	$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
	// Italic
	$text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
	// Links
	$text = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text );
	return $text;
}

/**
 * Render a list block.
 */
function render_list( string $tag, array $items ): string {
	$html = '<!-- wp:list --><' . $tag . '>';
	foreach ( $items as $item ) {
		$html .= '<li>' . $item . '</li>';
	}
	$html .= '</' . $tag . '><!-- /wp:list -->';
	return $html;
}

/**
 * Render a table block.
 */
function render_table( array $header, array $rows ): string {
	$html = '<!-- wp:table --><figure class="wp-block-table"><table><thead><tr>';
	foreach ( $header as $cell ) {
		$html .= '<th>' . esc_html( $cell ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$html .= '<tr>';
		foreach ( $row as $cell ) {
			$html .= '<td>' . esc_html( $cell ) . '</td>';
		}
		$html .= '</tr>';
	}
	$html .= '</tbody></table></figure><!-- /wp:table -->';
	return $html;
}

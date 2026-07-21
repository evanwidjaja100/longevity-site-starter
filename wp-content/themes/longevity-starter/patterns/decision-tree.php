<?php
/**
 * Title: Decision tree
 * Slug: longevity-starter/decision-tree
 * Categories: featured
 * Inserter: true
 * Description: Step-by-step question flow to guide reader choices.
 */
?>
<!-- wp:group {"className":"longevity-visual-module","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50","right":"var:preset|spacing|50"}},"border":{"width":"1px","radius":"var:preset|spacing|30"}},"borderColor":"border","backgroundColor":"surface"} -->
<div class="wp-block-group longevity-visual-module has-border-color has-border-border-color has-surface-background-color" style="border-width:1px;border-radius:var(--wp--preset--spacing--30);padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">
<!-- wp:heading {"level":3,"fontSize":"large"} --><h3 class="wp-block-heading has-large-font-size">How to choose: decision guide</h3><!-- /wp:heading -->
<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size">Answer the questions below to narrow down what fits your situation. This is a starting point — not medical advice.</p><!-- /wp:paragraph -->
<!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/><!-- /wp:separator -->
<!-- wp:heading {"level":4,"fontSize":"medium"} --><h4 class="wp-block-heading has-medium-font-size">Step 1: What is your primary goal?</h4><!-- /wp:heading -->
<!-- wp:list {"className":"longevity-decision-options"} -->
<ul class="longevity-decision-options"><li><strong>General health</strong> — start with the evidence guide</li><li><strong>Buying a product</strong> — start with the Consumer Lab review</li><li><strong>Comparing options</strong> — use the buyer-facts table below</li></ul>
<!-- /wp:list -->
<!-- wp:heading {"level":4,"fontSize":"medium"} --><h4 class="wp-block-heading has-medium-font-size">Step 2: How much evidence do you need?</h4><!-- /wp:heading -->
<!-- wp:list {"className":"longevity-decision-options"} -->
<ul class="longevity-decision-options"><li><strong>I want the strongest evidence available</strong> — look for A- or B-grade claims</li><li><strong>I want to understand uncertainty</strong> — read the limitations section</li><li><strong>I just need an overview</strong> — the bottom line summary covers key findings</li></ul>
<!-- /wp:list -->
<!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/><!-- /wp:separator -->
<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size"><strong>Not sure where to start?</strong> Visit the <a href="<?php echo esc_url( \Longevity\Core\Routes::public_page_url( 'start_here' ) ); ?>" data-lel-event="start_here_open" data-placement="decision-tree">Start Here</a> page for a guided introduction.</p><!-- /wp:paragraph -->
</div><!-- /wp:group -->

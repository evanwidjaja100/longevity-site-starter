<?php
/**
 * Title: Choose your path
 * Slug: longevity-starter/choose-your-path
 * Categories: featured, text
 * Inserter: true
 */
?>
<!-- wp:group {"align":"wide","className":"longevity-section","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide longevity-section"><!-- wp:paragraph {"className":"longevity-kicker"} --><p class="longevity-kicker">Choose your path</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">What brings you here today?</h2><!-- /wp:heading --><!-- wp:html -->
<div class="longevity-path-cards"><a class="longevity-path-card" href="<?php echo esc_url( home_url( '/category/evidence-literacy/' ) ); ?>" data-lel-event="topic_open" data-topic="evidence-literacy" data-placement="path-cards"><h3>Understand evidence</h3><p>Learn how to evaluate health claims and study quality.</p></a><a class="longevity-path-card" href="<?php echo esc_url( \Longevity\Core\Routes::public_page_url( 'start_here' ) ); ?>" data-lel-event="start_here_open" data-placement="path-cards"><h3>Improve the foundations</h3><p>Evidence-informed guidance on sleep, movement, and nutrition.</p></a><a class="longevity-path-card" href="<?php echo esc_url( \Longevity\Core\Routes::public_page_url( 'testing_methodology' ) ); ?>" data-lel-event="methodology_open" data-placement="path-cards"><h3>Evaluate a product</h3><p>Measurements, devices, test protocols, and buyer facts.</p></a></div>
<!-- /wp:html --></div><!-- /wp:group -->

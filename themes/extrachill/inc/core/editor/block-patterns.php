<?php
/**
 * Block patterns for the Extra Chill theme.
 *
 * Registers an "Extra Chill" pattern category and reusable patterns built
 * entirely from CORE blocks (Group / Columns / Heading / Paragraph / Buttons).
 * Because the theme now ships a root theme.json (from @extrachill/tokens), these
 * core blocks inherit the Extra Chill palette, spacing, and typography — so the
 * patterns stay on-brand without any pattern-specific CSS.
 *
 * Used as the design-system foundation for the extrachill.com/power manifesto
 * page, but intentionally generic and reusable across any page on the network.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Extra Chill pattern category so theme patterns are discoverable
 * in the block inserter under a single, branded group.
 */
function extrachill_register_block_pattern_categories() {
	if ( ! function_exists( 'register_block_pattern_category' ) ) {
		return;
	}

	register_block_pattern_category(
		'extrachill',
		array(
			'label'       => __( 'Extra Chill', 'extrachill' ),
			'description' => __( 'On-brand building blocks for Extra Chill pages.', 'extrachill' ),
		)
	);
}
add_action( 'init', 'extrachill_register_block_pattern_categories' );

/**
 * Register Extra Chill block patterns.
 *
 * All markup uses core blocks with theme.json preset classes (has-*-color,
 * has-*-font-size, etc.) so the palette/typography come from the tokens-driven
 * theme.json rather than inline values. This keeps the patterns in sync with the
 * design system automatically.
 */
function extrachill_register_block_patterns() {
	if ( ! function_exists( 'register_block_pattern' ) ) {
		return;
	}

	/*
	 * Pillar / values section.
	 *
	 * The reusable "values unit": heading + short statement + one clear CTA,
	 * wrapped in a padded card Group. Drop several in a row to build a manifesto
	 * / values page (e.g. extrachill.com/power).
	 */
	register_block_pattern(
		'extrachill/pillar',
		array(
			'title'       => __( 'Pillar / Values Section', 'extrachill' ),
			'description' => __( 'A single values pillar: heading, short statement, and one clear call to action. Stack several to build a manifesto or values page.', 'extrachill' ),
			'categories'  => array( 'extrachill' ),
			'keywords'    => array( 'pillar', 'values', 'manifesto', 'cta', 'feature' ),
			'content'     => '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|spacing-xl","bottom":"var:preset|spacing|spacing-xl","left":"var:preset|spacing|spacing-lg","right":"var:preset|spacing|spacing-lg"},"blockGap":"var:preset|spacing|spacing-md"}},"backgroundColor":"card-background","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-card-background-background-color has-background" style="padding-top:var(--wp--preset--spacing--spacing-xl);padding-right:var(--wp--preset--spacing--spacing-lg);padding-bottom:var(--wp--preset--spacing--spacing-xl);padding-left:var(--wp--preset--spacing--spacing-lg)"><!-- wp:heading {"level":2,"fontSize":"font-size-2xl"} -->
<h2 class="wp-block-heading has-font-size-2xl-font-size">Pillar headline</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"font-size-body"} -->
<p class="has-font-size-body-font-size">A short, punchy statement of what this pillar stands for. One or two sentences that make the value concrete — no fluff. Say what Extra Chill actually does here and why it matters.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"accent","textColor":"button-text-color"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-button-text-color-color has-accent-background-color has-text-color has-background wp-element-button" href="#">Learn more</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->',
		)
	);

	/*
	 * Network map is intentionally NOT a static pattern.
	 *
	 * The network surfaces (Events / Community / Wire / Artist Platform) are only
	 * worth showing with LIVE proof numbers (active members, artist count, etc.).
	 * Hardcoded/placeholder numbers on a credibility-driven manifesto page are
	 * worse than none. The network map is delivered separately as core Block
	 * Bindings (WP 6.5+): an `extrachill/network-stat` binding source feeds live,
	 * cross-site, cached stats into otherwise-static core blocks. Tracked as its
	 * own issue so the pillar pattern can ship now without fake data.
	 */
}
add_action( 'init', 'extrachill_register_block_patterns' );

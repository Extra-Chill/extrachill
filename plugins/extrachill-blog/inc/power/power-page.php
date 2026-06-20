<?php
/**
 * The /power network manifesto landing page.
 *
 * The /power surface is the network MANIFESTO + router: it tells outsiders
 * Extra Chill is a whole independent-music NETWORK (events, community, wire,
 * artist platform, publication), not just a blog. It is manifesto-first,
 * routing-second — it LINKS to the live surfaces, it does not embed cross-blog
 * functional blocks.
 *
 * Approach (server-rendered page):
 *  - /power is a standard published WP Page at the (confirmed-free) slug
 *    "power", auto-created on activation and re-checked on a version-gated
 *    admin_init — the same provisioning convention the artist platform uses for
 *    /create-artist et al. The page renders through the theme's normal loop +
 *    inc/single/single-page.php (which calls the_content()).
 *  - The manifesto + network map are rendered SERVER-SIDE via a `the_content`
 *    filter (inc/power/power-template.php), NOT seeded as Gutenberg block
 *    markup. This is deliberate and matches every other Extra Chill surface
 *    (homepage section templates, network-bridge, etc.): plain semantic HTML
 *    styled with assets/css/power.css using the LIVE design tokens
 *    (var(--card-background), var(--text-color), var(--accent), var(--spacing-*),
 *    var(--border-radius-*)).
 *
 * Why NOT theme.json block patterns / preset classes:
 *  - EC dark mode lives entirely in the theme's root.css
 *    `@media (prefers-color-scheme: dark)` block, which overrides the `--*` CSS
 *    VARIABLES. The theme.json palette bakes STATIC hex values into preset
 *    classes (e.g. `.has-card-background-background-color { background:#f1f5f9 }`)
 *    that do NOT flip in dark mode. A page built from those preset classes
 *    renders light cards + dark text in dark mode — broken contrast. Rendering
 *    against the live `--*` tokens gets correct light AND dark automatically.
 *    (Theme-side fix tracked separately so the pattern system isn't dark-blind.)
 *
 * Live proof numbers come from ec_get_network_stats() at render time
 * (see power-template.php), guarded with function_exists() so the page never
 * fatals and degrades to label-only cards if multisite is a version behind.
 *
 * Additive + router guarantees:
 *  - New slug, new page; nothing links to it by default (the link-page footer
 *    re-aim is artist-platform#76, intentionally not touched here).
 *  - Cards deep-link OUT to the subsites; the page embeds no cross-blog
 *    functional blocks.
 *  - The artist conversion section carries an #artists anchor so the future
 *    footer re-aim can target it directly.
 *
 * @package ExtraChillBlog
 * @since 0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/power-template.php';

/**
 * Slug for the manifesto page.
 */
const EXTRACHILL_BLOG_POWER_SLUG = 'power';

/**
 * Ensure the published /power page exists, creating it if absent.
 *
 * The page is a lightweight shell: its stored content is a single sentinel
 * paragraph. The manifesto + network map are injected at render time by the
 * the_content filter in power-template.php, so the live design tokens (and
 * therefore dark mode) always apply and the proof numbers are never frozen
 * into post_content.
 *
 * Idempotent: only inserts when no page with the slug exists. Existing /power
 * pages (including human edits) are left untouched.
 */
function extrachill_blog_create_power_page() {
	$existing = get_page_by_path( EXTRACHILL_BLOG_POWER_SLUG );

	if ( $existing ) {
		return;
	}

	wp_insert_post(
		array(
			'post_title'   => 'The Power of Extra Chill',
			'post_name'    => EXTRACHILL_BLOG_POWER_SLUG,
			'post_content' => '<!-- extrachill-power-manifesto -->',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		)
	);
}

/**
 * Provision the /power page on a version-gated admin_init.
 *
 * Mirrors the artist platform's page-provisioning convention so the page is
 * (re)created on fresh installs and plugin upgrades alike, without re-running on
 * every request.
 */
function extrachill_blog_maybe_create_power_page() {
	$stored_version = get_option( 'extrachill_blog_power_page_version', '0' );

	if ( version_compare( $stored_version, EXTRACHILL_BLOG_VERSION, '<' ) ) {
		extrachill_blog_create_power_page();
		update_option( 'extrachill_blog_power_page_version', EXTRACHILL_BLOG_VERSION );
	}
}
add_action( 'admin_init', 'extrachill_blog_maybe_create_power_page' );

/**
 * Replace the /power page content with the server-rendered manifesto.
 *
 * Runs only in the main query on the /power page so block themes, feeds, and
 * other contexts are untouched. Hides the page title (the manifesto supplies
 * its own hero H1).
 *
 * @param string $content Original post content.
 * @return string Manifesto HTML on /power, original content otherwise.
 */
function extrachill_blog_render_power_content( $content ) {
	if ( ! is_page( EXTRACHILL_BLOG_POWER_SLUG ) || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}

	return extrachill_blog_power_manifesto_html();
}
add_filter( 'the_content', 'extrachill_blog_render_power_content' );

/**
 * Hide the default page H1 on /power (the hero provides its own).
 *
 * @param bool $show Whether to show the page title.
 * @return bool False on /power, original value otherwise.
 */
function extrachill_blog_power_hide_title( $show ) {
	if ( is_page( EXTRACHILL_BLOG_POWER_SLUG ) ) {
		return false;
	}
	return $show;
}
add_filter( 'extrachill_show_page_title', 'extrachill_blog_power_hide_title' );

/**
 * Enqueue the /power page styles only on the /power page.
 */
function extrachill_blog_enqueue_power_styles() {
	if ( ! is_page( EXTRACHILL_BLOG_POWER_SLUG ) ) {
		return;
	}

	$css_path = EXTRACHILL_BLOG_PLUGIN_DIR . 'assets/css/power.css';
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-blog-power',
			EXTRACHILL_BLOG_PLUGIN_URL . 'assets/css/power.css',
			array( 'extrachill-root' ),
			filemtime( $css_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_blog_enqueue_power_styles' );

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
 * Approach (page-as-content):
 *  - /power is a standard published WP Page at the (confirmed-free) slug
 *    "power", auto-created on activation and re-checked on a version-gated
 *    admin_init — exactly the pattern the artist platform uses to provision
 *    /create-artist et al. (extrachill-artist-platform.php). This is the
 *    established main-site convention: main-site custom pages (About, History,
 *    Long Term Vision) are just published Pages, and they render through the
 *    theme's normal loop + inc/single/single-page.php (which calls
 *    the_content()). No new rewrite/route is needed, and the change is purely
 *    additive: it touches nothing about the homepage or existing pages.
 *  - The page content is COMPOSED from the theme's `extrachill/pillar` block
 *    pattern (the values unit: heading + statement + CTA) for the manifesto
 *    pillars, plus the `extrachill/network-map` dynamic block (this plugin) for
 *    the live-proof-number network map. The pillar markup uses the theme.json
 *    preset classes so it inherits the EC brand with zero bespoke CSS.
 *
 * Why pillar markup is inlined here rather than fetched from the pattern
 * registry: the page is seeded programmatically (no human places the pattern in
 * the editor), so we write the same core-block markup the pattern ships. If the
 * theme pattern ever diverges, the page can be re-seeded; the markup is kept in
 * sync with theme `inc/core/editor/block-patterns.php`.
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

/**
 * Slug for the manifesto page.
 */
const EXTRACHILL_BLOG_POWER_SLUG = 'power';

/**
 * Build one pillar values unit as core-block markup.
 *
 * Mirrors the theme's registered `extrachill/pillar` pattern (heading +
 * statement + one CTA inside a padded card Group), using the same theme.json
 * preset classes so it inherits the EC palette/typography with no extra CSS.
 *
 * @param string $heading   Pillar headline.
 * @param string $statement Short, punchy statement.
 * @param string $cta_label Call-to-action label.
 * @param string $cta_url   Call-to-action URL.
 * @return string Block markup for a single pillar.
 */
function extrachill_blog_power_pillar( $heading, $statement, $cta_label, $cta_url ) {
	$heading   = esc_html( $heading );
	$statement = esc_html( $statement );
	$cta_label = esc_html( $cta_label );
	$cta_url   = esc_url( $cta_url );

	return <<<HTML
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|spacing-xl","bottom":"var:preset|spacing|spacing-xl","left":"var:preset|spacing|spacing-lg","right":"var:preset|spacing|spacing-lg"},"blockGap":"var:preset|spacing|spacing-md"}},"backgroundColor":"card-background","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-card-background-background-color has-background" style="padding-top:var(--wp--preset--spacing--spacing-xl);padding-right:var(--wp--preset--spacing--spacing-lg);padding-bottom:var(--wp--preset--spacing--spacing-xl);padding-left:var(--wp--preset--spacing--spacing-lg)"><!-- wp:heading {"level":2,"fontSize":"font-size-2xl"} -->
<h2 class="wp-block-heading has-font-size-2xl-font-size">{$heading}</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"font-size-body"} -->
<p class="has-font-size-body-font-size">{$statement}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"accent","textColor":"button-text-color"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-button-text-color-color has-accent-background-color has-text-color has-background wp-element-button" href="{$cta_url}">{$cta_label}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
HTML;
}

/**
 * Compose the full block content for the /power manifesto page.
 *
 * Sections, in order:
 *   1. HERO — one-line reframe + one low-commitment CTA.
 *   2. PILLARS — four `extrachill/pillar` values units.
 *   3. NETWORK MAP — the `extrachill/network-map` dynamic block (live proof
 *      numbers + deep links).
 *   4. ARTIST SECTION (#artists) — focused conversion slice for artists.
 *   5. CLOSING — repeated low-commitment CTA.
 *
 * @return string Page block content.
 */
function extrachill_blog_power_page_content() {
	$community_url = ( function_exists( 'ec_get_site_url' ) && ec_get_site_url( 'community' ) )
		? ec_get_site_url( 'community' )
		: 'https://community.extrachill.com';

	$artist_url = ( function_exists( 'ec_get_site_url' ) && ec_get_site_url( 'artist' ) )
		? ec_get_site_url( 'artist' )
		: 'https://artist.extrachill.com';

	$artist_register_url = trailingslashit( $artist_url ) . 'login/#tab-register';

	$hero = <<<HTML
<!-- wp:group {"tagName":"header","style":{"spacing":{"padding":{"top":"var:preset|spacing|spacing-xl","bottom":"var:preset|spacing|spacing-xl"},"blockGap":"var:preset|spacing|spacing-md"}},"layout":{"type":"constrained"}} -->
<header class="wp-block-group" style="padding-top:var(--wp--preset--spacing--spacing-xl);padding-bottom:var(--wp--preset--spacing--spacing-xl)"><!-- wp:heading {"textAlign":"center","level":1,"fontSize":"font-size-3xl"} -->
<h1 class="wp-block-heading has-text-align-center has-font-size-3xl-font-size">Extra Chill is the online music scene.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","fontSize":"font-size-lg"} -->
<p class="has-text-align-center has-font-size-lg-font-size">Not just a blog — an independent, open-source music network. A live calendar, a community of musicians and fans, a festival news wire, and free tools for artists to run their own corner. All grassroots, all independent, no astro-turf.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"accent","textColor":"button-text-color"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-button-text-color-color has-accent-background-color has-text-color has-background wp-element-button" href="{$community_url}">Join the community</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></header>
<!-- /wp:group -->
HTML;

	$pillars_intro = <<<'HTML'
<!-- wp:heading {"textAlign":"center","level":2,"fontSize":"font-size-2xl"} -->
<h2 class="wp-block-heading has-text-align-center has-font-size-2xl-font-size">The power of staying independent</h2>
<!-- /wp:heading -->
HTML;

	$pillar_music = extrachill_blog_power_pillar(
		'The Power of Independent Music',
		'Independent artists make the music that actually moves the scene forward. We cover it, calendar it, and point fans straight at it — no major-label gatekeeping, no pay-to-play. The music comes first, every time.',
		'See what\'s playing',
		( function_exists( 'ec_get_site_url' ) && ec_get_site_url( 'events' ) ) ? ec_get_site_url( 'events' ) : 'https://events.extrachill.com'
	);

	$pillar_community = extrachill_blog_power_pillar(
		'The Power of Community',
		'We are not shouting into the void. Musicians, fans, and industry folks meet in the same forums, upvote each other, and build real connections. The scene is a conversation, and everyone has a seat at the table.',
		'Join the conversation',
		$community_url
	);

	$pillar_platform = extrachill_blog_power_pillar(
		'The Power of the Platform',
		'Every independent artist gets a free home base: a link page at extrachill.link, your own subscribers, and analytics. You own your corner — we just hand you the keys and get out of the way.',
		'Build your home base',
		$artist_register_url
	);

	$pillar_independent = extrachill_blog_power_pillar(
		'The Power of Staying Independent',
		'Since 2011, out of a Charleston dorm room. We stand by our core philosophies and never give in to corporate pressure or aggressive monetization. Sustainable, memorable, and built on the spirit of the music — not on getting rich.',
		'Read our long-term vision',
		home_url( '/long-term-vision/' )
	);

	$network_map = "<!-- wp:heading {\"textAlign\":\"center\",\"level\":2,\"fontSize\":\"font-size-2xl\"} -->\n<h2 class=\"wp-block-heading has-text-align-center has-font-size-2xl-font-size\">One network, many doors</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph {\"align\":\"center\",\"fontSize\":\"font-size-body\"} -->\n<p class=\"has-text-align-center has-font-size-body-font-size\">Every surface below is live and growing. Pick a door.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:extrachill/network-map /-->";

	$artist_section = <<<HTML
<!-- wp:group {"tagName":"section","anchor":"artists","style":{"spacing":{"padding":{"top":"var:preset|spacing|spacing-xl","bottom":"var:preset|spacing|spacing-xl","left":"var:preset|spacing|spacing-lg","right":"var:preset|spacing|spacing-lg"},"blockGap":"var:preset|spacing|spacing-md"}},"backgroundColor":"card-background","layout":{"type":"constrained"}} -->
<section class="wp-block-group has-card-background-background-color has-background" id="artists" style="padding-top:var(--wp--preset--spacing--spacing-xl);padding-right:var(--wp--preset--spacing--spacing-lg);padding-bottom:var(--wp--preset--spacing--spacing-xl);padding-left:var(--wp--preset--spacing--spacing-lg)"><!-- wp:heading {"level":2,"fontSize":"font-size-2xl"} -->
<h2 class="wp-block-heading has-font-size-2xl-font-size">Are you an artist? Here's your home base.</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"font-size-body"} -->
<p class="has-font-size-body-font-size">Extra Chill gives independent artists real tools, free, with no catch:</p>
<!-- /wp:paragraph -->

<!-- wp:list {"fontSize":"font-size-body"} -->
<ul class="has-font-size-body-font-size"><!-- wp:list-item -->
<li>A free link page at extrachill.link — your whole presence at one URL.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Build a subscriber list so you can reach fans directly.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Get discovered by a community that actually shows up.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Analytics so you can see what's working.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"accent","textColor":"button-text-color"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-button-text-color-color has-accent-background-color has-text-color has-background wp-element-button" href="{$artist_register_url}">Claim your free artist profile</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></section>
<!-- /wp:group -->
HTML;

	$closing = <<<HTML
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|spacing-xl","bottom":"var:preset|spacing|spacing-xl"},"blockGap":"var:preset|spacing|spacing-md"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--spacing-xl);padding-bottom:var(--wp--preset--spacing--spacing-xl)"><!-- wp:heading {"textAlign":"center","level":2,"fontSize":"font-size-2xl"} -->
<h2 class="wp-block-heading has-text-align-center has-font-size-2xl-font-size">We hope you'll stick around.</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","fontSize":"font-size-body"} -->
<p class="has-text-align-center has-font-size-body-font-size">If independent music is your thing, you're already home. Pull up a chair in the community.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"accent","textColor":"button-text-color"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-button-text-color-color has-accent-background-color has-text-color has-background wp-element-button" href="{$community_url}">Join the community</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
HTML;

	return implode(
		"\n\n",
		array(
			$hero,
			$pillars_intro,
			$pillar_music,
			$pillar_community,
			$pillar_platform,
			$pillar_independent,
			$network_map,
			$artist_section,
			$closing,
		)
	);
}

/**
 * Ensure the published /power page exists, creating it if absent.
 *
 * Idempotent: only inserts when no page with the slug exists. Existing /power
 * pages (including any human edits) are left untouched.
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
			'post_content' => extrachill_blog_power_page_content(),
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

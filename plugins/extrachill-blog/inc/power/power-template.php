<?php
/**
 * Server-rendered markup for the /power network explorer.
 *
 * Plain semantic HTML styled by assets/css/power.css using the LIVE design
 * tokens, so the page renders correctly in both light and dark mode (EC dark
 * mode flips the `--*` CSS variables via root.css @media prefers-color-scheme).
 *
 * Sections, in order: a concise hero, the live network map, and one short
 * independence statement. The page routes visitors into the working network
 * instead of making them read a manifesto before they can explore it.
 *
 * All NetworkStats reads are guarded; unavailable metrics degrade to
 * label-only cards. Subsite URLs resolve via ec_get_site_url() with hardcoded
 * fallbacks.
 *
 * @package ExtraChillBlog
 * @since 0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a network subsite URL with a hardcoded fallback.
 *
 * @param string $key      Site key (events|community|wire|artist).
 * @param string $fallback Fallback URL when the resolver is unavailable.
 * @return string Resolved (or fallback) URL.
 */
function extrachill_blog_power_site_url( $key, $fallback ) {
	if ( function_exists( 'ec_get_site_url' ) ) {
		$url = ec_get_site_url( $key );
		if ( ! empty( $url ) ) {
			return $url;
		}
	}
	return $fallback;
}

/**
 * Resolve the live network statistics the page consumes.
 *
 * Single guarded call. Returns an empty array when the NetworkStats primitive
 * is unavailable so the renderer degrades to label-only cards.
 *
 * @return array<string,array{key:string,label:string,value:int|array|null,available:bool}>
 */
function extrachill_blog_power_stats() {
	if ( ! function_exists( 'ec_get_network_stats' ) ) {
		return array();
	}

	$stats = ec_get_network_stats(
		array(
			'events_count',
			'events_cities',
			'community_members',
			'community_topics',
			'wire_posts',
			'artist_profiles',
			'total_posts',
		)
	);

	return is_array( $stats ) ? $stats : array();
}

/**
 * Extract a single integer proof-number from a NetworkStats envelope map.
 *
 * Returns null unless the metric is explicitly available with a numeric value
 * (honors the "never a fabricated zero" rule).
 *
 * @param array  $stats NetworkStats envelope map.
 * @param string $key   Metric key.
 * @return int|null Integer value or null when unavailable.
 */
function extrachill_blog_power_stat_value( array $stats, $key ) {
	if ( empty( $stats[ $key ] ) || ! is_array( $stats[ $key ] ) ) {
		return null;
	}

	$metric = $stats[ $key ];

	if ( empty( $metric['available'] ) ) {
		return null;
	}

	$value = isset( $metric['value'] ) ? $metric['value'] : null;

	if ( is_int( $value ) || is_numeric( $value ) ) {
		return (int) $value;
	}

	return null;
}

/**
 * Build a proof line from one or more metric fragments.
 *
 * Each fragment renders as "{number} {noun}" only when available; unavailable
 * fragments are dropped. Returns '' when nothing resolves.
 *
 * @param array $stats     NetworkStats envelope map.
 * @param array $fragments List of [ 'key', 'singular', 'plural' ].
 * @return string Human-readable proof line (may be empty).
 */
function extrachill_blog_power_proof_line( array $stats, array $fragments ) {
	$parts = array();

	foreach ( $fragments as $fragment ) {
		$value = extrachill_blog_power_stat_value( $stats, $fragment['key'] );

		if ( null === $value ) {
			continue;
		}

		$noun    = ( 1 === $value ) ? $fragment['singular'] : $fragment['plural'];
		$parts[] = number_format_i18n( $value ) . ' ' . $noun;
	}

	return implode( ' across ', $parts );
}

/**
 * Render the network-map surface cards with live proof numbers.
 *
 * @return string Network-map HTML.
 */
function extrachill_blog_power_network_map() {
	$stats = extrachill_blog_power_stats();

	$cards = array(
		array(
			'title'    => __( 'Live Music Calendar', 'extrachill-blog' ),
			'desc'     => __( 'Concerts everywhere — big cities and small, big artists and small — free to browse, no login wall.', 'extrachill-blog' ),
			'url'      => extrachill_blog_power_site_url( 'events', 'https://events.extrachill.com' ),
			'site_key' => 'events',
			'cta'      => __( 'Browse the calendar', 'extrachill-blog' ),
			'proof'    => extrachill_blog_power_proof_line(
				$stats,
				array(
					array(
						'key'      => 'events_count',
						'singular' => __( 'event', 'extrachill-blog' ),
						'plural'   => __( 'events', 'extrachill-blog' ),
					),
					array(
						'key'      => 'events_cities',
						'singular' => __( 'city', 'extrachill-blog' ),
						'plural'   => __( 'cities', 'extrachill-blog' ),
					),
				)
			),
		),
		array(
			'title'    => __( 'The Community', 'extrachill-blog' ),
			'desc'     => __( 'Forums for musicians, fans, and industry folks — the online music scene, in conversation.', 'extrachill-blog' ),
			'url'      => extrachill_blog_power_site_url( 'community', 'https://community.extrachill.com' ),
			'site_key' => 'community',
			'cta'      => __( 'Join the conversation', 'extrachill-blog' ),
			'proof'    => extrachill_blog_power_proof_line(
				$stats,
				array(
					array(
						'key'      => 'community_members',
						'singular' => __( 'active member', 'extrachill-blog' ),
						'plural'   => __( 'active members', 'extrachill-blog' ),
					),
					array(
						'key'      => 'community_topics',
						'singular' => __( 'topic', 'extrachill-blog' ),
						'plural'   => __( 'topics', 'extrachill-blog' ),
					),
				)
			),
		),
		array(
			'title'    => __( 'Festival Wire', 'extrachill-blog' ),
			'desc'     => __( 'Timely, no-nonsense coverage of festival news and lineups as it breaks.', 'extrachill-blog' ),
			'url'      => extrachill_blog_power_site_url( 'wire', 'https://wire.extrachill.com' ),
			'site_key' => 'wire',
			'cta'      => __( 'Read the Wire', 'extrachill-blog' ),
			'proof'    => extrachill_blog_power_proof_line(
				$stats,
				array(
					array(
						'key'      => 'wire_posts',
						'singular' => __( 'dispatch', 'extrachill-blog' ),
						'plural'   => __( 'dispatches', 'extrachill-blog' ),
					),
				)
			),
		),
		array(
			'title'    => __( 'Artist Platform', 'extrachill-blog' ),
			'desc'     => __( 'Free link pages, subscribers, and analytics for independent artists to run their own corner.', 'extrachill-blog' ),
			'url'      => extrachill_blog_power_site_url( 'artist', 'https://artist.extrachill.com' ),
			'site_key' => 'artist',
			'cta'      => __( 'Explore the platform', 'extrachill-blog' ),
			'proof'    => extrachill_blog_power_proof_line(
				$stats,
				array(
					array(
						'key'      => 'artist_profiles',
						'singular' => __( 'artist profile', 'extrachill-blog' ),
						'plural'   => __( 'artist profiles', 'extrachill-blog' ),
					),
				)
			),
		),
		array(
			'title'    => __( 'The Publication', 'extrachill-blog' ),
			'desc'     => __( 'Where it all started in 2011 — independent music journalism, written by people who show up.', 'extrachill-blog' ),
			'url'      => home_url( '/' ),
			'site_key' => '',
			'cta'      => __( 'Read the blog', 'extrachill-blog' ),
			'proof'    => extrachill_blog_power_proof_line(
				$stats,
				array(
					array(
						'key'      => 'total_posts',
						'singular' => __( 'article', 'extrachill-blog' ),
						'plural'   => __( 'articles', 'extrachill-blog' ),
					),
				)
			),
		),
		array(
			'title'    => __( 'The Open-Source Platform', 'extrachill-blog' ),
			'desc'     => __( 'The whole network is open source and built in public. Peek under the hood.', 'extrachill-blog' ),
			'url'      => 'https://github.com/Extra-Chill',
			'site_key' => '',
			'cta'      => __( 'See the code on GitHub', 'extrachill-blog' ),
			'proof'    => '',
		),
	);

	ob_start();
	?>
	<div class="power-network-map">
		<?php foreach ( $cards as $card ) : ?>
			<?php
			$card_url   = $card['site_key'] ? extrachill_blog_bridge_url( $card['url'], $card['site_key'], 'power' ) : $card['url'];
			$card_class = $card['site_key'] ? 'power-network-card ec-cross-site-link' : 'power-network-card';
			?>
			<a class="<?php echo esc_attr( $card_class ); ?>" href="<?php echo esc_url( $card_url ); ?>">
				<h3 class="power-network-card__title"><?php echo esc_html( $card['title'] ); ?></h3>
				<?php if ( ! empty( $card['proof'] ) ) : ?>
					<p class="power-network-card__proof"><?php echo esc_html( $card['proof'] ); ?></p>
				<?php endif; ?>
				<p class="power-network-card__desc"><?php echo esc_html( $card['desc'] ); ?></p>
				<span class="power-network-card__cta"><?php echo esc_html( $card['cta'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
	return trim( ob_get_clean() );
}

/**
 * Render the full /power network explorer HTML.
 *
 * @return string Manifesto markup.
 */
function extrachill_blog_power_manifesto_html() {
	ob_start();
	?>
	<div class="power-page">

		<header class="power-hero">
			<h1 class="power-hero__title"><?php esc_html_e( 'Extra Chill is not a blog. It\'s a whole network.', 'extrachill-blog' ); ?></h1>
			<p class="power-hero__lede"><?php esc_html_e( 'One independent music scene with many doors. Pick the part that feels like home.', 'extrachill-blog' ); ?></p>
		</header>

		<section class="power-section">
			<h2 class="power-section__heading"><?php esc_html_e( 'Pick a door', 'extrachill-blog' ); ?></h2>
			<?php
			echo extrachill_blog_power_network_map(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_* helpers in extrachill_blog_power_network_map().
			?>
		</section>

		<section class="power-note">
			<h2 class="power-note__title"><?php esc_html_e( 'Independent since 2011', 'extrachill-blog' ); ?></h2>
			<p><?php esc_html_e( 'Extra Chill is grassroots, open source, and built for the people who make music scenes matter. No corporate playbook. No algorithm deciding who deserves a voice.', 'extrachill-blog' ); ?></p>
		</section>

	</div>
	<?php
	return trim( ob_get_clean() );
}

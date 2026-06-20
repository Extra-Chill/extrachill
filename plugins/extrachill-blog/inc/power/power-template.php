<?php
/**
 * Server-rendered markup for the /power manifesto page.
 *
 * Plain semantic HTML styled by assets/css/power.css using the LIVE design
 * tokens, so the page renders correctly in both light and dark mode (EC dark
 * mode flips the `--*` CSS variables via root.css @media prefers-color-scheme).
 *
 * Sections, in order:
 *   1. HERO — one-line reframe + one low-commitment CTA.
 *   2. PILLARS — four values units (heading + statement + CTA).
 *   3. NETWORK MAP — surface cards with LIVE proof numbers + deep links.
 *   4. ARTIST SECTION (#artists) — focused conversion slice for artists.
 *   5. CLOSING — repeated low-commitment CTA.
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
 * Render a single manifesto pillar (heading + statement + CTA).
 *
 * @param string $heading   Pillar headline.
 * @param string $statement Short statement.
 * @param string $cta_label CTA label.
 * @param string $cta_url   CTA URL.
 * @return string Pillar HTML.
 */
function extrachill_blog_power_pillar( $heading, $statement, $cta_label, $cta_url ) {
	ob_start();
	?>
	<div class="power-pillar">
		<h3 class="power-pillar__title"><?php echo esc_html( $heading ); ?></h3>
		<p class="power-pillar__statement"><?php echo esc_html( $statement ); ?></p>
		<a class="button-1 button-medium power-pillar__cta" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
	</div>
	<?php
	return trim( ob_get_clean() );
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
			'title' => __( 'Live Music Calendar', 'extrachill-blog' ),
			'desc'  => __( 'Concerts everywhere — big cities and small, big artists and small — free to browse, no login wall.', 'extrachill-blog' ),
			'url'   => extrachill_blog_power_site_url( 'events', 'https://events.extrachill.com' ),
			'cta'   => __( 'Browse the calendar', 'extrachill-blog' ),
			'proof' => extrachill_blog_power_proof_line(
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
			'title' => __( 'The Community', 'extrachill-blog' ),
			'desc'  => __( 'Forums for musicians, fans, and industry folks — the online music scene, in conversation.', 'extrachill-blog' ),
			'url'   => extrachill_blog_power_site_url( 'community', 'https://community.extrachill.com' ),
			'cta'   => __( 'Join the conversation', 'extrachill-blog' ),
			'proof' => extrachill_blog_power_proof_line(
				$stats,
				array(
					array(
						'key'      => 'community_members',
						'singular' => __( 'member', 'extrachill-blog' ),
						'plural'   => __( 'members', 'extrachill-blog' ),
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
			'title' => __( 'Festival Wire', 'extrachill-blog' ),
			'desc'  => __( 'Timely, no-nonsense coverage of festival news and lineups as it breaks.', 'extrachill-blog' ),
			'url'   => extrachill_blog_power_site_url( 'wire', 'https://wire.extrachill.com' ),
			'cta'   => __( 'Read the Wire', 'extrachill-blog' ),
			'proof' => extrachill_blog_power_proof_line(
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
			'title' => __( 'Artist Platform', 'extrachill-blog' ),
			'desc'  => __( 'Free link pages, subscribers, and analytics for independent artists to run their own corner.', 'extrachill-blog' ),
			'url'   => extrachill_blog_power_site_url( 'artist', 'https://artist.extrachill.com' ),
			'cta'   => __( 'Explore the platform', 'extrachill-blog' ),
			'proof' => extrachill_blog_power_proof_line(
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
			'title' => __( 'The Publication', 'extrachill-blog' ),
			'desc'  => __( 'Where it all started in 2011 — independent music journalism, written by people who show up.', 'extrachill-blog' ),
			'url'   => home_url( '/' ),
			'cta'   => __( 'Read the blog', 'extrachill-blog' ),
			'proof' => extrachill_blog_power_proof_line(
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
			'title' => __( 'The Open-Source Platform', 'extrachill-blog' ),
			'desc'  => __( 'The whole network is open source — and increasingly built and operated by AI agents we direct in plain language, live on the server. Peek under the hood.', 'extrachill-blog' ),
			'url'   => 'https://github.com/Extra-Chill',
			'cta'   => __( 'See the code on GitHub', 'extrachill-blog' ),
			'proof' => '',
		),
	);

	ob_start();
	?>
	<div class="power-network-map">
		<?php foreach ( $cards as $card ) : ?>
			<a class="power-network-card" href="<?php echo esc_url( $card['url'] ); ?>">
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
 * Render the full /power manifesto HTML.
 *
 * @return string Manifesto markup.
 */
function extrachill_blog_power_manifesto_html() {
	$community_url       = extrachill_blog_power_site_url( 'community', 'https://community.extrachill.com' );
	$events_url          = extrachill_blog_power_site_url( 'events', 'https://events.extrachill.com' );
	$artist_url          = extrachill_blog_power_site_url( 'artist', 'https://artist.extrachill.com' );
	$artist_register_url = trailingslashit( $artist_url ) . 'login/#tab-register';

	$pillars = array(
		extrachill_blog_power_pillar(
			__( 'The Power of Independent Music', 'extrachill-blog' ),
			__( 'Our live music calendar is on a mission: a comprehensive listing of concerts everywhere — big cities and small ones, big artists and small ones alike. Free to browse, no login wall, no algorithm deciding what you see — just a straight answer to "who\'s playing near me?"', 'extrachill-blog' ),
			__( 'Browse the calendar', 'extrachill-blog' ),
			$events_url
		),
		extrachill_blog_power_pillar(
			__( 'The Power of Community', 'extrachill-blog' ),
			__( 'We are not shouting into the void. Musicians, fans, and industry folks meet in the same forums, upvote each other, and build real connections. The scene is a conversation, and everyone has a seat at the table.', 'extrachill-blog' ),
			__( 'Join the conversation', 'extrachill-blog' ),
			$community_url
		),
		extrachill_blog_power_pillar(
			__( 'The Power of the Platform', 'extrachill-blog' ),
			__( 'Every independent artist gets a free home base: a link page at extrachill.link, your own subscribers, and analytics. You own your corner — we just hand you the keys and get out of the way.', 'extrachill-blog' ),
			__( 'Build your home base', 'extrachill-blog' ),
			$artist_register_url
		),
		extrachill_blog_power_pillar(
			__( 'The Power of Staying True', 'extrachill-blog' ),
			__( 'Since 2011, out of a Charleston dorm room. We stand by our core philosophies and never give in to corporate pressure or aggressive monetization. Sustainable, memorable, and built on the spirit of the music.', 'extrachill-blog' ),
			__( 'Read our story', 'extrachill-blog' ),
			home_url( '/about/' )
		),
	);

	$artist_benefits = array(
		__( 'A free link page at extrachill.link — your whole presence at one URL.', 'extrachill-blog' ),
		__( 'Build a subscriber list so you can reach fans directly.', 'extrachill-blog' ),
		__( 'Get discovered by a community that actually shows up.', 'extrachill-blog' ),
		__( 'Analytics so you can see what\'s working.', 'extrachill-blog' ),
	);

	ob_start();
	?>
	<div class="power-page">

		<header class="power-hero">
			<h1 class="power-hero__title"><?php esc_html_e( 'Extra Chill is the online music scene.', 'extrachill-blog' ); ?></h1>
			<p class="power-hero__lede"><?php esc_html_e( 'Not just a blog — an independent, open-source music network. A live calendar, a community of musicians and fans, a festival news wire, and free tools for artists to run their own corner. All grassroots, all independent, no astro-turf.', 'extrachill-blog' ); ?></p>
			<a class="button-1 button-large power-hero__cta" href="<?php echo esc_url( $community_url ); ?>"><?php esc_html_e( 'Join the community', 'extrachill-blog' ); ?></a>
		</header>

		<section class="power-section">
			<h2 class="power-section__heading"><?php esc_html_e( 'What we stand for', 'extrachill-blog' ); ?></h2>
			<div class="power-pillars">
				<?php
				foreach ( $pillars as $pillar ) {
					echo $pillar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_* helpers in extrachill_blog_power_pillar().
				}
				?>
			</div>
		</section>

		<section class="power-section">
			<h2 class="power-section__heading"><?php esc_html_e( 'One network, many doors', 'extrachill-blog' ); ?></h2>
			<p class="power-section__lede"><?php esc_html_e( 'Every surface below is live and growing. Pick a door.', 'extrachill-blog' ); ?></p>
			<?php
			echo extrachill_blog_power_network_map(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_* helpers in extrachill_blog_power_network_map().
			?>
		</section>

		<section class="power-artists" id="artists">
			<h2 class="power-artists__title"><?php esc_html_e( 'Are you an artist? Here\'s your home base.', 'extrachill-blog' ); ?></h2>
			<p class="power-artists__intro"><?php esc_html_e( 'Extra Chill gives independent artists real tools, free, with no catch:', 'extrachill-blog' ); ?></p>
			<ul class="power-artists__list">
				<?php foreach ( $artist_benefits as $benefit ) : ?>
					<li><?php echo esc_html( $benefit ); ?></li>
				<?php endforeach; ?>
			</ul>
			<a class="button-1 button-large power-artists__cta" href="<?php echo esc_url( $artist_register_url ); ?>"><?php esc_html_e( 'Claim your free artist profile', 'extrachill-blog' ); ?></a>
		</section>

		<section class="power-closing">
			<h2 class="power-closing__title"><?php esc_html_e( 'We hope you\'ll stick around.', 'extrachill-blog' ); ?></h2>
			<p class="power-closing__text"><?php esc_html_e( 'If independent music is your thing, you\'re already home. Pull up a chair in the community.', 'extrachill-blog' ); ?></p>
			<a class="button-1 button-large power-closing__cta" href="<?php echo esc_url( $community_url ); ?>"><?php esc_html_e( 'Join the community', 'extrachill-blog' ); ?></a>
		</section>

	</div>
	<?php
	return trim( ob_get_clean() );
}

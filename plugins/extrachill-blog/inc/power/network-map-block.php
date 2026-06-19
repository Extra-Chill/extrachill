<?php
/**
 * Network Map dynamic block for the /power manifesto page.
 *
 * Renders the "network map" section of extrachill.com/power: a grid of surface
 * cards (Live Music Calendar, The Community, Festival Wire, Artist Platform,
 * The Publication), each carrying a LIVE proof-number sourced from
 * ec_get_network_stats() and a deep link into the corresponding subsite.
 *
 * Why a dynamic (server-rendered) block instead of a static theme pattern:
 *  - The theme deliberately does NOT ship a static `extrachill/network-map`
 *    pattern. It was dropped (theme PR #38) precisely because hardcoded /
 *    placeholder proof numbers on a credibility-driven manifesto page are worse
 *    than none. Live numbers must come from NetworkStats at render time.
 *  - Storing live numbers in a page's post_content would freeze them; rendering
 *    them in a dynamic block keeps the page honest and self-updating.
 *  - extrachill-blog has no JS build system (see CLAUDE.md), so this block is
 *    PHP-only: registered with a render_callback and no editor script. It is not
 *    insertable in the editor — it is seeded into the /power page content
 *    programmatically (mirroring how the artist platform seeds self-closing
 *    dynamic blocks like `<!-- wp:extrachill/artist-creator /-->`).
 *
 * Graceful degradation: every NetworkStats read is guarded with
 * function_exists( 'ec_get_network_stats' ) and each metric is checked for
 * `available`. When a number is unavailable the card still renders with its
 * label and link — it just omits the proof number. The page never fatals if
 * extrachill-multisite is a version behind.
 *
 * @package ExtraChillBlog
 * @since 0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the extrachill/network-map dynamic block.
 *
 * PHP-only block: a render_callback with no editor script. Safe to call on
 * `init`; bails if block registration is unavailable.
 */
function extrachill_blog_register_network_map_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	register_block_type(
		'extrachill/network-map',
		array(
			'api_version'     => 2,
			'title'           => __( 'Network Map', 'extrachill-blog' ),
			'description'     => __( 'Surface cards for the Extra Chill network with live proof numbers and deep links. Used on the /power manifesto page.', 'extrachill-blog' ),
			'category'        => 'widgets',
			'render_callback' => 'extrachill_blog_render_network_map_block',
			'supports'        => array(
				'html'     => false,
				'reusable' => false,
			),
		)
	);
}
add_action( 'init', 'extrachill_blog_register_network_map_block' );

/**
 * Resolve the live network statistics needed by the network map.
 *
 * Single guarded call to ec_get_network_stats() for all keys the map consumes.
 * Returns an empty array when the NetworkStats primitive is unavailable so the
 * renderer degrades to label-only cards.
 *
 * @return array<string,array{key:string,label:string,value:int|array|null,available:bool}>
 */
function extrachill_blog_get_network_map_stats() {
	if ( ! function_exists( 'ec_get_network_stats' ) ) {
		return array();
	}

	$keys = array(
		'events_count',
		'events_cities',
		'community_members',
		'community_topics',
		'wire_posts',
		'artist_profiles',
		'total_posts',
	);

	$stats = ec_get_network_stats( $keys );

	return is_array( $stats ) ? $stats : array();
}

/**
 * Extract a single integer proof-number from a NetworkStats envelope map.
 *
 * Honors the "honesty rule": returns null unless the metric is explicitly
 * available with a numeric value.
 *
 * @param array  $stats NetworkStats envelope map keyed by metric.
 * @param string $key   Metric key.
 * @return int|null Integer value or null when unavailable.
 */
function extrachill_blog_network_stat_value( array $stats, $key ) {
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
 * Build the proof-number line for a card from one or more metric fragments.
 *
 * Each fragment is rendered as "{number} {label}" only when its value is
 * available; unavailable fragments are dropped. Returns an empty string when no
 * fragment resolves, so the caller can omit the proof line entirely.
 *
 * @param array $stats     NetworkStats envelope map.
 * @param array $fragments List of [ 'key' => metric_key, 'singular' => 'event', 'plural' => 'events' ].
 * @return string Escaped, human-readable proof line (may be empty).
 */
function extrachill_blog_network_proof_line( array $stats, array $fragments ) {
	$parts = array();

	foreach ( $fragments as $fragment ) {
		$value = extrachill_blog_network_stat_value( $stats, $fragment['key'] );

		if ( null === $value ) {
			continue;
		}

		$noun = ( 1 === $value )
			? $fragment['singular']
			: $fragment['plural'];

		$parts[] = number_format_i18n( $value ) . ' ' . $noun;
	}

	return implode( ' across ', $parts );
}

/**
 * Render callback for the extrachill/network-map block.
 *
 * @return string Block HTML.
 */
function extrachill_blog_render_network_map_block() {
	$stats = extrachill_blog_get_network_map_stats();

	$site_url = static function ( $key, $fallback ) {
		if ( function_exists( 'ec_get_site_url' ) ) {
			$url = ec_get_site_url( $key );
			if ( ! empty( $url ) ) {
				return $url;
			}
		}
		return $fallback;
	};

	$cards = array(
		array(
			'title' => __( 'Live Music Calendar', 'extrachill-blog' ),
			'desc'  => __( 'Independent shows and festivals, city by city — built from venue listings, not ad buys.', 'extrachill-blog' ),
			'url'   => $site_url( 'events', 'https://events.extrachill.com' ),
			'cta'   => __( 'Browse the calendar', 'extrachill-blog' ),
			'proof' => extrachill_blog_network_proof_line(
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
			'url'   => $site_url( 'community', 'https://community.extrachill.com' ),
			'cta'   => __( 'Join the conversation', 'extrachill-blog' ),
			'proof' => extrachill_blog_network_proof_line(
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
			'url'   => $site_url( 'wire', 'https://wire.extrachill.com' ),
			'cta'   => __( 'Read the Wire', 'extrachill-blog' ),
			'proof' => extrachill_blog_network_proof_line(
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
			'desc'  => __( 'Free link pages, forums, and merch tools for independent artists to run their own corner.', 'extrachill-blog' ),
			'url'   => $site_url( 'artist', 'https://artist.extrachill.com' ),
			'cta'   => __( 'Explore the platform', 'extrachill-blog' ),
			'proof' => extrachill_blog_network_proof_line(
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
			'proof' => extrachill_blog_network_proof_line(
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

<?php
/**
 * Homepage Hero Section
 *
 * Welcome message, live network proof numbers, and primary CTAs.
 * The stat strip renders only metrics NetworkStats reports as available —
 * never a fabricated zero.
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

$username = '';
if ( is_user_logged_in() ) {
	$user     = wp_get_current_user();
	$username = $user->user_nicename;
}

$hero_stats = extrachill_blog_get_hero_stats();

$hero_stat_labels = array(
	'events_count'    => __( 'upcoming events', 'extrachill-blog' ),
	'events_cities'   => __( 'cities', 'extrachill-blog' ),
	'total_members'   => __( 'members', 'extrachill-blog' ),
	'artist_profiles' => __( 'artists', 'extrachill-blog' ),
);

$events_url    = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'events' ) : 'https://events.extrachill.com';
$community_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : 'https://community.extrachill.com';
$artist_url    = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'artist' ) : 'https://artist.extrachill.com';
?>
<div class="full-width-breakout ec-edge-shell">
<section id="hero-section">
	<h2>
		<?php
		if ( $username ) {
			printf(
				/* translators: %s: user display name */
				esc_html__( 'Welcome back, %s', 'extrachill-blog' ),
				esc_html( $username )
			);
		} else {
			esc_html_e( 'Join the Online Music Scene', 'extrachill-blog' );
		}
		?>
	</h2>

	<h3>
		<?php
		echo $username
			? esc_html__( 'Thanks for being part of the scene', 'extrachill-blog' )
			: esc_html__( 'A live music calendar, a community, and free tools for independent artists', 'extrachill-blog' );
		?>
	</h3>

	<?php if ( ! empty( $hero_stats ) ) : ?>
		<div class="hero-stat-strip">
			<?php foreach ( $hero_stats as $stat_key => $stat_value ) : ?>
				<div class="hero-stat">
					<span class="hero-stat-number"><?php echo esc_html( number_format_i18n( $stat_value ) ); ?></span>
					<span class="hero-stat-label"><?php echo esc_html( $hero_stat_labels[ $stat_key ] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="hero-buttons-container">
		<a href="<?php echo esc_url( $events_url ); ?>"
			class="button-1 button-medium">
			<?php esc_html_e( 'Live Music Calendar', 'extrachill-blog' ); ?>
		</a>

		<a href="<?php echo esc_url( $community_url ); ?>"
			class="button-2 button-medium">
			<?php esc_html_e( 'Community', 'extrachill-blog' ); ?>
		</a>

		<a href="<?php echo esc_url( $artist_url ); ?>"
			class="button-3 button-medium">
			<?php esc_html_e( 'Artist Platform', 'extrachill-blog' ); ?>
		</a>
	</div>

	<p class="hero-power-link">
		<a href="<?php echo esc_url( home_url( '/power/' ) ); ?>">
			<?php esc_html_e( 'Extra Chill is a whole network — see what\'s underneath →', 'extrachill-blog' ); ?>
		</a>
	</p>
</section>
</div>

<?php
/**
 * Homepage Artist Platform Promo Card
 *
 * Renders inside home-final section alongside the About box.
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

$is_logged_in       = is_user_logged_in();
$user_artist_ids    = array();
$can_create_artists = false;
$artist_site_url    = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'artist' ) : 'https://artist.extrachill.com';

if ( $is_logged_in ) {
	if ( function_exists( 'ec_get_artists_for_user' ) ) {
		$user_artist_ids = ec_get_artists_for_user( get_current_user_id() );
	}
	if ( function_exists( 'ec_can_create_artist_profiles' ) ) {
		$can_create_artists = ec_can_create_artist_profiles( get_current_user_id() );
	}
}
?>
<div class="home-network-card" aria-labelledby="artist-platform-header">
	<h2 class="home-network-card-header" id="artist-platform-header">Artist Platform</h2>
	<p class="home-network-card-description">
		The home for independent music on Extra Chill. Create your profile, build a custom link page, and track your growth with real-time analytics.
	</p>
	<div class="home-network-card-cta" style="display: flex; gap: var(--spacing-sm); flex-wrap: wrap; justify-content: center;">
		<?php if ( $is_logged_in && ! empty( $user_artist_ids ) ) : ?>
			<a href="<?php echo esc_url( $artist_site_url . '/manage-artist/' ); ?>" class="button-1 button-medium">Manage Artists</a>
		<?php elseif ( $is_logged_in && $can_create_artists ) : ?>
			<a href="<?php echo esc_url( $artist_site_url . '/create-artist/' ); ?>" class="button-1 button-medium">Create Artist Profile</a>
		<?php else : ?>
			<a href="https://extrachill.link/join" class="button-1 button-medium" target="_blank" rel="noopener">Join the Platform</a>
		<?php endif; ?>
		<a href="<?php echo esc_url( $artist_site_url . '/artists/' ); ?>" class="button-3 button-medium">Browse Artists</a>
	</div>
</div>

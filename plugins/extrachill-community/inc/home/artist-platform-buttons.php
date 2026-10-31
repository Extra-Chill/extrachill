<?php
/**
 * Artist Platform Integration Button
 *
 * Displays prominent Artist Platform CTA on community homepage via hook integration.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the current user's Artist Platform homepage action.
 *
 * @return array|null Action URL and label, or null when the Artist Platform is unavailable.
 */
function ec_community_get_artist_platform_action() {
	if ( ! function_exists( 'ec_get_site_url' ) ) {
		return null;
	}

	$artist_site_url = ec_get_site_url( 'artist' );
	if ( ! is_string( $artist_site_url ) || '' === $artist_site_url ) {
		return null;
	}

	$artist_site_url = rtrim( $artist_site_url, '/' );
	if ( is_user_logged_in() ) {
		$user_id         = get_current_user_id();
		$user_artist_ids = function_exists( 'ec_get_artists_for_user' )
			? ec_get_artists_for_user( $user_id )
			: array();

		if ( ! empty( $user_artist_ids ) ) {
			return array(
				'url'   => $artist_site_url . '/manage-artist/',
				'label' => __( 'Manage Artists', 'extra-chill-community' ),
			);
		}

		if ( function_exists( 'ec_can_create_artist_profiles' ) && ec_can_create_artist_profiles( $user_id ) ) {
			return array(
				'url'   => $artist_site_url . '/create-artist/',
				'label' => __( 'Create Artist Profile', 'extra-chill-community' ),
			);
		}
	}

	return array(
		'url'   => $artist_site_url,
		'label' => __( 'Explore Artist Platform', 'extra-chill-community' ),
	);
}

/**
 * Add the Artist Platform action to the community homepage after the forums loop.
 */
function ec_community_add_artist_platform_buttons() {
	$action = ec_community_get_artist_platform_action();
	if ( null === $action ) {
		return;
	}
	?>
	<div class="artist-platform-homepage-actions">
		<a href="<?php echo esc_url( $action['url'] ); ?>" class="button-2 button-medium">
			<?php echo esc_html( $action['label'] ); ?>
		</a>
	</div>
	<?php
}

add_action( 'extrachill_community_home_after_forums', 'ec_community_add_artist_platform_buttons' );

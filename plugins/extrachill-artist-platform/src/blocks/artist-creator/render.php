<?php
/**
 * Artist Creator Block - Server-Side Render
 *
 * Renders the artist creation form on the frontend for authorized users.
 * Single-purpose: create a new artist profile.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Require login
if ( ! is_user_logged_in() ) {
    echo '<div class="notice notice-info">';
    echo '<p>' . esc_html__( 'Please log in to create an artist profile.', 'extrachill-artist-platform' ) . '</p>';
    echo '</div>';
    return;
}

$current_user_id = get_current_user_id();

// Ensure required functions exist
if ( ! function_exists( 'ec_can_create_artist_profiles' ) ) {
    echo '<div class="notice notice-error">';
    echo '<p>' . esc_html__( 'Artist platform is not properly configured.', 'extrachill-artist-platform' ) . '</p>';
    echo '</div>';
    return;
}

// Check permission
$can_create = ec_can_create_artist_profiles( $current_user_id );

if ( ! $can_create ) {
	echo '<div class="notice notice-info">';
	echo '<p>' . esc_html__( 'Artist profiles are for artists and music professionals.', 'extrachill-artist-platform' ) . '</p>';
	echo '</div>';
	return;
}

// Hard stop: if the user already owns at least one artist profile, render ONLY
// the notice + management actions and do NOT mount the React creation form.
// Falling through here previously left the blank creation form fully usable
// below the notice, allowing the same user to create duplicate profiles (#82).
$existing_artists = array();
if ( function_exists( 'ec_get_artists_for_user' ) ) {
	$existing_artists = ec_get_artists_for_user( $current_user_id );
}

if ( ! empty( $existing_artists ) ) {
	$artist_count = count( $existing_artists );
	echo '<div class="notice notice-info">';
	if ( $artist_count === 1 ) {
		echo '<p>' . esc_html__( 'You already have an artist profile.', 'extrachill-artist-platform' ) . '</p>';
	} else {
		echo '<p>' . sprintf(
			/* translators: %d: number of artist profiles */
			esc_html__( 'You already have %d artist profiles.', 'extrachill-artist-platform' ),
			$artist_count
		) . '</p>';
	}
	echo '<a href="' . esc_url( home_url( '/manage-artist/' ) ) . '" class="button-1 button-medium">' . esc_html__( 'Manage Artist', 'extrachill-artist-platform' ) . '</a>';
	echo '</div>';
	return;
}

// Emit the artist_signup_started funnel event: an eligible logged-in user is
// viewing the create-artist form (entered the activation flow). Carries the
// anonymous visitor_id + user_id so this step stitches to the upstream
// user_registration row and the downstream artist_profile_created /
// _first_publish rows for the same member. This emit is intentionally dumb —
// one row per form render. Per-person dedup is the READER's job: the funnel
// is read via the extrachill/get-activation-funnel ability, which counts each
// step as COUNT(DISTINCT COALESCE(NULLIF(user_id,0), visitor_id)), so a
// member re-viewing the form collapses to one person and does not distort the
// funnel. Do NOT read this funnel with the generic get-analytics-summary
// COUNT(*), which counts rows and would over-count signup-flow entries.
// Users who already have a profile never reach this point — the hard stop
// above returns before the form (and this emit) renders, so they are correctly
// excluded from the signup_started step.
if ( function_exists( 'ec_artist_platform_emit_funnel_event' )
	&& defined( 'EC_ANALYTICS_EVENT_ARTIST_SIGNUP_STARTED' ) ) {
	ec_artist_platform_emit_funnel_event(
		EC_ANALYTICS_EVENT_ARTIST_SIGNUP_STARTED,
		array( 'user_id' => $current_user_id )
	);
}

// Prefill data from user profile.
// Intentionally do NOT prefill artist_name from the user's display_name: an
// artist/act name is rarely the same as the person's name, and a confused user
// who submits without changing it creates a profile literally named after
// themselves. The field renders empty with a placeholder so the user must
// consciously type the act's name.
$prefill = array();
$current_user = wp_get_current_user();
if ( $current_user && $current_user->ID ) {
    $prefill['avatar_id']   = get_user_meta( $current_user->ID, 'custom_avatar_id', true );
    if ( $prefill['avatar_id'] ) {
        $prefill['avatar_thumb'] = wp_get_attachment_image_url( $prefill['avatar_id'], 'thumbnail' );
    }
}

// Build config payload for frontend
$config = array(
    'restUrl'           => rest_url( 'extrachill/v1/' ),
    'nonce'             => wp_create_nonce( 'wp_rest' ),
    'prefill'           => $prefill,
    'manageArtistUrl'   => home_url( '/manage-artist/' ),
    'createLinkPageUrl' => home_url( '/manage-link-page/' ),
    // Admin-only during development
    'createShopUrl'     => current_user_can( 'manage_options' ) ? home_url( '/manage-shop/' ) : '',
);

// Enqueue frontend script and styles
$asset_file = include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'build/blocks/artist-creator/view.asset.php';

wp_enqueue_script(
    'ec-artist-creator-frontend',
    EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'build/blocks/artist-creator/view.js',
    $asset_file['dependencies'],
    $asset_file['version'],
    true
);

wp_localize_script( 'ec-artist-creator-frontend', 'ecArtistCreatorConfig', $config );

// Render mount point
$wrapper_attributes = get_block_wrapper_attributes( array(
    'class' => 'ec-artist-creator',
) );

echo '<div ' . $wrapper_attributes . '>';
echo '<div id="ec-artist-creator-root"></div>';
echo '</div>';

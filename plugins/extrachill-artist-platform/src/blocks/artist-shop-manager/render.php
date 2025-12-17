<?php
/**
 * Artist Shop Manager Block - Server-Side Render
 *
 * Renders the shop manager React app on the frontend for logged-in users.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! is_user_logged_in() ) {
    echo '<div class="notice notice-info">';
    echo '<p>' . esc_html__( 'Please log in to manage your shop products.', 'extrachill-artist-platform' ) . '</p>';
    echo '</div>';
    return;
}

$current_user_id = get_current_user_id();

if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
    echo '<div class="notice notice-error">';
    echo '<p>' . esc_html__( 'Artist platform is not properly configured.', 'extrachill-artist-platform' ) . '</p>';
    echo '</div>';
    return;
}

$user_artists = ec_get_artists_for_user( $current_user_id, true );
$selected_id  = 0;

if ( ! empty( $user_artists ) ) {
    $selected_id = function_exists( 'ec_get_latest_artist_for_user' )
        ? ec_get_latest_artist_for_user( $current_user_id )
        : (int) reset( $user_artists );
}

$user_artists_data = array();
foreach ( $user_artists as $artist_id ) {
    $artist_post = get_post( $artist_id );
    if ( $artist_post && $artist_post->post_status === 'publish' ) {
        $stripe_account_id = (string) get_post_meta( $artist_id, '_stripe_connect_account_id', true );
        $stripe_status     = (string) get_post_meta( $artist_id, '_stripe_connect_status', true );
        $stripe_connected  = ! empty( $stripe_account_id );

        $user_artists_data[] = array(
            'id'                 => (int) $artist_id,
            'name'               => $artist_post->post_title,
            'slug'               => $artist_post->post_name,
            'stripe_connected'   => $stripe_connected,
            'stripe_status'      => $stripe_status,
            'can_receive_payments' => ( 'active' === $stripe_status ),
        );
    }
}

$shop_rest_url = function_exists( 'ec_get_site_url' )
    ? trailingslashit( ec_get_site_url( 'shop' ) ) . 'wp-json/extrachill/v1/'
    : rest_url( 'extrachill/v1/' );

$config = array(
    'restUrl'     => rest_url( 'extrachill/v1/' ),
    'shopRestUrl' => $shop_rest_url,
    'nonce'       => wp_create_nonce( 'wp_rest' ),
    'userArtists' => array_values( $user_artists_data ),
    'selectedId'  => (int) $selected_id,
);

$asset_file = include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'build/blocks/artist-shop-manager/view.asset.php';

wp_enqueue_script(
    'ec-artist-shop-manager-frontend',
    EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL . 'build/blocks/artist-shop-manager/view.js',
    $asset_file['dependencies'],
    $asset_file['version'],
    true
);

wp_localize_script( 'ec-artist-shop-manager-frontend', 'ecArtistShopManagerConfig', $config );

$wrapper_attributes = get_block_wrapper_attributes( array(
    'class' => 'ec-artist-shop-manager',
) );

echo '<div ' . $wrapper_attributes . '>';
echo '<div id="ec-artist-shop-manager-root" data-selected-id="' . esc_attr( (int) $selected_id ) . '"></div>';
echo '</div>';


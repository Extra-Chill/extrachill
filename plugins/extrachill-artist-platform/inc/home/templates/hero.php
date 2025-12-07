<?php
/**
 * Artist Platform Homepage Hero Section
 *
 * Displays welcome/hero message based on user authentication state:
 * - Not logged in: Welcome message with login/signup buttons
 * - Logged in, no artists: Personalized welcome
 * - Logged in with artists: Dashboard welcome
 *
 * @package ExtraChillArtistPlatform
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Extract parameters from action hook
$current_user       = isset( $current_user ) ? $current_user : wp_get_current_user();
$is_logged_in       = isset( $is_logged_in ) ? $is_logged_in : is_user_logged_in();
$can_create_artists = isset( $can_create_artists ) ? $can_create_artists : false;
$user_artist_ids    = isset( $user_artist_ids ) ? $user_artist_ids : array();
?>

<?php if ( ! $is_logged_in ) : ?>
    <!-- Not Logged In - Hero -->
    <div class="artist-home-hero">
        <h2><?php esc_html_e( 'Welcome to the Artist Platform', 'extrachill-artist-platform' ); ?></h2>
        <p><?php esc_html_e( 'Create your artist profile, build a custom link page, connect with fans, and manage your music career all in one place.', 'extrachill-artist-platform' ); ?></p>

        <div class="welcome-actions">
            <a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="button-1 button-large">
                <?php esc_html_e( 'Log In', 'extrachill-artist-platform' ); ?>
            </a>
            <a href="<?php echo esc_url( home_url( '/login/#tab-register' ) ); ?>" class="button-2 button-large">
                <?php esc_html_e( 'Sign Up', 'extrachill-artist-platform' ); ?>
            </a>
        </div>
    </div>

<?php elseif ( empty( $user_artist_ids ) && $can_create_artists ) : ?>
    <!-- Logged In, No Artists, CAN Create -->
    <div class="artist-home-hero">
        <h2><?php printf( esc_html__( 'Welcome, %s!', 'extrachill-artist-platform' ), esc_html( $current_user->display_name ) ); ?></h2>
        <p><?php esc_html_e( 'Ready to get started with your artist journey? Create your first artist profile to unlock all platform features.', 'extrachill-artist-platform' ); ?></p>
        <div class="welcome-actions">
            <a href="<?php echo esc_url( home_url( '/manage-artist-profiles/' ) ); ?>" class="button-1 button-large">
                <?php esc_html_e( 'Create Artist Profile', 'extrachill-artist-platform' ); ?>
            </a>
        </div>
    </div>

<?php elseif ( empty( $user_artist_ids ) ) : ?>
    <!-- Logged In, No Artists, CANNOT Create -->
    <div class="artist-home-hero">
        <h2><?php printf( esc_html__( 'Welcome, %s!', 'extrachill-artist-platform' ), esc_html( $current_user->display_name ) ); ?></h2>
        <p><?php esc_html_e( 'Request artist access to create profiles and custom link pages on extrachill.link.', 'extrachill-artist-platform' ); ?></p>
        <div class="welcome-actions">
            <a href="https://community.extrachill.com/settings/#tab-artist-platform" class="button-1 button-large">
                <?php esc_html_e( 'Request Artist Access', 'extrachill-artist-platform' ); ?>
            </a>
        </div>
    </div>

<?php else : ?>
    <!-- Logged In, Has Artists - Dashboard Welcome -->
    <div class="artist-home-hero">
        <h2><?php printf( esc_html__( 'Welcome back, %s!', 'extrachill-artist-platform' ), esc_html( $current_user->display_name ) ); ?></h2>
        <p><?php printf( esc_html( _n( 'Manage your artist profile and platform features below.', 'Manage your %d artist profiles and platform features below.', count( $user_artist_ids ), 'extrachill-artist-platform' ) ), count( $user_artist_ids ) ); ?></p>
    </div>

<?php endif; ?>

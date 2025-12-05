<?php
/**
 * Artist Profile Archive Template
 *
 * Displays the artist directory at /artists/ using activity-sorted artist cards.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<?php do_action( 'extrachill_before_body_content' ); ?>

<?php extrachill_breadcrumbs(); ?>

<header class="page-header">
    <h1 class="page-title"><?php esc_html_e( 'Artist Directory', 'extrachill-artist-platform' ); ?></h1>
</header>

<div class="taxonomy-description">
    <p><?php esc_html_e( 'Discover amazing artists, connect with them, and join their community discussions.', 'extrachill-artist-platform' ); ?></p>
</div>

<?php if ( is_user_logged_in() ) : ?>
    <?php
    $current_user_id = get_current_user_id();
    $user_artist_ids = ec_get_artists_for_user( $current_user_id );
    $artist_count    = count( $user_artist_ids );

    if ( $artist_count > 0 ) :
        $latest_artist_id = ec_get_latest_artist_for_user( $current_user_id );
        $manage_url       = home_url( '/manage-artist-profiles/?artist_id=' . $latest_artist_id );
        $artist_label     = $artist_count === 1
            ? esc_html__( 'Manage Artist', 'extrachill-artist-platform' )
            : esc_html__( 'Manage Artists', 'extrachill-artist-platform' );
        ?>
        <div class="artist-directory-actions">
            <a href="<?php echo esc_url( $manage_url ); ?>" class="button-2 button-medium">
                <?php echo $artist_label; ?>
            </a>
        </div>
    <?php elseif ( ec_can_create_artist_profiles( $current_user_id ) ) : ?>
        <div class="artist-directory-actions">
            <a href="<?php echo esc_url( home_url( '/manage-artist-profiles/' ) ); ?>" class="button-2 button-medium">
                <?php esc_html_e( 'Create Artist Profile', 'extrachill-artist-platform' ); ?>
            </a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
do_action( 'extrachill_archive_above_posts' );
?>

<div class="full-width-breakout">
    <div class="article-container">
        <?php ec_display_artist_cards_grid( 24, false, true ); ?>
    </div>
</div>

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>

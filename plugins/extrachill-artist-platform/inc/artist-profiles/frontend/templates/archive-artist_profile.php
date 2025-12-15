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

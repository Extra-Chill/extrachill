<?php
/**
 * Recently Active Topics Component
 *
 * Template component loaded via extrachill_community_home_top action hook.
 * Displays three most recently active forum topics on community homepage.
 * Registered by inc/home/actions.php.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bbp_get_topic_post_type' ) || ! function_exists( 'bbpress' ) ) {
    return;
}

$query_args = array(
    'post_type'      => bbp_get_topic_post_type(),
    'posts_per_page' => 3,
    'post_status'    => 'publish',
    'orderby'        => 'meta_value',
    'meta_key'       => '_bbp_last_active_time',
    'meta_type'      => 'DATETIME',
    'order'          => 'DESC',
);

$query = new WP_Query( $query_args );
?>

<div class="recently-active-topics">
    <h2>Recently Active Topics</h2>
    <div class="bbp-topics-grid recently-active-topic-row">
        <?php
        if ( $query->have_posts() ) :
            while ( $query->have_posts() ) :
                $query->the_post();
                $topic_id = get_the_ID();
                ?>
				<?php require locate_template( 'bbpress/loop-single-topic-card.php', false, false ); ?>
                <?php
            endwhile;

            wp_reset_postdata();
        else :
            echo '<p>No recently active topics found.</p>';
        endif;
        ?>
    </div>
    <div class="view-all-users-link">
        <a href="<?php echo esc_url( home_url( '/recent' ) ); ?>" class="button-3 button-medium">View Recently Active</a>
    </div>
</div>

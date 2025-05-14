<?php
/**
 * Template Name: Leaderboard Template
 *
 * @package WordPress
 * @subpackage Your_Theme
 */

get_header();
?>
<div <?php generate_do_attr( 'page' ); ?>>
    <?php
    /**
     * generate_inside_site_container hook.
     */
    do_action( 'generate_inside_site_container' );
    ?>
    <div <?php generate_do_attr( 'site-content' ); ?>>
        <?php
        /**
         * generate_inside_container hook.
         */
        do_action( 'generate_inside_container' );
        ?>
        <?php extrachill_breadcrumbs(); ?>

<?php

echo '<div id="chill-home">';
echo '<div id="chill-home-header"><span>';

// Display leaderboard title
echo '<h1 class="leaderboard-title">' . __('Leaderboard', 'your-theme') . '</h1>';

// Check if we are on a user profile page - REMOVED for leaderboard

echo '</span>';

echo '</div>'; // End of chill-home-header

// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;

// Define items per page for pagination and calculate the offset
$items_per_page = 25; // Adjust as needed
$current_page = max(1, get_query_var('paged', 1));
$offset = ($current_page - 1) * $items_per_page;

// Adjust the user query to fetch users ordered by points
$args = [
    'orderby' => 'meta_value_num',
    'meta_key' => 'wp_surgeon_total_points',
    'order' => 'DESC',
    'number' => $items_per_page,
    'offset' => $offset, 
];


// Fetch users with adjusted query
$user_query = new WP_User_Query($args);

// Total users for pagination
$total_users = $user_query->get_total();

// Number of pages
$total_pages = ceil($total_users / $items_per_page);

// Users
$users = $user_query->get_results();

// REMOVED Newest member section


echo '<div class="bbp-user-profile"><div class="bbp-user-section">';
echo '<table class="leaderboard-table">';
echo '<thead><tr><th>Username</th><th>Points</th><th>Rank</th><th>Join Date</th></tr></thead>';
echo '<tbody>';

foreach ($users as $user) {
    $user_profile_url = bbp_get_user_profile_url($user->ID);
    $join_date = date("Y-m-d", strtotime($user->user_registered));
    $points = wp_surgeon_display_user_points($user->ID); 
    $rank = wp_surgeon_display_user_rank($user->ID); 

    echo '<tr>';
    echo '<td><a href="' . esc_url($user_profile_url) . '">' . esc_html($user->display_name) . '</a></td>';
    echo '<td>' . esc_html($points) . '</td>';
    echo '<td>' . esc_html($rank) . '</td>';
    echo '<td>' . esc_html($join_date) . '</td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</div></div>';

echo '</div>'; // End of chill-home

// Pagination setup
if ($total_pages > 1) {
    $pagination_args = array(
        'current'   => $current_page,
        'total'     => $total_pages,
        'prev_text' => __('« Prev'),
        'next_text' => __('Next »'),
    );
    $pagination_args = array_merge($pagination_args, array(
        'base'   => get_pagenum_link(1) . '%_%',
        'format' => 'page/%#%/',
    ));

    echo '<div class="pagination leaderboard-pagination">';
    echo paginate_links($pagination_args);
    echo '</div>';
}

?>
        </div><!-- .site-content -->
    </div><!-- .page -->
<?php
get_footer();
?>

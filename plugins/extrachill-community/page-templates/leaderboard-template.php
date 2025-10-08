<?php
/**
 * Template Name: Leaderboard Template
 *
 * Displays user ranking based on community engagement points.
 *
 * @package ExtraChillCommunity
 */

get_header();
?>
<div class="page-content">
    <?php
    /**
     * Custom hook for inside site container.
     */
    do_action( 'extra_chill_inside_site_container' );
    ?>
    <div class="site-content">
        <div class="container">
            <?php
            /**
             * Custom hook for inside container.
             */
            do_action( 'extra_chill_inside_container' );
            ?>
        <?php extrachill_breadcrumbs(); ?>

<?php

// Display leaderboard title
echo '<h1 class="leaderboard-title">' . __('Leaderboard', 'your-theme') . '</h1>';

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
    'meta_key' => 'extrachill_total_points',
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
    $points = extrachill_display_user_points($user->ID); 
    $rank = extrachill_display_user_rank($user->ID); 

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

// Pagination setup - Create mock query object for centralized pagination
if ($total_pages > 1) {
    $mock_query = new stdClass();
    $mock_query->max_num_pages = $total_pages;
    $mock_query->found_posts = $total_users;
    $mock_query->query_vars = array('posts_per_page' => $items_per_page, 'paged' => $current_page);

    extrachill_pagination($mock_query, 'leaderboard');
}

?>
        </div><!-- .container -->
    </div><!-- .site-content -->
</div><!-- .page-content -->
<?php
get_footer();
?>

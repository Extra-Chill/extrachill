<?php
/**
 * Template Name: User List with Points and Join Date
 *
 * @package WordPress
 * @subpackage Your_Theme
 */

get_header();

echo '<div id="chill-home">';
echo '<div id="chill-home-header"><span>';

// Check if we are on a user profile page
$isUserProfile = bbp_is_single_user();

if ($isUserProfile) {
    $title = '@' . bbp_get_displayed_user_field('user_nicename');
    echo '<h1 class="profile-title-inline">' . $title . '</h1>';

    // Display the follow button only on user profile pages
    if (function_exists('extrachill_follow_button')) {
        extrachill_follow_button(bbp_get_displayed_user_id());
    }
} else {
    // Display the title for non-profile pages
    echo '<h1>' . get_the_title() . '</h1>';
}

echo '</span>';

if (is_user_logged_in()) :
    echo '<p>Logged in as <a href="/user-dashboard">' . esc_html(wp_get_current_user()->display_name) . '.</a></p>';
else :
    echo '<p>You are not signed in. <a href="/login">Login</a> or <a href="/register">Register</a></p>';
endif;

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

// Adjust the user query to fetch the most recent 50 users at first
$args = [
    'orderby' => 'registered',
    'order' => 'DESC',
    'number' => $items_per_page,
    'offset' => $current_page == 1 ? 0 : (($current_page - 2) * $items_per_page) + 50,
];


// Fetch users with adjusted query
$user_query = new WP_User_Query($args);

// Total users for pagination (assuming a larger query was made initially if needed)
$total_users = $user_query->get_total();

// Number of pages
$total_pages = ceil($total_users / $items_per_page);

// Users
$users = $user_query->get_results();

// Only display the newest member section on the first page
if ($current_page == 1) {
    // Assuming $users[0] is the newest member because we ordered them by registration date DESC
    $newest_user = $users[0];
    
    // Link to the newest user's BBPress profile
    $newest_user_profile_url = bbp_get_user_profile_url($newest_user->ID);
    
    echo '<div class="user-summary">';
    echo '<p>Total Users: ' . esc_html($total_users) . '</p>'; // Display total number of users
    echo '<p>Newest Member: <a href="' . esc_url($newest_user_profile_url) . '">' . esc_html($newest_user->display_name) . '</a></p>'; // Display newest user with a link to their profile
    echo '</div>';
}


echo '<div class="bbp-user-profile"><div class="bbp-user-section">';
echo '<table>';
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
    $pagination_base = home_url('/all-users/') . '%_%';
    $pagination_args = array(
        'base' => $pagination_base,
        'format' => 'page/%#%',
        'current' => $current_page,
        'total' => $total_pages,
        'prev_text' => __('« Prev'),
        'next_text' => __('Next »'),
    );

    echo '<div class="pagination">';
    echo paginate_links($pagination_args);
    echo '</div>';
}

get_footer();
?>

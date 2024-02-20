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

// Define items per page for pagination
$items_per_page = 50; // Adjust as needed

// Get current page
$current_page = max(1, get_query_var('paged'));

// Calculate the offset
$offset = ($current_page - 1) * $items_per_page;

// Prepare the user query with pagination
$args = [
    'orderby' => 'registered',
    'order' => 'DESC',
    'number' => $items_per_page,
    'offset' => $offset,
];

// Fetch users with pagination
$user_query = new WP_User_Query($args);

// Total users for pagination
$total_users = $user_query->get_total();

// Number of pages
$total_pages = ceil($total_users / $items_per_page);

// Users
$users = $user_query->get_results();

// Separate query for the newest member
$newest_user_query = new WP_User_Query(array('number' => 1, 'orderby' => 'registered', 'order' => 'DESC'));
$newest_user = $newest_user_query->get_results()[0]; // Get the newest member

// Link to the newest user's BBPress profile
$newest_user_profile_url = bbp_get_user_profile_url($newest_user->ID);

echo '<div class="user-summary">';
echo '<p>Total Users: ' . esc_html($total_users) . '</p>'; // Display total number of users
echo '<p>Newest Member: <a href="' . esc_url($newest_user_profile_url) . '">' . esc_html($newest_user->display_name) . '</a></p>'; // Display newest user with a link to their profile
echo '</div>';

echo '<div class="bbp-user-profile"><div class="bbp-user-section">';
echo '<table>';
echo '<thead><tr><th>Username</th><th>Points</th><th>Rank</th><th>Join Date</th></tr></thead>'; // Add headers for Points, Rank, and Join Date
echo '<tbody>';

foreach ($users as $user) {
    // Link to user's BBPress profile
    $user_profile_url = bbp_get_user_profile_url($user->ID);

    // Format the join date
    $join_date = date("Y-m-d", strtotime($user->user_registered));

    // Get the user's total points (ensure this value is being accurately fetched and stored)
    $points = wp_surgeon_display_user_points($user->ID); // This function should return the user's points, calculated or retrieved from user meta

    // Get the user's rank based on their total points
    $rank = wp_surgeon_display_user_rank($user->ID); // Ensure this function correctly interprets points to determine rank

    echo '<tr>';
    echo '<td><a href="' . esc_url($user_profile_url) . '">' . esc_html($user->display_name) . '</a></td>'; // Display user's name with a link to their profile
    echo '<td>' . esc_html($points) . '</td>'; // Display user's total points
    echo '<td>' . esc_html($rank) . '</td>'; // Display user's rank
    echo '<td>' . esc_html($join_date) . '</td>'; // Display user's join date
    echo '</tr>';
}


echo '</tbody>';
echo '</table>';
echo '</div></div>';


echo '</div>'; // End of chill-home
$current_page = max(1, get_query_var('paged') ? get_query_var('paged') : get_query_var('page'));

// Check if we have more than one page of results
if ($total_pages > 1) {
$pagination_base = home_url('/all-users/') . '%_%'; // Replace '/your-page-slug/' with the actual slug of your page
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
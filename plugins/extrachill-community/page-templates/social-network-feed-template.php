<?php
/*
Template Name: Social Network Feed
Description: A page template to display a user's followers and following.
*/

get_header();

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
?>
<p id="back-to-profile"><a href="javascript:history.back()">Back to Previous Page</a></p>
<?php
// Fetch the 'section' and 'user' query parameters
$section = isset($_GET['section']) ? $_GET['section'] : 'followers';
$user_id = isset($_GET['user']) ? intval($_GET['user']) : 0;

// Check if the user exists
$user_info = get_userdata($user_id);
if (!$user_info) {
    echo '<p>User not found.</p>';
    get_footer();
    exit;
}

// Based on the section, query the respective list
if ($section === 'followers') {
    $user_list = get_user_meta($user_id, 'extrachill_followers', true);
    $list_title = "Followers";
} else {
    $user_list = get_user_meta($user_id, 'extrachill_following', true);
    $list_title = "Following";
}

// Page content starts here
echo '<div class="container social-network-page">';

// Dropdown for switching between followers and following
echo '<select id="social-section-switch" data-user-id="' . esc_attr($user_id) . '">';
echo '<option value="followers" ' . ($section === 'followers' ? 'selected' : '') . '>Followers</option>';
echo '<option value="following" ' . ($section === 'following' ? 'selected' : '') . '>Following</option>';
echo '</select>';

echo '<div class="list-social-network-page">';
echo '<h1>' . esc_html($list_title) . '</h1>';
// Display the list
if (!empty($user_list)) {
    echo '<ul>';
    foreach ($user_list as $id) {
        $user = get_userdata($id);
        if ($user) {
            $profile_url = bbp_get_user_profile_url($id);
            $name = $user->display_name;
            $avatar = get_avatar($id, 32);

            echo '<li>';
            echo '<a href="' . esc_url($profile_url) . '">' . $avatar . ' ' . esc_html($name) . '</a>';
            echo '</li>';
        }
    }
    echo '</ul>';
} else {
    echo '<p>No ' . esc_html(strtolower($list_title)) . ' found.</p>';
}

echo '</div></div>'; // Close container

get_footer();

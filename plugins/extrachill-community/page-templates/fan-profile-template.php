<?php
/*
Template Name: Fan Profile Page
*/ 

get_header();

// Get the profile ID from the URL
$profile_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

// Fetch the fan profile post
$fan_profile = get_post($profile_id);

if ($fan_profile && $fan_profile->post_type === 'fan_profile') {
    // Current user data 
    $current_user_id = get_current_user_id();

    ?>
    <div id="primary" class="content-area">
        <div id="fan-profile-links">
            <?php
            // Show the Dashboard link only if the user is logged in
            if (is_user_logged_in()) {
                echo '<a href="/user-dashboard">Dashboard</a> | ';
            }

            // Generate the BBPress user profile URL for the fan profile owner
            $bbpress_profile_url = bbp_get_user_profile_url($fan_profile->post_author);
            $community_profile_url = esc_url($bbpress_profile_url);
            echo '<a href="' . $community_profile_url . '">Community Profile</a> | ';

            // Determine the edit/create profile URL for the current logged-in user
            $post_type = 'fan_profile';
            $post_id = wp_surgeon_has_profile_post($current_user_id, $post_type);

            if (is_user_logged_in() && $post_id === $fan_profile->ID) {
                // Show 'Edit' link if the current user is the author of the profile
                $edit_fan_profile_url = get_site_url() . "/edit-profile/?post_id=" . $fan_profile->ID;
                echo '<a href="' . esc_url($edit_fan_profile_url) . '">Edit Profile</a>';
            } elseif (is_user_logged_in() && !$post_id) {
                // Show 'Create' link if the current user does not have a fan profile
                $create_fan_profile_url = get_site_url() . "/create-profile/?profile_type={$post_type}";
                echo '<a href="' . esc_url($create_fan_profile_url) . '">Create Profile</a>';
            }
            ?>
        </div>

        <main id="main" class="site-main">
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <header class="entry-header">
                    <h1 class="entry-title"><?php echo esc_html($fan_profile->post_title); ?></h1>
                    <p>Music Fan</p>
                </header><!-- .entry-header -->

                <div class="entry-content">
                    <?php echo wp_kses_post($fan_profile->post_content); ?>
                </div><!-- .entry-content -->
            </article><!-- #post-<?php the_ID(); ?> -->
        </main><!-- #main -->
    </div><!-- #primary -->
    <?php
} else {
    echo '<p>Fan profile not found.</p>';
}

get_footer();
?>

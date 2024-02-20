<?php
/*
Template Name: Professional Profile Page
*/

get_header();

// Get the profile ID from the URL
$profile_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0; // Change 'profile_id' to 'post_id'

// Fetch the professional profile post
$professional_profile = get_post($profile_id);

if ($professional_profile && $professional_profile->post_type === 'professional_profile') {
    // Display the professional profile content
    ?>
    <div id="primary" class="content-area">
        <?php
        // Show edit link if current user is the author or an admin
        $current_user = wp_get_current_user();
        if (is_user_logged_in() && (get_current_user_id() === $professional_profile->post_author || current_user_can('administrator'))) {
            $post_type = 'professional_profile';
            $post_id = wp_surgeon_has_profile_post($current_user->ID, $post_type);
            $edit_professional_profile_url = $post_id ? get_site_url() . "/edit-profile/?post_id={$post_id}" : get_site_url() . "/create-profile/?profile_type={$post_type}";
            echo '<div id="professional-profile-edit-link"><a href="' . esc_url($edit_professional_profile_url) . '">Edit Professional Profile</a></div>';
        }
        ?>
        <main id="main" class="site-main">
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <header class="entry-header">
<header class="entry-header">
    <h1 class="entry-title"><?php echo esc_html($professional_profile->post_title); ?></h1>
    <p>Music Industry Professional</p> <!-- Add this line to display "Professional" below the entry header -->
</header><!-- .entry-header -->


                <div class="entry-content">
                    <?php echo wp_kses_post($professional_profile->post_content); ?>
                </div><!-- .entry-content -->
            </article><!-- #post-<?php the_ID(); ?> -->
        </main><!-- #main -->
    </div><!-- #primary -->

    <?php
} else {
    echo '<p>Professional profile not found.</p>';
}

get_footer();
?>

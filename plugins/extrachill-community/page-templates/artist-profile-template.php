<?php
/*
Template Name: Artist Profile Page
*/

get_header();

// Get the profile ID from the URL
$profile_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0; // Change 'profile_id' to 'post_id'

// Fetch the artist profile post
$artist_profile = get_post($profile_id);

if ($artist_profile && $artist_profile->post_type === 'artist_profile') {
    // Display the artist profile content
    ?>
    <div id="primary" class="content-area">
        <?php
        // Show edit link if current user is the author or an admin
        $current_user = wp_get_current_user();
        if (is_user_logged_in() && (get_current_user_id() === $artist_profile->post_author || current_user_can('administrator'))) {
            $post_type = 'artist_profile';
            $post_id = wp_surgeon_has_profile_post($current_user->ID, $post_type);
            $edit_artist_profile_url = $post_id ? get_site_url() . "/edit-profile/?post_id={$post_id}" : get_site_url() . "/create-profile/?profile_type={$post_type}";
            echo '<div id="artist-profile-edit-link"><a href="' . esc_url($edit_artist_profile_url) . '">Edit artist Profile</a></div>';
        }
        ?>
        <main id="main" class="site-main">
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <header class="entry-header">
<header class="entry-header">
    <h1 class="entry-title"><?php echo esc_html($artist_profile->post_title); ?></h1>
    <p>Artist</p> <!-- Add this line to display "artist" below the entry header -->
</header><!-- .entry-header -->


                <div class="entry-content">
                    <?php echo wp_kses_post($artist_profile->post_content); ?>
                </div><!-- .entry-content -->
            </article><!-- #post-<?php the_ID(); ?> -->
        </main><!-- #main -->
    </div><!-- #primary -->

    <?php
} else {
    echo '<p>Artist profile not found.</p>';
}

get_footer();
?>

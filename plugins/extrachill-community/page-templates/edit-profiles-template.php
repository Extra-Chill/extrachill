<?php
/*
Template Name: Edit Profile
*/

get_header(); 
?>

<div id="chill-home">
    <div id="chill-home-header">
        <?php if (is_user_logged_in()) : ?>
            <?php
                $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
                $profile_post = get_post($post_id);
if ($profile_post->post_author != get_current_user_id()) {
    wp_redirect(home_url());
    exit;
}


                $profile_type = get_post_type($profile_post);
                // Determine the title based on the profile type
                switch ($profile_type) {
                    case 'fan_profile':
                        $title = 'Edit Fan Profile';
                        break;
                    case 'artist_profile':
                        $title = 'Edit Artist Profile';
                        break;
                    case 'professional_profile':
                        $title = 'Edit Industry Professional Profile';
                        break;
                    default:
                        $title = get_the_title(); // Default title
                        break;
                }
            ?>
            <h1><?php echo esc_html($title); ?></h1>
            <p>Logged in as <a href="/user-dashboard"><?php echo esc_html(wp_get_current_user()->display_name); ?>.</a></p>
        <?php endif; ?>
    </div>

    <?php
    // Include the appropriate profile form based on the post type
    if ($profile_type === 'fan_profile') {
        include(get_stylesheet_directory() . '/profiles/fan-profile.php');
    } elseif ($profile_type === 'artist_profile') {
        include(get_stylesheet_directory() . '/profiles/artist-profile.php');
    } elseif ($profile_type === 'professional_profile') {
        include(get_stylesheet_directory() . '/profiles/professional-profile.php');
    }
    ?>

    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <?php the_content(); ?>
        <?php endwhile; ?>
    <?php endif; ?>
</div>

<?php get_footer(); ?>

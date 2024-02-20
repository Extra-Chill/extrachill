<?php
/*
Template Name: Create Profile
*/

get_header(); 
?>

<div id="chill-home"> <!-- Adding a div with the ID ec-comm-home -->
<div id="chill-home-header">
        <?php if (is_user_logged_in()) : ?>
            <?php
                // Determine the title based on the profile type
                $profile_type = isset($_GET['profile_type']) ? $_GET['profile_type'] : '';
                switch ($profile_type) {
                    case 'fan_profile':
                        $title = 'Create Fan Profile';
                        break;
                    case 'artist_profile':
                        $title = 'Create Artist Profile';
                        break;
                    case 'professional_profile':
                        $title = 'Create Professional Profile';
                        break;
                    default:
                        $title = get_the_title(); // Default title
                        break;
                }
            ?>
            <h1><?php echo esc_html($title); ?></h1>
            <p>Logged in as <a href="/user-dashboard"><?php echo esc_html(wp_get_current_user()->display_name); ?>.</a></p>
        <?php else : ?>
            <?php
            // Display a message to non-logged-in users
            echo '<h1>Create Profile</h1>';
            echo '<p>Please <a href="' . wp_login_url() . '">log in</a> or <a href="' . wp_registration_url() . '">register</a> to create a profile.</p>';
            ?>
        <?php endif; ?>
    </div>
</div>

<?php
$current_user = wp_get_current_user();
$existing_post_id = wp_surgeon_has_profile_post($current_user->ID, 'fan_profile');
$profile_type = isset($_GET['profile_type']) ? $_GET['profile_type'] : '';

if ($profile_type === 'fan_profile') {
    if ($existing_post_id) {
        // An existing fan profile was found, redirect to its URL
        $profile_url = get_permalink($existing_post_id);
        wp_redirect($profile_url);
        exit;
    } else {
        // Create a new fan profile
        include(get_stylesheet_directory() . '/profiles/fan-profile.php');
    }
} elseif ($profile_type === 'artist_profile') {
    include(get_stylesheet_directory() . '/profiles/artist-profile.php');
} elseif ($profile_type === 'professional_profile') {
    include(get_stylesheet_directory() . '/profiles/professional-profile.php');
}

?>

<!-- Output the standard WordPress content within the div -->
<?php if (have_posts()) : ?>
    <?php while (have_posts()) : the_post(); ?>
        <?php the_content(); ?>
    <?php endwhile; ?>
<?php endif; ?>

<?php get_footer(); ?>

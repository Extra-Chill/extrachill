<?php
/*
 * Template Name: Custom Homepage
 */

get_header();
?>

<div id="chill-home"> <!-- Add a div with the ID ec-comm-home -->
    <div id="chill-home-header">
    <?php if (is_user_logged_in()) : ?>
        <h1><?php echo get_the_title(); ?></h1>
        <p>Logged in as <a href="/user-dashboard"><?php echo esc_html(wp_get_current_user()->display_name); ?>.</a></p>
    <?php else : ?>
        <h1><?php echo get_the_title(); ?></h1> <!-- Display the default page title -->
        <p>You are not signed in. <a href="/login">Login</a> or <a href="/register">Register</a></p> <!-- Modify the href values -->
    <?php endif; ?>
    </div>
    <!-- Output the standard WordPress content within the div -->
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <?php the_content(); ?>
        <?php endwhile; ?>
    <?php endif; ?>
    
    <!-- Call the function to display online user stats -->
    <?php if (function_exists('display_online_users_stats')) : ?>
        <div id="online-users-stats">
            <?php display_online_users_stats(); ?>
        </div>
    <?php endif; ?>
</div>

<?php get_footer(); ?>

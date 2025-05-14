<?php
/*
 * Template Name: bbPress Template
 */

get_header();

echo '<div id="chill-home">'; 
if (!bbp_is_single_user()) : // Only show header on non-profile pages
    echo '<div id="chill-home-header"><span>';
endif;
bbp_breadcrumb();

// Check if we are on a user profile page
$isUserProfile = bbp_is_single_user();

if (!$isUserProfile) {
    echo '<h1>' . esc_html(get_the_title()) . '</h1>';

    // ✅ Check if we are on a single topic page
    if (bbp_is_single_topic()) {
        $views = get_post_meta(get_the_ID(), 'bbp_topic_views', true);
        ?><div class="views-container"><?php
        bbp_get_template_part( 'share' );
        echo '<p class="topic-views">' . esc_html($views) . ' views</p>';
        ?></div><?php
    }   
}

if (!bbp_is_single_user()) : // Close header div if opened
    
echo '</div>'; // End of chill-home-header
endif;

// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;

echo '</div>'; // End of chill-home
?>

<?php get_footer(); ?>

<?php
// Get the file modification time for cache busting
$svg_path = get_template_directory() . '/assets/fonts/fontawesome.svg';
$svg_version = file_exists($svg_path) ? filemtime($svg_path) : '';

// Hook this navigation menu into the header
add_action('extrachill_header_top_right', function() {
    get_template_part('inc/header/navigation-menu');
}, 10);
?>
<nav id="site-navigation" class="main-navigation" role="navigation" aria-label="Main Navigation">
    <button class="menu-toggle-container" role="button" aria-expanded="false" tabindex="0" aria-label="Toggle Menu">
        <span class="menu-line top"></span>
        <span class="menu-line middle"></span>
        <span class="menu-line bottom"></span>
    </button>

    <div id="primary-menu" class="flyout-menu">
        <!-- Top part: Search section -->
        <div class="search-section">
            <?php get_search_form(); ?>
    </div>

        <!-- Bottom part: Main menu items -->
        <ul class="menu-items">
            <?php
                wp_nav_menu(array(
                    'theme_location' => 'primary',
                    'menu_id' => 'primary-menu-items',
                    'walker' => new Custom_Walker_Nav_Menu(),
                    'container' => false,
                    'items_wrap' => '%3$s'
                ));
            ?>
            <!-- Plugin Hook: Content before social links -->
            <?php do_action('extrachill_navigation_before_social_links'); ?>
            <li class="menu-social-links">
                <?php include get_template_directory() . '/social-links.php'; ?>
            </li>
            <?php
                wp_nav_menu(array(
                    'theme_location' => 'navigation-footer',
                    'container' => false,
                    'items_wrap' => '%3$s',
                    'fallback_cb' => false
                ));
            ?>
        </ul>
    </div>
</nav>

<div class="search-icon">
    <svg class="search-top">
        <use href="<?php echo get_template_directory_uri(); ?>/assets/fonts/fontawesome.svg<?php echo $svg_version ? '?v=' . $svg_version : ''; ?>#magnifying-glass-solid"></use>
    </svg>
</div>

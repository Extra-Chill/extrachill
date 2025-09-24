<?php
// Get the file modification time for cache busting
$svg_path = get_template_directory() . '/fonts/fontawesome.svg';
$svg_version = file_exists($svg_path) ? filemtime($svg_path) : '';
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
            <li class="menu-community-link">
                <a href="https://community.extrachill.com">Visit Forum</a>
            </li>
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
            <li class="menu-footer-links">
                <a href="/about">About</a> <a href="/contact">Contact</a> <a href="/shop">Merch Store</a>
            </li>
        </ul>
    </div>
</nav>

<span class="search-cart">
    <div class="search-icon">
        <svg class="search-top">
            <use href="<?php echo get_template_directory_uri(); ?>/fonts/fontawesome.svg<?php echo $svg_version ? '?v=' . $svg_version : ''; ?>#magnifying-glass-solid"></use>
        </svg>
    </div>
</span>

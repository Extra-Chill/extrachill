<?php
/**
 * Main Navigation Menu Template
 *
 * Hook-based flyout navigation with hamburger toggle, search integration,
 * and social links. Uses action hooks for plugin extensibility.
 *
 * @package ExtraChill
 * @since 69.57
 */

$svg_path = get_template_directory() . '/assets/fonts/fontawesome.svg';
$svg_version = file_exists($svg_path) ? filemtime($svg_path) : '';

add_action('extrachill_header_top_right', function() {
    $svg_path = get_template_directory() . '/assets/fonts/fontawesome.svg';
    $svg_version = file_exists($svg_path) ? filemtime($svg_path) : '';
    ?>
    <nav id="site-navigation" class="main-navigation" role="navigation" aria-label="Main Navigation">
        <button class="menu-toggle-container" role="button" aria-expanded="false" tabindex="0" aria-label="Toggle Menu">
            <span class="menu-line top"></span>
            <span class="menu-line middle"></span>
            <span class="menu-line bottom"></span>
        </button>

        <div id="primary-menu" class="flyout-menu">
            <div class="search-section">
                <?php extrachill_search_form(); ?>
        </div>

            <ul class="menu-items">
                <?php do_action('extrachill_navigation_main_menu'); ?>
                <?php do_action('extrachill_navigation_before_social_links'); ?>
                <li class="menu-social-links">
                    <?php do_action( 'extrachill_social_links' ); ?>
                </li>
                <?php do_action('extrachill_navigation_bottom_menu'); ?>
            </ul>
        </div>
    </nav>

    <div class="search-icon">
        <svg class="search-top">
            <use href="<?php echo get_template_directory_uri(); ?>/assets/fonts/fontawesome.svg<?php echo $svg_version ? '?v=' . $svg_version : ''; ?>#magnifying-glass-solid"></use>
        </svg>
    </div>
    <?php
}, 10);

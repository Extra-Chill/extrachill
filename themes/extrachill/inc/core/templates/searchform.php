<?php
/**
 * Search Form Template Component
 *
 * Provides centralized search form functionality with modern function-based approach.
 * Replaces WordPress's archaic get_search_form() pattern with organized architecture.
 *
 * @package ExtraChill
 * @since 1.0
 */

/**
 * Display search form
 *
 * Outputs the theme's search form HTML with proper escaping and accessibility.
 *
 * @return void
 * @since 1.0
 */
if (!function_exists('extrachill_search_form')) {
    function extrachill_search_form() {
        ?>
        <form action="<?php echo esc_url( home_url( '/' ) ); ?>" class="search-form searchform clearfix" method="get">
            <div class="search-wrap">
                <input type="search" placeholder="<?php esc_attr_e( 'Enter search terms...', 'extrachill' ); ?>" class="s field" name="s" value="<?php echo esc_attr( get_search_query() ); ?>">
                <button class="search" type="submit" aria-label="<?php esc_attr_e( 'Search', 'extrachill' ); ?>"><svg class="search-top">
            <use href="<?php echo get_template_directory_uri(); ?>/assets/fonts/fontawesome.svg#magnifying-glass-solid"></use>
        </svg></button>
            </div>
        </form><!-- .searchform -->
        <?php
    }
}
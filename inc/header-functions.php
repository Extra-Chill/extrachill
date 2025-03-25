<?php
/**
 * Contains all the fucntions and components related to header part.
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */
/* * ************************************************************************************* */

    function colormag_social_links() {
        $colormag_social_links = array(
            'colormag_social_facebook'  => ['icon' => 'facebook-f', 'label' => 'Facebook'], // Adding label for Facebook
            'colormag_social_twitter'   => ['icon' => 'x-twitter', 'label' => 'Twitter'], // Adding label for Twitter, adjust icon as needed
            'colormag_social_instagram' => ['icon' => 'instagram', 'label' => 'Instagram'], // Adding label for Instagram
            'colormag_social_youtube'   => ['icon' => 'youtube', 'label' => 'YouTube'], // Adding label for YouTube
            // Add other social networks as needed
        );
    
        ?>
        <div class="social-links">
            <ul>
                <?php
                foreach ( $colormag_social_links as $key => $social ) {
                    $link = get_theme_mod( $key, '' );
                    if ( ! empty( $link ) ) {
                        $new_tab = get_theme_mod( $key . '_checkbox', 0 ) == 1 ? 'target="_blank"' : '';
                        // Output the SVG for each social link with aria-label for accessibility
                        echo '<li><a href="' . esc_url( $link ) . '" ' . $new_tab . ' aria-label="' . esc_attr($social['label']) . '">';
                        echo '<svg class="social-icon-svg"><use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg?v=1.2#' . $social['icon'] . '"></use></svg>';
                        echo '</a></li>';
                    }
                }
                // Manually add the Igloo icon as before, with an appropriate aria-label
                echo '<li><a href="https://community.extrachill.com" target="_blank" aria-label="Igloo - Our Community Platform"><svg><use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#igloo-solid"></use></svg></a></li>';
                ?>
            </ul>
        </div><!-- .social-links -->
        <?php
    }
    
    


/* * ************************************************************************************* */

function colormag_header_image_markup_filter() {
	add_filter( 'get_header_image_tag', 'colormag_header_image_markup', 10, 3 );
}

add_action( 'colormag_header_image_markup_render', 'colormag_header_image_markup_filter' );

/* * ************************************************************************************* */

if ( ! function_exists( 'colormag_render_header_image' ) ) :

	/**
	 * Shows the small info text on top header part
	 */
	function colormag_render_header_image() {
		if ( function_exists( 'the_custom_header_markup' ) ) {
			do_action( 'colormag_header_image_markup_render' );
			the_custom_header_markup();
		} else {
			$header_image = get_header_image();
			if ( ! empty( $header_image ) ) {
				if ( get_theme_mod( 'colormag_header_image_link', 0 ) == 1 ) {
					?>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php echo esc_attr( get_bloginfo( 'name', 'display' ) ); ?>" rel="home">
				<?php } ?>

				<?php if ( get_theme_mod( 'colormag_header_image_link', 0 ) == 1 ) { ?>
					</a>
					<?php
				}
			}
		}
	}

endif;

if ( ! function_exists( 'colormag_top_header_bar_display' ) ) :

	/**
	 * Function to display the top header bar
	 *
	 * @since ColorMag 2.2.1
	 */
	function colormag_top_header_bar_display() {
		if ( ( ( get_theme_mod( 'colormag_breaking_news', 0 ) == 1 ) && ( get_theme_mod( 'colormag_breaking_news_position_options', 'header' ) == 'header' ) || ( get_theme_mod( 'colormag_date_display', 0 ) == 1 ) || get_theme_mod( 'colormag_social_link_activate', 0 ) == 1 ) ) :
			?>
		<?php
		endif;
	}

endif;

if ( ! function_exists( 'colormag_middle_header_bar_display' ) ) :

	/**
	 * Function to display the middle header bar
	 *
	 * @since ColorMag 2.2.1
	 */
	function colormag_middle_header_bar_display() {
		?>


<?php
					if ( ( get_theme_mod( 'colormag_header_logo_placement', 'header_text_only' ) == 'show_both' || get_theme_mod( 'colormag_header_logo_placement', 'header_text_only' ) == 'header_logo_only' ) ) {
						?>
						<?php
					}
					// seo better handling
					$screen_reader = '';
					if ( get_theme_mod( 'colormag_header_logo_placement', 'header_text_only' ) == 'header_logo_only' || ( get_theme_mod( 'colormag_header_logo_placement', 'header_text_only' ) == 'disable' ) ) {
						$screen_reader = 'screen-reader-text';
					}
					?>
		<?php
	}

endif;

if ( ! function_exists( 'colormag_below_header_bar_display' ) ) :

	/**
	 * Function to display the middle header bar
	 *
	 * @since ColorMag 2.2.1
	 */function colormag_below_header_bar_display() {
    // Define the $screen_reader variable
    // Only apply 'screen-reader-text' class if the theme setting is to hide the title for screen readers
    $screen_reader = ( get_theme_mod( 'colormag_header_logo_placement', 'header_text_only' ) === 'header_logo_only' || get_theme_mod( 'colormag_header_logo_placement', 'header_text_only' ) === 'disable' ) ? 'screen-reader-text' : '';

    if ( function_exists( 'max_mega_menu_is_enabled' ) && max_mega_menu_is_enabled( 'primary' ) ) {
        ?>
        <div class="mega-menu-integrate">
            <div class="inner-wrap clearfix">
                <?php wp_nav_menu( array( 'theme_location' => 'primary' ) ); ?>
            </div>
        </div>
        <?php
    } else {
        ?>
        <nav id="site-navigation" class="main-navigation">
            <div id="header-text" class="<?php echo $screen_reader; ?>">
                <?php
                // Check if it is the front page or home, then display the site title accordingly
                if ( is_front_page() || is_home() ) : ?>
                    <h1 id="site-title" >
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php echo esc_attr( get_bloginfo( 'name', 'display' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a>
                    </h1>
                <?php else : ?>
                    <div id="site-title">
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php echo esc_attr( get_bloginfo( 'name', 'display' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a>
                    </div>
                <?php endif; ?>
            </div><!-- #header-text -->
            <p class="menu-togle"><svg class="menu-toggle" aria-controls="primary-menu" aria-expanded="false">
               <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#bars-solid"></use>
                </svg></p>
            <?php
            if ( has_nav_menu( 'primary' ) ) {
                wp_nav_menu( array(
                    'theme_location'  => 'primary',
                    'container_class' => 'menu-primary-container',
                    'items_wrap'      => '<ul id="%1$s" class="%2$s">%3$s</ul>',
                ) );
            } else {
                wp_page_menu();
            }
            ?>
            <svg class="search-top">
                <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#magnifying-glass-solid"></use>
        </svg>
            <div class="search-form-top">
                <?php get_search_form(); ?>
            </div>
        </nav>
        <?php
    }
}



endif;


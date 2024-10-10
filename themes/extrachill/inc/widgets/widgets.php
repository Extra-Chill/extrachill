<?php
/**
 * Contains all the functions related to sidebar and widget.
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */
add_action( 'widgets_init', 'colormag_widgets_init' );

/**
 * Function to register the widget areas(sidebar) and widgets.
 */
function colormag_widgets_init() {

	/**
	 * Registering widget areas for front page
	 */
	// Registering main right sidebar
	register_sidebar( array(
		'name'          => __( 'Right Sidebar', 'colormag-pro' ),
		'id'            => 'colormag_right_sidebar',
		'description'   => __( 'Shows widgets at Right side.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering main left sidebar
	register_sidebar( array(
		'name'          => __( 'Left Sidebar', 'colormag-pro' ),
		'id'            => 'colormag_left_sidebar',
		'description'   => __( 'Shows widgets at Left side.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering Header sidebar
	register_sidebar( array(
		'name'          => __( 'Header Sidebar', 'colormag-pro' ),
		'id'            => 'colormag_header_sidebar',
		'description'   => __( 'Shows widgets in header section just above the main navigation menu.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title">',
		'after_title'   => '</h3>',
	) );

	// registering the Front Page: Top Full width Area
	register_sidebar( array(
		'name'          => __( 'Front Page: Top Full Width Area', 'colormag-pro' ),
		'id'            => 'colormag_front_page_top_full_width_area',
		'description'   => __( 'Show widget just below menu.', 'colormag-pro' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// registering the Front Page: Slider Area Sidebar
	register_sidebar( array(
		'name'          => __( 'Front Page: Slider Area', 'colormag-pro' ),
		'id'            => 'colormag_front_page_slider_area',
		'description'   => __( 'Show widget just below menu. Suitable for TG: Featured Cat Slider.', 'colormag-pro' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// registering the Front Page: Area beside slider Sidebar
	register_sidebar( array(
		'name'          => __( 'Front Page: Area beside slider', 'colormag-pro' ),
		'id'            => 'colormag_front_page_area_beside_slider',
		'description'   => __( 'Show widget beside the slider. Suitable for TG: Highlighted Posts.', 'colormag-pro' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// registering the Front Page: Content Top Section Sidebar
	register_sidebar( array(
		'name'          => __( 'Front Page: Content Top Section', 'colormag-pro' ),
		'id'            => 'colormag_front_page_content_top_section',
		'description'   => __( 'Content Top Section', 'colormag-pro' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// registering the Front Page: Content Middle Left Section Sidebar
	register_sidebar( array(
		'name'          => __( 'Front Page: Content Middle Left Section', 'colormag-pro' ),
		'id'            => 'colormag_front_page_content_middle_left_section',
		'description'   => __( 'Content Middle Left Section', 'colormag-pro' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// registering the Front Page: Content Middle Right Section Sidebar
	register_sidebar( array(
		'name'          => __( 'Front Page: Content Middle Right Section', 'colormag-pro' ),
		'id'            => 'colormag_front_page_content_middle_right_section',
		'description'   => __( 'Content Middle Right Section', 'colormag-pro' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// registering the Front Page: Content Bottom Section Sidebar
	register_sidebar( array(
		'name'          => __( 'Front Page: Content Bottom Section', 'colormag-pro' ),
		'id'            => 'colormag_front_page_content_bottom_section',
		'description'   => __( 'Content Middle Bottom Section', 'colormag-pro' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering contact Page sidebar
	register_sidebar( array(
		'name'          => __( 'Contact Page Sidebar', 'colormag-pro' ),
		'id'            => 'colormag_contact_page_sidebar',
		'description'   => __( 'Shows widgets on Contact Page Template.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering Error 404 Page sidebar
	register_sidebar( array(
		'name'          => __( 'Error 404 Page Sidebar', 'colormag-pro' ),
		'id'            => 'colormag_error_404_page_sidebar',
		'description'   => __( 'Shows widgets on Error 404 page.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering advertisement above footer sidebar
	register_sidebar( array(
		'name'          => __( 'Advertisement Above The Footer', 'colormag-pro' ),
		'id'            => 'colormag_advertisement_above_the_footer_sidebar',
		'description'   => __( 'Shows widgets Just Above The Footer, suitable for TG: 728x90 widget.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering footer sidebar one upper
	register_sidebar( array(
		'name'          => __( 'Footer Sidebar One ( Upper )', 'colormag-pro' ),
		'id'            => 'colormag_footer_sidebar_one_upper',
		'description'   => __( 'Shows widgets at footer sidebar one in upper.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering footer sidebar two upper
	register_sidebar( array(
		'name'          => __( 'Footer Sidebar Two ( Upper )', 'colormag-pro' ),
		'id'            => 'colormag_footer_sidebar_two_upper',
		'description'   => __( 'Shows widgets at footer sidebar two in upper.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering footer sidebar three upper
	register_sidebar( array(
		'name'          => __( 'Footer Sidebar Three ( Upper )', 'colormag-pro' ),
		'id'            => 'colormag_footer_sidebar_three_upper',
		'description'   => __( 'Shows widgets at footer sidebar three in upper.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering footer sidebar one
	register_sidebar( array(
		'name'          => __( 'Footer Sidebar One ( Lower )', 'colormag-pro' ),
		'id'            => 'colormag_footer_sidebar_one',
		'description'   => __( 'Shows widgets at footer sidebar one.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering footer sidebar two
	register_sidebar( array(
		'name'          => __( 'Footer Sidebar Two ( Lower )', 'colormag-pro' ),
		'id'            => 'colormag_footer_sidebar_two',
		'description'   => __( 'Shows widgets at footer sidebar two.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering footer sidebar three
	register_sidebar( array(
		'name'          => __( 'Footer Sidebar Three ( Lower )', 'colormag-pro' ),
		'id'            => 'colormag_footer_sidebar_three',
		'description'   => __( 'Shows widgets at footer sidebar three.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering footer sidebar four
	register_sidebar( array(
		'name'          => __( 'Footer Sidebar Four ( Lower )', 'colormag-pro' ),
		'id'            => 'colormag_footer_sidebar_four',
		'description'   => __( 'Shows widgets at footer sidebar four.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering full width footer sidebar.
	register_sidebar( array(
		'name'          => esc_html__( 'Footer Sidebar Full Width', 'colormag-pro' ),
		'id'            => 'colormag_footer_sidebar_full_width',
		'description'   => esc_html__( 'Shows widgets just above footer copyright area.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );

	// Registering sidebar for WooCommerce pages.
	if ( ( get_theme_mod( 'colormag_woocommerce_sidebar_register_setting', 0 ) == 1 ) && class_exists( 'WooCommerce' ) ) {
		// Registering WooCommerce Right Sidebar.
		register_sidebar( array(
			'name'          => esc_html__( 'WooCommerce Right Sidebar', 'colormag-pro' ),
			'id'            => 'colormag_woocommerce_right_sidebar',
			'description'   => esc_html__( 'Shows widgets at WooCommerce Right sidebar.', 'colormag-pro' ),
			'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
			'after_widget'  => '</aside>',
			'before_title'  => '<h3 class="widget-title"><span>',
			'after_title'   => '</span></h3>',
		) );

		// Registering WooCommerce Left Sidebar.
		register_sidebar( array(
			'name'          => esc_html__( 'WooCommerce Left Sidebar', 'colormag-pro' ),
			'id'            => 'colormag_woocommerce_left_sidebar',
			'description'   => esc_html__( 'Shows widgets at WooCommerce Left sidebar.', 'colormag-pro' ),
			'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
			'after_widget'  => '</aside>',
			'before_title'  => '<h3 class="widget-title"><span>',
			'after_title'   => '</span></h3>',
		) );
	}

	register_widget( 'colormag_featured_posts_slider_widget' );
	register_widget( 'colormag_highlighted_posts_widget' );
	register_widget( 'colormag_featured_posts_widget' );
	register_widget( 'colormag_featured_posts_vertical_widget' );
	register_widget( 'colormag_728x90_advertisement_widget' );
	register_widget( 'colormag_300x250_advertisement_widget' );
	register_widget( 'colormag_125x125_advertisement_widget' );
	// Pro Options
	register_widget( 'colormag_video_widget' );
	register_widget( 'colormag_news_in_picture_widget' );
	register_widget( 'colormag_default_news_widget' );
	register_widget( 'colormag_tabbed_widget' );
	register_widget( 'colormag_random_post_widget' );
	register_widget( 'colormag_slider_news_widget' );
	register_widget( 'colormag_breaking_news_widget' );
	register_widget( 'colormag_ticker_news_widget' );
	register_widget( 'colormag_featured_posts_small_thumbnails' );

	register_widget( 'colormag_weather_widget' );
	register_widget( 'colormag_cta_widget' );
	register_widget( 'colormag_video_playlist' );
	register_widget( 'colormag_exchange_widget' );
	register_widget( 'colormag_google_maps_widget' );
}

// Require file for TG: Featured Category Slider widget.
require COLORMAG_WIDGETS_DIR . '/colormag-featured-posts-slider-widget.php';

// Require file for TG: Highligted Posts.
require COLORMAG_WIDGETS_DIR . '/colormag-highlighted-posts-widget.php';

// Require file for TG: Featured Post style 1.
require COLORMAG_WIDGETS_DIR . '/colormag-featured-posts-widget.php';

// Require file for TG: Featured Post style 2.
require COLORMAG_WIDGETS_DIR . '/colormag-featured-posts-vertical-widget.php';

// Require file for TG: 300x250 Advertisement.
require COLORMAG_WIDGETS_DIR . '/colormag-300x250-advertisement-widget.php';

// Require file for TG: 728x90 Advertisement.
require COLORMAG_WIDGETS_DIR . '/colormag-728x90-advertisement-widget.php';

// Require file for TG: 728x90 Advertisement.
require COLORMAG_WIDGETS_DIR . '/colormag-125x125-advertisement-widget.php';

// Require file for TG: Videos.
require COLORMAG_WIDGETS_DIR . '/colormag-video-widget.php';

// Require file for TG: Featured Posts (Style 5).
require COLORMAG_WIDGETS_DIR . '/colormag-news-in-picture-widget.php';

// Require file for TG: Featured Posts (Style 4).
require COLORMAG_WIDGETS_DIR . '/colormag-default-news-widget.php';

// Require file for TG: Tabbed Widget.
require COLORMAG_WIDGETS_DIR . '/colormag-tabbed-widget.php';

// Require file for TG: Random Posts Widget.
require COLORMAG_WIDGETS_DIR . '/colormag-random-post-widget.php';

// Require file for TG: Featured Posts (Style 6).
require COLORMAG_WIDGETS_DIR . '/colormag-slider-news-widget.php';

// Require file for TG: Breaking News Widget.
require COLORMAG_WIDGETS_DIR . '/colormag-breaking-news-widget.php';

// Require file for TG: Featured Posts (Style 7).
require COLORMAG_WIDGETS_DIR . '/colormag-ticker-news-widget.php';

// Require file for TG: Featured Posts (Style 3).
require COLORMAG_WIDGETS_DIR . '/colormag-featured-posts-small-thumbnails.php';

// Require file for TG: Call To Action.
require COLORMAG_WIDGETS_DIR . '/colormag-cta-widget.php';

// Require file for TG: Weather.
require COLORMAG_WIDGETS_DIR . '/colormag-weather-widget.php';

// Require file for TG: Currency Exchange.
require COLORMAG_WIDGETS_DIR . '/colormag-exchange-widget.php';

// Require file for TG: Featured Videos Playlist.
require COLORMAG_WIDGETS_DIR . '/colormag-video-playlist.php';

// Require file for TG: Google Maps.
require COLORMAG_WIDGETS_DIR . '/colormag-google-maps-widget.php';


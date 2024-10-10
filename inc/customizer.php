<?php

/**
 * ColorMag Theme Customizer
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */
function colormag_customize_register( $wp_customize ) {

	// Transport postMessage variable set
	$customizer_selective_refresh = isset( $wp_customize->selective_refresh ) ? 'postMessage' : 'refresh';

	$wp_customize->get_setting( 'blogname' )->transport        = 'postMessage';
	$wp_customize->get_setting( 'blogdescription' )->transport = 'postMessage';

	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'blogname', array(
			'selector'        => '#site-title a',
			'render_callback' => 'colormag_customize_partial_blogname',
		) );

		$wp_customize->selective_refresh->add_partial( 'blogdescription', array(
			'selector'        => '#site-description',
			'render_callback' => 'colormag_customize_partial_blogdescription',
		) );
	}

	// Extend Customizer Options to add the small information text
	class COLORMAG_Custom_Information extends WP_Customize_Control {

		public $type = 'colormag-custom-information';

		public function render_content() {
			?>

			<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
			<p><?php echo wp_kses_post( $this->description ); ?></p>

			<?php
		}

	}

	// Start of the Header Options
	$wp_customize->add_panel( 'colormag_header_options', array(
		'capabitity'  => 'edit_theme_options',
		'description' => __( 'Change the Header Settings from here as you want', 'colormag-pro' ),
		'priority'    => 500,
		'title'       => __( 'Header Options', 'colormag-pro' ),
	) );

	// breaking news enable/disable
	$wp_customize->add_section( 'colormag_breaking_news_section', array(
		'title' => __( 'Breaking News', 'colormag-pro' ),
		'panel' => 'colormag_header_options',
	) );

	$wp_customize->add_setting( 'colormag_breaking_news', array(
		'priority'          => 1,
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_breaking_news', array(
		'type'     => 'checkbox',
		'label'    => __( 'Check to enable the breaking news section', 'colormag-pro' ),
		'section'  => 'colormag_breaking_news_section',
		'settings' => 'colormag_breaking_news',
	) );

	$wp_customize->add_setting( 'colormag_breaking_news_category_option', array(
		'priority'          => 2,
		'default'           => 'latest',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_breaking_news_category_option', array(
		'type'     => 'radio',
		'label'    => esc_html__( 'Choose the required option to display the latest posts from:', 'colormag-pro' ),
		'section'  => 'colormag_breaking_news_section',
		'settings' => 'colormag_breaking_news_category_option',
		'choices'  => array(
			'latest'   => esc_html__( 'Latest Posts', 'colormag-pro' ),
			'category' => esc_html__( 'Category', 'colormag-pro' ),
		),
	) );

	// Category select option for the breaking news
	$cats             = array();
	$categories_lists = get_categories();
	foreach ( $categories_lists as $categories => $category ) {
		$cats[ $category->term_id ] = $category->name;
	}

	$wp_customize->add_setting( 'colormag_breaking_news_category', array(
		'priority'          => 3,
		'default'           => '',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_breaking_news_category', array(
		'type'     => 'select',
		'choices'  => $cats,
		'label'    => esc_html__( 'Choose the required category to display as the latest posts:', 'colormag-pro' ),
		'section'  => 'colormag_breaking_news_section',
		'settings' => 'colormag_breaking_news_category',
	) );

	$wp_customize->add_setting( 'colormag_breaking_news_content_option', array(
		'priority'          => 3,
		'default'           => __( 'Latest:', 'colormag-pro' ),
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'wp_filter_nohtml_kses',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_breaking_news_content_option', array(
		'label'    => __( 'Enter the text to display for the ticker news', 'colormag-pro' ),
		'section'  => 'colormag_breaking_news_section',
		'settings' => 'colormag_breaking_news_content_option',
	) );

	// Selective refresh for breaking news text
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_breaking_news_content_option', array(
			'selector'        => '.breaking-news-latest',
			'render_callback' => 'colormag_breaking_news_content',
		) );
	}

	$wp_customize->add_setting( 'colormag_breaking_news_setting_animation_options', array(
		'priority'          => 2,
		'default'           => 'down',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_breaking_news_setting_animation_options', array(
		'type'     => 'select',
		'label'    => __( 'Choose the animation style for the Breaking News in the Header', 'colormag-pro' ),
		'section'  => 'colormag_breaking_news_section',
		'settings' => 'colormag_breaking_news_setting_animation_options',
		'choices'  => array(
			'up'   => __( 'Up', 'colormag-pro' ),
			'down' => __( 'Down', 'colormag-pro' ),
		),
	) );

	$wp_customize->add_setting( 'colormag_breaking_news_duration_setting_options', array(
		'priority'          => 3,
		'default'           => 4,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_breaking_news_duration_setting_options_sanitize',
	) );

	$wp_customize->add_control( 'colormag_breaking_news_duration_setting_options', array(
		'label'    => __( 'Enter the duration time for the Breaking News in the Header', 'colormag-pro' ),
		'section'  => 'colormag_breaking_news_section',
		'settings' => 'colormag_breaking_news_duration_setting_options',
	) );

	$wp_customize->add_setting( 'colormag_breaking_news_speed_setting_options', array(
		'priority'          => 4,
		'default'           => 1,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_breaking_news_speed_setting_options_sanitize',
	) );

	$wp_customize->add_control( 'colormag_breaking_news_speed_setting_options', array(
		'label'    => __( 'Enter the speed time for the Breaking News in the Header', 'colormag-pro' ),
		'section'  => 'colormag_breaking_news_section',
		'settings' => 'colormag_breaking_news_speed_setting_options',
	) );

	$wp_customize->add_setting( 'colormag_breaking_news_position_options', array(
		'priority'          => 5,
		'default'           => 'header',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_breaking_news_position_options', array(
		'type'     => 'radio',
		'label'    => __( 'Choose the location/area to place the Breaking News', 'colormag-pro' ),
		'section'  => 'colormag_breaking_news_section',
		'settings' => 'colormag_breaking_news_position_options',
		'choices'  => array(
			'header' => __( 'Header', 'colormag-pro' ),
			'main'   => __( 'Below Navigation', 'colormag-pro' ),
		),
	) );

	// date display enable/disable
	$wp_customize->add_section( 'colormag_date_display_section', array(
		'title' => __( 'Show Date', 'colormag-pro' ),
		'panel' => 'colormag_header_options',
	) );

	$wp_customize->add_setting( 'colormag_date_display', array(
		'priority'          => 2,
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_date_display', array(
		'type'     => 'checkbox',
		'label'    => __( 'Check to show the date in header', 'colormag-pro' ),
		'section'  => 'colormag_date_display_section',
		'settings' => 'colormag_date_display',
	) );

	// Selective refresh for date display
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_date_display', array(
			'selector'        => '.date-in-header',
			'render_callback' => 'colormag_date_display',
		) );
	}

	// date in header display type
	$wp_customize->add_setting( 'colormag_date_display_type', array(
		'default'           => 'theme_default',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_date_display_type', array(
		'type'     => 'radio',
		'label'    => esc_html__( 'Date in header display type:', 'colormag-pro' ),
		'choices'  => array(
			'theme_default'          => esc_html__( 'Theme Default Setting', 'colormag-pro' ),
			'wordpress_date_setting' => esc_html__( 'From WordPress Date Setting', 'colormag-pro' ),
		),
		'section'  => 'colormag_date_display_section',
		'settings' => 'colormag_date_display_type',
	) );

	// Selective refresh for date display type
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_date_display_type', array(
			'selector'        => '.date-in-header',
			'render_callback' => 'colormag_date_display_type',
		) );
	}

	// home icon enable/disable in primary menu
	$wp_customize->add_section( 'colormag_home_icon_display_section', array(
		'title' => __( 'Show Home Icon', 'colormag-pro' ),
		'panel' => 'colormag_header_options',
	) );

	$wp_customize->add_setting( 'colormag_home_icon_display', array(
		'priority'          => 3,
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_home_icon_display', array(
		'type'     => 'checkbox',
		'label'    => __( 'Check to show the home icon in the primary menu', 'colormag-pro' ),
		'section'  => 'colormag_home_icon_display_section',
		'settings' => 'colormag_home_icon_display',
	) );

	// Selective refresh for displaying home icon
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_home_icon_display', array(
			'selector'        => '.home-icon',
			'render_callback' => '',
		) );
	}

	// primary sticky menu enable/disable
	$wp_customize->add_section( 'colormag_primary_sticky_menu_section', array(
		'title' => __( 'Sticky Menu', 'colormag-pro' ),
		'panel' => 'colormag_header_options',
	) );

	$wp_customize->add_setting( 'colormag_primary_sticky_menu', array(
		'priority'          => 4,
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_primary_sticky_menu', array(
		'type'     => 'checkbox',
		'label'    => __( 'Check to enable the sticky behavior of the primary menu', 'colormag-pro' ),
		'section'  => 'colormag_primary_sticky_menu_section',
		'settings' => 'colormag_primary_sticky_menu',
	) );

	$wp_customize->add_setting( 'colormag_primary_sticky_menu_type', array(
		'default'           => 'sticky',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_primary_sticky_menu_type', array(
		'type'    => 'radio',
		'label'   => esc_html__( 'Select the option you want:', 'colormag-pro' ),
		'choices' => array(
			'sticky'           => esc_html__( 'Make the menu sticky', 'colormag-pro' ),
			'reveal_on_scroll' => esc_html__( 'Reveal the menu on scroll up', 'colormag-pro' ),
		),
		'section' => 'colormag_primary_sticky_menu_section',
	) );

	// search icon in menu enable/disable
	$wp_customize->add_section( 'colormag_search_icon_in_menu_section', array(
		'title' => __( 'Search Icon', 'colormag-pro' ),
		'panel' => 'colormag_header_options',
	) );

	$wp_customize->add_setting( 'colormag_search_icon_in_menu', array(
		'priority'          => 5,
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_search_icon_in_menu', array(
		'type'     => 'checkbox',
		'label'    => __( 'Check to display the Search Icon in the primary menu', 'colormag-pro' ),
		'section'  => 'colormag_search_icon_in_menu_section',
		'settings' => 'colormag_search_icon_in_menu',
	) );

	// random posts in menu enable/disable
	$wp_customize->add_section( 'colormag_random_post_in_menu_section', array(
		'title' => __( 'Random Post', 'colormag-pro' ),
		'panel' => 'colormag_header_options',
	) );

	$wp_customize->add_setting( 'colormag_random_post_in_menu', array(
		'priority'          => 6,
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_random_post_in_menu', array(
		'type'     => 'checkbox',
		'label'    => __( 'Check to display the Random Post Icon in the primary menu', 'colormag-pro' ),
		'section'  => 'colormag_random_post_in_menu_section',
		'settings' => 'colormag_random_post_in_menu',
	) );

	// Selective refresh for displaying random post icon
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_random_post_in_menu', array(
			'selector'        => '.random-post',
			'render_callback' => 'colormag_random_post',
		) );
	}

	// logo upload options
	$wp_customize->add_section( 'colormag_header_logo', array(
		'priority' => 1,
		'title'    => __( 'Header Logo', 'colormag-pro' ),
		'panel'    => 'colormag_header_options',
	) );

	if ( ! function_exists( 'the_custom_logo' ) ) {
		$wp_customize->add_setting( 'colormag_logo', array(
			'default'           => '',
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'esc_url_raw',
		) );

		$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'colormag_logo', array(
			'label'   => __( 'Upload logo for your header', 'colormag-pro' ),
			'section' => 'colormag_header_logo',
			'setting' => 'colormag_logo',
		) ) );
	}

	$wp_customize->add_setting( 'colormag_header_logo_placement', array(
		'default'           => 'header_text_only',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_header_logo_placement', array(
		'type'    => 'radio',
		'label'   => __( 'Choose the option that you want', 'colormag-pro' ),
		'section' => 'colormag_header_logo',
		'choices' => array(
			'header_logo_only' => __( 'Header Logo Only', 'colormag-pro' ),
			'header_text_only' => __( 'Header Text Only', 'colormag-pro' ),
			'show_both'        => __( 'Show Both', 'colormag-pro' ),
			'disable'          => __( 'Disable', 'colormag-pro' ),
		),
	) );

	// header image position setting
	$wp_customize->add_section( 'colormag_header_image_position_setting', array(
		'priority' => 6,
		'title'    => __( 'Header Image Position', 'colormag-pro' ),
		'panel'    => 'colormag_header_options',
	) );

	$wp_customize->add_setting( 'colormag_header_image_position', array(
		'default'           => 'position_two',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_header_image_position', array(
		'type'    => 'radio',
		'label'   => __( 'Header image display position', 'colormag-pro' ),
		'section' => 'colormag_header_image_position_setting',
		'choices' => array(
			'position_one'   => __( 'Display the Header image just above the site title/text.', 'colormag-pro' ),
			'position_two'   => __( 'Default: Display the Header image between site title/text and the main/primary menu.', 'colormag-pro' ),
			'position_three' => __( 'Display the Header image below main/primary menu.', 'colormag-pro' ),
		),
	) );

	$wp_customize->add_setting( 'colormag_header_image_link', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_header_image_link', array(
		'type'    => 'checkbox',
		'label'   => __( 'Check to make header image link back to home page', 'colormag-pro' ),
		'section' => 'colormag_header_image_position_setting',
	) );

	// Link header image to custom location
	$wp_customize->add_setting( 'colormag_header_image_custom_link', array(
		'default'           => '',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'esc_url_raw',
	) );

	$wp_customize->add_control( 'colormag_header_image_custom_link', array(
		'label'           => __( 'Custom link to header image ', 'colormag-pro' ),
		'section'         => 'colormag_header_image_position_setting',
		'settings'        => 'colormag_header_image_custom_link',
		'active_callback' => 'is_header_linked_home',
	) );

	// header display type
	$wp_customize->add_section( 'colormag_header_display_type_setting', array(
		'priority' => 3,
		'title'    => __( 'Header Display Type', 'colormag-pro' ),
		'panel'    => 'colormag_header_options',
	) );

	$wp_customize->add_setting( 'colormag_header_display_type', array(
		'default'           => 'type_one',
		'transport'         => 'postMessage',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_header_display_type', array(
		'type'    => 'radio',
		'label'   => __( 'Choose the header display type that you want', 'colormag-pro' ),
		'section' => 'colormag_header_display_type_setting',
		'choices' => array(
			'type_one'   => __( 'Type 1 (Default): Header text & logo on left, header sidebar on right', 'colormag-pro' ),
			'type_two'   => __( 'Type 2: Header sidebar on left, header text & logo on right', 'colormag-pro' ),
			'type_three' => __( 'Type 3: Header text, header sidebar both aligned center', 'colormag-pro' ),
		),
	) );

	class COLORMAG_Image_Radio_Control extends WP_Customize_Control {

		public function render_content() {

			if ( empty( $this->choices ) ) {
				return;
			}

			$name = '_customize-radio-' . $this->id;
			?>
			<style>
				#colormag-img-container .colormag-radio-img-img {
					border: 3px solid #DEDEDE;
					margin: 0 5px 5px 0;
					cursor: pointer;
					border-radius: 3px;
					-moz-border-radius: 3px;
					-webkit-border-radius: 3px;
				}

				#colormag-img-container .colormag-radio-img-selected {
					border: 3px solid #AAA;
					border-radius: 3px;
					-moz-border-radius: 3px;
					-webkit-border-radius: 3px;
				}

				input[type=checkbox]:before {
					content: '';
					margin: -3px 0 0 -4px;
				}
			</style>
			<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
			<ul class="controls" id='colormag-img-container'>
				<?php
				foreach ( $this->choices as $value => $label ) :
					$class = ( $this->value() == $value ) ? 'colormag-radio-img-selected colormag-radio-img-img' : 'colormag-radio-img-img';
					?>
					<li style="display: inline;">
						<label style="margin-left: 0">
							<input <?php $this->link(); ?>style='display:none' type="radio" value="<?php echo esc_attr( $value ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php
							$this->link();
							checked( $this->value(), $value );
							?> />
							<img src='<?php echo esc_html( $label ); ?>' class='<?php echo $class; ?>' />
						</label>
					</li>
				<?php
				endforeach;
				?>
			</ul>
			<script type="text/javascript">

				jQuery( document ).ready( function ( $ ) {
					$( '.controls#colormag-img-container li img' ).click( function () {
						$( '.controls#colormag-img-container li' ).each( function () {
							$( this ).find( 'img' ).removeClass( 'colormag-radio-img-selected' );
						} );
						$( this ).addClass( 'colormag-radio-img-selected' );
					} );
				} );

			</script>
			<?php
		}

	}

	// Main total Header area display type
	$wp_customize->add_section( 'colormag_main_total_header_area_display_type_option', array(
		'priority' => 4,
		'title'    => esc_html__( 'Main Header Area Display Type', 'colormag-pro' ),
		'panel'    => 'colormag_header_options',
	) );

	$wp_customize->add_setting( 'colormag_main_total_header_area_display_type', array(
		'default'           => 'type_one',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( new COLORMAG_Image_Radio_Control( $wp_customize, 'colormag_main_total_header_area_display_type', array(
		'type'    => 'radio',
		'label'   => esc_html__( 'Choose the main total header area display type that you want', 'colormag-pro' ),
		'section' => 'colormag_main_total_header_area_display_type_option',
		'choices' => array(
			'type_one'   => COLORMAG_ADMIN_IMAGES_URL . '/header-variation-1.png',
			'type_two'   => COLORMAG_ADMIN_IMAGES_URL . '/header-variation-2.png',
			'type_three' => COLORMAG_ADMIN_IMAGES_URL . '/header-variation-3.png',
			'type_four'  => COLORMAG_ADMIN_IMAGES_URL . '/header-variation-4.png',
			'type_five'  => COLORMAG_ADMIN_IMAGES_URL . '/header-variation-5.png',
			'type_six'   => COLORMAG_ADMIN_IMAGES_URL . '/header-variation-6.png',
		),
	) ) );
	// end of header options
	// Start of the Design Options
	$wp_customize->add_panel( 'colormag_design_options', array(
		'capabitity'  => 'edit_theme_options',
		'description' => __( 'Change the Design Settings from here as you want', 'colormag-pro' ),
		'priority'    => 505,
		'title'       => __( 'Design Options', 'colormag-pro' ),
	) );

	// FrontPage setting
	$wp_customize->add_section( 'colormag_front_page_setting', array(
		'priority' => 1,
		'title'    => __( 'Front Page Settings', 'colormag-pro' ),
		'panel'    => 'colormag_design_options',
	) );
	$wp_customize->add_setting( 'colormag_hide_blog_front', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_hide_blog_front', array(
		'type'    => 'checkbox',
		'label'   => __( 'Check to hide blog posts/static page on front page', 'colormag-pro' ),
		'section' => 'colormag_front_page_setting',
	) );

	// site layout setting
	$wp_customize->add_section( 'colormag_site_layout_setting', array(
		'priority' => 2,
		'title'    => __( 'Site Layout', 'colormag-pro' ),
		'panel'    => 'colormag_design_options',
	) );

	$wp_customize->add_setting( 'colormag_site_layout', array(
		'default'           => 'wide_layout',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
		'transport'         => 'postMessage',
	) );

	$wp_customize->add_control( 'colormag_site_layout', array(
		'type'    => 'radio',
		'label'   => __( 'Choose your site layout. The change is reflected in whole site', 'colormag-pro' ),
		'choices' => array(
			'boxed_layout' => __( 'Boxed Layout', 'colormag-pro' ),
			'wide_layout'  => __( 'Wide Layout', 'colormag-pro' ),
		),
		'section' => 'colormag_site_layout_setting',
	) );

	// default layout setting
	$wp_customize->add_section( 'colormag_default_layout_setting', array(
		'priority' => 3,
		'title'    => __( 'Default layout', 'colormag-pro' ),
		'panel'    => 'colormag_design_options',
	) );

	$wp_customize->add_setting( 'colormag_default_layout', array(
		'default'           => 'right_sidebar',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( new COLORMAG_Image_Radio_Control( $wp_customize, 'colormag_default_layout', array(
		'type'     => 'radio',
		'label'    => __( 'Select default layout. This layout will be reflected in whole site archives, categories, search page etc. The layout for a single post and page can be controlled from below options', 'colormag-pro' ),
		'section'  => 'colormag_default_layout_setting',
		'settings' => 'colormag_default_layout',
		'choices'  => array(
			'right_sidebar'               => COLORMAG_ADMIN_IMAGES_URL . '/right-sidebar.png',
			'left_sidebar'                => COLORMAG_ADMIN_IMAGES_URL . '/left-sidebar.png',
			'no_sidebar_full_width'       => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-full-width-layout.png',
			'no_sidebar_content_centered' => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-content-centered-layout.png',
		),
	) ) );

	// default layout for pages
	$wp_customize->add_section( 'colormag_default_page_layout_setting', array(
		'priority' => 4,
		'title'    => __( 'Default layout for pages only', 'colormag-pro' ),
		'panel'    => 'colormag_design_options',
	) );

	$wp_customize->add_setting( 'colormag_default_page_layout', array(
		'default'           => 'right_sidebar',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( new COLORMAG_Image_Radio_Control( $wp_customize, 'colormag_default_page_layout', array(
		'type'     => 'radio',
		'label'    => __( 'Select default layout for pages. This layout will be reflected in all pages unless unique layout is set for specific page', 'colormag-pro' ),
		'section'  => 'colormag_default_page_layout_setting',
		'settings' => 'colormag_default_page_layout',
		'choices'  => array(
			'right_sidebar'               => COLORMAG_ADMIN_IMAGES_URL . '/right-sidebar.png',
			'left_sidebar'                => COLORMAG_ADMIN_IMAGES_URL . '/left-sidebar.png',
			'no_sidebar_full_width'       => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-full-width-layout.png',
			'no_sidebar_content_centered' => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-content-centered-layout.png',
		),
	) ) );

	// default layout for single posts
	$wp_customize->add_section( 'colormag_default_single_posts_layout_setting', array(
		'priority' => 5,
		'title'    => __( 'Default layout for single posts only', 'colormag-pro' ),
		'panel'    => 'colormag_design_options',
	) );

	$wp_customize->add_setting( 'colormag_default_single_posts_layout', array(
		'default'           => 'right_sidebar',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( new COLORMAG_Image_Radio_Control( $wp_customize, 'colormag_default_single_posts_layout', array(
		'type'     => 'radio',
		'label'    => __( 'Select default layout for single posts. This layout will be reflected in all single posts unless unique layout is set for specific post', 'colormag-pro' ),
		'section'  => 'colormag_default_single_posts_layout_setting',
		'settings' => 'colormag_default_single_posts_layout',
		'choices'  => array(
			'right_sidebar'               => COLORMAG_ADMIN_IMAGES_URL . '/right-sidebar.png',
			'left_sidebar'                => COLORMAG_ADMIN_IMAGES_URL . '/left-sidebar.png',
			'no_sidebar_full_width'       => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-full-width-layout.png',
			'no_sidebar_content_centered' => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-content-centered-layout.png',
		),
	) ) );

	// category/archive pages layout setting
	$wp_customize->add_section( 'colormag_archive_search_layout_setting', array(
		'priority' => 6,
		'title'    => __( 'Blog/Archive and Search Pages Layout', 'colormag-pro' ),
		'panel'    => 'colormag_design_options',
	) );

	$wp_customize->add_setting( 'colormag_archive_search_layout', array(
		'default'           => 'double_column_layout',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_archive_search_layout', array(
		'type'    => 'radio',
		'label'   => esc_html__( 'Choose the layout option for the blog, archive and search results pages.', 'colormag-pro' ),
		'choices' => array(
			'double_column_layout' => esc_html__( 'Default (First image large and other two side by side)', 'colormag-pro' ),
			'single_column_layout' => esc_html__( 'One Column (Featured image on left and post excerpt on right)', 'colormag-pro' ),
			'full_width_layout'    => esc_html__( 'Full Width (Featured image on top and post excerpt below)', 'colormag-pro' ),
			'grid_layout'          => esc_html__( 'Grid Layout (Featured image on top and post excerpt below in two column grid)', 'colormag-pro' ),
		),
		'section' => 'colormag_archive_search_layout_setting',
	) );

	// primary color options
	$wp_customize->add_section( 'colormag_primary_color_setting', array(
		'panel'    => 'colormag_design_options',
		'priority' => 7,
		'title'    => __( 'Primary color option', 'colormag-pro' ),
	) );

	$wp_customize->add_setting( 'colormag_primary_color', array(
		'default'              => '#289dcc',
		'capability'           => 'edit_theme_options',
		'sanitize_callback'    => 'colormag_color_option_hex_sanitize',
		'sanitize_js_callback' => 'colormag_color_escaping_option_sanitize',
		'transport'            => 'postMessage',
	) );

	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'colormag_primary_color', array(
		'label'    => __( 'This will reflect in links, buttons and many others. Choose a color to match your site', 'colormag-pro' ),
		'section'  => 'colormag_primary_color_setting',
		'settings' => 'colormag_primary_color',
	) ) );

	// Color Skin
	$wp_customize->add_section( 'colormag_color_skin_setting_section', array(
		'priority' => 6,
		'title'    => esc_html__( 'Skin Color', 'colormag-pro' ),
		'panel'    => 'colormag_design_options',
	) );

	$wp_customize->add_setting( 'colormag_color_skin_setting', array(
		'default'           => 'white',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_color_skin_setting', array(
		'type'    => 'radio',
		'label'   => esc_html__( 'Choose the color skin for your site.', 'colormag-pro' ),
		'choices' => array(
			'white' => esc_html__( 'White Skin', 'colormag-pro' ),
			'dark'  => esc_html__( 'Dark Skin', 'colormag-pro' ),
		),
		'section' => 'colormag_color_skin_setting_section',
	) );

	if ( ! function_exists( 'wp_update_custom_css_post' ) ) {

		// Custom CSS setting
		class COLORMAG_Custom_CSS_Control extends WP_Customize_Control {

			public $type = 'custom_css';

			public function render_content() {
				?>
				<label>
					<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
					<textarea rows="5" style="width:100%;" <?php $this->link(); ?>><?php echo esc_textarea( $this->value() ); ?></textarea>
				</label>
				<?php
			}

		}

		$wp_customize->add_section( 'colormag_custom_css_setting', array(
			'priority' => 9,
			'title'    => __( 'Custom CSS', 'colormag-pro' ),
			'panel'    => 'colormag_design_options',
		) );

		$wp_customize->add_setting( 'colormag_custom_css', array(
			'default'              => '',
			'capability'           => 'edit_theme_options',
			'sanitize_callback'    => 'wp_filter_nohtml_kses',
			'sanitize_js_callback' => 'wp_filter_nohtml_kses',
		) );

		$wp_customize->add_control( new COLORMAG_Custom_CSS_Control( $wp_customize, 'colormag_custom_css', array(
			'label'    => __( 'Write your custom css', 'colormag-pro' ),
			'section'  => 'colormag_custom_css_setting',
			'settings' => 'colormag_custom_css',
		) ) );
	}
	// End of the Design Options
	// Start of the Social Link Options
	$wp_customize->add_panel( 'colormag_social_links_options', array(
		'priority'    => 510,
		'title'       => __( 'Social Options', 'colormag-pro' ),
		'description' => __( 'Change the Social Links Settings from here as you want', 'colormag-pro' ),
		'capability'  => 'edit_theme_options',
	) );

	$wp_customize->add_section( 'colormag_social_link_activate_settings', array(
		'priority' => 1,
		'title'    => __( 'Activate social links area', 'colormag-pro' ),
		'panel'    => 'colormag_social_links_options',
	) );

	$wp_customize->add_setting( 'colormag_social_link_activate', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_social_link_activate', array(
		'type'     => 'checkbox',
		'label'    => __( 'Check to activate social links area', 'colormag-pro' ),
		'section'  => 'colormag_social_link_activate_settings',
		'settings' => 'colormag_social_link_activate',
	) );

	// Social link location option.
	$wp_customize->add_setting( 'colormag_social_link_location_option', array(
		'default'           => 'both',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_social_link_location_option', array(
		'type'     => 'radio',
		'label'    => esc_html__( 'Social links to display on:', 'colormag-pro' ),
		'section'  => 'colormag_social_link_activate_settings',
		'settings' => 'colormag_social_link_location_option',
		'choices'  => array(
			'header' => esc_html__( 'Header only', 'colormag-pro' ),
			'footer' => esc_html__( 'Footer only', 'colormag-pro' ),
			'both'   => esc_html__( 'Both header and footer', 'colormag-pro' ),
		),
	) );

	// Selective refresh for displaying social icons/links
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_social_link_activate', array(
			'selector'        => '.social-links',
			'render_callback' => '',
		) );
	}

	$colormag_social_links = array(
		'colormag_social_facebook'    => array(
			'id'      => 'colormag_social_facebook',
			'title'   => __( 'Facebook', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_twitter'     => array(
			'id'      => 'colormag_social_twitter',
			'title'   => __( 'Twitter', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_googleplus'  => array(
			'id'      => 'colormag_social_googleplus',
			'title'   => __( 'Google-Plus', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_instagram'   => array(
			'id'      => 'colormag_social_instagram',
			'title'   => __( 'Instagram', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_pinterest'   => array(
			'id'      => 'colormag_social_pinterest',
			'title'   => __( 'Pinterest', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_youtube'     => array(
			'id'      => 'colormag_social_youtube',
			'title'   => __( 'YouTube', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_vimeo'       => array(
			'id'      => 'colormag_social_vimeo',
			'title'   => __( 'Vimeo-Square', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_linkedin'    => array(
			'id'      => 'colormag_social_linkedin',
			'title'   => __( 'LinkedIn', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_delicious'   => array(
			'id'      => 'colormag_social_delicious',
			'title'   => __( 'Delicious', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_flickr'      => array(
			'id'      => 'colormag_social_flickr',
			'title'   => __( 'Flickr', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_skype'       => array(
			'id'      => 'colormag_social_skype',
			'title'   => __( 'Skype', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_soundcloud'  => array(
			'id'      => 'colormag_social_soundcloud',
			'title'   => __( 'SoundCloud', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_vine'        => array(
			'id'      => 'colormag_social_vine',
			'title'   => __( 'Vine', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_stumbleupon' => array(
			'id'      => 'colormag_social_stumbleupon',
			'title'   => __( 'StumbleUpon', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_tumblr'      => array(
			'id'      => 'colormag_social_tumblr',
			'title'   => __( 'Tumblr', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_reddit'      => array(
			'id'      => 'colormag_social_reddit',
			'title'   => __( 'Reddit', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_xing'        => array(
			'id'      => 'colormag_social_xing',
			'title'   => __( 'Xing', 'colormag-pro' ),
			'default' => '',
		),
		'colormag_social_vk'          => array(
			'id'      => 'colormag_social_vk',
			'title'   => __( 'VK', 'colormag-pro' ),
			'default' => '',
		),
	);

	$i = 20;

	foreach ( $colormag_social_links as $colormag_social_link ) {

		$wp_customize->add_setting( $colormag_social_link['id'], array(
			'default'           => $colormag_social_link['default'],
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'esc_url_raw',
		) );

		$wp_customize->add_control( $colormag_social_link['id'], array(
			'label'    => $colormag_social_link['title'],
			'section'  => 'colormag_social_link_activate_settings',
			'settings' => $colormag_social_link['id'],
			'priority' => $i,
		) );

		$wp_customize->add_setting( $colormag_social_link['id'] . '_checkbox', array(
			'default'           => 0,
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'colormag_checkbox_sanitize',
		) );

		$wp_customize->add_control( $colormag_social_link['id'] . '_checkbox', array(
			'type'     => 'checkbox',
			'label'    => __( 'Check to show in new tab', 'colormag-pro' ),
			'section'  => 'colormag_social_link_activate_settings',
			'settings' => $colormag_social_link['id'] . '_checkbox',
			'priority' => $i,
		) );

		$i ++;
	}

	$user_custom_social_links = array(
		'user_custom_social_links_one'   => array(
			'id'      => 'user_custom_social_links_one',
			'title'   => __( 'Additional Social Link One', 'colormag-pro' ),
			'default' => '',
		),
		'user_custom_social_links_two'   => array(
			'id'      => 'user_custom_social_links_two',
			'title'   => __( 'Additional Social Link Two', 'colormag-pro' ),
			'default' => '',
		),
		'user_custom_social_links_three' => array(
			'id'      => 'user_custom_social_links_three',
			'title'   => __( 'Additional Social Link Three', 'colormag-pro' ),
			'default' => '',
		),
		'user_custom_social_links_four'  => array(
			'id'      => 'user_custom_social_links_four',
			'title'   => __( 'Additional Social Link Four', 'colormag-pro' ),
			'default' => '',
		),
		'user_custom_social_links_five'  => array(
			'id'      => 'user_custom_social_links_five',
			'title'   => __( 'Additional Social Link Five', 'colormag-pro' ),
			'default' => '',
		),
		'user_custom_social_links_six'   => array(
			'id'      => 'user_custom_social_links_six',
			'title'   => __( 'Additional Social Link Six', 'colormag-pro' ),
			'default' => '',
		),
	);

	$wp_customize->add_section( 'colormag_additional_social_icons', array(
		'priority' => 2,
		'title'    => __( 'Additional Social Icons', 'colormag-pro' ),
		'panel'    => 'colormag_social_links_options',
	) );

	$i = 20;

	foreach ( $user_custom_social_links as $user_custom_social_link ) {
		$wp_customize->add_setting( $user_custom_social_link['id'], array(
			'default'           => $user_custom_social_link['default'],
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'esc_url_raw',
		) );

		$wp_customize->add_control( $user_custom_social_link['id'], array(
			'label'    => $user_custom_social_link['title'],
			'section'  => 'colormag_additional_social_icons',
			'settings' => $user_custom_social_link['id'],
			'priority' => $i,
		) );

		$wp_customize->add_setting( $user_custom_social_link['id'] . '_fontawesome', array(
			'default'           => '',
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'wp_filter_nohtml_kses',
		) );

		$wp_customize->add_control( $user_custom_social_link['id'] . '_fontawesome', array(
			'label'    => __( 'Preferred Social Link FontAwesome Icon', 'colormag-pro' ),
			'section'  => 'colormag_additional_social_icons',
			'settings' => $user_custom_social_link['id'] . '_fontawesome',
			'priority' => $i,
		) );

		$wp_customize->add_setting( $user_custom_social_link['id'] . '_color', array(
			'default'              => '',
			'capability'           => 'edit_theme_options',
			'sanitize_callback'    => 'colormag_color_option_hex_sanitize',
			'sanitize_js_callback' => 'colormag_color_escaping_option_sanitize',
		) );

		$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, $user_custom_social_link['id'] . '_color', array(
			'label'    => __( 'Preferred Social Link Color Option', 'colormag-pro' ),
			'section'  => 'colormag_additional_social_icons',
			'settings' => $user_custom_social_link['id'] . '_color',
			'priority' => $i,
		) ) );

		$wp_customize->add_setting( $user_custom_social_link['id'] . '_checkbox', array(
			'default'           => 0,
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'colormag_checkbox_sanitize',
		) );

		$wp_customize->add_control( $user_custom_social_link['id'] . '_checkbox', array(
			'type'     => 'checkbox',
			'label'    => __( 'Check to show in new tab', 'colormag-pro' ),
			'section'  => 'colormag_additional_social_icons',
			'settings' => $user_custom_social_link['id'] . '_checkbox',
			'priority' => $i,
		) );

		$i ++;
	}
	// End of the Social Link Options

	// Start of the Author Bio Options
	$wp_customize->add_panel( 'colormag_author_bio_options', array(
		'capability'  => 'edit_theme_options',
		'description' => esc_html__( 'Change the Author Bio Settings from here as you want', 'colormag-pro' ),
		'priority'    => 512,
		'title'       => esc_html__( 'Author Bio Options', 'colormag-pro' ),
	) );

	// Author bio enable/disable option.
	$wp_customize->add_section( 'colormag_author_bio_disable_section', array(
		'priority' => 5,
		'title'    => esc_html__( 'Author Bio', 'colormag-pro' ),
		'panel'    => 'colormag_author_bio_options',
	) );

	$wp_customize->add_setting( 'colormag_author_bio_disable_setting', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_author_bio_disable_setting', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to disable the Author Bio', 'colormag-pro' ),
		'section'  => 'colormag_author_bio_disable_section',
		'settings' => 'colormag_author_bio_disable_setting',
	) );

	$wp_customize->add_section( 'colormag_author_bio_social_sites_show_setting', array(
		'priority' => 6,
		'title'    => esc_html__( 'Social Profiles in Author Bio', 'colormag-pro' ),
		'panel'    => 'colormag_author_bio_options',
	) );

	$wp_customize->add_setting( 'colormag_author_bio_social_sites_show', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_author_bio_social_sites_show', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to show the Social Profiles in the Author Bio', 'colormag-pro' ),
		'section'  => 'colormag_author_bio_social_sites_show_setting',
		'settings' => 'colormag_author_bio_social_sites_show',
	) );

	// Selective refresh for social profiles in author bio display
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_author_bio_social_sites_show', array(
			'selector'        => '.author-social-sites',
			'render_callback' => '',
		) );
	}

	// Author Bio url for pages in author bio section
	$wp_customize->add_section( 'colormag_author_bio_links_setting', array(
		'priority' => 6,
		'title'    => esc_html__( 'Author URL In Author Bio', 'colormag-pro' ),
		'panel'    => 'colormag_author_bio_options',
	) );

	$wp_customize->add_setting( 'colormag_author_bio_links', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_author_bio_links', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to display the link to the author page in the Author Bio section', 'colormag-pro' ),
		'section'  => 'colormag_author_bio_links_setting',
		'settings' => 'colormag_author_bio_links',
	) );

	// Author bio style.
	$wp_customize->add_section( 'colormag_author_bio_style_section', array(
		'priority' => 6,
		'title'    => esc_html__( 'Author Bio Layout', 'colormag-pro' ),
		'panel'    => 'colormag_author_bio_options',
	) );

	$wp_customize->add_setting( 'colormag_author_bio_style_setting', array(
		'default'           => 'style_one',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_author_bio_style_setting', array(
		'type'     => 'select',
		'label'    => esc_html__( 'Choose the author bio layout as needed.', 'colormag-pro' ),
		'section'  => 'colormag_author_bio_style_section',
		'settings' => 'colormag_author_bio_style_setting',
		'choices'  => array(
			'style_one'   => esc_html( 'Style 1', 'colormag-pro' ),
			'style_two'   => esc_html( 'Style 2', 'colormag-pro' ),
			'style_three' => esc_html( 'Style 3', 'colormag-pro' ),
		),
	) );

	// Selective refresh for author bio links display
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_author_bio_links', array(
			'selector'        => '.author-url',
			'render_callback' => '',
		) );
	}
	// End of the Author Bio Options

	// Start of the Footer Options
	$wp_customize->add_panel( 'colormag_footer_options', array(
		'capability'  => 'edit_theme_options',
		'description' => esc_html__( 'Change the Footer Settings from here as you want', 'colormag-pro' ),
		'priority'    => 515,
		'title'       => esc_html__( 'Footer Options', 'colormag-pro' ),
	) );

	// Footer display type option
	$wp_customize->add_section( 'colormag_main_footer_layout_display_type_section', array(
		'priority' => 1,
		'title'    => esc_html__( 'Footer Main Area Display Type', 'colormag-pro' ),
		'panel'    => 'colormag_footer_options',
	) );

	$wp_customize->add_setting( 'colormag_main_footer_layout_display_type', array(
		'default'           => 'type_one',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
		'transport'         => 'postMessage',
	) );

	$wp_customize->add_control( 'colormag_main_footer_layout_display_type', array(
		'type'    => 'radio',
		'label'   => esc_html__( 'Choose the main total footer area display type that you want', 'colormag-pro' ),
		'section' => 'colormag_main_footer_layout_display_type_section',
		'choices' => array(
			'type_one'   => esc_html__( 'Type 1 (Default)', 'colormag-pro' ),
			'type_two'   => esc_html__( 'Type 2', 'colormag-pro' ),
			'type_three' => esc_html__( 'Type 3', 'colormag-pro' ),
		),
	) );

	// Scroll to top button enable/disable option
	$wp_customize->add_section( 'colormag_scroll_to_top_setting', array(
		'priority' => 6,
		'title'    => esc_html__( 'Scroll To Top Button', 'colormag-pro' ),
		'panel'    => 'colormag_footer_options',
	) );

	$wp_customize->add_setting( 'colormag_scroll_to_top', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_scroll_to_top', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to disable the scroll to top button.', 'colormag-pro' ),
		'section'  => 'colormag_scroll_to_top_setting',
		'settings' => 'colormag_scroll_to_top',
	) );

	// footer editor section
	class COLORMAG_Footer_Control extends WP_Customize_Control {

		public $type = 'footer_control';

		public function render_content() {
			?>
			<label>
				<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
				<textarea rows="5" style="width:100%;" <?php $this->link(); ?>><?php echo esc_textarea( $this->value() ); ?></textarea>
			</label>
			<?php
		}

	}

	$wp_customize->add_section( 'colormag_footer_editor_setting', array(
		'priority' => 7,
		'title'    => esc_html__( 'Footer Copyright Editor', 'colormag-pro' ),
		'panel'    => 'colormag_footer_options',
	) );

	$default_footer_value = esc_html__( 'Copyright &copy; ', 'colormag-pro' ) . ' ' . '[the-year] [site-link]. All rights reserved. ' . '<br>' . esc_html__( 'Theme: ColorMag Pro by ', 'colormag-pro' ) . ' ' . '[tg-link]. ' . __( 'Powered by ', 'colormag-pro' ) . ' ' . '[wp-link].';

	$wp_customize->add_setting( 'colormag_footer_editor', array(
		'default'           => $default_footer_value,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_footer_editor_sanitize',
		'transport'         => $customizer_selective_refresh,
	) );

	if ( function_exists( 'wp_enqueue_code_editor' ) ) :

		$wp_customize->add_control( new WP_Customize_Code_Editor_Control( $wp_customize, 'colormag_footer_editor', array(
			'label'     => esc_html__( 'Edit the Copyright information in your footer. You can also use shortcodes [the-year], [site-link], [wp-link], [tg-link] for current year, your site link, WordPress site link and ThemeGrill site link respectively.', 'colormag-pro' ),
			'code_type' => 'text/html',
			'section'   => 'colormag_footer_editor_setting',
			'settings'  => 'colormag_footer_editor',
		) ) );

	else :

		$wp_customize->add_control( new COLORMAG_Footer_Control( $wp_customize, 'colormag_footer_editor', array(
			'label'    => esc_html__( 'Edit the Copyright information in your footer. You can also use shortcodes [the-year], [site-link], [wp-link], [tg-link] for current year, your site link, WordPress site link and ThemeGrill site link respectively.', 'colormag-pro' ),
			'section'  => 'colormag_footer_editor_setting',
			'settings' => 'colormag_footer_editor',
		) ) );

	endif;

	// Selective refresh for footer copyright text
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_footer_editor', array(
			'selector'        => '.copyright',
			'render_callback' => 'colormag_footer_copyright',
		) );
	}

	// Footer background section
	$wp_customize->add_section( 'colormag_footer_background_section', array(
		'priority' => 10,
		'title'    => esc_html__( 'Footer Background', 'colormag-pro' ),
		'panel'    => 'colormag_footer_options',
	) );

	// Footer background image upload setting
	$wp_customize->add_setting( 'colormag_footer_background_image', array(
		'default'           => '',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'esc_url_raw',
	) );

	$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'colormag_footer_background_image', array(
		'label'   => esc_html__( 'Background Image', 'colormag-pro' ),
		'setting' => 'colormag_footer_background_image',
		'section' => 'colormag_footer_background_section',
	) ) );

	// Footer background image position setting
	$wp_customize->add_setting( 'colormag_footer_background_image_position', array(
		'default'           => 'center-center',
		'capability'        => 'edit_theme_options',
		'transport'         => 'postMessage',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_footer_background_image_position', array(
		'type'    => 'select',
		'label'   => esc_html__( 'Background Image Position', 'colormag-pro' ),
		'setting' => 'colormag_footer_background_image_position',
		'section' => 'colormag_footer_background_section',
		'choices' => array(
			'left-top'      => esc_html__( 'Top Left', 'colormag-pro' ),
			'center-top'    => esc_html__( 'Top Center', 'colormag-pro' ),
			'right-top'     => esc_html__( 'Top Right', 'colormag-pro' ),
			'left-center'   => esc_html__( 'Center Left', 'colormag-pro' ),
			'center-center' => esc_html__( 'Center Center', 'colormag-pro' ),
			'right-center'  => esc_html__( 'Center Right', 'colormag-pro' ),
			'left-bottom'   => esc_html__( 'Bottom Left', 'colormag-pro' ),
			'center-bottom' => esc_html__( 'Bottom Center', 'colormag-pro' ),
			'right-bottom'  => esc_html__( 'Bottom Right', 'colormag-pro' ),
		),
	) );

	// Footer background size setting
	$wp_customize->add_setting( 'colormag_footer_background_image_size', array(
		'default'           => 'auto',
		'capability'        => 'edit_theme_options',
		'transport'         => 'postMessage',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_footer_background_image_size', array(
		'type'    => 'select',
		'label'   => esc_html__( 'Background Image Size', 'colormag-pro' ),
		'setting' => 'colormag_footer_background_image_size',
		'section' => 'colormag_footer_background_section',
		'choices' => array(
			'cover'   => esc_html__( 'Cover', 'colormag-pro' ),
			'contain' => esc_html__( 'Contain', 'colormag-pro' ),
			'auto'    => esc_html__( 'Auto', 'colormag-pro' ),
		),
	) );

	// Footer background attachment setting
	$wp_customize->add_setting( 'colormag_footer_background_image_attachment', array(
		'default'           => 'scroll',
		'capability'        => 'edit_theme_options',
		'transport'         => 'postMessage',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_footer_background_image_attachment', array(
		'type'    => 'select',
		'label'   => esc_html__( 'Background Image Attachment', 'colormag-pro' ),
		'setting' => 'colormag_footer_background_image_attachment',
		'section' => 'colormag_footer_background_section',
		'choices' => array(
			'scroll' => esc_html__( 'Scroll', 'colormag-pro' ),
			'fixed'  => esc_html__( 'Fixed', 'colormag-pro' ),
		),
	) );

	// Footer background repeat setting
	$wp_customize->add_setting( 'colormag_footer_background_image_repeat', array(
		'default'           => 'repeat',
		'capability'        => 'edit_theme_options',
		'transport'         => 'postMessage',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_footer_background_image_repeat', array(
		'type'    => 'select',
		'label'   => esc_html__( 'Background Image Repeat', 'colormag-pro' ),
		'setting' => 'colormag_footer_background_image_repeat',
		'section' => 'colormag_footer_background_section',
		'choices' => array(
			'no-repeat' => esc_html__( 'No Repeat', 'colormag-pro' ),
			'repeat'    => esc_html__( 'Repeat', 'colormag-pro' ),
			'repeat-x'  => esc_html__( 'Repeat Horizontally', 'colormag-pro' ),
			'repeat-y'  => esc_html__( 'Repeat Vertically', 'colormag-pro' ),
		),
	) );
	// End of Footer Options

	// Start of the Additional Options
	$wp_customize->add_panel( 'colormag_additional_options', array(
		'capability'  => 'edit_theme_options',
		'description' => __( 'Change the Additional Settings from here as you want', 'colormag-pro' ),
		'priority'    => 515,
		'title'       => __( 'Additional Options', 'colormag-pro' ),
	) );

	// unique post system options
	$wp_customize->add_section( 'colormag_unique_post_system_setting', array(
		'priority' => 1,
		'title'    => __( 'Unique Post System', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_unique_post_system', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_unique_post_system', array(
		'type'     => 'checkbox',
		'label'    => __( 'Check to activate the unique post system for the bundled widgets', 'colormag-pro' ),
		'section'  => 'colormag_unique_post_system_setting',
		'settings' => 'colormag_unique_post_system',
	) );

	// Sticky post and sidebar section
	$wp_customize->add_section( 'colormag_sticky_content_sidebar_setting', array(
		'priority' => 2,
		'title'    => esc_html__( 'Sticky Content And Sidebar', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_sticky_content_sidebar', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_sticky_content_sidebar', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to activate the sticky options for content and sidebar areas.', 'colormag-pro' ),
		'section'  => 'colormag_sticky_content_sidebar_setting',
		'settings' => 'colormag_sticky_content_sidebar',
	) );

	if ( ! function_exists( 'has_site_icon' ) || ( ! has_site_icon() && ( get_theme_mod( 'colormag_favicon_upload', '' ) != '' ) ) ) {
		// favicon options
		$wp_customize->add_section( 'colormag_favicon_show_setting', array(
			'priority' => 1,
			'title'    => __( 'Activate favicon', 'colormag-pro' ),
			'panel'    => 'colormag_additional_options',
		) );

		$wp_customize->add_setting( 'colormag_favicon_show', array(
			'default'           => 0,
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'colormag_checkbox_sanitize',
		) );

		$wp_customize->add_control( 'colormag_favicon_show', array(
			'type'     => 'checkbox',
			'label'    => __( 'Check to activate favicon. Upload favicon from below option', 'colormag-pro' ),
			'section'  => 'colormag_favicon_show_setting',
			'settings' => 'colormag_favicon_show',
		) );

		$wp_customize->add_setting( 'colormag_favicon_upload', array(
			'default'           => '',
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'esc_url_raw',
		) );

		$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'colormag_favicon_upload', array(
			'label'    => __( 'Upload favicon for your site', 'colormag-pro' ),
			'section'  => 'colormag_favicon_show_setting',
			'settings' => 'colormag_favicon_upload',
		) ) );
	}

	// related posts
	$wp_customize->add_section( 'colormag_related_posts_section', array(
		'priority' => 4,
		'title'    => __( 'Related Posts', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_related_posts_activate', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_related_posts_activate', array(
		'type'     => 'checkbox',
		'label'    => __( 'Check to activate the related posts', 'colormag-pro' ),
		'section'  => 'colormag_related_posts_section',
		'settings' => 'colormag_related_posts_activate',
	) );

	// Selective refresh for related posts feature
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_related_posts_activate', array(
			'selector'        => '.related-posts',
			'render_callback' => '',
		) );
	}

	$wp_customize->add_setting( 'colormag_related_posts', array(
		'default'           => 'categories',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_related_posts', array(
		'type'     => 'radio',
		'label'    => __( 'Related Posts Must Be Shown As:', 'colormag-pro' ),
		'section'  => 'colormag_related_posts_section',
		'settings' => 'colormag_related_posts',
		'choices'  => array(
			'categories' => __( 'Related Posts By Categories', 'colormag-pro' ),
			'tags'       => __( 'Related Posts By Tags', 'colormag-pro' ),
		),
	) );

	// Select option to display number of posts
	$wp_customize->add_setting( 'colormag_related_post_number_display', array(
		'default'           => '3',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_related_post_number_display', array(
		'type'     => 'select',
		'section'  => 'colormag_related_posts_section',
		'settings' => 'colormag_related_post_number_display',
		'label'    => esc_html__( 'Number of post to display', 'colormag-pro' ),
		'choices'  => array(
			'3' => esc_html__( '3', 'colormag-pro' ),
			'6' => esc_html__( '6', 'colormag-pro' ),
		),
	) );

	// Related posts layout option.
	$wp_customize->add_setting( 'colormag_related_posts_layout', array(
		'default'           => 'style_one',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_related_posts_layout', array(
		'type'     => 'select',
		'label'    => esc_html__( 'Choose the related posts layout as needed.', 'colormag-pro' ),
		'section'  => 'colormag_related_posts_section',
		'settings' => 'colormag_related_posts_layout',
		'choices'  => array(
			'style_one'   => esc_html__( 'Style 1', 'colormag-pro' ),
			'style_two'   => esc_html__( 'Style 2', 'colormag-pro' ),
			'style_three' => esc_html__( 'Style 3', 'colormag-pro' ),
			'style_four'  => esc_html__( 'Style 4', 'colormag-pro' ),
		),
	) );

	// Related post flyout option.
	$wp_customize->add_section( 'colormag_related_post_flyout_section', array(
		'priority' => 4,
		'title'    => esc_html__( 'Flyout Related Post', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_related_post_flyout_setting', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_related_post_flyout_setting', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to display the related post when browser scrolls at end.', 'colormag-pro' ),
		'section'  => 'colormag_related_post_flyout_section',
		'settings' => 'colormag_related_post_flyout_setting',
	) );

	$wp_customize->add_setting( 'colormag_related_posts_flyout_type', array(
		'default'           => 'categories',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_related_posts_flyout_type', array(
		'type'     => 'radio',
		'label'    => esc_html__( 'Related Posts Must Be Shown As:', 'colormag-pro' ),
		'section'  => 'colormag_related_post_flyout_section',
		'settings' => 'colormag_related_posts_flyout_type',
		'choices'  => array(
			'categories' => esc_html__( 'Related Posts By Categories', 'colormag-pro' ),
			'tags'       => esc_html__( 'Related Posts By Tags', 'colormag-pro' ),
		),
	) );

	// Option to display reading time.
	$wp_customize->add_section( 'colormag_reading_time_section', array(
		'priority' => 4,
		'title'    => esc_html__( 'Reading Time Display', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_reading_time_setting', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_reading_time_setting', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to display the reading time.', 'colormag-pro' ),
		'section'  => 'colormag_reading_time_section',
		'settings' => 'colormag_reading_time_setting',
	) );

	// Option to post meta date.
	$wp_customize->add_section( 'colormag_post_meta_date_section', array(
		'priority' => 4,
		'title'    => esc_html__( 'Post Meta Date', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_post_meta_date_setting', array(
		'default'           => 'post_date',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_post_meta_date_setting', array(
		'type'     => 'radio',
		'label'    => esc_html__( 'Choose post meta display type:', 'colormag-pro' ),
		'section'  => 'colormag_post_meta_date_section',
		'settings' => 'colormag_post_meta_date_setting',
		'choices'  => array(
			'post_date'                => esc_html__( 'Display published date ', 'colormag-pro' ),
			'post_human_readable_date' => esc_html__( 'Display published date in "X time ago" format', 'colormag-pro' ),
		),
	) );

	// prognroll bar indicator option
	$wp_customize->add_section( 'colormag_prognroll_indicator_section', array(
		'priority' => 4,
		'title'    => esc_html__( 'Reading Progress Indicator', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_prognroll_indicator', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_prognroll_indicator', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to activate the reading progress indicator in single post page.', 'colormag-pro' ),
		'section'  => 'colormag_prognroll_indicator_section',
		'settings' => 'colormag_prognroll_indicator',
	) );

	// Archive pages display content/category option addition
	$wp_customize->add_section( 'colormag_archive_content_excerpt_display_section', array(
		'priority' => 5,
		'title'    => esc_html__( 'Blog/Archive and Search Pages Display Type', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_archive_content_excerpt_display', array(
		'default'           => 'excerpt',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_archive_content_excerpt_display', array(
		'type'     => 'radio',
		'label'    => esc_html__( 'Choose to display the post content or excerpt:', 'colormag-pro' ),
		'section'  => 'colormag_archive_content_excerpt_display_section',
		'settings' => 'colormag_archive_content_excerpt_display',
		'choices'  => array(
			'excerpt' => esc_html__( '(Default) Display Excerpt', 'colormag-pro' ),
			'content' => esc_html__( 'Display Content', 'colormag-pro' ),
		),
	) );

	$wp_customize->add_setting( 'colormag_custom_information_for_content_display_type', array(
		'default'           => '',
		'sanitize_callback' => 'colormag_false_sanitize',
	) );
	$wp_customize->add_control( new COLORMAG_Custom_Information( $wp_customize, 'colormag_custom_information_for_content_display_type', array(
		'label'       => esc_html__( 'Important Notice:', 'colormag-pro' ),
		'description' => sprintf( __( 'The content will only be displayed if you have chosen %1$sOne Column (Featured image on left and post excerpt on right)%2$s or %1$sFull Width (Featured image on top and post excerpt below)%2$s option in %1$sBlog/Archive and Search Pages Layout%2$s under the %1$sDesign Settings%2$s.', 'colormag-pro' ), '<strong>', '</strong>' ),
		'settings'    => 'colormag_custom_information_for_content_display_type',
		'section'     => 'colormag_archive_content_excerpt_display_section',
	) ) );

	// Post Navigation layout
	$wp_customize->add_section( 'colormag_post_navigation_section', array(
		'priority' => 5,
		'title'    => esc_html__( 'Post Navigation', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_post_navigation', array(
		'default'           => 'default',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_post_navigation', array(
		'type'     => 'radio',
		'label'    => esc_html__( 'Post navigation to be shown as:', 'colormag-pro' ),
		'section'  => 'colormag_post_navigation_section',
		'settings' => 'colormag_post_navigation',
		'choices'  => array(
			'default'               => esc_html__( 'Default Layout', 'colormag-pro' ),
			'small_featured_image'  => esc_html__( 'Featured image with post title', 'colormag-pro' ),
			'medium_featured_image' => esc_html__( 'Featured image with post title (Style 2)', 'colormag-pro' ),
		),
	) );

	// entry meta remove
	$wp_customize->add_section( 'colormag_entry_meta_section', array(
		'priority' => 4,
		'title'    => esc_html__( 'Post Meta Display', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	// total entry meta remove
	$wp_customize->add_setting( 'colormag_all_entry_meta_remove', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => 'postMessage',
	) );

	$wp_customize->add_control( 'colormag_all_entry_meta_remove', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Disable the post meta for the post totally, ie, remove all of the meta data.', 'colormag-pro' ),
		'section'  => 'colormag_entry_meta_section',
		'settings' => 'colormag_all_entry_meta_remove',
	) );

	// author entry meta remove
	$wp_customize->add_setting( 'colormag_author_entry_meta_remove', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => 'postMessage',
	) );

	$wp_customize->add_control( 'colormag_author_entry_meta_remove', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Disable the author only in post meta section.', 'colormag-pro' ),
		'section'  => 'colormag_entry_meta_section',
		'settings' => 'colormag_author_entry_meta_remove',
	) );

	// date entry meta remove
	$wp_customize->add_setting( 'colormag_date_entry_meta_remove', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => 'postMessage',
	) );

	$wp_customize->add_control( 'colormag_date_entry_meta_remove', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Disable the date only in post meta section.', 'colormag-pro' ),
		'section'  => 'colormag_entry_meta_section',
		'settings' => 'colormag_date_entry_meta_remove',
	) );

	// category entry meta remove
	$wp_customize->add_setting( 'colormag_category_entry_meta_remove', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => 'postMessage',
	) );

	$wp_customize->add_control( 'colormag_category_entry_meta_remove', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Disable the category only in post meta section.', 'colormag-pro' ),
		'section'  => 'colormag_entry_meta_section',
		'settings' => 'colormag_category_entry_meta_remove',
	) );

	// comments entry meta remove
	$wp_customize->add_setting( 'colormag_comments_entry_meta_remove', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => 'postMessage',
	) );

	$wp_customize->add_control( 'colormag_comments_entry_meta_remove', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Disable the comments only in post meta section.', 'colormag-pro' ),
		'section'  => 'colormag_entry_meta_section',
		'settings' => 'colormag_comments_entry_meta_remove',
	) );

	// tags entry meta remove
	$wp_customize->add_setting( 'colormag_tags_entry_meta_remove', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => 'postMessage',
	) );

	$wp_customize->add_control( 'colormag_tags_entry_meta_remove', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Disable the tags only in post meta section.', 'colormag-pro' ),
		'section'  => 'colormag_entry_meta_section',
		'settings' => 'colormag_tags_entry_meta_remove',
	) );

	// post view entry meta remove
	$wp_customize->add_setting( 'colormag_post_view_entry_meta_remove', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_post_view_entry_meta_remove', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Disable the post view only in post meta section.', 'colormag-pro' ),
		'section'  => 'colormag_entry_meta_section',
		'settings' => 'colormag_post_view_entry_meta_remove',
	) );

	// edit button entry meta remove
	$wp_customize->add_setting( 'colormag_edit_button_entry_meta_remove', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_edit_button_entry_meta_remove', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Disable the edit button only in post meta section.', 'colormag-pro' ),
		'section'  => 'colormag_entry_meta_section',
		'settings' => 'colormag_edit_button_entry_meta_remove',
	) );

	// read more button text
	$wp_customize->add_section( 'colormag_read_more_text_section', array(
		'priority' => 5,
		'title'    => __( 'Change Read More Text', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_read_more_text', array(
		'default'           => __( 'Read more', 'colormag-pro' ),
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'wp_filter_nohtml_kses',
	) );

	$wp_customize->add_control( 'colormag_read_more_text', array(
		'label'    => __( 'Change the Read more text as required for your site.', 'colormag-pro' ),
		'section'  => 'colormag_read_more_text_section',
		'settings' => 'colormag_read_more_text',
	) );

	// View All button text
	$wp_customize->add_section( 'colormag_view_all_text_section', array(
		'priority' => 5,
		'title'    => esc_html__( 'Change View All Text', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_view_all_text', array(
		'default'           => esc_html__( 'View All', 'colormag-pro' ),
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'wp_filter_nohtml_kses',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_view_all_text', array(
		'label'    => esc_html__( 'Change the View All text as required for your site.', 'colormag-pro' ),
		'section'  => 'colormag_view_all_text_section',
		'settings' => 'colormag_view_all_text',
	) );

	// Selective refresh for view all button text
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_view_all_text', array(
			'selector'        => '.view-all-link',
			'render_callback' => 'colormag_view_all_button_text',
		) );
	}

	// You May Also Like button text
	$wp_customize->add_section( 'colormag_you_may_also_like_text_section', array(
		'priority' => 5,
		'title'    => esc_html__( 'Change You May Also Like Text', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_you_may_also_like_text', array(
		'default'           => esc_html__( 'You May Also Like', 'colormag-pro' ),
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'wp_filter_nohtml_kses',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_you_may_also_like_text', array(
		'label'    => esc_html__( 'Change the You May Also Like text as required for your site.', 'colormag-pro' ),
		'section'  => 'colormag_you_may_also_like_text_section',
		'settings' => 'colormag_you_may_also_like_text',
	) );

	// Selective refresh for you may also like text
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_you_may_also_like_text', array(
			'selector'        => '.related-posts-main-title',
			'render_callback' => 'colormag_you_may_also_like_text',
		) );
	}

	// social share buttons
	$wp_customize->add_section( 'colormag_social_share_section', array(
		'priority' => 5,
		'title'    => __( 'Social Share Button', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_social_share', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_social_share', array(
		'type'     => 'checkbox',
		'label'    => __( 'Check to activate social share buttons in single post', 'colormag-pro' ),
		'section'  => 'colormag_social_share_section',
		'settings' => 'colormag_social_share',
	) );

	// Selective refresh for social share buttons
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_social_share', array(
			'selector'        => '.share-buttons',
			'render_callback' => '',
		) );
	}

	// featured image popup check
	$wp_customize->add_section( 'colormag_featured_image_popup_setting', array(
		'priority' => 6,
		'title'    => __( 'Image Lightbox', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_featured_image_popup', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_featured_image_popup', array(
		'type'     => 'checkbox',
		'label'    => __( 'Check to enable the lightbox for the featured images in single post', 'colormag-pro' ),
		'section'  => 'colormag_featured_image_popup_setting',
		'settings' => 'colormag_featured_image_popup',
	) );

	// Featured Image Caption Display Setting
	$wp_customize->add_section( 'colormag_featured_image_caption_show_setting', array(
		'priority' => 6,
		'title'    => esc_html__( 'Featured Image Caption', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_featured_image_caption_show', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
		'transport'         => $customizer_selective_refresh,
	) );

	$wp_customize->add_control( 'colormag_featured_image_caption_show', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to show the image caption under the featured image in archive, search as well as the single post page.', 'colormag-pro' ),
		'section'  => 'colormag_featured_image_caption_show_setting',
		'settings' => 'colormag_featured_image_caption_show',
	) );

	// Selective refresh for featured image caption display
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'colormag_featured_image_caption_show', array(
			'selector'        => '.featured-image-caption',
			'render_callback' => 'colormag_featured_image_caption_display',
		) );
	}

	// Feature Image dispaly/hide option
	$wp_customize->add_section( 'colormag_featured_image_show_setting', array(
		'priority' => 6,
		'title'    => esc_html__( 'Featured Image', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_featured_image_show', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_featured_image_show', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to hide the featured image in single post page.', 'colormag-pro' ),
		'section'  => 'colormag_featured_image_show_setting',
		'settings' => 'colormag_featured_image_show',
	) );

	// Title above/ below the feature image.
	$wp_customize->add_section( 'colormag_single_post_title_position_setting', array(
		'priority' => 6,
		'title'    => esc_html__( 'Post Title Position', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_single_post_title_position', array(
		'default'           => 'below',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_radio_select_sanitize',
	) );

	$wp_customize->add_control( 'colormag_single_post_title_position', array(
		'type'     => 'select',
		'label'    => esc_html__( 'Select the post title position in single post page.', 'colormag-pro' ),
		'section'  => 'colormag_single_post_title_position_setting',
		'settings' => 'colormag_single_post_title_position',
		'choices'  => array(
			'above' => esc_html__( 'Above featured image', 'colormag-pro' ),
			'below' => esc_html__( 'Below featured image', 'colormag-pro' ),
		),
	) );

	// Feature Image dispaly option in single page
	$wp_customize->add_section( 'colormag_featured_image_show_setting_single_page', array(
		'priority' => 6,
		'title'    => esc_html__( 'Featured Image In Single Page', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_featured_image_single_page_show', array(
		'default'           => 0,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_featured_image_single_page_show', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to display the featured image in single page.', 'colormag-pro' ),
		'section'  => 'colormag_featured_image_show_setting_single_page',
		'settings' => 'colormag_featured_image_single_page_show',
	) );

	$wp_customize->add_section( 'colormag_schema_markup_section', array(
		'priority' => 8,
		'title'    => esc_html__( 'Schema Markup', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_schema_markup', array(
		'default'           => '',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_schema_markup', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to enable schema markup.', 'colormag-pro' ),
		'section'  => 'colormag_schema_markup_section',
		'settings' => 'colormag_schema_markup',
	) );

	$wp_customize->add_section( 'colormag_openweathermap_section', array(
		'priority' => 9,
		'title'    => esc_html__( 'OpenWeatherMap API Key', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_openweathermap_api_key', array(
		'default'           => '',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'wp_filter_nohtml_kses',
	) );

	$wp_customize->add_control( 'colormag_openweathermap_api_key', array(
		'type'     => 'text',
		'label'    => esc_html__( 'API Key', 'colormag-pro' ),
		'section'  => 'colormag_openweathermap_section',
		'settings' => 'colormag_openweathermap_api_key',
	) );

	// GoogleMaps API key
	$wp_customize->add_section( 'colormag_googlemap_section', array(
		'priority' => 9,
		'title'    => esc_html__( 'GoogleMaps API Key', 'colormag-pro' ),
		'panel'    => 'colormag_additional_options',
	) );

	$wp_customize->add_setting( 'colormag_googlemap_api_key', array(
		'default'           => '',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'wp_filter_nohtml_kses',
	) );

	$wp_customize->add_control( 'colormag_googlemap_api_key', array(
		'label'    => esc_html__( 'API Key', 'colormag-pro' ),
		'section'  => 'colormag_googlemap_section',
		'settings' => 'colormag_googlemap_api_key',
	) );
	// End of the Additional Options

	// Start of Woocommerce options.
	if ( class_exists( 'WooCommerce' ) ) {
		$wp_customize->add_panel( 'colormag_woocommerce_options', array(
			'priority'    => 535,
			'title'       => esc_html__( 'WooCommerce Options', 'colormag-pro' ),
			'capability'  => 'edit_theme_options',
			'description' => esc_html__( 'Change the WooCommerce Settings from here as you want', 'colormag-pro' ),
		) );

		$wp_customize->add_section( 'colormag_woocommerce_setting', array(
			'priority' => 1,
			'title'    => esc_html__( 'Woocommerce Settings', 'colormag-pro' ),
			'panel'    => 'colormag_woocommerce_options',
		) );

		// Add additional sidebar area for WooCommerce pages.
		$wp_customize->add_setting( 'colormag_woocommerce_sidebar_register_setting', array(
			'default'           => 0,
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'colormag_checkbox_sanitize',
		) );

		$wp_customize->add_control( 'colormag_woocommerce_sidebar_register_setting', array(
			'type'     => 'checkbox',
			'label'    => esc_html__( 'Check to register different sidebar areas to be used for WooCommerce pages.', 'colormag-pro' ),
			'section'  => 'colormag_woocommerce_setting',
			'settings' => 'colormag_woocommerce_sidebar_register_setting',
		) );

		// WooCommerce Shop Page Layout.
		$wp_customize->add_setting( 'colormag_woocmmerce_shop_page_layout', array(
			'default'           => 'right_sidebar',
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'colormag_radio_select_sanitize',
		) );

		$wp_customize->add_control( new COLORMAG_Image_Radio_Control( $wp_customize, 'colormag_woocmmerce_shop_page_layout', array(
			'type'     => 'radio',
			'label'    => esc_html__( 'WooCommerce Shop Page Layout', 'colormag-pro' ),
			'section'  => 'colormag_woocommerce_setting',
			'settings' => 'colormag_woocmmerce_shop_page_layout',
			'choices'  => array(
				'right_sidebar'               => COLORMAG_ADMIN_IMAGES_URL . '/right-sidebar.png',
				'left_sidebar'                => COLORMAG_ADMIN_IMAGES_URL . '/left-sidebar.png',
				'no_sidebar_full_width'       => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-full-width-layout.png',
				'no_sidebar_content_centered' => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-content-centered-layout.png',
			),
		) ) );

		// WooCommerce Archive Page Layout.
		$wp_customize->add_setting( 'colormag_woocmmerce_archive_page_layout', array(
			'default'           => 'right_sidebar',
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'colormag_radio_select_sanitize',
		) );

		$wp_customize->add_control( new COLORMAG_Image_Radio_Control( $wp_customize, 'colormag_woocmmerce_archive_page_layout', array(
			'type'     => 'radio',
			'label'    => esc_html__( 'WooCommerce Archive Page Layout', 'colormag-pro' ),
			'section'  => 'colormag_woocommerce_setting',
			'settings' => 'colormag_woocmmerce_archive_page_layout',
			'choices'  => array(
				'right_sidebar'               => COLORMAG_ADMIN_IMAGES_URL . '/right-sidebar.png',
				'left_sidebar'                => COLORMAG_ADMIN_IMAGES_URL . '/left-sidebar.png',
				'no_sidebar_full_width'       => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-full-width-layout.png',
				'no_sidebar_content_centered' => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-content-centered-layout.png',
			),
		) ) );

		// WooCommerce Single Product Page Layout.
		$wp_customize->add_setting( 'colormag_woocmmerce_single_product_page_layout', array(
			'default'           => 'right_sidebar',
			'capability'        => 'edit_theme_options',
			'sanitize_callback' => 'colormag_radio_select_sanitize',
		) );

		$wp_customize->add_control( new COLORMAG_Image_Radio_Control( $wp_customize, 'colormag_woocmmerce_single_product_page_layout', array(
			'type'     => 'radio',
			'label'    => esc_html__( 'WooCommerce Single Product Page Layout', 'colormag-pro' ),
			'section'  => 'colormag_woocommerce_setting',
			'settings' => 'colormag_woocmmerce_single_product_page_layout',
			'choices'  => array(
				'right_sidebar'               => COLORMAG_ADMIN_IMAGES_URL . '/right-sidebar.png',
				'left_sidebar'                => COLORMAG_ADMIN_IMAGES_URL . '/left-sidebar.png',
				'no_sidebar_full_width'       => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-full-width-layout.png',
				'no_sidebar_content_centered' => COLORMAG_ADMIN_IMAGES_URL . '/no-sidebar-content-centered-layout.png',
			),
		) ) );
	}
	// End of WooCommerce options.

	// Category Color Options
	$wp_customize->add_panel( 'colormag_category_color_panel', array(
		'priority'    => 535,
		'title'       => __( 'Category Color Options', 'colormag-pro' ),
		'capability'  => 'edit_theme_options',
		'description' => __( 'Change the color of each category items as you want.', 'colormag-pro' ),
	) );

	$wp_customize->add_section( 'colormag_category_color_setting', array(
		'priority' => 1,
		'title'    => __( 'Category Color Settings', 'colormag-pro' ),
		'panel'    => 'colormag_category_color_panel',
	) );

	$i                = 1;
	$args             = array(
		'orderby'    => 'id',
		'hide_empty' => 0,
	);
	$categories       = get_categories( $args );
	$wp_category_list = array();
	foreach ( $categories as $category_list ) {
		$wp_category_list[ $category_list->cat_ID ] = $category_list->cat_name;

		$wp_customize->add_setting( 'colormag_category_color_' . get_cat_id( $wp_category_list[ $category_list->cat_ID ] ), array(
			'default'              => '',
			'capability'           => 'edit_theme_options',
			'sanitize_callback'    => 'colormag_color_option_hex_sanitize',
			'sanitize_js_callback' => 'colormag_color_escaping_option_sanitize',
		) );

		$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'colormag_category_color_' . get_cat_id( $wp_category_list[ $category_list->cat_ID ] ), array(
			'label'    => sprintf( __( '%s', 'colormag-pro' ), $wp_category_list[ $category_list->cat_ID ] ),
			'section'  => 'colormag_category_color_setting',
			'settings' => 'colormag_category_color_' . get_cat_id( $wp_category_list[ $category_list->cat_ID ] ),
			'priority' => $i,
		) ) );
		$i ++;
	}

	$wp_customize->add_section( 'colormag_category_menu_color_section', array(
		'priority' => 2,
		'title'    => esc_html__( 'Category Color in Menu', 'colormag-pro' ),
		'panel'    => 'colormag_category_color_panel',
	) );

	$wp_customize->add_setting( 'colormag_category_menu_color', array(
		'default'           => '',
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'colormag_checkbox_sanitize',
	) );

	$wp_customize->add_control( 'colormag_category_menu_color', array(
		'type'     => 'checkbox',
		'label'    => esc_html__( 'Check to show category color in menu.', 'colormag-pro' ),
		'section'  => 'colormag_category_menu_color_section',
		'settings' => 'colormag_category_menu_color',
	) );

	}
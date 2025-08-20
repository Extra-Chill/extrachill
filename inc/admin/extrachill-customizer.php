<?php
/**
 * Extra Chill Theme Customizer
 *
 * @package    ThemeGrill
 * @subpackage Extra Chill
 * @since      Extra Chill 1.0
 */

function extrachill_customize_register( $wp_customize ) {

	// Transport postMessage variable set
	$customizer_selective_refresh = isset( $wp_customize->selective_refresh ) ? 'postMessage' : 'refresh';

	// Start of the Footer Options
	$wp_customize->add_panel( 'extrachill_footer_options', array(
		'capability'  => 'edit_theme_options',
		'description' => esc_html__( 'Change the Footer Settings from here as you want', 'extrachill' ),
		'priority'    => 515,
		'title'       => esc_html__( 'Footer Options', 'extrachill' ),
	) );


	// footer editor section
	class ExtraChill_Footer_Control extends WP_Customize_Control {

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

	$wp_customize->add_section( 'extrachill_footer_editor_setting', array(
		'priority' => 7,
		'title'    => esc_html__( 'Footer Copyright Editor', 'extrachill' ),
		'panel'    => 'extrachill_footer_options',
	) );

	$default_footer_value = esc_html__( 'Copyright &copy; ', 'extrachill' ) . ' ' . '[the-year] [site-link]. All rights reserved. ';

	$wp_customize->add_setting( 'extrachill_footer_editor', array(
		'default'           => $default_footer_value,
		'capability'        => 'edit_theme_options',
		'sanitize_callback' => 'wp_filter_nohtml_kses',
		'transport'         => $customizer_selective_refresh,
	) );

	if ( function_exists( 'wp_enqueue_code_editor' ) ) :

		$wp_customize->add_control( new WP_Customize_Code_Editor_Control( $wp_customize, 'extrachill_footer_editor', array(
			'label'     => esc_html__( 'Edit the Copyright information in your footer. You can also use shortcodes [the-year] and [site-link].', 'extrachill' ),
			'code_type' => 'text/html',
			'section'   => 'extrachill_footer_editor_setting',
			'settings'  => 'extrachill_footer_editor',
		) ) );

	else :

		$wp_customize->add_control( new ExtraChill_Footer_Control( $wp_customize, 'extrachill_footer_editor', array(
			'label'    => esc_html__( 'Edit the Copyright information in your footer. You can also use shortcodes [the-year] and [site-link].', 'extrachill' ),
			'section'  => 'extrachill_footer_editor_setting',
			'settings' => 'extrachill_footer_editor',
		) ) );

	endif;

	// Selective refresh for footer copyright text
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial( 'extrachill_footer_editor', array(
			'selector'        => '.copyright',
			'render_callback' => 'extrachill_footer_copyright',
		) );
	}
}
add_action( 'customize_register', 'extrachill_customize_register' );


function extrachill_footer_copyright() {
    $default_footer_value = esc_html__( 'Copyright &copy; ', 'extrachill' ) . ' ' . date_i18n( 'Y' ) . ' ' . '<a href="' . esc_url( home_url( '/' ) ) . '" title="' . esc_attr( get_bloginfo( 'name', 'display' ) ) . '" ><span>' . get_bloginfo( 'name', 'display' ) . '</span></a>. ' . esc_html__( 'All rights reserved.', 'extrachill' );
    $footer_copyright     = '<div class="copyright">' . get_theme_mod( 'extrachill_footer_editor', $default_footer_value ) . '</div>';
    echo do_shortcode( $footer_copyright );
}
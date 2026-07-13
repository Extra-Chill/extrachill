<?php
/**
 * Back-to-top navigation for long singular views.
 *
 * @package ExtraChill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Determine whether the current view should include back-to-top navigation.
 *
 * @return bool
 */
function extrachill_should_show_back_to_top() {
	return (bool) apply_filters( 'extrachill_show_back_to_top', is_singular() );
}

/**
 * Enqueue back-to-top behavior only where the control can render.
 */
function extrachill_enqueue_back_to_top() {
	if ( ! extrachill_should_show_back_to_top() ) {
		return;
	}

	$script_path = get_template_directory() . '/assets/js/back-to-top.js';
	if ( ! file_exists( $script_path ) ) {
		return;
	}

	wp_enqueue_script(
		'extrachill-back-to-top',
		get_template_directory_uri() . '/assets/js/back-to-top.js',
		array(),
		(string) filemtime( $script_path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_back_to_top' );

/**
 * Render the progressively enhanced back-to-top control.
 */
function extrachill_render_back_to_top() {
	if ( ! extrachill_should_show_back_to_top() ) {
		return;
	}
	?>
	<button class="back-to-top" type="button" aria-label="<?php esc_attr_e( 'Back to top', 'extrachill' ); ?>" aria-hidden="true" tabindex="-1">
		<?php echo ec_icon( 'circle-up-outline' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ec_icon() returns escaped SVG markup from the theme sprite. ?>
	</button>
	<?php
}
add_action( 'wp_footer', 'extrachill_render_back_to_top', 5 );

<?php
/**
 * Generic Newsletter Form Template
 *
 * Unified template for all newsletter subscription forms.
 * Accepts $context and $args for customization.
 *
 * @package ExtraChillNewsletter
 * @since 0.1.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$defaults = array(
	'wrapper_element'    => 'div',
	'wrapper_class'      => '',
	'heading'            => null,
	'heading_level'      => 'h3',
	'description'        => null,
	'layout'             => 'section',
	'placeholder'        => __( 'Enter your email', 'extrachill-newsletter' ),
	'button_text'        => __( 'Subscribe', 'extrachill-newsletter' ),
	'show_archive_link'  => false,
	'archive_link_text'  => __( 'Browse past newsletters', 'extrachill-newsletter' ),
);

$args = wp_parse_args( $args, $defaults );

$layout_class = 'newsletter-' . esc_attr( $args['layout'] ) . '-form';
$input_id     = 'newsletter-email-' . esc_attr( $context );

$wrapper_tag   = tag_escape( $args['wrapper_element'] );
$wrapper_class = trim( $args['wrapper_class'] . ' newsletter-form-wrapper' );
$heading_tag   = tag_escape( $args['heading_level'] );
?>

<<?php echo $wrapper_tag; ?> class="<?php echo esc_attr( $wrapper_class ); ?>">

	<?php if ( $args['heading'] || $args['description'] ) : ?>
		<?php if ( $args['layout'] === 'section' ) : ?>
			<div class="newsletter-form-header">
		<?php endif; ?>

		<?php if ( $args['heading'] ) : ?>
			<<?php echo $heading_tag; ?> class="newsletter-form-heading">
				<?php echo esc_html( $args['heading'] ); ?>
			</<?php echo $heading_tag; ?>>
		<?php endif; ?>

		<?php if ( $args['description'] ) : ?>
			<p class="newsletter-form-description">
				<?php echo esc_html( $args['description'] ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $args['layout'] === 'section' ) : ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<form data-newsletter-form data-newsletter-context="<?php echo esc_attr( $context ); ?>" class="newsletter-form <?php echo esc_attr( $layout_class ); ?>">

		<label for="<?php echo esc_attr( $input_id ); ?>" class="sr-only">
			<?php esc_html_e( 'Email address for newsletter', 'extrachill-newsletter' ); ?>
		</label>
		<input
			type="email"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="email"
			required
			placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
			aria-label="<?php esc_attr_e( 'Email address', 'extrachill-newsletter' ); ?>"
		>
		<button type="submit" class="button-2 button-medium"><?php echo esc_html( $args['button_text'] ); ?></button>

		<p data-newsletter-feedback class="notice" style="display:none;" aria-live="polite"></p>

		<?php if ( $args['show_archive_link'] ) : ?>
			<?php $archive_url = get_post_type_archive_link( 'newsletter' ); ?>
			<?php if ( $archive_url ) : ?>
				<p class="newsletter-archive-link">
					<a href="<?php echo esc_url( $archive_url ); ?>">
						<?php echo esc_html( $args['archive_link_text'] ); ?>
					</a>
				</p>
			<?php endif; ?>
		<?php endif; ?>

	</form>

</<?php echo $wrapper_tag; ?>>

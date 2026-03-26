<?php
/**
 * Studio block render.
 *
 * @package ExtraChillStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$defaults = array(
	'headline'      => __( 'Extra Chill Studio', 'extrachill-studio' ),
	'description'   => __( 'Publishing tools, social workflows, and AI chat for the Extra Chill team.', 'extrachill-studio' ),
	'deniedMessage' => __( 'Studio is available to Extra Chill team members. Contact Chris if you need access.', 'extrachill-studio' ),
);

$attributes     = isset( $attributes ) && is_array( $attributes ) ? $attributes : array();
$attributes     = wp_parse_args( $attributes, $defaults );
$headline       = $attributes['headline'] ?? $defaults['headline'];
$description    = $attributes['description'] ?? $defaults['description'];
$denied_message = $attributes['deniedMessage'] ?? $defaults['deniedMessage'];

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'ec-studio-block',
	)
);

if ( ! function_exists( 'ec_is_team_member' ) ) :
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped HTML attributes. ?> >
		<div class="ec-block-shell ec-studio-card ec-studio-card--dependency-missing notice notice-error">
			<p><?php esc_html_e( 'Extra Chill Studio requires the Extra Chill Users plugin to load team permissions.', 'extrachill-studio' ); ?></p>
		</div>
	</div>
	<?php
	return;
endif;

if ( ! is_user_logged_in() ) {
	return;
}

if ( ! current_user_can( 'manage_options' ) && ! ec_is_team_member() ) :
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped HTML attributes. ?> >
		<div class="ec-block-shell ec-studio-card ec-studio-card--denied notice notice-info">
			<?php if ( $headline ) : ?>
				<h2 class="ec-studio-card__title"><?php echo esc_html( $headline ); ?></h2>
			<?php endif; ?>

			<p class="ec-studio-card__description"><?php echo esc_html( $denied_message ); ?></p>
		</div>
	</div>
	<?php
	return;
endif;

$studio_user = wp_get_current_user();
$site_name   = get_bloginfo( 'name' );
$site_url    = home_url( '/' );
$rest_nonce  = wp_create_nonce( 'wp_rest' );
$socials_api = rest_url( 'datamachine-socials/v1/' );

?>

<div
	<?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped HTML attributes. ?>
	data-ec-studio-root
	data-user-name="<?php echo esc_attr( $studio_user->display_name ); ?>"
	data-site-name="<?php echo esc_attr( $site_name ); ?>"
	data-site-url="<?php echo esc_url( $site_url ); ?>"
	data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
	data-socials-api-base="<?php echo esc_url( $socials_api ); ?>"
	data-headline="<?php echo esc_attr( $headline ); ?>"
	data-description="<?php echo esc_attr( $description ); ?>"
>
	<div class="ec-studio-app__mount" data-ec-studio-app></div>
</div>

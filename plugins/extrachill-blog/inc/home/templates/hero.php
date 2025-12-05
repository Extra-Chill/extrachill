<?php
/**
 * Homepage Hero Section
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

$username = '';
if ( is_user_logged_in() ) {
	$user     = wp_get_current_user();
	$username = $user->user_nicename;
}
?>
<div class="full-width-breakout">
<section id="hero-section">
	<h2>
		<?php
		if ( $username ) {
			printf(
				esc_html__( 'Welcome back, %s', 'extrachill-blog' ),
				esc_html( $username )
			);
		} else {
			esc_html_e( 'Join the Online Music Scene', 'extrachill-blog' );
		}
		?>
	</h2>

	<h3>
		<?php
		echo $username
			? esc_html__( 'Thanks for being part of the scene', 'extrachill-blog' )
			: esc_html__( 'A melting pot for independent music', 'extrachill-blog' );
		?>
	</h3>

	<div class="hero-buttons-container">
		<a href="<?php echo esc_url( 'https://community.extrachill.com' ); ?>"
			class="button-2 button-medium">
			<?php esc_html_e( 'Community', 'extrachill-blog' ); ?>
		</a>

		<a href="<?php echo esc_url( 'https://artist.extrachill.com' ); ?>"
			class="button-3 button-medium">
			<?php esc_html_e( 'Artist Platform', 'extrachill-blog' ); ?>
		</a>

		<a href="<?php echo esc_url( 'https://events.extrachill.com' ); ?>"
			class="button-1 button-medium">
			<?php esc_html_e( 'Events Calendar', 'extrachill-blog' ); ?>
		</a>
	</div>
</section>
</div>

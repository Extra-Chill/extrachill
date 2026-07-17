<?php
/**
 * Shared inline artist subscription form.
 *
 * Assumes $artist_id is set by the template renderer.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

$current_artist_id = apply_filters( 'ec_get_artist_id', isset( $artist_id ) ? compact( 'artist_id' ) : array() );

if ( empty( $current_artist_id ) ) {
	return;
}

$data              = isset( $data ) && is_array( $data ) ? $data : array();
$artist_name       = isset( $artist_name ) ? $artist_name : ( $data['display_title'] ?? '' );
$context           = isset( $context ) && 'profile' === $context ? 'profile' : 'link-page';
$form_id           = 'extrch-subscribe-form-' . $context . '-' . absint( $current_artist_id );
$email_id          = 'subscriber-email-' . $context . '-' . absint( $current_artist_id );
$description_id    = $form_id . '-description';
$message_id        = $form_id . '-message';
$subscribe_api_url = isset( $subscribe_api_url ) ? $subscribe_api_url : '';

if ( ! empty( $data['_link_page_subscribe_description'] ) ) {
	$subscribe_description = $data['_link_page_subscribe_description'];
} else {
	$subscribe_description = sprintf(
		/* translators: %s: artist name. */
		__( 'Enter your email address to receive occasional news and updates from %s.', 'extrachill-artist-platform' ),
		$artist_name
	);
}

if ( empty( $artist_name ) ) {
	$subscribe_heading = __( 'Subscribe', 'extrachill-artist-platform' );
} else {
	/* translators: %s: artist name. */
	$subscribe_heading = sprintf( __( 'Subscribe to %s', 'extrachill-artist-platform' ), $artist_name );
}
?>

<section
	class="extrch-subscribe-inline-form-container extrch-<?php echo esc_attr( $context ); ?>-subscribe-inline-form-container"
	aria-labelledby="<?php echo esc_attr( $form_id ); ?>-heading"
>
	<h3 id="<?php echo esc_attr( $form_id ); ?>-heading" class="extrch-subscribe-header">
		<?php echo esc_html( $subscribe_heading ); ?>
	</h3>
	<p id="<?php echo esc_attr( $description_id ); ?>"><?php echo esc_html( $subscribe_description ); ?></p>

	<form
		id="<?php echo esc_attr( $form_id ); ?>"
		class="extrch-subscribe-form"
		data-subscribe-api-url="<?php echo esc_url( $subscribe_api_url ); ?>"
		aria-describedby="<?php echo esc_attr( $description_id ); ?>"
	>
		<div class="form-group">
			<label class="screen-reader-text" for="<?php echo esc_attr( $email_id ); ?>">
				<?php esc_html_e( 'Email Address', 'extrachill-artist-platform' ); ?>
			</label>
			<input
				type="email"
				name="subscriber_email"
				id="<?php echo esc_attr( $email_id ); ?>"
				placeholder="<?php esc_attr_e( 'Your email address', 'extrachill-artist-platform' ); ?>"
				required
				autocomplete="email"
				aria-describedby="<?php echo esc_attr( $description_id ); ?>"
			>
		</div>

		<button type="submit" class="button-1 button-medium">
			<?php esc_html_e( 'Subscribe', 'extrachill-artist-platform' ); ?>
		</button>

		<div
			id="<?php echo esc_attr( $message_id ); ?>"
			class="extrch-form-message"
			role="status"
			aria-live="polite"
		></div>
	</form>
</section>

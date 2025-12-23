<?php
/**
 * Artist Platform Tab for User Settings
 *
 * Displays artist platform access status or request form.
 * Three states: has access, pending request, or can request.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Artist Platform tab content
 *
 * @param int $user_id Current user ID.
 */
function extrachill_render_artist_access_tab( $user_id ) {
	$has_artist       = get_user_meta( $user_id, 'user_is_artist', true ) === '1';
	$has_professional = get_user_meta( $user_id, 'user_is_professional', true ) === '1';
	$pending_request  = get_user_meta( $user_id, 'artist_access_request', true );

	?>
	<h2><?php esc_html_e( 'Artist Platform', 'extra-chill-community' ); ?></h2>

	<?php if ( $has_artist || $has_professional ) : ?>
		<div class="artist-access-status artist-access-granted">
			<p><strong><?php esc_html_e( 'You have artist platform access!', 'extra-chill-community' ); ?></strong></p>
			<p><?php esc_html_e( 'You can create artist profiles and link pages on extrachill.link.', 'extra-chill-community' ); ?></p>
			<p>
				<a href="<?php echo esc_url( ec_get_site_url( 'artist' ) . '/create-artist/' ); ?>" class="button-1 button-medium">
					<?php esc_html_e( 'Create Artist Profile', 'extra-chill-community' ); ?>
				</a>
			</p>
		</div>

	<?php elseif ( ! empty( $pending_request ) && is_array( $pending_request ) ) : ?>
		<?php
		$request_date = isset( $pending_request['requested_at'] )
			? wp_date( get_option( 'date_format' ), $pending_request['requested_at'] )
			: __( 'Unknown', 'extra-chill-community' );
		$request_type = isset( $pending_request['type'] ) && $pending_request['type'] === 'artist'
			? __( 'I am a musician', 'extra-chill-community' )
			: __( 'I work in the music industry', 'extra-chill-community' );
		?>
		<div class="artist-access-status artist-access-pending">
			<p><strong><?php esc_html_e( 'Your request is pending admin review.', 'extra-chill-community' ); ?></strong></p>
			<p>
				<?php
				printf(
					/* translators: 1: request type, 2: request date */
					esc_html__( 'You requested access as "%1$s" on %2$s.', 'extra-chill-community' ),
					esc_html( $request_type ),
					esc_html( $request_date )
				);
				?>
			</p>
			<p><?php esc_html_e( 'An administrator will review your request shortly.', 'extra-chill-community' ); ?></p>
		</div>

	<?php else : ?>
		<div class="artist-access-request-form">
			<p><?php esc_html_e( 'Get access to create artist profiles and link pages on extrachill.link.', 'extra-chill-community' ); ?></p>

			<fieldset class="artist-access-options">
				<legend><?php esc_html_e( 'Select which best describes you:', 'extra-chill-community' ); ?></legend>
				<p>
					<label>
						<input type="radio" name="artist_access_type" value="artist" required>
						<?php esc_html_e( 'I am a musician', 'extra-chill-community' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="artist_access_type" value="professional">
						<?php esc_html_e( 'I work in the music industry', 'extra-chill-community' ); ?>
					</label>
				</p>
			</fieldset>

			<p>
				<button type="submit" name="request_artist_access" value="1" class="button-1 button-medium">
					<?php esc_html_e( 'Request Access', 'extra-chill-community' ); ?>
				</button>
			</p>
		</div>
	<?php endif; ?>
	<?php
}

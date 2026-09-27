<?php
/**
 * /manage-link-page/ as the one stable "my Link Page" entry point.
 *
 * Every "Manage / Create Link Page" link on the network can point here:
 * - a musician with a published Link Page is sent to its editor (the
 *   extrachill.link /edit shell after the cutover);
 * - a musician whose artist has no Link Page yet gets a "Create my Link
 *   Page" action instead of a dead end (Extra-Chill/extrachill-artist-platform#243, #245).
 *
 * Provisioning stays mutation-only: it runs on an explicit, nonce-checked
 * POST through the extrachill/create-artist-link-page ability.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/** Send owners with a Link Page on to their editor. */
function ec_manage_link_page_route_to_editor() {
	if ( ! is_user_logged_in() || ! is_page( 'manage-link-page' ) ) {
		return;
	}
	$url = ec_get_user_link_page_manage_url( get_current_user_id() );
	if ( '' === $url || false !== strpos( $url, '/manage-link-page/' ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- network editor host resolved from site options.
	wp_redirect( $url );
	exit;
}
add_action( 'template_redirect', 'ec_manage_link_page_route_to_editor', 20 );

/**
 * Artists the current user manages that have no published Link Page.
 *
 * @return array<int,WP_Post>
 */
function ec_manage_link_page_artists_without_page() {
	if ( ! is_user_logged_in() || ! function_exists( 'ec_get_artists_for_user' ) ) {
		return array();
	}
	$missing = array();
	foreach ( (array) ec_get_artists_for_user( get_current_user_id() ) as $artist_id ) {
		$artist = get_post( (int) $artist_id );
		if ( $artist && 'publish' === $artist->post_status && ! ec_artist_link_page_is_published( (int) ec_get_link_page_for_artist( (int) $artist_id ) ) ) {
			$missing[] = $artist;
		}
	}
	return $missing;
}

/**
 * Replace the editor mount with a create action when there is no page yet.
 *
 * @param string $content Rendered block.
 * @param array  $block   Parsed block.
 * @return string
 */
function ec_manage_link_page_create_prompt( $content, $block ) {
	if ( 'extrachill/link-page-editor' !== ( $block['blockName'] ?? '' ) || ! is_user_logged_in() ) {
		return $content;
	}
	$has_artists = function_exists( 'ec_get_artists_for_user' ) && ec_get_artists_for_user( get_current_user_id() );
	if ( ! $has_artists ) {
		$can_create = function_exists( 'ec_can_create_artist_profiles' ) && ec_can_create_artist_profiles( get_current_user_id() );
		return '<div class="notice notice-info"><p>'
			. esc_html__( 'Create an artist profile to get your free Link Page.', 'extrachill-artist-platform' )
			. '</p>'
			. ( $can_create ? '<a class="button-1 button-medium" href="' . esc_url( home_url( '/create-artist/' ) ) . '">' . esc_html__( 'Create Artist Profile', 'extrachill-artist-platform' ) . '</a>' : '' )
			. '</div>';
	}
	$missing = ec_manage_link_page_artists_without_page();
	if ( ! $missing ) {
		return $content;
	}
	ob_start();
	?>
	<div class="notice notice-info" data-link-page-setup-state="create">
		<p><?php esc_html_e( 'Your artist doesn\'t have a Link Page yet. Create it now — you can customize everything after.', 'extrachill-artist-platform' ); ?></p>
		<?php foreach ( $missing as $artist ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ec_create_artist_link_page">
				<input type="hidden" name="artist_id" value="<?php echo esc_attr( (string) $artist->ID ); ?>">
				<?php wp_nonce_field( 'ec_create_artist_link_page_' . $artist->ID ); ?>
				<button type="submit" class="button-1 button-medium">
					<?php
					/* translators: %s: artist name. */
					echo esc_html( sprintf( __( 'Create Link Page for %s', 'extrachill-artist-platform' ), $artist->post_title ) );
					?>
				</button>
			</form>
		<?php endforeach; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_filter( 'render_block', 'ec_manage_link_page_create_prompt', 20, 2 );

/** Handle the create action: a thin transport over the ability. */
function ec_manage_link_page_handle_create() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below against the artist ID.
	$artist_id = isset( $_POST['artist_id'] ) ? absint( $_POST['artist_id'] ) : 0;
	check_admin_referer( 'ec_create_artist_link_page_' . $artist_id );
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/create-artist-link-page' ) : null;
	$result  = $ability ? $ability->execute( array( 'artist_id' => $artist_id ) ) : new WP_Error( 'ability_missing', 'Link Page creation is unavailable.' );
	if ( is_wp_error( $result ) ) {
		if ( function_exists( 'extrachill_set_notice' ) ) {
			extrachill_set_notice( __( 'We couldn\'t create your Link Page. Please try again.', 'extrachill-artist-platform' ), 'error' );
		}
		wp_safe_redirect( home_url( '/manage-link-page/' ) );
		exit;
	}
	$target = ! empty( $result['edit_url'] ) ? (string) $result['edit_url'] : home_url( '/manage-link-page/' );
	// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- network editor host resolved from site options.
	wp_redirect( $target );
	exit;
}
add_action( 'admin_post_ec_create_artist_link_page', 'ec_manage_link_page_handle_create' );

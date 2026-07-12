<?php
/**
 * Focused network administration for artist access requests.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'extrachill_users_add_artist_access_menu' );
add_action( 'network_admin_edit_extrachill_artist_access', 'extrachill_users_handle_artist_access_action' );

/**
 * Add the owner-native artist access page.
 */
function extrachill_users_add_artist_access_menu() {
	$parent = defined( 'EXTRACHILL_NETWORK_MENU_SLUG' ) ? EXTRACHILL_NETWORK_MENU_SLUG : 'settings.php';

	add_submenu_page(
		$parent,
		__( 'Artist Access', 'extrachill-users' ),
		__( 'Artist Access', 'extrachill-users' ),
		'manage_network_options',
		'extrachill-artist-access',
		'extrachill_users_render_artist_access_page'
	);
}

/**
 * Process an approval or rejection from the network-admin page.
 */
function extrachill_users_handle_artist_access_action() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage artist access.', 'extrachill-users' ) );
	}

	check_admin_referer( 'extrachill_artist_access_action' );

	$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
	$action  = isset( $_POST['request_action'] ) ? sanitize_key( wp_unslash( $_POST['request_action'] ) ) : '';
	$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
	if ( ! in_array( $action, array( 'approve', 'reject' ), true ) ) {
		wp_die( esc_html__( 'Invalid artist access action.', 'extrachill-users' ) );
	}

	$ability = wp_get_ability( 'approve' === $action ? 'extrachill/approve-artist-access' : 'extrachill/reject-artist-access' );
	$result  = $ability ? $ability->execute(
		'approve' === $action ? array(
			'user_id' => $user_id,
			'type'    => $type,
		) : array( 'user_id' => $user_id )
	) : new WP_Error( 'ability_not_found', 'Artist access ability is unavailable.' );

	$redirect = add_query_arg(
		array(
			'page'   => 'extrachill-artist-access',
			'status' => is_wp_error( $result ) ? 'error' : 'updated',
		),
		network_admin_url( 'admin.php' )
	);
	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Render pending artist access requests.
 */
function extrachill_users_render_artist_access_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage artist access.', 'extrachill-users' ) );
	}

	$ability  = wp_get_ability( 'extrachill/list-artist-access-requests' );
	$result   = $ability ? $ability->execute( array() ) : array( 'requests' => array() );
	$requests = ! is_wp_error( $result ) && isset( $result['requests'] ) ? $result['requests'] : array();
	$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect flag.
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Artist Access Requests', 'extrachill-users' ); ?></h1>
		<?php if ( $status ) : ?>
			<div class="notice notice-<?php echo 'updated' === $status ? 'success' : 'error'; ?> is-dismissible"><p><?php esc_html_e( 'Artist access request processed.', 'extrachill-users' ); ?></p></div>
		<?php endif; ?>
		<?php if ( empty( $requests ) ) : ?>
			<p><?php esc_html_e( 'No pending artist access requests.', 'extrachill-users' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'User', 'extrachill-users' ); ?></th><th><?php esc_html_e( 'Email', 'extrachill-users' ); ?></th><th><?php esc_html_e( 'Type', 'extrachill-users' ); ?></th><th><?php esc_html_e( 'Actions', 'extrachill-users' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $requests as $request ) : ?>
					<tr>
						<td><?php echo esc_html( $request['user_login'] ); ?></td>
						<td><?php echo esc_html( $request['user_email'] ); ?></td>
						<td><?php echo esc_html( $request['type'] ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=extrachill_artist_access' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( 'extrachill_artist_access_action' ); ?>
								<input type="hidden" name="user_id" value="<?php echo esc_attr( $request['user_id'] ); ?>">
								<input type="hidden" name="type" value="<?php echo esc_attr( $request['type'] ); ?>">
								<button class="button button-primary" name="request_action" value="approve"><?php esc_html_e( 'Approve', 'extrachill-users' ); ?></button>
								<button class="button" name="request_action" value="reject"><?php esc_html_e( 'Reject', 'extrachill-users' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

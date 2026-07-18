<?php
/**
 * Focused network administration for Artist Dispatch access.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'ec_users_add_artist_dispatch_admin_menu' );
add_action( 'network_admin_edit_extrachill_artist_dispatch', 'ec_users_handle_artist_dispatch_admin_action' );

/**
 * Register the network-admin queue and policy screen.
 */
function ec_users_add_artist_dispatch_admin_menu() {
	$parent = defined( 'EXTRACHILL_NETWORK_MENU_SLUG' ) ? EXTRACHILL_NETWORK_MENU_SLUG : 'settings.php';
	add_submenu_page(
		$parent,
		__( 'Artist Dispatch', 'extrachill-users' ),
		__( 'Artist Dispatch', 'extrachill-users' ),
		'manage_network_options',
		'extrachill-artist-dispatch',
		'ec_users_render_artist_dispatch_admin_page'
	);
}

/**
 * Process policy and lifecycle actions through their canonical service/ability.
 */
function ec_users_handle_artist_dispatch_admin_action() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You cannot manage Artist Dispatch access.', 'extrachill-users' ) );
	}
	check_admin_referer( 'extrachill_artist_dispatch_action' );

	$action = isset( $_POST['dispatch_action'] ) ? sanitize_key( wp_unslash( $_POST['dispatch_action'] ) ) : '';
	$result = true;
	if ( 'save_policy' === $action ) {
		$minimum_points = isset( $_POST['minimum_points'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['minimum_points'] ) ) ) : '';
		ec_users_update_artist_dispatch_policy(
			array(
				'pilot_enabled'            => isset( $_POST['pilot_enabled'] ),
				'minimum_points'           => $minimum_points,
				'minimum_account_age_days' => isset( $_POST['minimum_account_age_days'] ) ? absint( $_POST['minimum_account_age_days'] ) : 0,
			)
		);
	} elseif ( in_array( $action, array( 'approve', 'reject', 'revoke' ), true ) ) {
		$ability = wp_get_ability( 'extrachill/' . $action . '-artist-dispatch-access' );
		$input   = array(
			'user_id'    => isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0,
			'request_id' => isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '',
		);
		if ( 'approve' === $action ) {
			$input['note'] = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		} else {
			$input['reason'] = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		}
		$result = $ability ? $ability->execute( $input ) : new WP_Error( 'ability_not_found', __( 'Artist Dispatch ability is unavailable.', 'extrachill-users' ) );
	} else {
		$result = new WP_Error( 'invalid_artist_dispatch_action', __( 'Invalid Artist Dispatch action.', 'extrachill-users' ) );
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'extrachill-artist-dispatch',
				'status' => is_wp_error( $result ) ? 'error' : 'updated',
			),
			network_admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Render the policy, pending queue, and approved grants.
 */
function ec_users_render_artist_dispatch_admin_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You cannot manage Artist Dispatch access.', 'extrachill-users' ) );
	}

	$policy   = ec_users_get_artist_dispatch_policy();
	$ability  = wp_get_ability( 'extrachill/list-artist-dispatch-access-requests' );
	$result   = $ability ? $ability->execute( array() ) : new WP_Error( 'ability_not_found', 'Artist Dispatch ability unavailable.' );
	$requests = ! is_wp_error( $result ) && isset( $result['requests'] ) ? $result['requests'] : array();
	$pending  = array_values( array_filter( $requests, static fn( $item ) => 'pending' === ( $item['state']['status'] ?? '' ) ) );
	$approved = array_values( array_filter( $requests, static fn( $item ) => 'approved' === ( $item['state']['status'] ?? '' ) ) );
	$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only redirect flag.
	$action   = network_admin_url( 'edit.php?action=extrachill_artist_dispatch' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Artist Dispatch Access', 'extrachill-users' ); ?></h1>
		<?php if ( $status ) : ?>
			<div class="notice notice-<?php echo 'updated' === $status ? 'success' : 'error'; ?> is-dismissible"><p><?php esc_html_e( 'Artist Dispatch settings or access were processed.', 'extrachill-users' ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Pilot Policy', 'extrachill-users' ); ?></h2>
		<p><?php esc_html_e( 'Requests remain unavailable until a points threshold is set and the pilot is enabled.', 'extrachill-users' ); ?></p>
		<form method="post" action="<?php echo esc_url( $action ); ?>">
			<?php wp_nonce_field( 'extrachill_artist_dispatch_action' ); ?>
			<input type="hidden" name="dispatch_action" value="save_policy">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="minimum_points"><?php esc_html_e( 'Minimum points', 'extrachill-users' ); ?></label></th><td><input id="minimum_points" name="minimum_points" type="number" min="0" step="1" value="<?php echo esc_attr( null === $policy['minimum_points'] ? '' : $policy['minimum_points'] ); ?>"><p class="description"><?php esc_html_e( 'Leave blank to keep requests unconfigured.', 'extrachill-users' ); ?></p></td></tr>
				<tr><th scope="row"><label for="minimum_account_age_days"><?php esc_html_e( 'Minimum account age', 'extrachill-users' ); ?></label></th><td><input id="minimum_account_age_days" name="minimum_account_age_days" type="number" min="0" step="1" value="<?php echo esc_attr( $policy['minimum_account_age_days'] ); ?>"> <?php esc_html_e( 'days', 'extrachill-users' ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Pilot', 'extrachill-users' ); ?></th><td><label><input name="pilot_enabled" type="checkbox" value="1" <?php checked( $policy['pilot_enabled'] ); ?>> <?php esc_html_e( 'Enable eligible access requests', 'extrachill-users' ); ?></label></td></tr>
			</table>
			<?php submit_button( __( 'Save Policy', 'extrachill-users' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Pending Requests', 'extrachill-users' ); ?></h2>
		<?php if ( empty( $pending ) ) : ?>
			<p><?php esc_html_e( 'No pending Artist Dispatch requests.', 'extrachill-users' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Member / Artist', 'extrachill-users' ); ?></th><th><?php esc_html_e( 'Application', 'extrachill-users' ); ?></th><th><?php esc_html_e( 'Checks', 'extrachill-users' ); ?></th><th><?php esc_html_e( 'Decision', 'extrachill-users' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $pending as $item ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $item['display_name'] ? $item['display_name'] : $item['user_login'] ); ?></strong><br><?php echo esc_html( $item['artist_label'] ? $item['artist_label'] : '#' . (int) $item['state']['artist_id'] ); ?><br><small><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $item['state']['requested_at'] ) ); ?> UTC</small></td>
						<td><?php echo nl2br( esc_html( $item['state']['description'] ) ); ?>
						<?php
						if ( ! empty( $item['state']['sample_url'] ) ) :
							?>
							<p><a href="<?php echo esc_url( $item['state']['sample_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Writing sample', 'extrachill-users' ); ?></a></p><?php endif; ?></td>
						<td><?php echo esc_html( $item['eligibility']['eligible'] ? __( 'Eligible now', 'extrachill-users' ) : implode( ' ', $item['eligibility']['reasons'] ) ); ?><br><?php echo esc_html( ! empty( $item['moderation']['active'] ) ? __( 'Moderation: active', 'extrachill-users' ) : __( 'Moderation: blocked', 'extrachill-users' ) ); ?><br><?php echo esc_html( $item['main_membership'] ? __( 'Main-site member', 'extrachill-users' ) : __( 'Not a main-site member', 'extrachill-users' ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( $action ); ?>">
								<?php wp_nonce_field( 'extrachill_artist_dispatch_action' ); ?>
								<input type="hidden" name="user_id" value="<?php echo esc_attr( $item['user_id'] ); ?>"><input type="hidden" name="request_id" value="<?php echo esc_attr( $item['state']['request_id'] ); ?>">
								<textarea name="note" rows="2" placeholder="<?php esc_attr_e( 'Optional approval note', 'extrachill-users' ); ?>"></textarea><br><button class="button button-primary" name="dispatch_action" value="approve"><?php esc_html_e( 'Approve', 'extrachill-users' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( $action ); ?>" style="margin-top:8px">
								<?php wp_nonce_field( 'extrachill_artist_dispatch_action' ); ?>
								<input type="hidden" name="user_id" value="<?php echo esc_attr( $item['user_id'] ); ?>"><input type="hidden" name="request_id" value="<?php echo esc_attr( $item['state']['request_id'] ); ?>">
								<textarea name="reason" rows="2" required placeholder="<?php esc_attr_e( 'Required rejection reason', 'extrachill-users' ); ?>"></textarea><br><button class="button" name="dispatch_action" value="reject"><?php esc_html_e( 'Reject', 'extrachill-users' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Approved Grants', 'extrachill-users' ); ?></h2>
		<?php if ( empty( $approved ) ) : ?>
			<p><?php esc_html_e( 'No approved Artist Dispatch grants.', 'extrachill-users' ); ?></p>
		<?php else : ?>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Member', 'extrachill-users' ); ?></th><th><?php esc_html_e( 'Artist', 'extrachill-users' ); ?></th><th><?php esc_html_e( 'Granted', 'extrachill-users' ); ?></th><th><?php esc_html_e( 'Revoke', 'extrachill-users' ); ?></th></tr></thead><tbody>
			<?php foreach ( $approved as $item ) : ?>
				<tr><td><?php echo esc_html( $item['display_name'] ? $item['display_name'] : $item['user_login'] ); ?></td><td><?php echo esc_html( $item['artist_label'] ? $item['artist_label'] : '#' . (int) $item['state']['artist_id'] ); ?></td><td><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $item['state']['grant']['granted_at'] ) ); ?> UTC</td><td><form method="post" action="<?php echo esc_url( $action ); ?>"><?php wp_nonce_field( 'extrachill_artist_dispatch_action' ); ?><input type="hidden" name="user_id" value="<?php echo esc_attr( $item['user_id'] ); ?>"><input type="hidden" name="request_id" value="<?php echo esc_attr( $item['state']['request_id'] ); ?>"><input name="reason" type="text" required placeholder="<?php esc_attr_e( 'Required reason', 'extrachill-users' ); ?>"> <button class="button" name="dispatch_action" value="revoke"><?php esc_html_e( 'Revoke', 'extrachill-users' ); ?></button></form></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
	</div>
	<?php
}

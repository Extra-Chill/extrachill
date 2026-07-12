<?php
/**
 * Network administration for artist-user relationships.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'ec_register_artist_relationship_admin_page' );
add_action( 'admin_post_ec_artist_relationship', 'ec_handle_artist_relationship_admin_action' );

/** Register the owner-native relationship administration page. */
function ec_register_artist_relationship_admin_page() {
	add_submenu_page(
		'settings.php',
		__( 'Artist Relationships', 'extrachill-artist-platform' ),
		__( 'Artist Relationships', 'extrachill-artist-platform' ),
		'manage_network_options',
		'ec-artist-relationships',
		'ec_render_artist_relationship_admin_page'
	);
}

/** Execute a relationship mutation through its public ability. */
function ec_handle_artist_relationship_admin_action() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage artist relationships.', 'extrachill-artist-platform' ) );
	}
	check_admin_referer( 'ec_artist_relationship' );

	$action = isset( $_POST['relationship_action'] ) ? sanitize_key( wp_unslash( $_POST['relationship_action'] ) ) : '';
	$names  = array(
		'link'    => 'extrachill/admin-link-artist-relationship',
		'unlink'  => 'extrachill/admin-unlink-artist-relationship',
		'cleanup' => 'extrachill/admin-cleanup-artist-relationships',
	);
	$result = new WP_Error( 'invalid_action', __( 'Invalid relationship action.', 'extrachill-artist-platform' ) );
	if ( isset( $names[ $action ] ) ) {
		$ability = wp_get_ability( $names[ $action ] );
		$result  = $ability ? $ability->execute(
			array(
				'user_id'   => isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0,
				'artist_id' => isset( $_POST['artist_id'] ) ? absint( $_POST['artist_id'] ) : 0,
			)
		) : new WP_Error( 'ability_not_found', __( 'Relationship ability is unavailable.', 'extrachill-artist-platform' ) );
	}

	$url = add_query_arg(
		array(
			'page'   => 'ec-artist-relationships',
			'view'   => isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'artists',
			'notice' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Relationship updated.', 'extrachill-artist-platform' ),
			'type'   => is_wp_error( $result ) ? 'error' : 'success',
		),
		network_admin_url( 'settings.php' )
	);
	wp_safe_redirect( $url );
	exit;
}

/** Render the relationship list, link form, and orphan cleanup workflow. */
function ec_render_artist_relationship_admin_page() {
	$requested_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'artists';
	$view   = in_array( $requested_view, array( 'artists', 'users', 'orphans' ), true ) ? $requested_view : 'artists';
	$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
	$name   = 'orphans' === $view ? 'extrachill/admin-list-orphan-artist-relationships' : 'extrachill/admin-list-artist-relationships';
	$input  = 'orphans' === $view ? array() : array( 'view' => $view, 'search' => $search );
	$ability = wp_get_ability( $name );
	$result  = $ability ? $ability->execute( $input ) : new WP_Error( 'ability_not_found', __( 'Relationship ability is unavailable.', 'extrachill-artist-platform' ) );
	$notice_type = isset( $_GET['type'] ) && 'error' === sanitize_key( wp_unslash( $_GET['type'] ) ) ? 'error' : 'success';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Artist Relationships', 'extrachill-artist-platform' ); ?></h1>
		<?php if ( isset( $_GET['notice'] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible"><p><?php echo esc_html( wp_unslash( $_GET['notice'] ) ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Manage bidirectional links between network users and artist profiles.', 'extrachill-artist-platform' ); ?></p>
		<nav class="nav-tab-wrapper">
			<?php foreach ( array( 'artists' => __( 'Artists', 'extrachill-artist-platform' ), 'users' => __( 'Users', 'extrachill-artist-platform' ), 'orphans' => __( 'Orphans', 'extrachill-artist-platform' ) ) as $key => $label ) : ?>
				<a class="nav-tab <?php echo $view === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ec-artist-relationships', 'view' => $key ), network_admin_url( 'settings.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( 'orphans' !== $view ) : ?>
			<form method="get" style="margin: 16px 0">
				<input type="hidden" name="page" value="ec-artist-relationships"><input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
				<label class="screen-reader-text" for="relationship-search"><?php esc_html_e( 'Search relationships', 'extrachill-artist-platform' ); ?></label>
				<input id="relationship-search" type="search" name="search" value="<?php echo esc_attr( $search ); ?>">
				<?php submit_button( __( 'Search', 'extrachill-artist-platform' ), 'secondary', '', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 16px 0">
				<input type="hidden" name="action" value="ec_artist_relationship"><input type="hidden" name="relationship_action" value="link"><input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
				<?php wp_nonce_field( 'ec_artist_relationship' ); ?>
				<label><?php esc_html_e( 'User ID', 'extrachill-artist-platform' ); ?> <input type="number" min="1" name="user_id" required></label>
				<label><?php esc_html_e( 'Artist ID', 'extrachill-artist-platform' ); ?> <input type="number" min="1" name="artist_id" required></label>
				<?php submit_button( __( 'Link User', 'extrachill-artist-platform' ), 'primary', '', false ); ?>
			</form>
		<?php endif; ?>

		<?php if ( is_wp_error( $result ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
		<?php else : ec_render_artist_relationship_admin_table( $view, 'orphans' === $view ? $result['orphans'] : $result['items'] ); endif; ?>
	</div>
	<?php
}

/**
 * Render relationship rows using core table styling.
 *
 * @param string $view  Current view.
 * @param array  $items Relationship rows.
 */
function ec_render_artist_relationship_admin_table( $view, array $items ) {
	?>
	<table class="widefat striped"><thead><tr><th><?php echo 'users' === $view ? esc_html__( 'User', 'extrachill-artist-platform' ) : esc_html__( 'Artist', 'extrachill-artist-platform' ); ?></th><th><?php echo 'orphans' === $view ? esc_html__( 'Invalid Artist ID', 'extrachill-artist-platform' ) : esc_html__( 'Relationships', 'extrachill-artist-platform' ); ?></th></tr></thead><tbody>
	<?php if ( empty( $items ) ) : ?><tr><td colspan="2"><?php esc_html_e( 'No relationships found.', 'extrachill-artist-platform' ); ?></td></tr><?php endif; ?>
	<?php foreach ( $items as $item ) : ?>
		<tr><td><?php echo esc_html( 'artists' === $view ? $item['title'] . ' (#' . $item['id'] . ')' : $item['user']['display_name'] ?? $item['display_name'] ); ?></td><td>
		<?php
		$relationships = 'artists' === $view ? $item['members'] : ( 'users' === $view ? $item['artists'] : array( array( 'ID' => $item['user']['ID'], 'artist_id' => $item['invalid_artist_id'] ) ) );
		foreach ( $relationships as $relationship ) {
			$user_id   = 'artists' === $view ? $relationship['ID'] : ( 'users' === $view ? $item['ID'] : $relationship['ID'] );
			$artist_id = 'artists' === $view ? $item['id'] : ( 'users' === $view ? $relationship['ID'] : $relationship['artist_id'] );
			$label     = 'artists' === $view ? $relationship['display_name'] . ' (' . $relationship['user_login'] . ')' : ( 'users' === $view ? $relationship['post_title'] : '#' . $artist_id );
			echo esc_html( $label ) . ' ';
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 12px 4px 0"><input type="hidden" name="action" value="ec_artist_relationship"><input type="hidden" name="relationship_action" value="<?php echo 'orphans' === $view ? 'cleanup' : 'unlink'; ?>"><input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>"><input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>"><input type="hidden" name="artist_id" value="<?php echo esc_attr( $artist_id ); ?>"><?php wp_nonce_field( 'ec_artist_relationship' ); ?><?php submit_button( 'orphans' === $view ? __( 'Clean Up', 'extrachill-artist-platform' ) : __( 'Remove', 'extrachill-artist-platform' ), 'small', '', false ); ?></form>
			<?php
		}
		?>
		</td></tr>
	<?php endforeach; ?>
	</tbody></table>
	<?php
}

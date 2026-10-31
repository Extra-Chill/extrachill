<?php
/**
 * ExtraChill Network Admin Menu
 *
 * Top-level network admin menu for ExtraChill Platform settings.
 *
 * @package ExtraChill\Network
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EXTRACHILL_NETWORK_MENU_SLUG' ) ) {
	define( 'EXTRACHILL_NETWORK_MENU_SLUG', 'extrachill-network' );
}

add_action( 'network_admin_menu', 'ec_add_network_menu', 5 );

/**
 * Add the top-level "Extra Chill Network" menu and its landing page.
 */
function ec_add_network_menu() {
	add_menu_page(
		'Extra Chill Network',
		'Extra Chill Network',
		'manage_network_options',
		EXTRACHILL_NETWORK_MENU_SLUG,
		'ec_render_network_landing_page',
		'dashicons-admin-multisite',
		3
	);
}

/**
 * Render the landing page for the Extra Chill Network menu.
 *
 * Enumerates the submenu pages registered under EXTRACHILL_NETWORK_MENU_SLUG so
 * the overview stays current as sibling plugins add or move pages.
 *
 * @global array $submenu
 */
function ec_render_network_landing_page() {
	global $submenu;

	$menu_slug = EXTRACHILL_NETWORK_MENU_SLUG;
	$pages     = isset( $submenu[ $menu_slug ] ) ? $submenu[ $menu_slug ] : array();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill Network', 'extrachill-network' ); ?></h1>

		<p class="description">
			<?php esc_html_e( 'Overview of network-wide Extra Chill settings and integrations.', 'extrachill-network' ); ?>
		</p>

		<div class="extrachill-network-cards" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 20px;">
			<?php foreach ( $pages as $page ) : ?>
				<?php
				// Skip the auto-injected parent link.
				if ( $page[2] === $menu_slug ) {
					continue;
				}

				$page_url = network_admin_url( 'admin.php?page=' . esc_attr( $page[2] ) );
				?>
				<div class="card">
					<h2>
						<a href="<?php echo esc_url( $page_url ); ?>">
							<?php echo esc_html( $page[0] ); ?>
						</a>
					</h2>
					<?php if ( ! empty( $page[3] ) && $page[3] !== $page[0] ) : ?>
						<p class="description">
							<?php echo esc_html( $page[3] ); ?>
						</p>
					<?php endif; ?>
					<p>
						<a class="button" href="<?php echo esc_url( $page_url ); ?>">
							<?php
							/* translators: %s: submenu page title */
							echo esc_html( sprintf( __( 'Open %s', 'extrachill-network' ), $page[0] ) );
							?>
						</a>
					</p>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<style>
		.extrachill-network-cards .card {
			margin: 0;
		}
		.extrachill-network-cards .card h2 {
			margin-top: 0;
		}
	</style>
	<?php
}

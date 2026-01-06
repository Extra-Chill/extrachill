<?php
/**
 * Network Admin Settings Page
 *
 * Tabbed interface with Audit and Config tabs under the Extra Chill Multisite menu.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Admin;

use function ExtraChill\SEO\Core\ec_seo_get_default_og_image_id;
use function ExtraChill\SEO\Core\ec_seo_get_indexnow_key;
use function ExtraChill\SEO\Core\ec_seo_set_default_og_image_id;
use function ExtraChill\SEO\Core\ec_seo_set_indexnow_key;
use function ExtraChill\SEO\Audit\ec_seo_get_audit_results;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'network_admin_menu', __NAMESPACE__ . '\\ec_seo_add_network_menu' );
add_action( 'network_admin_edit_extrachill_seo_settings', __NAMESPACE__ . '\\ec_seo_handle_settings_save' );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\ec_seo_enqueue_network_assets' );

/**
 * Adds SEO submenu under Extra Chill Multisite menu.
 */
function ec_seo_add_network_menu() {
	if ( ! defined( 'EXTRACHILL_MULTISITE_MENU_SLUG' ) ) {
		return;
	}

	add_submenu_page(
		EXTRACHILL_MULTISITE_MENU_SLUG,
		'SEO',
		'SEO',
		'manage_network_options',
		'extrachill-seo',
		__NAMESPACE__ . '\\ec_seo_render_settings_page'
	);
}

/**
 * Enqueues admin assets for the SEO settings page.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function ec_seo_enqueue_network_assets( $hook_suffix ) {
	if ( ! is_network_admin() ) {
		return;
	}

	if ( 'extra-chill-multisite_page_extrachill-seo' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_media();

	wp_enqueue_style(
		'extrachill-seo-admin',
		EXTRACHILL_SEO_URL . 'inc/admin/admin-styles.css',
		array(),
		filemtime( EXTRACHILL_SEO_PATH . 'inc/admin/admin-styles.css' )
	);

	wp_enqueue_script(
		'extrachill-seo-media-picker',
		EXTRACHILL_SEO_URL . 'inc/admin/media-picker.js',
		array(),
		filemtime( EXTRACHILL_SEO_PATH . 'inc/admin/media-picker.js' ),
		true
	);

	wp_enqueue_script(
		'extrachill-seo-admin',
		EXTRACHILL_SEO_URL . 'inc/admin/admin-scripts.js',
		array(),
		filemtime( EXTRACHILL_SEO_PATH . 'inc/admin/admin-scripts.js' ),
		true
	);

	wp_localize_script(
		'extrachill-seo-admin',
		'ecSeoAdmin',
		array(
			'restUrl' => rest_url( 'extrachill/v1/' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		)
	);
}

/**
 * Handles config settings form submission.
 */
function ec_seo_handle_settings_save() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'extrachill-seo' ) );
	}

	check_admin_referer( 'extrachill_seo_settings', 'extrachill_seo_nonce' );

	$default_og_image_id = isset( $_POST['extrachill_seo_default_og_image_id'] ) ? (int) $_POST['extrachill_seo_default_og_image_id'] : 0;
	$indexnow_key        = isset( $_POST['extrachill_seo_indexnow_key'] ) ? sanitize_text_field( wp_unslash( $_POST['extrachill_seo_indexnow_key'] ) ) : '';

	ec_seo_set_default_og_image_id( $default_og_image_id );
	ec_seo_set_indexnow_key( $indexnow_key );

	$redirect_url = add_query_arg(
		array(
			'page'    => 'extrachill-seo',
			'tab'     => 'config',
			'updated' => 'true',
		),
		network_admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Renders the main settings page with tabs.
 */
function ec_seo_render_settings_page() {
	$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'audit';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill SEO', 'extrachill-seo' ); ?></h1>

		<nav class="nav-tab-wrapper">
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'audit', remove_query_arg( 'updated' ) ) ); ?>" 
			   class="nav-tab <?php echo 'audit' === $current_tab ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Audit', 'extrachill-seo' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'config', remove_query_arg( 'updated' ) ) ); ?>" 
			   class="nav-tab <?php echo 'config' === $current_tab ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Config', 'extrachill-seo' ); ?>
			</a>
		</nav>

		<div class="extrachill-seo-tab-content">
			<?php if ( 'audit' === $current_tab ) : ?>
				<?php ec_seo_render_audit_tab(); ?>
			<?php else : ?>
				<?php ec_seo_render_config_tab(); ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * Renders the Audit tab content.
 */
function ec_seo_render_audit_tab() {
	$audit_data = function_exists( 'ExtraChill\\SEO\\Audit\\ec_seo_get_audit_results' )
		? ec_seo_get_audit_results()
		: array(
			'status'    => 'none',
			'timestamp' => 0,
			'results'   => array(),
		);

	$has_results   = 'none' !== $audit_data['status'];
	$is_in_progress = 'in_progress' === $audit_data['status'];
	?>
	<div class="extrachill-seo-audit">
		<h2><?php esc_html_e( 'SEO Health Dashboard', 'extrachill-seo' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Audit your network for SEO issues including missing meta descriptions, alt text, and broken links.', 'extrachill-seo' ); ?>
		</p>

		<div class="extrachill-seo-audit-actions">
			<button type="button" id="ec-seo-full-audit" class="button button-primary">
				<?php esc_html_e( 'Run Full Audit', 'extrachill-seo' ); ?>
			</button>
			<button type="button" id="ec-seo-batch-audit" class="button">
				<?php esc_html_e( 'Run Batch Audit', 'extrachill-seo' ); ?>
			</button>
			<button type="button" id="ec-seo-continue-audit" class="button" style="<?php echo $is_in_progress ? '' : 'display:none;'; ?>">
				<?php esc_html_e( 'Continue Audit', 'extrachill-seo' ); ?>
			</button>
			<span id="ec-seo-status-text" class="extrachill-seo-audit-status"></span>
		</div>

		<div id="ec-seo-progress" class="extrachill-seo-progress" style="display:none;">
			<div id="ec-seo-progress-text" class="extrachill-seo-progress-text"></div>
			<div class="extrachill-seo-progress-bar">
				<div id="ec-seo-progress-bar-fill" class="extrachill-seo-progress-bar-fill" style="width:0%;"></div>
			</div>
		</div>

		<?php if ( $has_results ) : ?>
			<div id="ec-seo-timestamp" class="extrachill-seo-timestamp">
				<?php
				if ( $audit_data['timestamp'] ) {
					printf(
						/* translators: %s: Date and time of last audit */
						esc_html__( 'Last audit: %s', 'extrachill-seo' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $audit_data['timestamp'] ) )
					);
					if ( $is_in_progress ) {
						echo ' <strong>(' . esc_html__( 'In Progress', 'extrachill-seo' ) . ')</strong>';
					}
				}
				?>
			</div>

			<div id="ec-seo-cards" class="extrachill-seo-cards">
				<?php ec_seo_render_dashboard_cards( $audit_data['results'] ); ?>
			</div>
		<?php else : ?>
			<div id="ec-seo-timestamp" class="extrachill-seo-timestamp" style="display:none;"></div>
			<div id="ec-seo-empty" class="extrachill-seo-empty">
				<p><?php esc_html_e( 'No audit data available. Run an audit to see SEO health metrics.', 'extrachill-seo' ); ?></p>
			</div>
			<div id="ec-seo-cards" class="extrachill-seo-cards" style="display:none;"></div>
		<?php endif; ?>

		<div id="ec-seo-details" class="extrachill-seo-details" style="display:none;">
			<div class="extrachill-seo-details-header">
				<h3 id="ec-seo-details-title"></h3>
				<button type="button" id="ec-seo-export" class="button">
					<?php esc_html_e( 'Export JSON', 'extrachill-seo' ); ?>
				</button>
			</div>
			<div id="ec-seo-details-loading" class="extrachill-seo-details-loading" style="display:none;">
				<?php esc_html_e( 'Loading...', 'extrachill-seo' ); ?>
			</div>
			<table id="ec-seo-details-table" class="widefat striped">
				<thead id="ec-seo-details-thead"></thead>
				<tbody id="ec-seo-details-tbody"></tbody>
			</table>
			<div id="ec-seo-details-pagination" class="extrachill-seo-details-pagination">
				<button type="button" id="ec-seo-prev" class="button" disabled>
					<?php esc_html_e( 'Previous', 'extrachill-seo' ); ?>
				</button>
				<span id="ec-seo-page-info"></span>
				<button type="button" id="ec-seo-next" class="button">
					<?php esc_html_e( 'Next', 'extrachill-seo' ); ?>
				</button>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Renders dashboard metric cards.
 *
 * @param array $results Audit results array.
 */
function ec_seo_render_dashboard_cards( $results ) {
	$metrics = array(
		'missing_excerpts'       => __( 'Posts Missing Excerpts', 'extrachill-seo' ),
		'missing_alt_text'       => __( 'Images Missing Alt Text', 'extrachill-seo' ),
		'missing_featured'       => __( 'Posts Without Featured Images', 'extrachill-seo' ),
		'broken_images'          => __( 'Broken Images', 'extrachill-seo' ),
		'broken_internal_links'  => __( 'Broken Internal Links', 'extrachill-seo' ),
		'broken_external_links'  => __( 'Broken External Links', 'extrachill-seo' ),
	);

	foreach ( $metrics as $key => $label ) :
		$metric       = $results[ $key ] ?? array(
			'total'   => 0,
			'by_site' => array(),
		);
		$count_class  = ec_seo_get_count_class( $metric['total'] );
		$sites        = $metric['by_site'] ?? array();
		$nonzero_sites = array_filter( $sites, fn( $s ) => $s['count'] > 0 );
		?>
		<div class="extrachill-seo-card" data-category="<?php echo esc_attr( $key ); ?>">
			<div class="extrachill-seo-card-count <?php echo esc_attr( $count_class ); ?>">
				<?php echo esc_html( number_format_i18n( $metric['total'] ) ); ?>
			</div>
			<div class="extrachill-seo-card-label">
				<?php echo esc_html( $label ); ?>
			</div>
			<?php if ( ! empty( $nonzero_sites ) ) : ?>
				<details class="extrachill-seo-card-breakdown">
					<summary><?php esc_html_e( 'Per-site breakdown', 'extrachill-seo' ); ?></summary>
					<ul>
						<?php foreach ( $nonzero_sites as $site ) : ?>
							<li>
								<?php echo esc_html( $site['label'] . ': ' . number_format_i18n( $site['count'] ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
			<?php if ( $metric['total'] > 0 ) : ?>
				<button type="button" class="button button-small ec-seo-view-details" data-category="<?php echo esc_attr( $key ); ?>">
					<?php esc_html_e( 'View Details', 'extrachill-seo' ); ?>
				</button>
			<?php endif; ?>
		</div>
		<?php
	endforeach;
}

/**
 * Gets CSS class for count value styling.
 *
 * @param int $count The count value.
 * @return string CSS class name.
 */
function ec_seo_get_count_class( $count ) {
	if ( 0 === $count ) {
		return 'zero';
	}
	if ( $count > 50 ) {
		return 'error';
	}
	if ( $count > 10 ) {
		return 'warning';
	}
	return '';
}

/**
 * Renders the Config tab content.
 */
function ec_seo_render_config_tab() {
	$default_og_image_id  = ec_seo_get_default_og_image_id();
	$indexnow_key         = ec_seo_get_indexnow_key();
	$default_og_image_url = $default_og_image_id ? wp_get_attachment_image_url( $default_og_image_id, 'thumbnail' ) : '';
	?>
	<div class="extrachill-seo-config">
		<?php if ( isset( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'SEO settings updated.', 'extrachill-seo' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="edit.php?action=extrachill_seo_settings">
			<?php wp_nonce_field( 'extrachill_seo_settings', 'extrachill_seo_nonce' ); ?>

			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="extrachill_seo_default_og_image_id"><?php esc_html_e( 'Default OG Image', 'extrachill-seo' ); ?></label>
						</th>
						<td>
							<div
								class="extrachill-seo-media-picker"
								data-target-input="#extrachill_seo_default_og_image_id"
								data-target-preview="#extrachill-seo-default-og-preview"
								data-target-id="#extrachill-seo-default-og-id"
							>
								<input type="hidden" name="extrachill_seo_default_og_image_id" id="extrachill_seo_default_og_image_id" value="<?php echo esc_attr( $default_og_image_id ); ?>" />
								<button type="button" class="button" data-action="select">
									<?php esc_html_e( 'Select Image', 'extrachill-seo' ); ?>
								</button>
								<button type="button" class="button" data-action="remove" <?php echo $default_og_image_id ? '' : 'disabled'; ?>>
									<?php esc_html_e( 'Remove', 'extrachill-seo' ); ?>
								</button>
								<p class="description">
									<?php esc_html_e( 'Fallback og:image when no featured image exists.', 'extrachill-seo' ); ?>
								</p>
								<p class="description" id="extrachill-seo-default-og-id" <?php echo $default_og_image_id ? '' : 'style="display:none;"'; ?>>
									<?php echo esc_html( sprintf( 'Attachment ID: %d', (int) $default_og_image_id ) ); ?>
								</p>
								<p>
									<img
										id="extrachill-seo-default-og-preview"
										src="<?php echo esc_url( $default_og_image_url ); ?>"
										alt=""
										style="max-width: 150px; height: auto; <?php echo $default_og_image_url ? '' : 'display:none;'; ?>"
									/>
								</p>
							</div>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="extrachill_seo_indexnow_key"><?php esc_html_e( 'IndexNow Key', 'extrachill-seo' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" name="extrachill_seo_indexnow_key" id="extrachill_seo_indexnow_key" value="<?php echo esc_attr( $indexnow_key ); ?>" />
							<p class="description">
								<?php esc_html_e( 'When set, posts will ping IndexNow on publish/unpublish/delete. You must also host /{key}.txt as a static file at the domain root.', 'extrachill-seo' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Settings', 'extrachill-seo' ) ); ?>
		</form>
	</div>
	<?php
}

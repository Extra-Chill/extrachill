<?php
/**
 * Feature Rollout network-admin page.
 *
 * Lets a network admin flip the LIVE rollout tier of each registered feature
 * (admin → team → public) from wp-admin. The feature-rollout primitive itself
 * lives in {@see inc/core/gating.php} (Extra-Chill/extrachill-users#60); this
 * file is ONLY the UI/persistence on top of it — it reuses the existing
 * ceiling/ladder/clamp functions and never reimplements that logic.
 *
 * Layering: the page lives in extrachill-users (next to the primitive it
 * controls) but attaches as a submenu of the network menu via the
 * public slug constant EXTRACHILL_NETWORK_MENU_SLUG. It references only that
 * constant — guarded with defined() — and never any network internals, so it
 * cleanly no-ops if the network menu shell is absent.
 *
 * See Extra-Chill/extrachill-users#66.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'network_admin_menu', 'ec_feature_rollout_add_menu' );

/**
 * Register the "Feature Rollout" submenu under the network menu.
 *
 * Soft dependency: no-ops if the network menu shell (and therefore its slug
 * constant) is absent, so extrachill-users never hard-requires the menu.
 */
function ec_feature_rollout_add_menu() {
	if ( ! defined( 'EXTRACHILL_NETWORK_MENU_SLUG' ) ) {
		return;
	}

	add_submenu_page(
		EXTRACHILL_NETWORK_MENU_SLUG,
		__( 'Feature Rollout', 'extrachill-users' ),
		__( 'Feature Rollout', 'extrachill-users' ),
		'manage_network_options',
		'extrachill-feature-rollout',
		'ec_feature_rollout_render_page'
	);
}

add_action( 'network_admin_edit_extrachill_feature_rollout', 'ec_feature_rollout_handle_save' );

/**
 * Handle the Feature Rollout form submission.
 *
 * Network-admin pages POST to edit.php?action=... which fires
 * network_admin_edit_{action}; we persist there and redirect back with a
 * success flag (mirroring the OAuth/Security network settings pattern).
 *
 * Write-side defense-in-depth: each submitted tier must be a valid rung on the
 * ladder AND not above the feature's code ceiling. The read path already clamps
 * via ec_feature_tier(), but we refuse to even store an out-of-range value.
 */
function ec_feature_rollout_handle_save() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'extrachill-users' ) );
	}

	check_admin_referer( 'ec_feature_rollout_settings', 'ec_feature_rollout_nonce' );

	$submitted = isset( $_POST['ec_feature_tier'] ) && is_array( $_POST['ec_feature_tier'] )
		? wp_unslash( $_POST['ec_feature_tier'] )
		: array();

	$ceilings = ec_feature_ceilings();

	foreach ( $ceilings as $feature => $ceiling ) {
		if ( ! isset( $submitted[ $feature ] ) ) {
			continue;
		}

		$requested = sanitize_text_field( $submitted[ $feature ] );

		// Must be a valid rung on the ladder.
		if ( ! ec_feature_tier_is_valid( $requested ) ) {
			continue;
		}

		// Must not exceed the code ceiling: clamp() returning the request
		// unchanged proves the request is at or below the ceiling.
		$ceiling_for_feature = ec_feature_ceiling( $feature );
		if ( ec_feature_tier_min( $requested, $ceiling_for_feature ) !== $requested ) {
			continue;
		}

		update_site_option( "ec_feature_tier_{$feature}", $requested );
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => 'extrachill-feature-rollout',
			'updated' => 'true',
		),
		network_admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Render the Feature Rollout network-admin page.
 *
 * For each registered feature we show: slug, the read-only code ceiling, a
 * live-tier <select> offering ONLY tiers at or below the ceiling, and the
 * effective (clamped) tier from ec_feature_tier() to prove the clamp.
 */
function ec_feature_rollout_render_page() {
	$ceilings = ec_feature_ceilings();
	$ladder   = ec_feature_tier_ladder();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Feature Rollout', 'extrachill-users' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success indicator after a nonce-protected update. ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Feature rollout tiers updated successfully.', 'extrachill-users' ); ?></p>
			</div>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'Flip each feature\'s live rollout tier between admin, team, and public. The code ceiling is owned by the codebase (changed only via deploy) — the live tier can never exceed it, so the effective tier shown below is always clamped to the ceiling.', 'extrachill-users' ); ?>
		</p>

		<?php if ( empty( $ceilings ) ) : ?>
			<p><?php esc_html_e( 'No features are currently registered.', 'extrachill-users' ); ?></p>
		<?php else : ?>
			<form method="post" action="edit.php?action=extrachill_feature_rollout">
				<?php wp_nonce_field( 'ec_feature_rollout_settings', 'ec_feature_rollout_nonce' ); ?>

				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Feature', 'extrachill-users' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Code Ceiling', 'extrachill-users' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Live Tier', 'extrachill-users' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Effective Tier', 'extrachill-users' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $ceilings as $feature => $ceiling_raw ) : ?>
							<?php
							$ceiling   = ec_feature_ceiling( $feature );
							$effective = ec_feature_tier( $feature );

							// Stored live tier (what the admin previously chose),
							// defaulting to the ceiling when unset/invalid.
							$stored = get_site_option( "ec_feature_tier_{$feature}", $ceiling );
							if ( ! ec_feature_tier_is_valid( $stored ) ) {
								$stored = $ceiling;
							}

							// The <select> reflects the clamp: only offer tiers at
							// or below the ceiling, so an above-ceiling tier can't
							// even be picked.
							$selectable = array();
							foreach ( $ladder as $tier ) {
								if ( ec_feature_tier_min( $tier, $ceiling ) === $tier ) {
									$selectable[] = $tier;
								}
							}

							// Selected value also clamped, defensively.
							$selected = ec_feature_tier_min( $stored, $ceiling );
							?>
							<tr>
								<td><code><?php echo esc_html( $feature ); ?></code></td>
								<td><?php echo esc_html( $ceiling ); ?></td>
								<td>
									<label class="screen-reader-text" for="ec_feature_tier_<?php echo esc_attr( $feature ); ?>">
										<?php
										/* translators: %s: feature slug. */
										echo esc_html( sprintf( __( 'Live tier for %s', 'extrachill-users' ), $feature ) );
										?>
									</label>
									<select
										id="ec_feature_tier_<?php echo esc_attr( $feature ); ?>"
										name="ec_feature_tier[<?php echo esc_attr( $feature ); ?>]">
										<?php foreach ( $selectable as $tier ) : ?>
											<option value="<?php echo esc_attr( $tier ); ?>" <?php selected( $selected, $tier ); ?>>
												<?php echo esc_html( $tier ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><strong><?php echo esc_html( $effective ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Rollout Tiers', 'extrachill-users' ) ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

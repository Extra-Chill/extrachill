<?php
/**
 * ExtraChill Network Commerce Credentials
 *
 * Network admin page for configuring all platform commerce/provider credentials.
 * This is the single WRITE surface for network-wide commerce secrets.
 *
 * Writes flow through the network-layer encrypted auth providers (built on
 * Data Machine's BaseAuthProvider AES-256-GCM envelope):
 *
 *   Stripe (artist marketplace + Connect payouts) — \ExtraChillNetwork\Commerce\Auth\StripeAuthProvider:
 *     - secret_key, connect_client_id, webhook_secret  (encrypted at rest)
 *     - publishable_key                                (plaintext by Stripe design)
 *
 *   Shipping (Shippo label generation) — \ExtraChillNetwork\Commerce\Auth\ShippoAuthProvider:
 *     - api_key                                        (encrypted at rest)
 *
 * These providers live in this (network-active) plugin, so the class_exists()
 * guards below are TRUE in network-admin and the save actually persists. The
 * shop consumes the read-filter contract (`extrachill_stripe_<key>` /
 * `extrachill_shippo_api_key`) registered by inc/commerce/auth/bootstrap.php.
 * Network-admin (super admin) only — these are platform-level secrets.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'ec_add_network_payments_menu' );

/**
 * Add commerce credentials page to network admin menu
 */
function ec_add_network_payments_menu() {
	add_submenu_page(
		EXTRACHILL_NETWORK_MENU_SLUG,
		'Payments Settings',
		'Payments',
		'manage_network_options',
		'extrachill-payments',
		'ec_render_network_payments_page'
	);
}

add_action( 'network_admin_edit_extrachill_payments', 'ec_handle_network_payments_save' );

/**
 * Commerce credential field specification.
 *
 * Single source of truth for the POST field name (ec_commerce_<key>) and the
 * field UI metadata. The `key` matches the Stripe provider field names
 * directly; the shipping field's `key` is the legacy POST name, mapped to the
 * provider `api_key` field on save (see ec_handle_network_payments_save()).
 * Grouped by `section` for rendering.
 *
 * @return array<int, array<string,string>>
 */
function ec_network_commerce_fields() {
	return array(
		// Stripe Connect (artist marketplace + payouts).
		array(
			'section'     => 'stripe',
			'key'         => 'secret_key',
			'label'       => __( 'Secret Key', 'extrachill-network' ),
			'placeholder' => 'sk_live_...',
			'description' => __( 'Your Stripe secret API key. Keep this confidential.', 'extrachill-network' ),
		),
		array(
			'section'     => 'stripe',
			'key'         => 'publishable_key',
			'label'       => __( 'Publishable Key', 'extrachill-network' ),
			'placeholder' => 'pk_live_...',
			'description' => __( 'Stripe publishable API key, used for frontend integration.', 'extrachill-network' ),
		),
		array(
			'section'     => 'stripe',
			'key'         => 'connect_client_id',
			'label'       => __( 'Connect Client ID', 'extrachill-network' ),
			'placeholder' => 'ca_...',
			'description' => __( 'Stripe Connect platform client ID. Enables artist Connect Express onboarding and payouts.', 'extrachill-network' ),
		),
		array(
			'section'     => 'stripe',
			'key'         => 'webhook_secret',
			'label'       => __( 'Webhook Secret', 'extrachill-network' ),
			'placeholder' => 'whsec_...',
			'description' => __( 'Webhook signing secret used to verify Stripe webhook events.', 'extrachill-network' ),
		),
		// Shipping (Shippo label generation).
		array(
			'section'     => 'shipping',
			'key'         => 'shippo_api_key',
			'label'       => __( 'Shippo API Key', 'extrachill-network' ),
			'placeholder' => 'shippo_live_...',
			'description' => __( 'Shippo API token used to generate USPS shipping labels. Keep this confidential.', 'extrachill-network' ),
		),
	);
}

/**
 * Handle commerce credentials form submission.
 *
 * Writes flow through the encrypted network-layer auth providers
 * (StripeAuthProvider / ShippoAuthProvider in ExtraChillNetwork\Commerce\Auth),
 * which store secrets in Data Machine's AES-256-GCM auth envelope. Because the
 * providers live in this network-active plugin, their classes load in
 * network-admin and the class_exists() guards below are TRUE — the save
 * persists. No-wipe-on-blank: each provider save() is a full-config overwrite,
 * so non-blank submitted fields are merged over the current decrypted config
 * before saving — a blank submission leaves every already-set field untouched.
 *
 * Graceful degradation: if Data Machine (and therefore the provider classes)
 * is not active, the submission is skipped rather than falling back to a
 * plaintext write.
 */
function ec_handle_network_payments_save() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'extrachill-network' ) );
	}

	check_admin_referer( 'ec_payments_settings', 'ec_payments_nonce' );

	// Partition non-blank submissions by provider. Each provider save() is a
	// full-config overwrite, so a provider is written at most once per save
	// with only its changed fields merged into the current config.
	$stripe_changes = array();
	$shippo_changes = array();

	foreach ( ec_network_commerce_fields() as $field ) {
		$post_key = 'ec_commerce_' . $field['key'];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer().
		$submitted = isset( $_POST[ $post_key ] )
			? sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) )
			: '';

		if ( '' === $submitted ) {
			continue;
		}

		if ( 'shipping' === $field['section'] ) {
			// The Shippo provider field is `api_key`; the form POST key keeps
			// the legacy `shippo_api_key` name for form stability.
			$shippo_changes['api_key'] = $submitted;
		} else {
			$stripe_changes[ $field['key'] ] = $submitted;
		}
	}

	if ( ! empty( $stripe_changes ) && class_exists( '\ExtraChillNetwork\Commerce\Auth\StripeAuthProvider' ) ) {
		$stripe_provider = new \ExtraChillNetwork\Commerce\Auth\StripeAuthProvider();
		\ExtraChillNetwork\Commerce\Auth\StripeAuthProvider::save(
			array_merge( $stripe_provider->get_config(), $stripe_changes )
		);
	}

	if ( ! empty( $shippo_changes ) && class_exists( '\ExtraChillNetwork\Commerce\Auth\ShippoAuthProvider' ) ) {
		$shippo_provider = new \ExtraChillNetwork\Commerce\Auth\ShippoAuthProvider();
		\ExtraChillNetwork\Commerce\Auth\ShippoAuthProvider::save(
			array_merge( $shippo_provider->get_config(), $shippo_changes )
		);
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => 'extrachill-payments',
			'updated' => 'true',
		),
		network_admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Whether a commerce credential is currently set in the encrypted auth store.
 *
 * Reads from the network-layer providers so the Set / Not set indicator
 * reflects the same encrypted store the write path targets. Returns false when
 * the provider classes are unavailable (Data Machine inactive) — there is no
 * plaintext fallback, by design.
 *
 * @param array<string,string> $field Field spec from ec_network_commerce_fields().
 * @return bool
 */
function ec_network_commerce_field_is_set( array $field ): bool {
	if ( 'shipping' === $field['section'] ) {
		return class_exists( '\ExtraChillNetwork\Commerce\Auth\ShippoAuthProvider' )
			&& '' !== ( new \ExtraChillNetwork\Commerce\Auth\ShippoAuthProvider() )->get_api_key();
	}

	if ( ! class_exists( '\ExtraChillNetwork\Commerce\Auth\StripeAuthProvider' ) ) {
		return false;
	}

	$stripe  = new \ExtraChillNetwork\Commerce\Auth\StripeAuthProvider();
	$getters = array(
		'secret_key'        => 'get_secret_key',
		'publishable_key'   => 'get_publishable_key',
		'connect_client_id' => 'get_connect_client_id',
		'webhook_secret'    => 'get_webhook_secret',
	);

	if ( ! isset( $getters[ $field['key'] ] ) ) {
		return false;
	}

	$getter = $getters[ $field['key'] ];
	return '' !== $stripe->$getter();
}

/**
 * Render network commerce credentials page.
 *
 * Stored credential values are NEVER echoed back into the form. Each field shows
 * only a "Set / Not set" status and renders an empty masked input so the value
 * can be replaced. This prevents secret leakage into the page HTML.
 */
function ec_render_network_payments_page() {
	$fields   = ec_network_commerce_fields();
	$statuses = array();

	foreach ( $fields as $field ) {
		$statuses[ $field['key'] ] = ec_network_commerce_field_is_set( $field );
	}

	$is_configured   = ! empty( $statuses['secret_key'] ) && ! empty( $statuses['publishable_key'] );
	$connect_enabled = ! empty( $statuses['connect_client_id'] );
	$shipping_ready  = ! empty( $statuses['shippo_api_key'] );

	$stripe_fields   = array_filter( $fields, static fn( $f ) => 'stripe' === $f['section'] );
	$shipping_fields = array_filter( $fields, static fn( $f ) => 'shipping' === $f['section'] );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill Commerce Settings', 'extrachill-network' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only success flag. ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Commerce settings updated successfully.', 'extrachill-network' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="edit.php?action=extrachill_payments">
			<?php wp_nonce_field( 'ec_payments_settings', 'ec_payments_nonce' ); ?>

			<table class="form-table">
				<tbody>
					<tr>
						<th colspan="2">
							<h2><?php esc_html_e( 'Stripe Connect', 'extrachill-network' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Stripe credentials for the artist marketplace payment processing and Connect payouts.', 'extrachill-network' ); ?>
								<?php if ( $is_configured ) : ?>
									<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Processing configured', 'extrachill-network' ); ?></span>
								<?php else : ?>
									<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Not configured', 'extrachill-network' ); ?></span>
								<?php endif; ?>
								<?php if ( $connect_enabled ) : ?>
									&nbsp;<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Connect payouts enabled', 'extrachill-network' ); ?></span>
								<?php else : ?>
									&nbsp;<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Connect client ID not set (artist payouts unavailable)', 'extrachill-network' ); ?></span>
								<?php endif; ?>
							</p>
						</th>
					</tr>
					<?php foreach ( $stripe_fields as $field ) : ?>
						<?php ec_render_commerce_field_row( $field, ! empty( $statuses[ $field['key'] ] ) ); ?>
					<?php endforeach; ?>

					<tr>
						<th colspan="2" style="padding-top: 30px;">
							<h2><?php esc_html_e( 'Shipping (Shippo)', 'extrachill-network' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Shippo API token for USPS shipping label generation (artist fulfillment).', 'extrachill-network' ); ?>
								<?php if ( $shipping_ready ) : ?>
									<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Configured', 'extrachill-network' ); ?></span>
								<?php else : ?>
									<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Not configured', 'extrachill-network' ); ?></span>
								<?php endif; ?>
							</p>
						</th>
					</tr>
					<?php foreach ( $shipping_fields as $field ) : ?>
						<?php ec_render_commerce_field_row( $field, ! empty( $statuses[ $field['key'] ] ) ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( 'Stored values are never shown. Leave a field blank to keep its current value; enter a new value to replace it.', 'extrachill-network' ); ?>
			</p>

			<?php submit_button( __( 'Save Commerce Settings', 'extrachill-network' ) ); ?>
		</form>

		<div class="card" style="margin-top: 20px; max-width: 800px;">
			<h3><?php esc_html_e( 'Setup Instructions', 'extrachill-network' ); ?></h3>
			<p><strong><?php esc_html_e( 'Stripe', 'extrachill-network' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Create a Stripe account at stripe.com and enable Stripe Connect in your dashboard.', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Developers > API keys: copy your Secret Key and Publishable Key.', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Developers > Connect: copy your platform Connect Client ID (the "ca_..." identifier).', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Create a webhook endpoint pointing to your site and copy the Webhook Signing Secret.', 'extrachill-network' ); ?></li>
			</ol>
			<p>
				<strong><?php esc_html_e( 'Stripe Webhook URL:', 'extrachill-network' ); ?></strong>
				<code><?php echo esc_url( rest_url( 'extrachill/v1/shop/stripe-webhook' ) ); ?></code>
			</p>
			<p><strong><?php esc_html_e( 'Shippo', 'extrachill-network' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Create a Shippo account at goshippo.com.', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Settings > API: copy your Live API Token.', 'extrachill-network' ); ?></li>
			</ol>
		</div>
	</div>

	<style>
		.card {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			padding: 20px;
			margin: 20px 0;
		}
		.card h3 {
			margin-top: 0;
		}
	</style>
	<?php
}

/**
 * Render a single masked commerce credential field row.
 *
 * Stored values are never output; the input is always empty so the operator can
 * replace the value, and a Set / Not set indicator reflects the current state.
 *
 * @param array<string,string> $field  Field spec from ec_network_commerce_fields().
 * @param bool                 $is_set Whether a value is currently stored.
 */
function ec_render_commerce_field_row( $field, $is_set ) {
	$input_id = 'ec_commerce_' . $field['key'];
	$post_key = 'ec_commerce_' . $field['key'];
	?>
	<tr>
		<th scope="row">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
		</th>
		<td>
			<input type="password"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $post_key ); ?>"
					value=""
					autocomplete="new-password"
					class="regular-text"
					placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>" />
			<?php if ( $is_set ) : ?>
				<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Set', 'extrachill-network' ); ?></span>
			<?php else : ?>
				<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Not set', 'extrachill-network' ); ?></span>
			<?php endif; ?>
			<p class="description">
				<?php echo esc_html( $field['description'] ); ?>
			</p>
		</td>
	</tr>
	<?php
}

<?php
/**
 * Artist Settings Page
 *
 * Displays artist payout settings and Stripe Connect onboarding status
 * in the WooCommerce My Account area.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the artist settings page.
 */
function extrachill_shop_render_artist_settings() {
	if ( ! extrachill_shop_user_is_artist() ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to view this page.', 'extrachill-shop' ) . '</p></div>';
		return;
	}

	$user_artists     = extrachill_shop_get_user_artists();
	$commission_rate  = extrachill_shop_get_commission_rate_display();
	$artist_share     = extrachill_shop_get_artist_share_display();
	?>

	<div class="extrachill-artist-settings">
		<h2><?php esc_html_e( 'Artist Settings', 'extrachill-shop' ); ?></h2>

		<section class="settings-section">
			<h3><?php esc_html_e( 'Your Artist Profiles', 'extrachill-shop' ); ?></h3>
			<?php if ( empty( $user_artists ) ) : ?>
				<p><?php esc_html_e( 'No artist profiles linked to your account.', 'extrachill-shop' ); ?></p>
			<?php else : ?>
				<ul class="artist-profiles-list">
					<?php foreach ( $user_artists as $artist ) : ?>
						<li>
							<strong><?php echo esc_html( $artist['post_title'] ); ?></strong>
							<?php
							$product_count = extrachill_shop_get_artist_product_count( $artist['ID'] );
							$pending_count = extrachill_shop_get_artist_product_count( $artist['ID'], 'pending' );
							?>
							<span class="product-count">
								<?php
								printf(
									/* translators: %d: number of products */
									esc_html( _n( '%d product', '%d products', $product_count, 'extrachill-shop' ) ),
									esc_html( $product_count )
								);
								if ( $pending_count > 0 ) {
									printf(
										/* translators: %d: number of pending products */
										' <span class="pending">(%d ' . esc_html__( 'pending', 'extrachill-shop' ) . ')</span>',
										esc_html( $pending_count )
									);
								}
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<section class="settings-section">
			<h3><?php esc_html_e( 'Commission Structure', 'extrachill-shop' ); ?></h3>
			<div class="commission-info">
				<div class="commission-item">
					<span class="label"><?php esc_html_e( 'Platform Fee', 'extrachill-shop' ); ?></span>
					<span class="value"><?php echo esc_html( $commission_rate ); ?></span>
				</div>
				<div class="commission-item highlight">
					<span class="label"><?php esc_html_e( 'Your Share', 'extrachill-shop' ); ?></span>
					<span class="value"><?php echo esc_html( $artist_share ); ?></span>
				</div>
			</div>
			<p class="description">
				<?php esc_html_e( 'You receive this percentage of each sale after payment processing fees.', 'extrachill-shop' ); ?>
			</p>
		</section>

		<section class="settings-section">
			<h3><?php esc_html_e( 'Payout Settings', 'extrachill-shop' ); ?></h3>
			<?php
			$stripe_status    = extrachill_shop_get_stripe_account_status();
			$stripe_connected = $stripe_status['connected'];
			$can_receive      = $stripe_status['can_receive_payments'];
			$account_status   = $stripe_status['status'];
			?>

			<?php if ( $stripe_connected && $can_receive ) : ?>
				<div class="notice notice-success">
					<p>
						<strong><?php esc_html_e( 'Stripe Connected', 'extrachill-shop' ); ?></strong><br>
						<?php esc_html_e( 'Your Stripe account is connected and ready to receive payouts.', 'extrachill-shop' ); ?>
					</p>
				</div>
				<p>
					<?php $dashboard_url = extrachill_shop_get_stripe_dashboard_url(); ?>
					<a href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" rel="noopener noreferrer" class="button">
						<?php esc_html_e( 'View Stripe Dashboard', 'extrachill-shop' ); ?>
					</a>
				</p>
			<?php elseif ( $stripe_connected && 'restricted' === $account_status ) : ?>
				<div class="notice notice-info">
					<p>
						<strong><?php esc_html_e( 'Complete Your Stripe Setup', 'extrachill-shop' ); ?></strong><br>
						<?php esc_html_e( 'Your Stripe account needs additional information before you can receive payments.', 'extrachill-shop' ); ?>
					</p>
				</div>
				<?php $onboarding_url = extrachill_shop_get_stripe_onboarding_url(); ?>
				<?php if ( $onboarding_url ) : ?>
					<p>
						<a href="<?php echo esc_url( $onboarding_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Complete Stripe Setup', 'extrachill-shop' ); ?>
						</a>
					</p>
				<?php endif; ?>
			<?php elseif ( $stripe_connected && 'pending' === $account_status ) : ?>
				<div class="notice notice-info">
					<p>
						<strong><?php esc_html_e( 'Stripe Setup In Progress', 'extrachill-shop' ); ?></strong><br>
						<?php esc_html_e( 'Complete your Stripe onboarding to start receiving payments.', 'extrachill-shop' ); ?>
					</p>
				</div>
				<?php $onboarding_url = extrachill_shop_get_stripe_onboarding_url(); ?>
				<?php if ( $onboarding_url ) : ?>
					<p>
						<a href="<?php echo esc_url( $onboarding_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Continue Stripe Setup', 'extrachill-shop' ); ?>
						</a>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<div class="notice notice-info">
					<p>
						<strong><?php esc_html_e( 'Connect Stripe to Receive Payouts', 'extrachill-shop' ); ?></strong><br>
						<?php esc_html_e( 'Connect your Stripe account to receive payments for your product sales.', 'extrachill-shop' ); ?>
					</p>
				</div>
				<?php
				$onboarding_url = extrachill_shop_get_stripe_onboarding_url();
				if ( $onboarding_url ) :
					?>
					<p>
						<a href="<?php echo esc_url( $onboarding_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Connect with Stripe', 'extrachill-shop' ); ?>
						</a>
					</p>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( 'Stripe Connect is not yet configured. Please contact support.', 'extrachill-shop' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<section class="settings-section">
			<h3><?php esc_html_e( 'Shipping Policy', 'extrachill-shop' ); ?></h3>
			<p>
				<?php esc_html_e( 'All products are shipped by the artist. Include shipping costs in your product prices.', 'extrachill-shop' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Customers see a single price with no separate shipping charges at checkout.', 'extrachill-shop' ); ?>
			</p>
		</section>
	</div>
	<?php
}

/**
 * Check if current user has connected Stripe account.
 *
 * @return bool True if connected.
 */
function extrachill_shop_is_stripe_connected() {
	$user_id    = get_current_user_id();
	$account_id = get_user_meta( $user_id, '_stripe_connect_account_id', true );

	if ( empty( $account_id ) ) {
		return false;
	}

	// Check if account can actually receive payments.
	$status = get_user_meta( $user_id, '_stripe_connect_status', true );
	return 'active' === $status;
}

/**
 * Get the user's Stripe Connect account ID.
 *
 * @return string|false Stripe account ID or false.
 */
function extrachill_shop_get_stripe_account_id() {
	$user_id    = get_current_user_id();
	$account_id = get_user_meta( $user_id, '_stripe_connect_account_id', true );
	return $account_id ? $account_id : false;
}

/**
 * Get the Stripe Connect onboarding URL.
 *
 * Uses Stripe Express Account Links for onboarding.
 *
 * @return string|false Onboarding URL or false if not configured.
 */
function extrachill_shop_get_stripe_onboarding_url() {
	if ( ! extrachill_shop_stripe_is_configured() ) {
		return false;
	}

	$user_id    = get_current_user_id();
	$account_id = get_user_meta( $user_id, '_stripe_connect_account_id', true );

	// Create account if none exists.
	if ( empty( $account_id ) ) {
		$result = extrachill_shop_create_stripe_account( $user_id );
		if ( ! $result['success'] ) {
			return false;
		}
		$account_id = $result['account_id'];
	}

	// Generate onboarding link.
	$link_result = extrachill_shop_create_account_link( $account_id, 'account_onboarding' );
	if ( ! $link_result['success'] ) {
		return false;
	}

	return $link_result['url'];
}

/**
 * Get the Stripe dashboard link for the current user.
 *
 * @return string|false Dashboard URL or false if not configured.
 */
function extrachill_shop_get_stripe_dashboard_url() {
	$user_id    = get_current_user_id();
	$account_id = get_user_meta( $user_id, '_stripe_connect_account_id', true );

	if ( empty( $account_id ) ) {
		return false;
	}

	$result = extrachill_shop_create_dashboard_link( $account_id );
	if ( ! $result['success'] ) {
		return 'https://dashboard.stripe.com/';
	}

	return $result['url'];
}

/**
 * Get the current Stripe account status.
 *
 * @return array Status data with keys: connected, status, can_receive_payments
 */
function extrachill_shop_get_stripe_account_status() {
	$user_id    = get_current_user_id();
	$account_id = get_user_meta( $user_id, '_stripe_connect_account_id', true );

	if ( empty( $account_id ) ) {
		return array(
			'connected'            => false,
			'status'               => null,
			'can_receive_payments' => false,
		);
	}

	$cached_status = get_user_meta( $user_id, '_stripe_connect_status', true );

	return array(
		'connected'            => true,
		'status'               => $cached_status,
		'can_receive_payments' => 'active' === $cached_status,
	);
}

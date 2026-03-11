<?php
/**
 * Studio block render.
 *
 * @package ExtraChillStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$defaults = array(
	'headline'      => __( 'Extra Chill Studio', 'extrachill-studio' ),
	'description'   => __( 'Internal tools for the Extra Chill team. Shape captions, organize publishing tasks, and prepare the next wave of AI-assisted workflows.', 'extrachill-studio' ),
	'deniedMessage' => __( 'Studio is currently available to Extra Chill team members only.', 'extrachill-studio' ),
);

$attributes     = isset( $attributes ) && is_array( $attributes ) ? $attributes : array();
$attributes     = wp_parse_args( $attributes, $defaults );
$headline       = $attributes['headline'] ?? $defaults['headline'];
$description    = $attributes['description'] ?? $defaults['description'];
$denied_message = $attributes['deniedMessage'] ?? $defaults['deniedMessage'];

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'ec-studio-block',
	)
);

if ( ! function_exists( 'ec_is_team_member' ) ) :
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped HTML attributes. ?>>
		<div class="ec-studio-card ec-studio-card--dependency-missing notice notice-error">
			<p><?php esc_html_e( 'Extra Chill Studio requires the Extra Chill Users plugin to load team permissions.', 'extrachill-studio' ); ?></p>
		</div>
	</div>
	<?php
	return;
endif;

if ( ! is_user_logged_in() ) :
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped HTML attributes. ?>>
		<div class="ec-studio-card ec-studio-card--logged-out">
			<?php if ( $headline ) : ?>
				<h2 class="ec-studio-card__title"><?php echo esc_html( $headline ); ?></h2>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="ec-studio-card__description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return;
endif;

if ( ! ec_is_team_member() ) :
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped HTML attributes. ?>>
		<div class="ec-studio-card ec-studio-card--denied notice notice-info">
			<?php if ( $headline ) : ?>
				<h2 class="ec-studio-card__title"><?php echo esc_html( $headline ); ?></h2>
			<?php endif; ?>

			<p class="ec-studio-card__description"><?php echo esc_html( $denied_message ); ?></p>
		</div>
	</div>
	<?php
	return;
endif;

extrachill_studio_enqueue_shared_tabs_assets();

$studio_user = wp_get_current_user();
$site_name   = get_bloginfo( 'name' );
$site_url    = home_url( '/' );
$rest_nonce  = wp_create_nonce( 'wp_rest' );
$socials_api = rest_url( 'datamachine-socials/v1/' );
?>

<div
	<?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped HTML attributes. ?>
	data-ec-studio-root
	data-user-name="<?php echo esc_attr( $studio_user->display_name ); ?>"
	data-site-name="<?php echo esc_attr( $site_name ); ?>"
	data-site-url="<?php echo esc_url( $site_url ); ?>"
	data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
	data-socials-api-base="<?php echo esc_url( $socials_api ); ?>"
>
	<div class="ec-studio-shell">
		<?php if ( $headline ) : ?>
			<h2 class="ec-studio-shell__title"><?php echo esc_html( $headline ); ?></h2>
		<?php endif; ?>

		<?php if ( $description ) : ?>
			<p class="ec-studio-shell__description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>

		<div class="ec-studio-shell__status-bar">
			<span class="ec-studio-shell__status-pill"><?php esc_html_e( 'Phase 0', 'extrachill-studio' ); ?></span>
			<span class="ec-studio-shell__status-copy">
				<?php
				printf(
					/* translators: %s: team member display name */
					esc_html__( 'Signed in as %s', 'extrachill-studio' ),
					esc_html( $studio_user->display_name )
				);
				?>
			</span>
		</div>

		<div class="shared-tabs-component ec-studio-tabs">
			<div class="shared-tabs-buttons-container">
				<div class="shared-tab-item">
					<button type="button" class="shared-tab-button active" data-tab="tab-studio-overview">
						<?php esc_html_e( 'Overview', 'extrachill-studio' ); ?>
						<span class="shared-tab-arrow open"></span>
					</button>
					<div id="tab-studio-overview" class="shared-tab-pane">
						<div class="ec-studio-pane__mount" data-ec-studio-pane="overview"></div>
					</div>
				</div>

				<div class="shared-tab-item">
					<button type="button" class="shared-tab-button" data-tab="tab-studio-qr-codes">
						<?php esc_html_e( 'QR Codes', 'extrachill-studio' ); ?>
						<span class="shared-tab-arrow"></span>
					</button>
					<div id="tab-studio-qr-codes" class="shared-tab-pane">
						<div class="ec-studio-pane__mount" data-ec-studio-pane="qr-codes"></div>
					</div>
				</div>

				<div class="shared-tab-item">
					<button type="button" class="shared-tab-button" data-tab="tab-studio-socials">
						<?php esc_html_e( 'Socials', 'extrachill-studio' ); ?>
						<span class="shared-tab-arrow"></span>
					</button>
					<div id="tab-studio-socials" class="shared-tab-pane">
						<div class="ec-studio-pane__mount" data-ec-studio-pane="socials"></div>
					</div>
				</div>
			</div>

			<div class="shared-desktop-tab-content-area"></div>
		</div>
	</div>
</div>

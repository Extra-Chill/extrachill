<?php
/**
 * Studio homepage template.
 *
 * Renders either the login/register block or the Studio block at the homepage
 * layer so auth gating happens before Studio markup is sent to the browser.
 *
 * @package ExtraChillStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_logged_in   = is_user_logged_in();
$can_check_team = function_exists( 'ec_is_team_member' );
$can_access     = $is_logged_in && ( current_user_can( 'manage_options' ) || ( $can_check_team && ec_is_team_member() ) );

extrachill_breadcrumbs();
?>

<?php if ( ! $is_logged_in ) : ?>
	<div class="notice notice-info">
		<p><?php esc_html_e( 'Sign in to access Extra Chill Studio.', 'extrachill-studio' ); ?></p>
	</div>

	<?php echo do_blocks( '<!-- wp:extrachill/login-register /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() returns rendered block markup. ?>

<?php elseif ( ! $can_access ) : ?>
	<div class="notice notice-info">
		<p><?php esc_html_e( 'Studio is available to Extra Chill team members. Contact Chris if you need access.', 'extrachill-studio' ); ?></p>
	</div>

<?php else : ?>
	<?php echo do_blocks( '<!-- wp:extrachill/studio /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() returns rendered block markup. ?>
<?php endif; ?>

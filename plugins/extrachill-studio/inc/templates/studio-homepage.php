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
$is_team_member = $is_logged_in && $can_check_team && ec_is_team_member();

extrachill_breadcrumbs();
?>

<h2><?php esc_html_e( 'Extra Chill Studio', 'extrachill-studio' ); ?></h2>


<?php if ( ! $is_logged_in ) : ?>
	<div class="notice notice-info">
		<p><?php esc_html_e( 'Extra Chill Studio is an internal workspace for the Extra Chill team. Sign in below to continue.', 'extrachill-studio' ); ?></p>
	</div>

	<?php echo do_blocks( '<!-- wp:extrachill/login-register /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() returns rendered block markup. ?>

<?php elseif ( $can_check_team && ! $is_team_member ) : ?>
	<div class="notice notice-info">
		<p><?php esc_html_e( 'You are signed in, but Studio is currently limited to Extra Chill team members. If you need access for a publishing or operations task, contact Chris.', 'extrachill-studio' ); ?></p>
	</div>

	<?php echo do_blocks( '<!-- wp:extrachill/studio /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() returns rendered block markup. ?>

<?php else : ?>
	<?php echo do_blocks( '<!-- wp:extrachill/studio /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() returns rendered block markup. ?>
<?php endif; ?>

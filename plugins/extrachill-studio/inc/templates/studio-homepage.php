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

extrachill_breadcrumbs();
?>

<h2><?php esc_html_e( 'Extra Chill Studio', 'extrachill-studio' ); ?></h2>

<?php if ( is_user_logged_in() ) : ?>
	<?php echo do_blocks( '<!-- wp:extrachill/studio /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() returns rendered block markup. ?>
<?php else : ?>
	<p><?php esc_html_e( 'Internal tools for the Extra Chill team. Sign in to access the Studio workspace.', 'extrachill-studio' ); ?></p>
	<?php echo do_blocks( '<!-- wp:extrachill/login-register /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() returns rendered block markup. ?>
<?php endif; ?>

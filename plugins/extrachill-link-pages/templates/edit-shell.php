<?php
/**
 * /edit shell on the public Link Page host.
 *
 * Private response: the theme and the editor use the local WordPress session.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

nocache_headers();
$ec_link_page_edit_ready = ec_enqueue_link_page_edit_shell();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor selection.
$ec_link_page_login = ! is_user_logged_in() ? apply_filters( 'ec_link_page_edit_login_url', wp_login_url(), absint( $_GET['link_page'] ?? 0 ) ) : '';
get_header();
?>
<main class="ec-link-page-edit-shell">
	<div id="ec-link-page-edit-status" class="notice notice-info" role="status" aria-live="polite">
		<?php if ( $ec_link_page_login ) : ?>
			<p><a href="<?php echo esc_url( $ec_link_page_login ); ?>">Sign in to edit your link page</a></p>
		<?php else : ?>
			<p><?php echo esc_html( $ec_link_page_edit_ready ? 'Loading your link page editor…' : 'Link Page editing is unavailable right now.' ); ?></p>
		<?php endif; ?>
	</div>
	<div id="ec-link-page-edit-root" class="ec-link-page-editor"></div>
</main>
<?php
get_footer();

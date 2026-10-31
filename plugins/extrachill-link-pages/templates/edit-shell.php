<?php
/**
 * /edit shell on the public Link Page host.
 *
 * Static for every visitor (cacheable): authentication and data happen in
 * assets/js/link-page-edit-shell.js with a bearer token.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

$ec_link_page_edit_ready = ec_enqueue_link_page_edit_shell();
get_header();
?>
<main class="ec-link-page-edit-shell">
	<div id="ec-link-page-edit-status" class="notice notice-info" role="status" aria-live="polite">
		<p><?php echo esc_html( $ec_link_page_edit_ready ? 'Loading your link page editor…' : 'Link Page editing is unavailable right now.' ); ?></p>
	</div>
	<div id="ec-link-page-edit-root" class="ec-link-page-editor"></div>
</main>
<?php
get_footer();

<?php
/**
 * Under Construction Notice
 *
 * Displays a persistent info notice on all shop pages informing visitors
 * that the store is being built out as an artist merch marketplace.
 *
 * Remove this file (and its require_once) when the shop is fully launched.
 *
 * @package ExtraChillShop
 * @since 0.6.2
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render under-construction notice on all shop pages.
 *
 * Hooks into the theme's extrachill_notices action which fires at the top
 * of <main> on every page. Scoped to blog ID 3 (shop.extrachill.com).
 */
function extrachill_shop_under_construction_notice() {
	if ( get_current_blog_id() !== 3 ) {
		return;
	}

	// Don't show in wp-admin.
	if ( is_admin() ) {
		return;
	}

	?>
	<div class="notice notice-info">
		<p>
			<strong>The Extra Chill Merch Store is under construction.</strong>
			We're building a marketplace where independent artists can set up their own merch booths,
			manage inventory, fulfill orders, and get paid directly &mdash; like Etsy for the music scene.
			Stay tuned.
		</p>
	</div>
	<?php
}
add_action( 'extrachill_notices', 'extrachill_shop_under_construction_notice', 5 );

<?php
/**
 * Seller storefront chrome.
 *
 * Renders the header of a filtered storefront (seller name, link to the
 * seller's public page, and "Manage Shop" for people who run it), and sends
 * the Shop's `/artist/<slug>/` taxonomy archive, which lists no products, to
 * the artist's storefront.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'extrachill_shop_redirect_artist_archive_to_storefront', 5 );

/**
 * Public-page link label for a seller type.
 *
 * @param string $type Seller type.
 * @return string
 */
function extrachill_shop_seller_public_link_label( string $type ): string {
	return 'artist' === $type
		? __( 'Artist Profile', 'extrachill-shop' )
		: __( 'Upcoming Shows', 'extrachill-shop' );
}

/**
 * Render the storefront header for a seller.
 *
 * @param array $seller `{type, id}` seller reference.
 */
function extrachill_shop_render_storefront_header( array $seller ): void {
	$public = extrachill_shop_get_public_seller( $seller );
	if ( ! $public ) {
		return;
	}

	$manage_url = '';
	if ( is_user_logged_in() && true === extrachill_shop_current_user_can_manage_seller( $seller ) ) {
		$manage_url = extrachill_shop_get_shop_manager_url( $seller );
	}
	?>
	<header class="page-header">
		<h1 class="page-title">
			<?php
			/* translators: %s: seller name. */
			echo esc_html( sprintf( __( '%s Merch', 'extrachill-shop' ), $public['name'] ) );
			?>
		</h1>
		<p>
			<?php if ( '' !== $public['url'] ) : ?>
				<a class="button-3 button-small" href="<?php echo esc_url( $public['url'] ); ?>"><?php echo esc_html( extrachill_shop_seller_public_link_label( $public['type'] ) ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $manage_url ) : ?>
				<a class="button-1 button-small" href="<?php echo esc_url( $manage_url ); ?>"><?php esc_html_e( 'Manage Shop', 'extrachill-shop' ); ?></a>
			<?php endif; ?>
		</p>
	</header>
	<?php
}

/**
 * Redirect the Shop's artist taxonomy archive to the artist's storefront.
 *
 * Products carry the artist as meta, not as a taxonomy term, so the archive
 * is always empty.
 */
function extrachill_shop_redirect_artist_archive_to_storefront(): void {
	if ( ! is_tax( 'artist' ) || ! function_exists( 'extrachill_get_artist_profile_by_slug' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return;
	}

	$artist = extrachill_get_artist_profile_by_slug( $term->slug );
	$url    = empty( $artist['id'] ) ? '' : extrachill_shop_get_seller_store_url(
		array(
			'type' => 'artist',
			'id'   => (int) $artist['id'],
		)
	);
	if ( '' === $url ) {
		return;
	}

	wp_safe_redirect( $url, 301 );
	exit;
}

<?php
/**
 * Seller storefronts at `/<type>/<slug>/`.
 *
 * `shop.extrachill.com/venue/lo-fi-brewing/` is Lo-Fi Brewing's merch, the
 * pair of `events.extrachill.com/venue/lo-fi-brewing/`; artists and promoters
 * work the same way. The route renders the Shop catalog (the homepage
 * template) filtered to that seller, with a storefront header. Legacy
 * `?seller=type:id` links redirect here.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_SHOP_STOREFRONT_REWRITE_VERSION = '1';

add_action( 'init', 'extrachill_shop_storefront_rewrite_rules' );
add_action( 'init', 'extrachill_shop_storefront_maybe_flush_rewrites', 100 );
add_action( 'template_redirect', 'extrachill_shop_storefront_template_redirect', 1 );
add_filter( 'pre_handle_404', 'extrachill_shop_storefront_pre_handle_404', 10, 2 );
add_filter( 'document_title_parts', 'extrachill_shop_storefront_title', 1000 );
add_filter( 'extrachill_seo_canonical_url', 'extrachill_shop_storefront_canonical' );

/**
 * Register storefront routes. They take precedence over the network
 * `artist`/`venue` taxonomy archives, which list no products on the Shop.
 */
function extrachill_shop_storefront_rewrite_rules(): void {
	add_rewrite_tag( '%ec_shop_seller_type%', '(artist|venue|promoter)' );
	add_rewrite_tag( '%ec_shop_seller_slug%', '([^/]+)' );
	add_rewrite_rule( '^(artist|venue|promoter)/([^/]+)/?$', 'index.php?ec_shop_seller_type=$matches[1]&ec_shop_seller_slug=$matches[2]', 'top' );
	add_rewrite_rule( '^(artist|venue|promoter)/([^/]+)/page/([0-9]{1,})/?$', 'index.php?ec_shop_seller_type=$matches[1]&ec_shop_seller_slug=$matches[2]&paged=$matches[3]', 'top' );
}

/** Flush rewrites once when the storefront routes are introduced or changed. */
function extrachill_shop_storefront_maybe_flush_rewrites(): void {
	if ( get_option( 'extrachill_shop_storefront_rewrite_version' ) === EXTRACHILL_SHOP_STOREFRONT_REWRITE_VERSION ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( 'extrachill_shop_storefront_rewrite_version', EXTRACHILL_SHOP_STOREFRONT_REWRITE_VERSION, false );
}

/** Whether the request is a storefront route (resolved or not). */
function extrachill_shop_is_storefront_route(): bool {
	return '' !== (string) get_query_var( 'ec_shop_seller_type', '' );
}

/**
 * Where a legacy `?seller=` request should go, or '' to leave it alone.
 *
 * Applies on the Shop homepage and storefronts, where the filter bar's
 * seller dropdown submits `?seller=type:id` (empty for "All Sellers").
 *
 * @param array $query Request query args.
 * @return string Redirect URL, or ''.
 */
function extrachill_shop_storefront_legacy_redirect_url( array $query ): string {
	if ( ! array_key_exists( 'seller', $query ) || ! is_string( $query['seller'] ) ) {
		return '';
	}
	$key  = sanitize_text_field( wp_unslash( $query['seller'] ) );
	$keep = array_diff_key( $query, array_flip( array( 'seller', 'paged' ) ) );
	$keep = array_map( static fn( $value ) => is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '', $keep );
	$keep = array_filter( $keep, static fn( $value ) => '' !== $value );

	if ( '' === $key ) {
		$target = home_url( '/' );
	} else {
		$ref    = extrachill_shop_parse_seller_key( $key );
		$target = $ref ? extrachill_shop_get_seller_store_url( $ref ) : '';
		if ( '' === $target ) {
			return '';
		}
	}
	return empty( $keep ) ? $target : add_query_arg( $keep, $target );
}

/**
 * Redirect legacy `?seller=` links and 404 unknown storefront slugs.
 */
function extrachill_shop_storefront_template_redirect(): void {
	if ( ! extrachill_shop_is_storefront_route() && ! is_front_page() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public catalog filter.
	$redirect = extrachill_shop_storefront_legacy_redirect_url( $_GET );
	if ( '' !== $redirect ) {
		wp_safe_redirect( $redirect, 301 );
		exit;
	}

	if ( extrachill_shop_is_storefront_route() && null === extrachill_shop_get_requested_storefront_seller() ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}

/**
 * Answer a resolved storefront with 200. The main query has no posts of its
 * own; the catalog runs a product query.
 *
 * @param bool|null $preempt Existing preemption.
 * @param WP_Query  $query   Main query.
 * @return bool|null
 */
function extrachill_shop_storefront_pre_handle_404( $preempt, $query ) {
	if ( '' === (string) $query->get( 'ec_shop_seller_type', '' ) || null === extrachill_shop_get_requested_storefront_seller() ) {
		return $preempt;
	}
	status_header( 200 );
	return true;
}

/**
 * Title a storefront "<Seller> Merch".
 *
 * @param array $parts Title parts.
 * @return array
 */
function extrachill_shop_storefront_title( array $parts ): array {
	$seller = extrachill_shop_is_storefront_route() ? extrachill_shop_get_requested_storefront_seller() : null;
	$public = $seller ? extrachill_shop_get_public_seller( $seller ) : null;
	if ( $public ) {
		/* translators: %s: seller name. */
		$parts['title'] = sprintf( __( '%s Merch', 'extrachill-shop' ), $public['name'] );
		unset( $parts['tagline'] );
	}
	return $parts;
}

/**
 * Canonicalize a storefront to its own path (not the Shop homepage).
 *
 * @param string $canonical Default canonical.
 * @return string
 */
function extrachill_shop_storefront_canonical( string $canonical ): string {
	$seller = extrachill_shop_is_storefront_route() ? extrachill_shop_get_requested_storefront_seller() : null;
	$url    = $seller ? extrachill_shop_get_seller_store_url( $seller ) : '';
	if ( '' === $url ) {
		return $canonical;
	}
	$paged = (int) get_query_var( 'paged', 0 );
	return $paged > 1 ? $url . 'page/' . $paged . '/' : $url;
}

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
	if ( is_user_logged_in() && true === extrachill_shop_can_manage_seller( get_current_user_id(), $seller ) ) {
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

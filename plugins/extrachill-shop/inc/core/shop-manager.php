<?php
/**
 * Seller shop manager.
 *
 * Shop owns one manager for every seller type (artist, venue, promoter) at
 * `/manage`. Other sites link in with `?seller=type:id`; they never embed it.
 * The seller `{type, id}` is the unit of management and authority stays with
 * the seller's owning site, checked by every Shop ability.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'extrachill_shop_register_manager_block' );
add_action( 'init', 'extrachill_shop_maybe_provision_manager_page', 20 );
add_action( 'wp_abilities_api_init', 'extrachill_shop_register_select_seller_ability' );

/**
 * Option holding the provisioned manager page ID.
 */
const EXTRACHILL_SHOP_MANAGER_PAGE_OPTION = 'extrachill_shop_manager_page_id';

/**
 * User meta holding the last seller selected in the manager (`type:id`).
 */
const EXTRACHILL_SHOP_RECENT_SELLER_META = '_ec_shop_recent_seller';

/**
 * Whether the current site is the Shop site.
 *
 * @return bool
 */
function extrachill_shop_is_shop_site(): bool {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return true;
	}
	return get_current_blog_id() === (int) ec_get_blog_id( 'shop' );
}

/**
 * Register the manager block from its built metadata.
 */
function extrachill_shop_register_manager_block(): void {
	$block_dir = EXTRACHILL_SHOP_PLUGIN_DIR . 'build/blocks/shop-manager';
	if ( file_exists( $block_dir . '/block.json' ) ) {
		register_block_type( $block_dir );
	}
}

/**
 * Create the `/manage` page once.
 *
 * Guarded by an option, so this runs a single time per site: an existing
 * `manage` page is adopted rather than overwritten, and a page an editor later
 * removes is not recreated.
 */
function extrachill_shop_maybe_provision_manager_page(): void {
	if ( get_option( EXTRACHILL_SHOP_MANAGER_PAGE_OPTION ) || ! extrachill_shop_is_shop_site() ) {
		return;
	}

	$existing = get_page_by_path( 'manage', OBJECT, 'page' );
	if ( $existing instanceof WP_Post ) {
		update_option( EXTRACHILL_SHOP_MANAGER_PAGE_OPTION, (int) $existing->ID );
		return;
	}

	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'manage',
			'post_title'   => __( 'Manage Shop', 'extrachill-shop' ),
			'post_content' => '<!-- wp:extrachill/shop-manager /-->',
		),
		true
	);
	if ( ! is_wp_error( $page_id ) ) {
		update_option( EXTRACHILL_SHOP_MANAGER_PAGE_OPTION, (int) $page_id );
	}
}

/**
 * Shop manager URL, optionally preselecting a seller.
 *
 * Delegates to the network-wide `ec_get_shop_manager_url()` (Users) so every
 * site builds the same URL.
 *
 * @param array|null $seller Optional `{type, id}` reference.
 * @return string Manager URL, or '' when unavailable.
 */
function extrachill_shop_get_shop_manager_url( $seller = null ): string {
	return function_exists( 'ec_get_shop_manager_url' ) ? (string) ec_get_shop_manager_url( $seller ) : '';
}

/**
 * Format a seller reference as its `type:id` key.
 *
 * @param array $seller Seller reference.
 * @return string
 */
function extrachill_shop_seller_ref_key( array $seller ): string {
	return (string) ( $seller['type'] ?? '' ) . ':' . (int) ( $seller['id'] ?? 0 );
}

/**
 * Parse a `type:id` seller key.
 *
 * @param mixed $key Seller key.
 * @return array|null Seller reference, or null when malformed.
 */
function extrachill_shop_parse_seller_key( $key ): ?array {
	if ( ! is_string( $key ) || ! preg_match( '/^(artist|venue|promoter):([1-9][0-9]*)$/', $key, $matches ) ) {
		return null;
	}
	return array(
		'type' => $matches[1],
		'id'   => (int) $matches[2],
	);
}

/**
 * Pick the seller the manager opens with.
 *
 * An explicit `?seller=` must match an authorized seller; it never falls back
 * to another one. Without one, the last selection is used, then the first
 * authorized seller.
 *
 * @param array       $sellers   Authorized sellers.
 * @param string|null $requested Requested `type:id`, or null when absent.
 * @param mixed       $recent    Last selected `type:id`.
 * @return array|null
 */
function extrachill_shop_resolve_manager_seller( array $sellers, ?string $requested, $recent ): ?array {
	$key = null === $requested ? $recent : $requested;
	foreach ( $sellers as $seller ) {
		if ( extrachill_shop_seller_ref_key( $seller ) === $key ) {
			return $seller;
		}
	}
	return null === $requested ? ( $sellers[0] ?? null ) : null;
}

/**
 * Build the manager's sellers and selection for the current user.
 *
 * Discovery comes from Users (`ec_get_manageable_shop_sellers()`). An explicit
 * seller outside that list is still admitted when its owner authorizes the
 * current user, which covers network admins and owner-side access that
 * discovery does not enumerate.
 *
 * @param string|null $requested Requested `type:id`, or null when absent.
 * @return array{sellers: array, selected: array|null}
 */
function extrachill_shop_get_manager_state( ?string $requested ): array {
	$user_id = get_current_user_id();
	$sellers = function_exists( 'ec_get_manageable_shop_sellers' ) ? ec_get_manageable_shop_sellers( $user_id ) : array();

	$ref = null === $requested ? null : extrachill_shop_parse_seller_key( $requested );
	if ( $ref && null === extrachill_shop_resolve_manager_seller( $sellers, $requested, null )
		&& true === extrachill_shop_current_user_can_manage_seller( $ref ) ) {
		$canonical = extrachill_shop_get_canonical_seller( $ref );
		$sellers[] = array(
			'type'       => $ref['type'],
			'id'         => $ref['id'],
			'name'       => is_wp_error( $canonical ) ? '' : (string) $canonical['name'],
			'manage_url' => extrachill_shop_seller_owner_manage_url( $ref, is_wp_error( $canonical ) ? array() : $canonical ),
		);
	}

	foreach ( $sellers as &$seller ) {
		$seller['storefront_url'] = extrachill_shop_get_seller_store_url( $seller );
		$seller['manage_url']     = (string) ( $seller['manage_url'] ?? '' );
	}
	unset( $seller );

	return array(
		'sellers'  => $sellers,
		'selected' => extrachill_shop_resolve_manager_seller(
			$sellers,
			$requested,
			get_user_meta( $user_id, EXTRACHILL_SHOP_RECENT_SELLER_META, true )
		),
	);
}

/**
 * Where the seller is managed outside the Shop (Manage Artist, Venue Settings).
 *
 * Venues and promoters get it from their owner (Events `manage_url`).
 *
 * @param array $seller    `{type, id}` seller reference.
 * @param array $canonical Canonical seller from its owner, if resolved.
 * @return string
 */
function extrachill_shop_seller_owner_manage_url( array $seller, array $canonical ): string {
	if ( 'artist' === $seller['type'] ) {
		return function_exists( 'ec_get_artist_manage_url' ) ? (string) ec_get_artist_manage_url() : '';
	}
	return (string) ( $canonical['manage_url'] ?? '' );
}

/**
 * Register `extrachill/shop-select-seller`.
 *
 * Remembers the seller last opened in the manager. Selection is persisted
 * only through this authenticated ability, never by a GET page view.
 */
function extrachill_shop_register_select_seller_ability(): void {
	wp_register_ability(
		'extrachill/shop-select-seller',
		array(
			'label'               => __( 'Select Shop Seller', 'extrachill-shop' ),
			'description'         => __( 'Remember the seller the current user is managing in the shop manager.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'seller' => extrachill_shop_seller_input_schema(),
				),
				'required'   => array( 'seller' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'seller' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_select_seller',
			'permission_callback' => 'extrachill_shop_authorize_seller_input',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Persist the selected seller for the current user.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_select_seller( array $input ) {
	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}
	$key = extrachill_shop_seller_ref_key( $seller );
	update_user_meta( get_current_user_id(), EXTRACHILL_SHOP_RECENT_SELLER_META, $key );
	return array( 'seller' => $key );
}

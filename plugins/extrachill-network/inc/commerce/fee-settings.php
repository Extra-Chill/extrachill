<?php
/**
 * Commerce fee settings (network layer).
 *
 * One network option, `extrachill_commerce_fees`, holds every platform fee
 * rate: the global default. The network Payments page writes it. A seller or
 * an event can override some fields (#1698); the shop's
 * `extrachill_commerce_fee()` reads the result through
 * `ec_commerce_fee_settings( $context )`.
 * It lives here, in the network-active plugin, because the Payments page runs
 * in network admin where the shop plugin is not loaded.
 *
 * Until the page is first saved, the values fall back to the two stores this
 * replaces: the shop's `extrachill_shop_commission_rate` (blog option) and the
 * network `extrachill_ticket_fees` array. Nothing is written on read. Keys
 * those stores never had (the per-ticket service fee and the card processing
 * estimate) use the shipped defaults in `ec_commerce_fee_new_defaults()`.
 *
 * The card processing estimate (`processing_percent`, `processing_fixed_minor`)
 * is shared by tickets and merch. It was first saved as
 * `ticket_processing_percent` / `ticket_processing_fixed_minor`; those keys
 * are still read when the new ones are missing (`ec_commerce_fee_legacy_keys()`).
 *
 * @package ExtraChillNetwork
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fee setting keys mapped to whether the value is a percentage (true) or a
 * flat amount in minor units (false).
 *
 * @return array<string,bool>
 */
function ec_commerce_fee_fields() {
	return array(
		'merch_percent'                     => true,
		'ticket_percent'                    => true,
		'ticket_buyer_fee_per_ticket_minor' => false,
		'ticket_buyer_fee_flat_minor'       => false,
		'ticket_buyer_fee_percent'          => true,
		'processing_percent'                => true,
		'processing_fixed_minor'            => false,
	);
}

/**
 * Renamed keys: current key => the key it was saved under before.
 *
 * The processing estimate started as a ticket-only setting (#1553) and now
 * also sets the merch application fee (#1563).
 *
 * @return array<string,string>
 */
function ec_commerce_fee_legacy_keys() {
	return array(
		'processing_percent'     => 'ticket_processing_percent',
		'processing_fixed_minor' => 'ticket_processing_fixed_minor',
	);
}

/**
 * Highest allowed value per percentage key, when lower than 100.
 *
 * The processing percentage divides the buyer total by (1 - rate), so it must
 * stay well below 100.
 *
 * @return array<string,float>
 */
function ec_commerce_fee_percent_caps() {
	return array( 'processing_percent' => 50.0 );
}

/**
 * Shipped defaults for keys the legacy stores never had.
 *
 * - `ticket_buyer_fee_per_ticket_minor`: $1 per ticket, paid by the buyer.
 * - `processing_percent` / `processing_fixed_minor`: the card processing
 *   estimate (2.9% + 30¢). The ticket service fee is grossed up by it, so the
 *   venue nets the full ticket subtotal; the merch application fee adds it
 *   when Extra Chill pays the seller's processing.
 *
 * @return array<string,float|int>
 */
function ec_commerce_fee_new_defaults() {
	return array(
		'ticket_buyer_fee_per_ticket_minor' => 100,
		'processing_percent'                => 2.9,
		'processing_fixed_minor'            => 30,
	);
}

/**
 * Fee defaults, read from the stores this setting replaces.
 *
 * @return array<string,float|int>
 */
function ec_commerce_fee_defaults() {
	$rate = get_blog_option( (int) ec_get_blog_id( 'shop' ), 'extrachill_shop_commission_rate', '10' );
	// The shop stored either a percentage ('10') or a decimal (0.1).
	$merch  = is_numeric( $rate ) ? ( (float) $rate <= 1 ? (float) $rate * 100 : (float) $rate ) : 10.0;
	$ticket = get_site_option( 'extrachill_ticket_fees', array() );
	$ticket = is_array( $ticket ) ? $ticket : array();

	return ec_commerce_fee_sanitize(
		array(
			'merch_percent'               => $merch,
			'ticket_percent'              => $ticket['seller_fee_percent'] ?? 0,
			'ticket_buyer_fee_flat_minor' => $ticket['buyer_fee_flat_minor'] ?? 0,
			'ticket_buyer_fee_percent'    => $ticket['buyer_fee_percent'] ?? 0,
		) + ec_commerce_fee_new_defaults(),
		array_fill_keys( array_keys( ec_commerce_fee_fields() ), 0 )
	);
}

/**
 * Clamp fee values: percentages to 0-100 (or a lower cap from
 * `ec_commerce_fee_percent_caps()`), flat amounts to whole minor units of at
 * least 0. A missing or non-numeric value keeps its fallback. A key saved
 * under its old name (`ec_commerce_fee_legacy_keys()`) is read from there.
 *
 * @param array $values   Submitted or stored values.
 * @param array $fallback Values used for missing or invalid keys.
 * @return array<string,float|int>
 */
function ec_commerce_fee_sanitize( array $values, array $fallback ) {
	$clean = array();
	$caps  = ec_commerce_fee_percent_caps();
	foreach ( ec_commerce_fee_legacy_keys() as $key => $legacy ) {
		if ( ! isset( $values[ $key ] ) && isset( $values[ $legacy ] ) ) {
			$values[ $key ] = $values[ $legacy ];
		}
	}
	foreach ( ec_commerce_fee_fields() as $key => $is_percent ) {
		$value         = isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ? (float) $values[ $key ] : (float) ( $fallback[ $key ] ?? 0 );
		$value         = max( 0.0, $value );
		$clean[ $key ] = $is_percent ? min( $caps[ $key ] ?? 100.0, $value ) : (int) round( $value );
	}
	return $clean;
}

/**
 * The global fee settings: the saved option over the legacy defaults.
 *
 * This is the network default every sale starts from. The network Payments
 * page reads and writes it; everything that charges or shows a fee reads the
 * resolved settings, `ec_commerce_fee_settings()`, instead.
 *
 * @return array<string,float|int> Keyed by `ec_commerce_fee_fields()`.
 */
function ec_commerce_fee_global_settings() {
	$stored = get_site_option( 'extrachill_commerce_fees', array() );
	return ec_commerce_fee_sanitize( is_array( $stored ) ? $stored : array(), ec_commerce_fee_defaults() );
}

/**
 * Fee settings for a sale: the global settings, then any overrides.
 *
 * Overrides layer from least to most specific, field by field:
 *
 *     global → seller (type + id) → event
 *
 * An override holds only the fields it changes (`ec_commerce_fee_sanitize_override()`),
 * so every other field inherits from the layer below. With no override, the
 * result is exactly the global settings.
 *
 * @param array $context {
 *     Optional. What is being sold. Unknown keys are passed to the filter.
 *
 *     @type string $kind     `extrachill_merch` or `extrachill_ticket`.
 *     @type array  $seller   Seller reference `{type, id}`.
 *     @type int    $event_id Event post ID on the Events site (tickets).
 * }
 * @return array<string,float|int> Keyed by `ec_commerce_fee_fields()`.
 */
function ec_commerce_fee_settings( array $context = array() ) {
	$settings = ec_commerce_fee_global_settings();
	$layers   = array();
	$seller   = ec_commerce_fee_seller_ref( $context['seller'] ?? null );
	if ( $seller ) {
		$layers['seller'] = ec_commerce_fee_get_override( 'seller', $seller );
	}
	$event_id = (int) ( $context['event_id'] ?? 0 );
	if ( $event_id > 0 ) {
		$layers['event'] = ec_commerce_fee_get_override( 'event', $event_id );
	}
	foreach ( $layers as $override ) {
		$settings = array_merge( $settings, $override );
	}

	/**
	 * Filter the resolved fee settings for a sale.
	 *
	 * Runs after the seller and event overrides. The result is clamped by
	 * `ec_commerce_fee_sanitize()`; a missing or invalid field keeps its
	 * resolved value.
	 *
	 * @param array $settings Resolved settings, keyed by `ec_commerce_fee_fields()`.
	 * @param array $context  The context passed to `ec_commerce_fee_settings()`.
	 * @param array $layers   Overrides applied, keyed by scope (`seller`, `event`).
	 */
	$filtered = apply_filters( 'ec_commerce_fee_settings_resolved', $settings, $context, $layers );
	return ec_commerce_fee_sanitize( is_array( $filtered ) ? $filtered : array(), $settings );
}

/**
 * Post meta on an Events site event holding its fee override.
 */
const EC_COMMERCE_FEE_EVENT_META = '_extrachill_commerce_fee_override';

/**
 * Shop site option prefix for a seller's fee override; the key ends in
 * `{type}:{id}`, like the seller's other Shop records.
 */
const EC_COMMERCE_FEE_SELLER_OPTION_PREFIX = 'extrachill_shop_fee_seller_';

/**
 * Keep the fields an override sets, clamped like the global settings.
 *
 * Only numeric fields are kept; everything else inherits. Values go through
 * `ec_commerce_fee_sanitize()`, so an override can never hold a value the
 * global setting could not.
 *
 * @param mixed $values Submitted or stored override.
 * @return array<string,float|int> Subset of `ec_commerce_fee_fields()`.
 */
function ec_commerce_fee_sanitize_override( $values ) {
	if ( ! is_array( $values ) ) {
		return array();
	}
	$legacy = ec_commerce_fee_legacy_keys();
	$set    = array();
	foreach ( array_keys( ec_commerce_fee_fields() ) as $key ) {
		$value = $values[ $key ] ?? ( isset( $legacy[ $key ] ) ? ( $values[ $legacy[ $key ] ] ?? null ) : null );
		if ( is_numeric( $value ) ) {
			$set[ $key ] = true;
		}
	}
	return array_intersect_key( ec_commerce_fee_sanitize( $values, array() ), $set );
}

/**
 * A valid seller reference, or null.
 *
 * @param mixed $seller Seller reference.
 * @return array{type:string,id:int}|null
 */
function ec_commerce_fee_seller_ref( $seller ) {
	if ( ! is_array( $seller ) || ! in_array( $seller['type'] ?? '', array( 'artist', 'venue', 'promoter' ), true ) || (int) ( $seller['id'] ?? 0 ) < 1 ) {
		return null;
	}
	return array(
		'type' => (string) $seller['type'],
		'id'   => (int) $seller['id'],
	);
}

/**
 * Where an override is stored: the owning blog and, for events, the post.
 *
 * - `event`: registered post meta on the event, on the Events site.
 * - `seller`: a Shop site option keyed by the seller, beside the seller's
 *   Stripe and shipping records.
 *
 * @param string    $scope  `event` or `seller`.
 * @param int|array $target Event post ID, or seller reference.
 * @return array{blog_id:int,key:string,post_id:int}|null Null for an invalid target.
 */
function ec_commerce_fee_override_location( $scope, $target ) {
	if ( 'event' === $scope ) {
		$blog_id = (int) ec_get_blog_id( 'events' );
		$post_id = is_numeric( $target ) ? (int) $target : 0;
		return $blog_id > 0 && $post_id > 0 ? array(
			'blog_id' => $blog_id,
			'key'     => EC_COMMERCE_FEE_EVENT_META,
			'post_id' => $post_id,
		) : null;
	}
	if ( 'seller' === $scope ) {
		$blog_id = (int) ec_get_blog_id( 'shop' );
		$seller  = ec_commerce_fee_seller_ref( $target );
		return $blog_id > 0 && $seller ? array(
			'blog_id' => $blog_id,
			'key'     => EC_COMMERCE_FEE_SELLER_OPTION_PREFIX . $seller['type'] . ':' . $seller['id'],
			'post_id' => 0,
		) : null;
	}
	return null;
}

/**
 * The fee override stored for an event or seller.
 *
 * @param string    $scope  `event` or `seller`.
 * @param int|array $target Event post ID, or seller reference.
 * @return array<string,float|int> Only the fields the override sets; empty when none.
 */
function ec_commerce_fee_get_override( $scope, $target ) {
	$location = ec_commerce_fee_override_location( $scope, $target );
	if ( ! $location ) {
		return array();
	}
	if ( 'seller' === $scope ) {
		return ec_commerce_fee_sanitize_override( get_blog_option( $location['blog_id'], $location['key'], array() ) );
	}
	switch_to_blog( $location['blog_id'] );
	$stored = get_post_meta( $location['post_id'], $location['key'], true );
	restore_current_blog();
	return ec_commerce_fee_sanitize_override( $stored );
}

/**
 * Change an event or seller fee override, field by field.
 *
 * Numeric fields are set; a field given as null stops overriding and
 * inherits again. Fields not given keep their override. An override left
 * with no fields is deleted. Authority is the caller's job (the Shop's
 * fee override abilities).
 *
 * @param string    $scope  `event` or `seller`.
 * @param int|array $target Event post ID, or seller reference.
 * @param array     $fees   Field => number, or null to inherit.
 * @return array|false The stored override, or false for an invalid target.
 */
function ec_commerce_fee_update_override( $scope, $target, array $fees ) {
	$location = ec_commerce_fee_override_location( $scope, $target );
	if ( ! $location ) {
		return false;
	}
	$override = ec_commerce_fee_get_override( $scope, $target );
	foreach ( array_keys( ec_commerce_fee_fields() ) as $key ) {
		if ( array_key_exists( $key, $fees ) && null === $fees[ $key ] ) {
			unset( $override[ $key ] );
		}
	}
	$override = ec_commerce_fee_sanitize_override( array_merge( $override, ec_commerce_fee_sanitize_override( $fees ) ) );
	if ( array() === $override ) {
		ec_commerce_fee_delete_override( $scope, $target );
		return array();
	}
	if ( 'seller' === $scope ) {
		update_blog_option( $location['blog_id'], $location['key'], $override );
		return $override;
	}
	switch_to_blog( $location['blog_id'] );
	update_post_meta( $location['post_id'], $location['key'], $override );
	restore_current_blog();
	return $override;
}

/**
 * Remove an event or seller fee override, so every field inherits again.
 *
 * @param string    $scope  `event` or `seller`.
 * @param int|array $target Event post ID, or seller reference.
 * @return bool False for an invalid target.
 */
function ec_commerce_fee_delete_override( $scope, $target ) {
	$location = ec_commerce_fee_override_location( $scope, $target );
	if ( ! $location ) {
		return false;
	}
	if ( 'seller' === $scope ) {
		delete_blog_option( $location['blog_id'], $location['key'] );
		return true;
	}
	switch_to_blog( $location['blog_id'] );
	delete_post_meta( $location['post_id'], $location['key'] );
	restore_current_blog();
	return true;
}

/**
 * Register the event fee override meta.
 *
 * Registered on every site: Shop writes it under `switch_to_blog()`, and
 * registration (with its sanitizer) applies to the whole request, not one
 * blog. Protected meta, not in REST: only the Shop's fee override abilities
 * change it, and only for network admins.
 */
function ec_commerce_fee_register_event_meta() {
	register_post_meta(
		'data_machine_events',
		EC_COMMERCE_FEE_EVENT_META,
		array(
			'type'              => 'object',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => 'ec_commerce_fee_sanitize_override',
			'auth_callback'     => static function () {
				return current_user_can( 'manage_network_options' );
			},
		)
	);
}
add_action( 'init', 'ec_commerce_fee_register_event_meta' );

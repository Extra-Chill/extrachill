<?php
/**
 * Commerce fee overrides: the authority over seller and event fee rates.
 *
 * The network fee settings are the global default. A seller or an event can
 * override some fields; the overrides are stored on their owners and
 * resolved by `ec_commerce_fee_settings( $context )` (extrachill-network,
 * `inc/commerce/fee-settings.php`), which every fee caller reads:
 *
 *     global → seller (type + id) → event
 *
 * - extrachill/get-commerce-fee-override    The override, the global rates and the resolved rates.
 * - extrachill/set-commerce-fee-override    Set some fields; a null field inherits again.
 * - extrachill/clear-commerce-fee-override  Remove the override: every field inherits.
 *
 * Network admins only (`manage_network_options`, the capability of the
 * network Payments page that edits the global rates). Sellers cannot set
 * their own fees. Orders keep the fee they were charged, so a change applies
 * to checkouts started after it.
 *
 * Surfaced by `wp extrachill shop fees` (extrachill-cli).
 *
 * @package ExtraChillShop
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_fee_override_abilities' );

/**
 * Register the fee override abilities.
 */
function extrachill_shop_register_fee_override_abilities(): void {
	$output = array(
		'type'       => 'object',
		'properties' => array(
			'scope'    => array( 'type' => 'string' ),
			'event_id' => array( 'type' => array( 'integer', 'null' ) ),
			'seller'   => array( 'type' => array( 'object', 'null' ) ),
			'override' => array(
				'type'        => 'object',
				'description' => 'Fields this scope sets; every other field inherits.',
			),
			'global'   => array(
				'type'        => 'object',
				'description' => 'The network default rates.',
			),
			'resolved' => array(
				'type'        => 'object',
				'description' => 'The rates a sale in this scope is charged (for an event, with its ticket seller\'s override).',
			),
		),
	);

	wp_register_ability(
		'extrachill/get-commerce-fee-override',
		array(
			'label'               => __( 'Get Commerce Fee Override', 'extrachill-shop' ),
			'description'         => __( 'Read a seller or event fee override with the global and resolved fee rates. Network admins only.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => extrachill_shop_fee_override_input_schema( false ),
			'output_schema'       => $output,
			'execute_callback'    => 'extrachill_shop_ability_get_fee_override',
			'permission_callback' => 'extrachill_shop_fee_override_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/set-commerce-fee-override',
		array(
			'label'               => __( 'Set Commerce Fee Override', 'extrachill-shop' ),
			'description'         => __( 'Override some fee rates for a seller or an event. Fields not given keep their override; a null field inherits again. Applies to checkouts started afterwards. Network admins only.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => extrachill_shop_fee_override_input_schema( true ),
			'output_schema'       => $output,
			'execute_callback'    => 'extrachill_shop_ability_set_fee_override',
			'permission_callback' => 'extrachill_shop_fee_override_permission',
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

	wp_register_ability(
		'extrachill/clear-commerce-fee-override',
		array(
			'label'               => __( 'Clear Commerce Fee Override', 'extrachill-shop' ),
			'description'         => __( 'Remove a seller or event fee override so every rate inherits again. Network admins only.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => extrachill_shop_fee_override_input_schema( false ),
			'output_schema'       => $output,
			'execute_callback'    => 'extrachill_shop_ability_clear_fee_override',
			'permission_callback' => 'extrachill_shop_fee_override_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => true,
				),
			),
		)
	);
}

/**
 * Input schema: the scope and its target, plus the fees for a set.
 *
 * @param bool $with_fees Whether the `fees` object is part of the input.
 * @return array
 */
function extrachill_shop_fee_override_input_schema( bool $with_fees ): array {
	$properties = array(
		'scope'    => array(
			'type' => 'string',
			'enum' => array( 'event', 'seller' ),
		),
		'event_id' => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'Event post ID on the Events site (scope event).',
		),
		'seller'   => extrachill_shop_seller_input_schema(),
	);
	$required   = array( 'scope' );
	if ( $with_fees ) {
		$fields = array();
		$caps   = ec_commerce_fee_percent_caps();
		foreach ( ec_commerce_fee_fields() as $key => $is_percent ) {
			$fields[ $key ] = array(
				'type'    => array( $is_percent ? 'number' : 'integer', 'null' ),
				'minimum' => 0,
			);
			if ( $is_percent ) {
				$fields[ $key ]['maximum'] = $caps[ $key ] ?? 100;
			}
		}
		$properties['fees'] = array(
			'type'                 => 'object',
			'description'          => 'Fee fields to set (percentages, or minor units for *_minor). null inherits the field again.',
			'properties'           => $fields,
			'additionalProperties' => false,
			'minProperties'        => 1,
		);
		$required[]         = 'fees';
	}
	return array(
		'type'                 => 'object',
		'properties'           => $properties,
		'required'             => $required,
		'additionalProperties' => false,
	);
}

/**
 * Only network admins change or read fee overrides.
 *
 * @return true|WP_Error
 */
function extrachill_shop_fee_override_permission() {
	if ( current_user_can( 'manage_network_options' ) ) {
		return true;
	}
	return new WP_Error( 'forbidden', __( 'Only network admins can manage commerce fee overrides.', 'extrachill-shop' ), array( 'status' => 403 ) );
}

/**
 * The override target named by ability input.
 *
 * @param array $input Ability input.
 * @return array{scope:string,target:int|array}|WP_Error
 */
function extrachill_shop_fee_override_target( array $input ) {
	$scope = (string) ( $input['scope'] ?? '' );
	if ( 'event' === $scope ) {
		if ( isset( $input['seller'] ) || ! is_int( $input['event_id'] ?? null ) || $input['event_id'] < 1 ) {
			return new WP_Error( 'invalid_fee_override_target', __( 'An event fee override needs an event_id and no seller.', 'extrachill-shop' ), array( 'status' => 400 ) );
		}
		return array(
			'scope'  => 'event',
			'target' => $input['event_id'],
		);
	}
	if ( 'seller' === $scope ) {
		if ( isset( $input['event_id'] ) ) {
			return new WP_Error( 'invalid_fee_override_target', __( 'A seller fee override needs a seller and no event_id.', 'extrachill-shop' ), array( 'status' => 400 ) );
		}
		$seller = extrachill_shop_seller_from_input( $input );
		return is_wp_error( $seller ) ? $seller : array(
			'scope'  => 'seller',
			'target' => $seller,
		);
	}
	return new WP_Error( 'invalid_fee_override_scope', __( 'The scope must be event or seller.', 'extrachill-shop' ), array( 'status' => 400 ) );
}

/**
 * Whether the target exists, so an override is never stored for a typo.
 *
 * Events are checked on the Events site (any status, so a show can be set
 * up before it is published); sellers through their owner site.
 *
 * @param array $target `extrachill_shop_fee_override_target()`.
 * @return true|WP_Error
 */
function extrachill_shop_fee_override_target_exists( array $target ) {
	if ( 'seller' === $target['scope'] ) {
		$canonical = extrachill_shop_get_canonical_seller( $target['target'] );
		return is_wp_error( $canonical ) ? $canonical : true;
	}
	$blog_id = (int) ec_get_blog_id( 'events' );
	if ( $blog_id < 1 ) {
		return new WP_Error( 'events_site_unavailable', __( 'The Events site is unavailable.', 'extrachill-shop' ), array( 'status' => 503 ) );
	}
	switch_to_blog( $blog_id );
	$type = get_post_type( (int) $target['target'] );
	restore_current_blog();
	return 'data_machine_events' === $type ? true : new WP_Error( 'event_not_found', __( 'Event not found.', 'extrachill-shop' ), array( 'status' => 404 ) );
}

/**
 * Ability output for a target: its override, the global and resolved rates.
 *
 * An event's resolved rates include its ticket seller's override, read
 * from the ticket offer checkout uses (`extrachill/get-event-ticket-offer`).
 *
 * @param array $target `extrachill_shop_fee_override_target()`.
 * @return array
 */
function extrachill_shop_fee_override_output( array $target ): array {
	$seller  = 'seller' === $target['scope'] ? $target['target'] : null;
	$context = array( 'seller' => $seller );
	if ( 'event' === $target['scope'] ) {
		$offer   = extrachill_shop_execute_owner_ability( 'events', 'extrachill/get-event-ticket-offer', 'GET', array( 'event' => $target['target'] ) );
		$seller  = is_wp_error( $offer ) ? null : ec_commerce_fee_seller_ref( $offer['seller'] ?? null );
		$context = array(
			'kind'     => 'extrachill_ticket',
			'seller'   => $seller,
			'event_id' => $target['target'],
		);
	}
	return array(
		'scope'    => $target['scope'],
		'event_id' => 'event' === $target['scope'] ? (int) $target['target'] : null,
		'seller'   => $seller,
		'override' => ec_commerce_fee_get_override( $target['scope'], $target['target'] ),
		'global'   => ec_commerce_fee_global_settings(),
		'resolved' => ec_commerce_fee_settings( $context ),
	);
}

/**
 * Read a fee override.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_get_fee_override( array $input ) {
	$target = extrachill_shop_fee_override_target( $input );
	return is_wp_error( $target ) ? $target : extrachill_shop_fee_override_output( $target );
}

/**
 * Set some fields of a fee override.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_set_fee_override( array $input ) {
	$target = extrachill_shop_fee_override_target( $input );
	if ( is_wp_error( $target ) ) {
		return $target;
	}
	$fees = $input['fees'] ?? null;
	if ( ! is_array( $fees ) || array() === $fees || array() !== array_diff_key( $fees, ec_commerce_fee_fields() ) ) {
		return new WP_Error( 'invalid_fee_override', __( 'Give at least one known fee field.', 'extrachill-shop' ), array( 'status' => 400 ) );
	}
	foreach ( $fees as $key => $value ) {
		if ( null !== $value && ( ! is_numeric( $value ) || (float) $value < 0 ) ) {
			/* translators: %s: fee field. */
			return new WP_Error( 'invalid_fee_override', sprintf( __( '%s must be a number of at least 0, or null to inherit.', 'extrachill-shop' ), $key ), array( 'status' => 400 ) );
		}
	}
	$exists = extrachill_shop_fee_override_target_exists( $target );
	if ( true !== $exists ) {
		return $exists;
	}
	if ( false === ec_commerce_fee_update_override( $target['scope'], $target['target'], $fees ) ) {
		return new WP_Error( 'fee_override_failed', __( 'The fee override could not be saved.', 'extrachill-shop' ), array( 'status' => 500 ) );
	}
	return extrachill_shop_fee_override_output( $target );
}

/**
 * Remove a fee override.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_clear_fee_override( array $input ) {
	$target = extrachill_shop_fee_override_target( $input );
	if ( is_wp_error( $target ) ) {
		return $target;
	}
	if ( ! ec_commerce_fee_delete_override( $target['scope'], $target['target'] ) ) {
		return new WP_Error( 'fee_override_failed', __( 'The fee override could not be cleared.', 'extrachill-shop' ), array( 'status' => 500 ) );
	}
	return extrachill_shop_fee_override_output( $target );
}

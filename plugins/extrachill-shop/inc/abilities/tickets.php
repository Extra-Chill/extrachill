<?php
/** Ticket abilities. Public checkout is admitted only by the protected API adapter. */
defined( 'ABSPATH' ) || exit;
add_action( 'wp_abilities_api_init', 'extrachill_shop_register_ticket_abilities' );
function extrachill_shop_register_ticket_abilities() {
	wp_register_ability( 'extrachill/start-ticket-checkout', array(
		'label'               => 'Start ticket checkout',
		'description'         => 'Start seller-settled Stripe ticket checkout.',
		'category'            => 'extrachill-shop',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'event', 'quantity', 'name', 'email' ),
			'properties'           => array(
				'event'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'quantity' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10,
				),
				'name'     => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'email'    => array(
					'type'   => 'string',
					'format' => 'email',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'checkout_url' => array( 'type' => 'string' ),
				'session_id'   => array( 'type' => 'string' ),
			),
		),
		'permission_callback' => '__return_true',
		'execute_callback'    => 'extrachill_shop_start_ticket_checkout',
		'meta'                => array( 'show_in_rest' => false ),
	) );
	wp_register_ability( 'extrachill/refund-ticket-order', array(
		'label'               => 'Refund ticket order',
		'description'         => 'Refund an unredeemed ticket order.',
		'category'            => 'extrachill-shop',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'order_reference' ),
			'properties'           => array(
				'order_reference' => array(
					'type'      => 'string',
					'minLength' => 1,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'refunded' => array( 'type' => 'boolean' ) ),
		),
		'permission_callback' => 'extrachill_shop_ticket_refund_permission',
		'execute_callback'    => 'extrachill_shop_refund_ticket_order',
		'meta'                => array( 'show_in_rest' => true ),
	) );
}

<?php
/**
 * Global function stand-ins for ticket tests.
 *
 * @package ExtraChillShop
 */

function is_email( $email ) {
	return filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function extrachill_shop_stripe_init() {
	return true;
}

function do_action( ...$args ) {
	$GLOBALS['ticket_actions'][] = $args;
}

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

function extrachill_shop_account_can_receive_payments( $account ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature mirrors the runtime helper.
	return $GLOBALS['ticket_account_ready'];
}

function do_action( ...$args ) {
	$GLOBALS['ticket_actions'][] = $args;
}

/**
 * Registers the Extra Chill Stripe Checkout payment method with the
 * WooCommerce block checkout. Payment runs server side: the gateway's
 * process_payment() returns a Stripe Checkout redirect that the block follows.
 *
 * @param {Object} wc WooCommerce globals (wcSettings, wcBlocksRegistry).
 * @param {Object} wp WordPress globals (element, htmlEntities).
 */
( function ( wc, wp ) {
	const settings = wc.wcSettings.getSetting( 'extrachill_stripe_checkout_data', {} );
	const decode = wp.htmlEntities.decodeEntities;
	const el = wp.element.createElement;
	const label = decode( settings.title || 'Pay securely with Stripe' );
	const description = decode(
		settings.description || "You'll be redirected to Stripe to pay securely."
	);

	function Content() {
		return el( 'p', null, description );
	}

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'extrachill_stripe_checkout',
		label: el( 'span', null, label ),
		ariaLabel: label,
		content: el( Content ),
		edit: el( Content ),
		canMakePayment() {
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )( window.wc, window.wp );
